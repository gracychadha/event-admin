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
    $periodId = base64_decode($encodedId);

    if (!is_numeric($periodId)) {
        echo "Invalid Period ID!";
        exit;
    }

    $stmt = mysqli_prepare($db, "SELECT * FROM registration_periods WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $periodId);
    mysqli_stmt_execute($stmt);
    $periodResult = mysqli_stmt_get_result($stmt);

    if ($periodResult && mysqli_num_rows($periodResult) > 0) {
        $row = mysqli_fetch_assoc($periodResult);
        $existingName  = $row['period_name'];
        $existingStart = $row['start_date'];
        $existingEnd   = $row['end_date'];
        $existingOrder = $row['display_order'];
        $existingStatus = $row['status'];
    } else {
        echo "Registration Period not found!";
        exit;
    }
    mysqli_stmt_close($stmt);
} else {
    header("Location: manage-registration-periods.php");
    exit;
}

// Update period
if (isset($_POST['submit'])) {
    $periodName   = trim($_POST['period_name']);
    $startDate    = $_POST['start_date'];
    $endDate      = $_POST['end_date'];
    $displayOrder = (int) $_POST['display_order'];
    $status       = (int) $_POST['status'];

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

    // Overlap check (excluding this record itself, active periods only)
    if ($valid) {
        $stmt = mysqli_prepare($db, "SELECT period_name FROM registration_periods
            WHERE status = 1 AND id != ? AND NOT (end_date < ? OR start_date > ?) LIMIT 1");
        mysqli_stmt_bind_param($stmt, "iss", $periodId, $startDate, $endDate);
        mysqli_stmt_execute($stmt);
        $overlap = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($overlap) {
            $msg .= "<div class='alert alert-danger'>Date range overlaps with the existing active period <strong>" . htmlspecialchars($overlap['period_name']) . "</strong>. Active periods cannot overlap.</div>";
            $valid = false;
        }
    }

    if ($valid) {
        $stmt = mysqli_prepare($db, "UPDATE registration_periods
            SET period_name = ?, start_date = ?, end_date = ?, display_order = ?, status = ?
            WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "sssiii", $periodName, $startDate, $endDate, $displayOrder, $status, $periodId);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $statusCode = 1;
            $encodedStatus = base64_encode($statusCode);
            echo ("<SCRIPT LANGUAGE='JavaScript'>window.location.href='manage-registration-periods.php?status=$encodedStatus';</SCRIPT>");
            exit;
        } else {
            mysqli_stmt_close($stmt);
            $msg .= "<div class='alert alert-danger'>Error updating period: " . htmlspecialchars(mysqli_error($db)) . "</div>";
        }
    }

    // Keep submitted values on validation failure
    $existingName  = $periodName;
    $existingStart = $startDate;
    $existingEnd   = $endDate;
    $existingOrder = $displayOrder;
    $existingStatus = $status;
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
    <title>Registration Period Update</title>
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
                                <h5 class="m-b-10">Update Registration Period</h5>
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
                                            <input type="text" name="period_name" class="form-control"
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

                                    <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Display Order</label>
                                            <input type="number" name="display_order" class="form-control"
                                                value="<?php echo (int) $existingOrder; ?>" min="0">
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label class="form-label">Status <span class="red-text">*</span></label>
                                            <select name="status" class="form-control" required>
                                                <option value="1" <?php echo ($existingStatus == 1) ? 'selected' : ''; ?>>Active</option>
                                                <option value="0" <?php echo ($existingStatus == 0) ? 'selected' : ''; ?>>Inactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                        <button type="submit" class="btn btn-secondary" name="submit" id="submit">
                                            <i class="feather icon-save"></i>&nbsp; Update Registration Period
                                        </button>
                                        <a href="manage-registration-periods.php" class="btn btn-outline-secondary">Back</a>
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
