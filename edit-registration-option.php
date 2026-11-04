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
    $optionId = base64_decode($encodedId);

    if (!is_numeric($optionId)) {
        echo "Invalid Option ID!";
        exit;
    }

    $stmt = mysqli_prepare($db, "SELECT * FROM registration_category_options WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $optionId);
    mysqli_stmt_execute($stmt);
    $optionResult = mysqli_stmt_get_result($stmt);

    if ($optionResult && mysqli_num_rows($optionResult) > 0) {
        $row = mysqli_fetch_assoc($optionResult);
        $existingCategoryId = $row['category_id'];
        $existingName       = $row['option_name'];
        $existingAmount     = $row['amount'];
        $existingCurrency   = $row['currency'];
        $existingAvailability = $row['availability'];
        $existingGstType    = $row['gst_type'];
        $existingGstPct     = $row['gst_percentage'];
        $existingStart      = $row['start_date'];
        $existingEnd        = $row['end_date'];
        $existingStatus     = $row['status'];
    } else {
        echo "Registration Option not found!";
        exit;
    }
    mysqli_stmt_close($stmt);
} else {
    header("Location: manage-registration-options.php");
    exit;
}

$categoriesResult = mysqli_query($db, "SELECT id, category_name FROM registration_categories ORDER BY category_name ASC");

// Update option
if (isset($_POST['submit'])) {
    $categoryId    = (int) $_POST['category_id'];
    $optionName    = trim($_POST['option_name']);
    $amount        = round((float) $_POST['amount'], 2);
    $currency      = strtoupper(trim($_POST['currency']));
    $availability  = (int) $_POST['availability'];
    $gstType       = $_POST['gst_type'];
    $gstPercentage = ($gstType === 'not_applicable') ? 0 : round((float) $_POST['gst_percentage'], 2);
    $startDate     = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $endDate       = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $status        = (int) $_POST['status'];

    $valid = true;

    if ($categoryId <= 0) {
        $msg .= "<div class='alert alert-danger'>Please select a category.</div>";
        $valid = false;
    }
    if ($optionName === '') {
        $msg .= "<div class='alert alert-danger'>Option Name is required.</div>";
        $valid = false;
    }
    if ($amount < 0) {
        $msg .= "<div class='alert alert-danger'>Amount cannot be negative.</div>";
        $valid = false;
    } elseif ($availability === 1 && $amount <= 0) {
        $msg .= "<div class='alert alert-danger'>Amount must be greater than zero for an available option.</div>";
        $valid = false;
    }
    if (!in_array($currency, ['INR', 'USD', 'EUR', 'GBP'], true)) {
        $msg .= "<div class='alert alert-danger'>Invalid currency.</div>";
        $valid = false;
    }
    if (!in_array($gstType, ['included', 'excluded', 'not_applicable'], true)) {
        $msg .= "<div class='alert alert-danger'>Invalid GST type.</div>";
        $valid = false;
    }
    if ($gstType !== 'not_applicable' && ($gstPercentage < 0 || $gstPercentage > 100)) {
        $msg .= "<div class='alert alert-danger'>GST percentage must be between 0 and 100.</div>";
        $valid = false;
    }
    if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
        $msg .= "<div class='alert alert-danger'>End Date cannot be earlier than Start Date.</div>";
        $valid = false;
    }

    if ($valid) {
        $stmt = mysqli_prepare($db, "UPDATE registration_category_options
            SET category_id = ?, option_name = ?, amount = ?, currency = ?, availability = ?,
                gst_type = ?, gst_percentage = ?, start_date = ?, end_date = ?, status = ?
            WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "isdsisdssii", $categoryId, $optionName, $amount, $currency, $availability, $gstType, $gstPercentage, $startDate, $endDate, $status, $optionId);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $statusCode = 1;
            $encodedStatus = base64_encode($statusCode);
            echo ("<SCRIPT LANGUAGE='JavaScript'>window.location.href='manage-registration-options.php?status=$encodedStatus';</SCRIPT>");
            exit;
        } else {
            mysqli_stmt_close($stmt);
            $msg .= "<div class='alert alert-danger'>Error updating option: " . htmlspecialchars(mysqli_error($db)) . "</div>";
        }
    }

    // Keep submitted values on validation failure
    $existingCategoryId = $categoryId;
    $existingName       = $optionName;
    $existingAmount     = $amount;
    $existingCurrency   = $currency;
    $existingAvailability = $availability;
    $existingGstType    = $gstType;
    $existingGstPct     = $gstPercentage;
    $existingStart      = $startDate;
    $existingEnd        = $endDate;
    $existingStatus     = $status;
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
    <title>Registration Option Update</title>
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
                                <h5 class="m-b-10">Update Workshop / Add-on Option</h5>
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
                                            <label class="form-label">Category <span class="red-text">*</span></label>
                                            <select name="category_id" class="form-control" required>
                                                <?php while ($cat = mysqli_fetch_assoc($categoriesResult)): ?>
                                                    <option value="<?php echo (int) $cat['id']; ?>" <?php echo ((int) $existingCategoryId === (int) $cat['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Option Name <span class="red-text">*</span></label>
                                            <input type="text" name="option_name" class="form-control"
                                                value="<?php echo htmlspecialchars($existingName); ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Amount <span class="red-text">*</span></label>
                                            <input type="number" step="0.01" min="0" name="amount" class="form-control"
                                                value="<?php echo $existingAmount; ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Currency</label>
                                            <select name="currency" class="form-control">
                                                <option value="INR" <?php echo ($existingCurrency === 'INR') ? 'selected' : ''; ?>>INR (&#8377;)</option>
                                                <option value="USD" <?php echo ($existingCurrency === 'USD') ? 'selected' : ''; ?>>USD ($)</option>
                                                <option value="EUR" <?php echo ($existingCurrency === 'EUR') ? 'selected' : ''; ?>>EUR (&euro;)</option>
                                                <option value="GBP" <?php echo ($existingCurrency === 'GBP') ? 'selected' : ''; ?>>GBP (&pound;)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Availability</label>
                                            <select name="availability" class="form-control">
                                                <option value="1" <?php echo ((int) $existingAvailability === 1) ? 'selected' : ''; ?>>Available</option>
                                                <option value="0" <?php echo ((int) $existingAvailability === 0) ? 'selected' : ''; ?>>Unavailable</option>
                                            </select>
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

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">GST Type</label>
                                            <select name="gst_type" id="gst_type" class="form-control">
                                                <option value="not_applicable" <?php echo ($existingGstType === 'not_applicable') ? 'selected' : ''; ?>>Not Applicable</option>
                                                <option value="included" <?php echo ($existingGstType === 'included') ? 'selected' : ''; ?>>GST Included</option>
                                                <option value="excluded" <?php echo ($existingGstType === 'excluded') ? 'selected' : ''; ?>>GST Excluded</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">GST Percentage (%)</label>
                                            <input type="number" step="0.01" min="0" max="100" name="gst_percentage" id="gst_percentage" class="form-control"
                                                value="<?php echo $existingGstPct; ?>" <?php echo ($existingGstType === 'not_applicable') ? 'disabled' : ''; ?>>
                                            <small class="text-muted" id="gst_hint"></small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Valid From</label>
                                            <input type="date" name="start_date" id="opt_start_date" class="form-control"
                                                value="<?php echo $existingStart ? $existingStart : ''; ?>">
                                            <small class="text-muted">Optional</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Valid Till</label>
                                            <input type="date" name="end_date" id="opt_end_date" class="form-control"
                                                value="<?php echo $existingEnd ? $existingEnd : ''; ?>">
                                            <small class="text-muted">Optional</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                        <button type="submit" class="btn btn-secondary" name="submit" id="submit">
                                            <i class="feather icon-save"></i>&nbsp; Update Registration Option
                                        </button>
                                        <a href="manage-registration-options.php" class="btn btn-outline-secondary">Back</a>
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

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script>
        var gstTypeSel = document.getElementById('gst_type');
        var gstPctInput = document.getElementById('gst_percentage');
        var gstHint = document.getElementById('gst_hint');
        gstTypeSel.addEventListener('change', function() {
            var disabled = (this.value === 'not_applicable');
            gstPctInput.disabled = disabled;
            gstHint.textContent = disabled ? '' : (this.value === 'included' ? 'GST is part of the amount above' : 'GST added on top of the amount above');
        });

        document.getElementById('opt_start_date').addEventListener('change', function() {
            document.getElementById('opt_end_date').min = this.value;
        });
        document.getElementById('opt_end_date').addEventListener('change', function() {
            var start = document.getElementById('opt_start_date').value;
            if (start && this.value && this.value < start) {
                this.setCustomValidity('End Date cannot be earlier than Start Date');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>

</html>
