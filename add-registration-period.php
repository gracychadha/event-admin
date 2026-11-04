<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}

include("db/config.php");
include("registration_pricing_helpers.php");

$msg = "";

if (isset($_POST['submit'])) {
    $periodName = trim($_POST['period_name']);
    $startDate  = $_POST['start_date'];
    $endDate    = $_POST['end_date'];
    $displayOrder = (int) $_POST['display_order'];
    $status     = (int) $_POST['status'];

    $valid = true;

    if ($periodName === '') {
        $msg .= "<div class='alert alert-danger'>Period Name is required.</div>";
        $valid = false;
    }
    if (!$startDate || !$endDate) {
        $msg .= "<div class='alert alert-danger'>Start Date and End Date are required.</div>";
        $valid = false;
    } elseif (strtotime($endDate) < strtotime($startDate)) {
        $msg .= "<div class='alert alert-danger'>End Date cannot be earlier than Start Date.</div>";
        $valid = false;
    }

    // Prevent overlapping active periods
    if ($valid) {
        $stmt = mysqli_prepare($db, "SELECT period_name FROM registration_periods
            WHERE status = 1 AND NOT (end_date < ? OR start_date > ?) LIMIT 1");
        mysqli_stmt_bind_param($stmt, "ss", $startDate, $endDate);
        mysqli_stmt_execute($stmt);
        $overlap = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($overlap) {
            $msg .= "<div class='alert alert-danger'>Date range overlaps with the existing active period <strong>" . htmlspecialchars($overlap['period_name']) . "</strong>. Active periods cannot overlap.</div>";
            $valid = false;
        }
    }

    if ($valid) {
        $stmt = mysqli_prepare($db, "INSERT INTO registration_periods (period_name, start_date, end_date, display_order, status) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "sssii", $periodName, $startDate, $endDate, $displayOrder, $status);
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $msg = "
            <div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
            <strong><i class='feather icon-check'></i> Thanks!</strong> Registration Period added successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
              <span aria-hidden='true'>&times;</span>
            </button>
            </div>";
        } else {
            mysqli_stmt_close($stmt);
            $msg = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
            <strong><i class='feather icon-x'></i> Error!</strong> Failed to add Registration Period.
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
    <title>Add Registration Period</title>
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
                                <h5 class="m-b-10">Add Registration Period</h5>
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
                                            <label class="form-label">Period Name <span class="red-text">*</span></label>
                                            <input type="text" name="period_name" id="period_name" class="form-control" placeholder="e.g. Early Bird" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Start Date <span class="red-text">*</span></label>
                                            <input type="date" name="start_date" id="start_date" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">End Date <span class="red-text">*</span></label>
                                            <input type="date" name="end_date" id="end_date" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Display Order</label>
                                            <input type="number" name="display_order" class="form-control" value="0" min="0">
                                            <small class="text-muted">Lower numbers appear first (Early Bird, Regular, Late, On Spot...).</small>
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Status <span class="red-text">*</span></label>
                                            <select name="status" class="form-control" required>
                                                <option value="1" selected>Active</option>
                                                <option value="0">Inactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                        <button type="submit" class="btn btn-secondary" name="submit">
                                            <i class="feather icon-save"></i>&nbsp; Add Registration Period
                                        </button>
                                        <a href="manage-registration-periods.php" class="btn btn-outline-secondary">View All Periods</a>
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
        // Frontend date validation
        document.getElementById('start_date').addEventListener('change', function() {
            document.getElementById('end_date').min = this.value;
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
