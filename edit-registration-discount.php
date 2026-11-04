<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

$name = $_SESSION['login_user'];
include("db/config.php");
include("registration_pricing_helpers.php");

$msg = "";

if (isset($_GET['id'])) {
    $encodedId = $_GET['id'];
    $discountId = base64_decode($encodedId);

    if (!is_numeric($discountId)) {
        echo "Invalid Discount ID!";
        exit;
    }

    $stmt = mysqli_prepare($db, "SELECT * FROM registration_discounts WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $discountId);
    mysqli_stmt_execute($stmt);
    $discountResult = mysqli_stmt_get_result($stmt);

    if ($discountResult && mysqli_num_rows($discountResult) > 0) {
        $row = mysqli_fetch_assoc($discountResult);
        $existingName    = $row['discount_name'];
        $existingStart   = $row['start_date'];
        $existingEnd     = $row['end_date'];
        $existingType    = $row['discount_type'];
        $existingValue   = $row['discount_value'];
        $existingStatus  = $row['status'];
    } else {
        echo "Special Discount not found!";
        exit;
    }
    mysqli_stmt_close($stmt);

    // Currently mapped categories
    $stmt = mysqli_prepare($db, "SELECT category_id FROM registration_discount_categories WHERE discount_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $discountId);
    mysqli_stmt_execute($stmt);
    $mappedResult = mysqli_stmt_get_result($stmt);
    $mappedCategories = [];
    while ($m = mysqli_fetch_assoc($mappedResult)) {
        $mappedCategories[] = (int) $m['category_id'];
    }
    mysqli_stmt_close($stmt);
} else {
    header("Location: manage-registration-discounts.php");
    exit;
}

$categoriesResult = mysqli_query($db, "SELECT id, category_name FROM registration_categories ORDER BY category_name ASC");

// Update discount
if (isset($_POST['submit'])) {
    $discountName  = trim($_POST['discount_name']);
    $startDate     = $_POST['start_date'];
    $endDate       = $_POST['end_date'];
    $discountType  = $_POST['discount_type'];
    $discountValue = round((float) $_POST['discount_value'], 2);
    $categoryIds   = isset($_POST['category_ids']) ? array_map('intval', $_POST['category_ids']) : [];
    $status        = (int) $_POST['status'];

    $valid = true;

    if ($discountName === '') {
        $msg .= "<div class='alert alert-danger'>Discount Name is required.</div>";
        $valid = false;
    }
    if (!$startDate || !$endDate) {
        $msg .= "<div class='alert alert-danger'>Start Date and End Date are required.</div>";
        $valid = false;
    } elseif (strtotime($endDate) < strtotime($startDate)) {
        $msg .= "<div class='alert alert-danger'>End Date cannot be earlier than Start Date.</div>";
        $valid = false;
    }
    if (!in_array($discountType, ['percentage', 'fixed'], true)) {
        $msg .= "<div class='alert alert-danger'>Invalid discount type.</div>";
        $valid = false;
    }
    if ($discountType === 'percentage' && ($discountValue <= 0 || $discountValue > 100)) {
        $msg .= "<div class='alert alert-danger'>Percentage discount must be between 0 and 100.</div>";
        $valid = false;
    }
    if ($discountType === 'fixed' && $discountValue <= 0) {
        $msg .= "<div class='alert alert-danger'>Fixed discount amount must be greater than zero.</div>";
        $valid = false;
    }
    if (empty($categoryIds)) {
        $msg .= "<div class='alert alert-danger'>Please select at least one applicable category.</div>";
        $valid = false;
    }

    if ($valid) {
        // Verify all selected categories exist
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $types = str_repeat('i', count($categoryIds));
        $stmt = mysqli_prepare($db, "SELECT COUNT(*) AS c FROM registration_categories WHERE id IN ($placeholders)");
        mysqli_stmt_bind_param($stmt, $types, ...$categoryIds);
        mysqli_stmt_execute($stmt);
        $catCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
        mysqli_stmt_close($stmt);

        if ($catCount !== count($categoryIds)) {
            $msg .= "<div class='alert alert-danger'>One or more selected categories were not found.</div>";
            $valid = false;
        }
    }

    if ($valid) {
        mysqli_begin_transaction($db);
        $stmt = mysqli_prepare($db, "UPDATE registration_discounts
            SET discount_name = ?, start_date = ?, end_date = ?, discount_type = ?, discount_value = ?, status = ?
            WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "ssssdii", $discountName, $startDate, $endDate, $discountType, $discountValue, $status, $discountId);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            // Rebuild category mapping
            $stmtDel = mysqli_prepare($db, "DELETE FROM registration_discount_categories WHERE discount_id = ?");
            mysqli_stmt_bind_param($stmtDel, "i", $discountId);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);

            $mapOk = true;
            foreach ($categoryIds as $catId) {
                $stmtMap = mysqli_prepare($db, "INSERT INTO registration_discount_categories (discount_id, category_id) VALUES (?, ?)");
                mysqli_stmt_bind_param($stmtMap, "ii", $discountId, $catId);
                if (!mysqli_stmt_execute($stmtMap)) {
                    $mapOk = false;
                }
                mysqli_stmt_close($stmtMap);
            }

            if ($mapOk) {
                mysqli_commit($db);
                $statusCode = 1;
                $encodedStatus = base64_encode($statusCode);
                echo ("<SCRIPT LANGUAGE='JavaScript'>window.location.href='manage-registration-discounts.php?status=$encodedStatus';</SCRIPT>");
                exit;
            } else {
                mysqli_rollback($db);
                $msg .= "<div class='alert alert-danger'>Failed to update discount categories.</div>";
            }
        } else {
            mysqli_rollback($db);
            mysqli_stmt_close($stmt);
            $msg .= "<div class='alert alert-danger'>Error updating discount: " . htmlspecialchars(mysqli_error($db)) . "</div>";
        }
    }

    // Keep submitted values on validation failure
    $existingName  = $discountName;
    $existingStart = $startDate;
    $existingEnd   = $endDate;
    $existingType  = $discountType;
    $existingValue = $discountValue;
    $existingStatus = $status;
    $mappedCategories = $categoryIds;
}

$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'];
$helpdeskNumber = $settings['helpdesk_no'];
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Special Discount Update</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .red-text {
            color: red;
        }

        .cat-grid {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
            max-height: 220px;
            overflow-y: auto;
        }

        .cat-grid label {
            display: block;
            font-weight: 400;
            margin-bottom: 6px;
        }
    </style>
</head>

<body class="">
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <!-- Header -->
    <?php include("header.php"); ?>
    <!-- /Header -->
    <!-- navbar -->
    <?php include("navbar.php"); ?>
    <!-- /navbar -->
    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Update Special Discount</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <?php if ($msg) echo $msg; ?>
                            <br />
                            <form method="post" action="" autocomplete="off">
                                <div class="row">
                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Discount Name <span class="red-text">*</span></label>
                                            <input type="text" name="discount_name" class="form-control"
                                                value="<?php echo htmlspecialchars($existingName); ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Start Date <span class="red-text">*</span></label>
                                            <input type="date" name="start_date" id="start_date" class="form-control"
                                                value="<?php echo $existingStart; ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">End Date <span class="red-text">*</span></label>
                                            <input type="date" name="end_date" id="end_date" class="form-control"
                                                value="<?php echo $existingEnd; ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Discount Type <span class="red-text">*</span></label>
                                            <select name="discount_type" id="discount_type" class="form-control" required>
                                                <option value="percentage" <?php echo ($existingType === 'percentage') ? 'selected' : ''; ?>>Percentage (%)</option>
                                                <option value="fixed" <?php echo ($existingType === 'fixed') ? 'selected' : ''; ?>>Fixed Amount</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Discount Value <span class="red-text">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text" id="discountPrefix"><?php echo ($existingType === 'percentage') ? '%' : '&#8377;'; ?></span>
                                                <input type="number" step="0.01" min="0.01" name="discount_value" id="discount_value" class="form-control"
                                                    value="<?php echo $existingValue; ?>" required>
                                            </div>
                                            <small class="text-muted" id="discountHint"></small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="1" <?php echo ((int) $existingStatus === 1) ? 'selected' : ''; ?>>Active</option>
                                                <option value="0" <?php echo ((int) $existingStatus === 0) ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Applicable Categories <span class="red-text">*</span></label>
                                            <div class="cat-grid">
                                                <label class="font-weight-bold"><input type="checkbox" id="checkAllCats"> &nbsp;Select All</label>
                                                <hr class="my-1">
                                                <?php while ($cat = mysqli_fetch_assoc($categoriesResult)): ?>
                                                    <label>
                                                        <input type="checkbox" name="category_ids[]" value="<?php echo (int) $cat['id']; ?>" class="cat-checkbox"
                                                            <?php echo in_array((int) $cat['id'], $mappedCategories, true) ? 'checked' : ''; ?>>
                                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                                    </label>
                                                <?php endwhile; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                        <button type="submit" class="btn btn-secondary" name="submit" id="submit">
                                            <i class="feather icon-save"></i>&nbsp; Update Special Discount
                                        </button>
                                        <a href="manage-registration-discounts.php" class="btn btn-outline-secondary">Back</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="card-body">
                            <div class="dt-responsive table-responsive"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        var discountTypeSel = document.getElementById('discount_type');
        var discountValueInput = document.getElementById('discount_value');
        var discountPrefix = document.getElementById('discountPrefix');
        var discountHint = document.getElementById('discountHint');

        function syncDiscountType() {
            if (discountTypeSel.value === 'percentage') {
                discountPrefix.textContent = '%';
                discountValueInput.max = '100';
                discountHint.textContent = 'Percentage of the registration fee (max 100).';
            } else {
                discountPrefix.textContent = '\u20B9';
                discountValueInput.removeAttribute('max');
                discountHint.textContent = 'Flat amount subtracted from the registration fee (capped at the fee).';
            }
        }
        discountTypeSel.addEventListener('change', syncDiscountType);
        syncDiscountType();

        document.getElementById('checkAllCats').addEventListener('change', function() {
            document.querySelectorAll('.cat-checkbox').forEach(function(cb) {
                cb.checked = document.getElementById('checkAllCats').checked;
            });
        });

        document.getElementById('end_date').addEventListener('change', function() {
            var start = document.getElementById('start_date').value;
            if (start && this.value && this.value < start) {
                this.setCustomValidity('End Date cannot be earlier than Start Date');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>

</html>
