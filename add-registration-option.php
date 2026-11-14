<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}

include("db/config.php");
include("registration_pricing_helpers.php");

$msg = "";
$old = $_POST;

$categoriesResult = mysqli_query($db, "SELECT id, category_name FROM registration_categories ORDER BY category_name ASC");

// collect posted sub-rows for repopulation
$subRows = [];
foreach (($old['sub_name'] ?? []) as $i => $nm) {
    $subRows[$i] = ['name' => $nm, 'amount' => $old['sub_amount'][$i] ?? ''];
}

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

    // ---- Sub options (empty rows skip) ----
    $subs = [];
    $subErrors = [];
    foreach (($_POST['sub_name'] ?? []) as $i => $nm) {
        $nm  = trim($nm);
        $amt = round((float) ($_POST['sub_amount'][$i] ?? 0), 2);
        if ($nm === '' && $amt <= 0) continue;          // khali row ignore
        if ($nm === '') {
            $subErrors[] = "Sub Option #" . ($i + 1) . " ka naam missing hai.";
            continue;
        }
        if ($amt <= 0) {
            $subErrors[] = "Sub Option \"$nm\" ka amount 0 se zyada hona chahiye.";
            continue;
        }
        $subs[] = ['name' => $nm, 'amount' => $amt];
    }

    $valid = true;

    if ($categoryId <= 0) {
        $msg .= "<div class='alert alert-danger'>Please select a category.</div>";
        $valid = false;
    }
    if ($optionName === '') {
        $msg .= "<div class='alert alert-danger'>Option Name is required.</div>";
        $valid = false;
    }

    // Amount tabhi required jab koi sub-option na ho (standalone option ke liye)
    if (empty($subs)) {
        if ($amount < 0) {
            $msg .= "<div class='alert alert-danger'>Amount cannot be negative.</div>";
            $valid = false;
        } elseif ($availability === 1 && $amount <= 0) {
            $msg .= "<div class='alert alert-danger'>Amount must be greater than zero (or add Sub Options).</div>";
            $valid = false;
        }
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
    if ($subErrors) {
        $msg .= "<div class='alert alert-danger'>" . implode('<br>', $subErrors) . "</div>";
        $valid = false;
    }

    if ($valid) {
        mysqli_begin_transaction($db);
        try {
            // 1. Check karo ki is category ka ye parent pehle se exist karta hai ya nahi
            $stmt = mysqli_prepare($db, "SELECT id FROM registration_category_options 
                WHERE category_id = ? AND option_name = ? AND parent_id IS NULL LIMIT 1");
            mysqli_stmt_bind_param($stmt, "is", $categoryId, $optionName);
            mysqli_stmt_execute($stmt);
            $existingParent = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            $hasChildren = !empty($subs);
            $parentAmount = $hasChildren ? 0.00 : $amount; // If there are workshops, the parent amount will be 0.

            if ($existingParent) {
                // ---- Parent rule: Do not insert a new entry; update the existing one ----
                $parentId = (int) $existingParent['id'];
                $stmt = mysqli_prepare($db, "UPDATE registration_category_options 
                    SET amount = ?, currency = ?, availability = ?, gst_type = ?, gst_percentage = ?, 
                        start_date = ?, end_date = ?, status = ? 
                    WHERE id = ?");
                mysqli_stmt_bind_param(
                    $stmt,
                    "dsisdssii",
                    $parentAmount,
                    $currency,
                    $availability,
                    $gstType,
                    $gstPercentage,
                    $startDate,
                    $endDate,
                    $status,
                    $parentId
                );
                if (!mysqli_stmt_execute($stmt)) throw new Exception("Parent option update failed: " . mysqli_error($db));
                mysqli_stmt_close($stmt);
            } else {
                // ---- NEW PARENT INSERT ----
                $stmt = mysqli_prepare($db, "INSERT INTO registration_category_options 
                    (category_id, option_name, amount, currency, availability, gst_type, gst_percentage, start_date, end_date, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param(
                    $stmt,
                    "isdsisdssi",
                    $categoryId,
                    $optionName,
                    $parentAmount,
                    $currency,
                    $availability,
                    $gstType,
                    $gstPercentage,
                    $startDate,
                    $endDate,
                    $status
                );
                if (!mysqli_stmt_execute($stmt)) throw new Exception("Parent option insert failed: " . mysqli_error($db));
                $parentId = mysqli_insert_id($db);
                mysqli_stmt_close($stmt);
            }

            // 2. Handle sub-options (check first to avoid duplicates)
            $addedCount = 0;
            $updatedCount = 0;
            foreach ($subs as $s) {
                // Check whether this workshop already exists under this parent
                $stmt = mysqli_prepare($db, "SELECT id FROM registration_category_options 
                    WHERE parent_id = ? AND option_name = ? LIMIT 1");
                mysqli_stmt_bind_param($stmt, "is", $parentId, $s['name']);
                mysqli_stmt_execute($stmt);
                $existingChild = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($existingChild) {
                    // ---- EXISTING CHILD UPDATE (On Amount change) ----
                    $childId = (int) $existingChild['id'];
                    $stmt = mysqli_prepare($db, "UPDATE registration_category_options 
                        SET amount = ?, currency = ?, availability = ?, gst_type = ?, gst_percentage = ?, 
                            start_date = ?, end_date = ?, status = ? 
                        WHERE id = ?");
                    mysqli_stmt_bind_param(
                        $stmt,
                        "dsisdssii",
                        $s['amount'],
                        $currency,
                        $availability,
                        $gstType,
                        $gstPercentage,
                        $startDate,
                        $endDate,
                        $status,
                        $childId
                    );
                    $updatedCount++;
                } else {
                    // ---- NEW CHILD INSERT ----
                    $stmt = mysqli_prepare($db, "INSERT INTO registration_category_options 
                        (category_id, parent_id, option_name, amount, currency, availability, gst_type, gst_percentage, start_date, end_date, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param(
                        $stmt,
                        "iisdsisdssi",
                        $categoryId,
                        $parentId,
                        $s['name'],
                        $s['amount'],
                        $currency,
                        $availability,
                        $gstType,
                        $gstPercentage,
                        $startDate,
                        $endDate,
                        $status
                    );
                    $addedCount++;
                }

                if (!mysqli_stmt_execute($stmt)) throw new Exception("Sub option \"{$s['name']}\" save failed: " . mysqli_error($db));
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($db);

            // Dynamic success message
            $actionMsg = "";
            if ($existingParent) {
                $actionMsg = "Existing option <b>" . htmlspecialchars($optionName) . "</b> updated";
                if ($addedCount > 0) $actionMsg .= " and $addedCount a new workshop has been added.";
                if ($updatedCount > 0) $actionMsg .= " aur $updatedCount workshop is updated.";
            } else {
                $actionMsg = "New option <b>" . htmlspecialchars($optionName) . "</b> added";
                if ($addedCount > 0) $actionMsg .= " with $addedCount workshop(s)";
            }
            $actionMsg .= " successfully.";

            $msg = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
                <strong><i class='feather icon-check'></i> Thanks!</strong> $actionMsg
                <button type='button' class='close' data-dismiss='alert' aria-label='Close'><span aria-hidden='true'>&times;</span></button>
                </div>";
        } catch (Exception $e) {
            mysqli_rollback($db);
            $msg = "<div class='alert alert-danger alert-dismissible fade show' role='alert' id='goldmessage'>
                <strong><i class='feather icon-x'></i> Error!</strong> " . htmlspecialchars($e->getMessage()) . "
                <button type='button' class='close' data-dismiss='alert'><span aria-hidden='true'>&times;</span></button>
                </div>";
        }
    }
}

$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);
$favicon = $settings['favicon'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Add Registration Option</title>
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

        .sub-box {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 14px;
            background: #fafbfc;
        }
    </style>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <?php include("header.php"); ?>
    <?php include("navbar.php"); ?>

    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Add Workshop / Add-on Option</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <?php if ($msg) echo $msg; ?><br />

                            <form method="post" action="" autocomplete="off">
                                <div class="row">
                                    <div class="col-xl-6 col-md-12">
                                        <div class="form-group">
                                            <label class="form-label">Category <span class="red-text">*</span></label>
                                            <select name="category_id" class="form-control" required>
                                                <option value="" disabled selected>Choose</option>
                                                <?php while ($cat = mysqli_fetch_assoc($categoriesResult)): ?>
                                                    <option value="<?= (int)$cat['id'] ?>" <?= ($old['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($cat['category_name']) ?></option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>


                                    <div class="col-xl-6 col-md-12">
                                        <div class="form-group">
                                            <label class="form-label">Workshop Type (Parent Category) <span class="red-text">*</span></label>
                                            <select name="option_name" class="form-control" required>
                                                <option value="" disabled <?= empty($old['option_name']) ? 'selected' : '' ?>>Select Type</option>
                                                <option value="Full Day Workshop" <?= ($old['option_name'] ?? '') === 'Full Day Workshop' ? 'selected' : '' ?>>Full Day Workshop</option>
                                                <option value="Half Day Workshop" <?= ($old['option_name'] ?? '') === 'Half Day Workshop' ? 'selected' : '' ?>>Half Day Workshop</option>
                                                <option value="Pre Conference CME" <?= ($old['option_name'] ?? '') === 'Pre Conference CME' ? 'selected' : '' ?>>Pre Conference CME</option>
                                            </select>
                                            <small class="text-muted">Add the names of the specific workshops under "Sub-options" below.</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Amount <span class="red-text">*</span></label>
                                            <input type="number" step="0.01" min="0" name="amount" class="form-control"
                                                value="<?= htmlspecialchars($old['amount'] ?? '') ?>" required>
                                            <small class="text-muted">Set it to 0 if the price is below the options range.</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Currency</label>
                                            <select name="currency" class="form-control">
                                                <?php foreach (['INR' => 'INR (₹)', 'USD' => 'USD ($)', 'EUR' => 'EUR (€)', 'GBP' => 'GBP (£)'] as $k => $lbl): ?>
                                                    <option value="<?= $k ?>" <?= ($old['currency'] ?? 'INR') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Availability</label>
                                            <select name="availability" class="form-control">
                                                <option value="1" <?= ($old['availability'] ?? 1) == 1 ? 'selected' : '' ?>>Available</option>
                                                <option value="0" <?= ($old['availability'] ?? '') == 0 ? 'selected' : '' ?>>Unavailable</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="1" <?= ($old['status'] ?? 1) == 1 ? 'selected' : '' ?>>Active</option>
                                                <option value="0" <?= ($old['status'] ?? '') == 0 ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">GST Type</label>
                                            <select name="gst_type" id="gst_type" class="form-control">
                                                <?php foreach (['not_applicable' => 'Not Applicable', 'included' => 'GST Included', 'excluded' => 'GST Excluded'] as $k => $lbl): ?>
                                                    <option value="<?= $k ?>" <?= ($old['gst_type'] ?? 'not_applicable') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">GST Percentage (%)</label>
                                            <input type="number" step="0.01" min="0" max="100" name="gst_percentage" id="gst_percentage"
                                                class="form-control" value="<?= htmlspecialchars($old['gst_percentage'] ?? 0) ?>" <?= ($old['gst_type'] ?? 'not_applicable') === 'not_applicable' ? 'disabled' : '' ?>>
                                            <small class="text-muted" id="gst_hint"></small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Valid From</label>
                                            <input type="date" name="start_date" id="opt_start_date" class="form-control" value="<?= htmlspecialchars($old['start_date'] ?? '') ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Valid Till</label>
                                            <input type="date" name="end_date" id="opt_end_date" class="form-control" value="<?= htmlspecialchars($old['end_date'] ?? '') ?>">
                                        </div>
                                    </div>

                                    <!-- ================= SUB OPTIONS ================= -->
                                    <div class="col-xl-12">
                                        <div class="form-group">
                                            <label class="form-label">Specific Workshop Names & Prices <span class="text-muted" style="font-weight:400">(Workshops falling under this parent)</span></label>
                                            <div class="sub-box">
                                                <div class="row font-weight-bold mb-2">
                                                    <div class="col-md-8">Workshop / Session Name</div>
                                                    <div class="col-md-3">Amount</div>
                                                    <div class="col-md-1"></div>
                                                </div>

                                                <div id="subRows">
                                                    <?php
                                                    $rendered = 0;
                                                    foreach ($subRows as $s): ?>
                                                        <div class="row mb-2 sub-row">
                                                            <div class="col-md-8">
                                                                <input type="text" name="sub_name[]" class="form-control"
                                                                    placeholder="e.g. Understanding, Conducting and Reporting Causal Mediation Analysis"
                                                                    value="<?= htmlspecialchars($s['name']) ?>">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <input type="number" step="0.01" min="0" name="sub_amount[]" class="form-control"
                                                                    placeholder="1500" value="<?= htmlspecialchars($s['amount']) ?>">
                                                            </div>
                                                            <div class="col-md-1 text-center">
                                                                <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.sub-row').remove()">&times;</button>
                                                            </div>
                                                        </div>
                                                    <?php $rendered++;
                                                    endforeach;

                                                    for ($i = $rendered; $i < 3; $i++): ?>
                                                        <div class="row mb-2 sub-row">
                                                            <div class="col-md-8">
                                                                <input type="text" name="sub_name[]" class="form-control"
                                                                    placeholder="e.g. Understanding, Conducting and Reporting Causal Mediation Analysis">
                                                            </div>
                                                            <div class="col-md-3">
                                                                <input type="number" step="0.01" min="0" name="sub_amount[]" class="form-control" placeholder="1500">
                                                            </div>
                                                            <div class="col-md-1 text-center">
                                                                <button type="button" class="btn btn-sm btn-danger" onclick="this.closest('.sub-row').remove()">&times;</button>
                                                            </div>
                                                        </div>
                                                    <?php endfor; ?>
                                                </div>

                                                <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addSubRow()">
                                                    <i class="feather icon-plus"></i> Add Another Workshop
                                                </button>
                                                <small class="text-muted d-block mt-2">Empty rows will be automatically skipped. Currency, frequency, and validity will be inherited from the parent above.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-xl-12">
                                        <button type="submit" class="btn btn-secondary" name="submit">
                                            <i class="feather icon-save"></i>&nbsp; Add Registration Option
                                        </button>
                                        <a href="manage-registration-options.php" class="btn btn-outline-secondary">View All Options</a>
                                    </div>
                                </div>
                            </form>
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
        // Function to add a dynamic row (creates only a blank row; does not populate it with data)
        function addSubRow() {
            var row = document.createElement('div');
            row.className = 'row mb-2 sub-row';
            row.innerHTML =
                '<div class="col-md-8">' +
                '<input type="text" name="sub_name[]" class="form-control" ' +
                'placeholder="e.g. Understanding, Conducting and Reporting Causal Mediation Analysis (CMA)">' +
                '</div>' +
                '<div class="col-md-3">' +
                '<input type="number" step="0.01" min="0" name="sub_amount[]" class="form-control" placeholder="1500">' +
                '</div>' +
                '<div class="col-md-1 text-center">' +
                '<button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.sub-row\').remove()">&times;</button>' +
                '</div>';
            document.getElementById('subRows').appendChild(row);
        }

        $(document).ready(function() {
            $("#goldmessage").delay(5000).slideUp(300);
        });

        var gstTypeSel = document.getElementById('gst_type'),
            gstPctInput = document.getElementById('gst_percentage'),
            gstHint = document.getElementById('gst_hint');

        function syncGst() {
            var off = gstTypeSel.value === 'not_applicable';
            gstPctInput.disabled = off;
            gstHint.textContent = off ? '' : (gstTypeSel.value === 'included' ? 'GST is part of the amount above' : 'GST added on top of the amount above');
        }
        gstTypeSel.addEventListener('change', syncGst);
        syncGst();
        document.getElementById('opt_start_date').addEventListener('change', function() {
            document.getElementById('opt_end_date').min = this.value;
        });
    </script>
</body>

</html>