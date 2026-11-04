<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}
$name = $_SESSION['login_user'];

include("db/config.php");
include("registration_pricing_helpers.php");

// Optional filters consistent with the dashboard style
$filterStatus = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$allowedStatuses = ['pending', 'paid', 'failed', 'cancelled'];

$stmt = mysqli_prepare($db, "SELECT r.*, u.first_name, u.last_name, u.email, u.contact_number
    FROM registrations r
    LEFT JOIN registered_users u ON u.student_id = r.user_id
    ORDER BY r.id DESC");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'];
$helpdeskNumber = $settings['helpdesk_no'];
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<meta http-equiv="content-type" content="text/html;charset=UTF-8" />

<head>
    <title>Delegate Registrations</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
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
                                <h5 class="m-b-10">Delegate Registrations</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                        </div>
                        <div class="card-body">
                            <div class="dt-responsive table-responsive">
                                <?php
                                if (isset($_SESSION['msg'])) {
                                    echo $_SESSION['msg'];
                                    unset($_SESSION['msg']);
                                }
                                ?>
                                <br />

                                <?php
                                echo '<table id="basic-btn" class="table table-striped table-bordered nowrap">';
                                echo "<thead>";
                                echo "<tr>";
                                echo "<th>SNO</th>";
                                echo "<th>REF NO</th>";
                                echo "<th>DELEGATE</th>";
                                echo "<th>CATEGORY</th>";
                                echo "<th>PERIOD</th>";
                                echo "<th>FEE</th>";
                                echo "<th>DISCOUNT</th>";
                                echo "<th>GST</th>";
                                echo "<th>PAYABLE</th>";
                                echo "<th>PAYMENT STATUS</th>";
                                echo "<th>REGISTERED ON</th>";
                                echo "<th>ACTION</th>";
                                echo "</tr>";
                                echo "</thead>";
                                echo "<tbody>";

                                $count = 1;
                                while ($row = mysqli_fetch_assoc($result)) {
                                    $encoded_id = base64_encode($row['id']);
                                    $delegateName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                    if ($delegateName === '') {
                                        $delegateName = '(User #' . $row['user_id'] . ')';
                                    }

                                    echo "<tr class='record'>";
                                    echo "<td>" . $count++ . "</td>";
                                    echo "<td>" . htmlspecialchars($row['registration_ref']) . "</td>";
                                    echo "<td>" . htmlspecialchars($delegateName) . "<br><small class='text-muted'>" . htmlspecialchars($row['email'] ?? '') . "</small></td>";
                                    echo "<td>" . htmlspecialchars($row['category_name']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['period_name']) . "</td>";
                                    echo "<td>" . rp_money($row['original_fee'], $row['currency']) . "</td>";
                                    echo "<td>" . ((float) $row['discount_amount'] > 0
                                        ? "-" . rp_money($row['discount_amount'], $row['currency']) . "<br><small class='text-muted'>" . htmlspecialchars($row['discount_name'] ?? '') . "</small>"
                                        : "-") . "</td>";
                                    echo "<td>" . ((float) $row['gst_amount'] > 0
                                        ? rp_money($row['gst_amount'], $row['currency']) . "<br><small class='text-muted'>" . ucfirst($row['gst_type']) . "</small>"
                                        : "-") . "</td>";
                                    echo "<td><strong>" . rp_money($row['payable_amount'], $row['currency']) . "</strong></td>";

                                    echo "<td>";
                                    if ($row['payment_status'] === 'paid') {
                                        echo "<span class='badge badge-success p-1'>Paid</span>";
                                    } elseif ($row['payment_status'] === 'pending') {
                                        echo "<span class='badge badge-warning p-1'>Pending</span>";
                                    } elseif ($row['payment_status'] === 'failed') {
                                        echo "<span class='badge badge-danger p-1'>Failed</span>";
                                    } else {
                                        echo "<span class='badge badge-secondary p-1'>" . ucfirst($row['payment_status']) . "</span>";
                                    }
                                    echo "</td>";

                                    echo "<td>" . date('d M Y h:i A', strtotime($row['created_at'])) . "</td>";
                                    echo "<td>
                                        <a href='view-registration.php?id=$encoded_id' class='btn btn-info btn-sm'>
                                            <i class='feather icon-eye'></i> View
                                        </a>
                                    </td>";
                                    echo "</tr>";
                                }

                                if ($count === 1) {
                                    echo "<tr><td colspan='12' class='text-center text-muted'>No registrations found yet.</td></tr>";
                                }

                                echo "</tbody>";
                                echo "</table>";
                                ?>

                            </div>
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
            $("#gold").delay(5000).slideUp(300);
        });
    </script>

</body>

</html>
