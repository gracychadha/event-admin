<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}

include("db/config.php");
include("registration_pricing_helpers.php");

$msg = "";

// Fetch categories for dropdown
$categoriesResult = mysqli_query($db, "SELECT id, category_name FROM registration_categories ORDER BY category_name ASC");

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
        $stmt = mysqli_prepare($db, "INSERT INTO registration_category_options
            (category_id, option_name, amount, currency, availability, gst_type, gst_percentage, start_date, end_date, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isdsisdssi", $categoryId, $optionName, $amount, $currency, $availability, $gstType, $gstPercentage, $startDate, $endDate, $status);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $msg = "
            <div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
            <strong><i class='feather icon-check'></i> Thanks!</strong> Registration Option added successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
              <span aria-hidden='true'>&times;</span>
            </button>
            </div>";
        } else {
            mysqli_stmt_close($stmt);
            $msg = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
            <strong><i class='feather icon-x'></i> Error!</strong> Failed to add Registration Option.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
              <span aria-hidden='true'>&times;</span>
            </button>
            </div>";
        }
    }
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
    </style>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <!-- Header -->
    <?php include("header.php"); ?>
    <!-- /Header -->

    <!-- Navbar -->
    <?php include("navbar.php"); ?>
    <!-- /Navbar -->

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
                            <?php if ($msg) echo $msg; ?>
                            <br />

                            <form method="post" action="" autocomplete="off">
                                <div class="row">
                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Category <span class="red-text">*</span></label>
                                            <select name="category_id" class="form-control" required>
                                                <option value="" selected disabled>Choose</option>
                                                <?php while ($cat = mysqli_fetch_assoc($categoriesResult)): ?>
                                                    <option value="<?php echo (int) $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Option Name <span class="red-text">*</span></label>
                                            <input type="text" name="option_name" class="form-control" placeholder="e.g. Full Day Workshop" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Amount <span class="red-text">*</span></label>
                                            <input type="number" step="0.01" min="0" name="amount" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Currency</label>
                                            <select name="currency" class="form-control">
                                                <option value="INR">INR (&#8377;)</option>
                                                <option value="USD">USD ($)</option>
                                                <option value="EUR">EUR (&euro;)</option>
                                                <option value="GBP">GBP (&pound;)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Availability</label>
                                            <select name="availability" class="form-control">
                                                <option value="1">Available</option>
                                                <option value="0">Unavailable</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="1" selected>Active</option>
                                                <option value="0">Inactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">GST Type</label>
                                            <select name="gst_type" id="gst_type" class="form-control">
                                                <option value="not_applicable">Not Applicable</option>
                                                <option value="included">GST Included</option>
                                                <option value="excluded">GST Excluded</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">GST Percentage (%)</label>
                                            <input type="number" step="0.01" min="0" max="100" name="gst_percentage" id="gst_percentage" class="form-control" value="0" disabled>
                                            <small class="text-muted" id="gst_hint"></small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Valid From</label>
                                            <input type="date" name="start_date" id="opt_start_date" class="form-control">
                                            <small class="text-muted">Optional</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Valid Till</label>
                                            <input type="date" name="end_date" id="opt_end_date" class="form-control">
                                            <small class="text-muted">Optional</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                        <button type="submit" class="btn btn-secondary" name="submit">
                                            <i class="feather icon-save"></i>&nbsp; Add Registration Option
                                        </button>
                                        <a href="manage-registration-options.php" class="btn btn-outline-secondary">View All Options</a>
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
    <script src="assets/js/plugins/jquery.dataTables.min.js"></script>
    <script src="assets/js/plugins/dataTables.bootstrap4.min.js"></script>
    <script src="assets/js/plugins/buttons.colVis.min.js"></script>
    <script src="assets/js/plugins/buttons.print.min.js"></script>
    <script src="assets/js/plugins/pdfmake.min.js"></script>
    <script src="assets/js/plugins/vfs_fonts.js"></script>
    <script src="assets/js/plugins/jszip.min.js"></script>
    <script src="assets/js/plugins/dataTables.buttons.min.js"></script>
    <script src="assets/js/plugins/buttons.html5.min.js"></script>
    <script src="assets/js/plugins/buttons.bootstrap4.min.js"></script>
    <script src="assets/js/pages/data-export-custom.js"></script>
    <script>
        $(document).ready(function() {
            $("#goldmessage").delay(5000).slideUp(300);
        });

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
