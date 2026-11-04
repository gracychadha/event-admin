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

if (!isset($_GET['id'])) {
    header("Location: manage-registrations.php");
    exit;
}

$registrationId = base64_decode($_GET['id']);
if (!is_numeric($registrationId)) {
    echo "Invalid Registration ID!";
    exit;
}

$stmt = mysqli_prepare($db, "SELECT r.*, u.first_name, u.last_name, u.email, u.contact_number, u.gender, u.country
    FROM registrations r
    LEFT JOIN registered_users u ON u.student_id = r.user_id
    WHERE r.id = ?");
mysqli_stmt_bind_param($stmt, "i", $registrationId);
mysqli_stmt_execute($stmt);
$registration = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$registration) {
    echo "Registration not found!";
    exit;
}

// Payment attempts
$stmt = mysqli_prepare($db, "SELECT * FROM registration_payments WHERE registration_id = ? ORDER BY id DESC");
mysqli_stmt_bind_param($stmt, "i", $registrationId);
mysqli_stmt_execute($stmt);
$paymentsResult = mysqli_stmt_get_result($stmt);
$payments = [];
while ($p = mysqli_fetch_assoc($paymentsResult)) {
    $payments[] = $p;
}
mysqli_stmt_close($stmt);

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
    <title>Registration Detail</title>
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

        .detail-label {
            font-weight: 600;
            color: #6b6f82;
        }

        .breakdown-table td {
            padding: 8px 12px;
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
                                <h5 class="m-b-10">Registration Detail &ndash; <?php echo htmlspecialchars($registration['registration_ref']); ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <a href="manage-registrations.php" class="btn btn-outline-secondary btn-sm"><i class="feather icon-arrow-left"></i> Back to Registrations</a>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-xl-6 col-lg-12">
                                    <h6 class="m-b-15"><i class="feather icon-user"></i> Delegate Information</h6>
                                    <table class="table table-sm">
                                        <tr><td class="detail-label">Registration Ref</td><td><?php echo htmlspecialchars($registration['registration_ref']); ?></td></tr>
                                        <tr><td class="detail-label">Name</td><td><?php echo htmlspecialchars(trim(($registration['first_name'] ?? '') . ' ' . ($registration['last_name'] ?? ''))); ?></td></tr>
                                        <tr><td class="detail-label">Email</td><td><?php echo htmlspecialchars($registration['email'] ?? ''); ?></td></tr>
                                        <tr><td class="detail-label">Contact</td><td><?php echo htmlspecialchars($registration['contact_number'] ?? ''); ?></td></tr>
                                        <tr><td class="detail-label">Country</td><td><?php echo htmlspecialchars($registration['country'] ?? ''); ?></td></tr>
                                        <tr><td class="detail-label">Registered On</td><td><?php echo date('d M Y h:i A', strtotime($registration['created_at'])); ?></td></tr>
                                    </table>

                                    <h6 class="m-b-15 m-t-20"><i class="feather icon-tag"></i> Pricing Configuration Applied</h6>
                                    <table class="table table-sm">
                                        <tr><td class="detail-label">Category</td><td><?php echo htmlspecialchars($registration['category_name']); ?></td></tr>
                                        <tr><td class="detail-label">Registration Period</td><td><?php echo htmlspecialchars($registration['period_name']); ?></td></tr>
                                        <tr><td class="detail-label">Concurrency</td><td>
                                            <?php if ($registration['payment_status'] === 'paid'): ?>
                                                <span class='badge badge-success p-1'>Paid</span>
                                            <?php elseif ($registration['payment_status'] === 'pending'): ?>
                                                <span class='badge badge-warning p-1'>Pending</span>
                                            <?php else: ?>
                                                <span class='badge badge-secondary p-1'><?php echo ucfirst($registration['payment_status']); ?></span>
                                            <?php endif; ?>
                                        </td></tr>
                                    </table>
                                </div>

                                <div class="col-xl-6 col-lg-12">
                                    <h6 class="m-b-15"><i class="feather icon-dollar-sign"></i> Price Breakdown (Frozen Snapshot)</h6>
                                    <table class="table table-bordered breakdown-table">
                                        <tr><td>Original Registration Fee</td><td class="text-right"><?php echo rp_money($registration['original_fee'], $registration['currency']); ?></td></tr>
                                        <?php if ((float) $registration['option_amount'] > 0): ?>
                                            <tr><td>Add-on: <?php echo htmlspecialchars($registration['option_name'] ?? ''); ?></td><td class="text-right"><?php echo rp_money($registration['option_amount'], $registration['currency']); ?></td></tr>
                                        <?php endif; ?>
                                        <?php if ((float) $registration['discount_amount'] > 0): ?>
                                            <tr class="text-success"><td>Discount: <?php echo htmlspecialchars($registration['discount_name'] ?? ''); ?></td><td class="text-right">-<?php echo rp_money($registration['discount_amount'], $registration['currency']); ?></td></tr>
                                        <?php endif; ?>
                                        <tr><td>Taxable Amount</td><td class="text-right"><?php echo rp_money($registration['taxable_amount'], $registration['currency']); ?></td></tr>
                                        <tr><td>GST (<?php echo ucfirst($registration['gst_type']); ?><?php echo ($registration['gst_percentage'] > 0 ? ' ' . rtrim(rtrim($registration['gst_percentage'], '0'), '.') . '%' : ''); ?>)</td><td class="text-right"><?php echo rp_money($registration['gst_amount'], $registration['currency']); ?></td></tr>
                                        <tr class="font-weight-bold"><td>Final Payable Amount</td><td class="text-right"><?php echo rp_money($registration['payable_amount'], $registration['currency']); ?></td></tr>
                                    </table>
                                    <p class="text-muted"><small>These amounts are snapshots captured at registration time and never change, even if the pricing configuration is edited later.</small></p>
                                </div>
                            </div>

                            <div class="row m-t-20">
                                <div class="col-sm-12">
                                    <h6 class="m-b-15"><i class="feather icon-credit-card"></i> Payment Attempts</h6>
                                    <div class="dt-responsive table-responsive">
                                        <table class="table table-striped table-bordered" id="basic-btn">
                                            <thead>
                                                <tr>
                                                    <th>ORDER ID</th>
                                                    <th>PAYMENT ID</th>
                                                    <th>AMOUNT</th>
                                                    <th>STATUS</th>
                                                    <th>METHOD</th>
                                                    <th>DATE</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($payments)): ?>
                                                    <tr><td colspan="6" class="text-center text-muted">No payment attempts recorded.</td></tr>
                                                <?php endif; ?>
                                                <?php foreach ($payments as $pay): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($pay['razorpay_order_id'] ?? '-'); ?></td>
                                                        <td><?php echo htmlspecialchars($pay['razorpay_payment_id'] ?? '-'); ?></td>
                                                        <td><?php echo rp_money($pay['amount'], $pay['currency']); ?></td>
                                                        <td>
                                                            <?php if ($pay['status'] === 'captured'): ?>
                                                                <span class='badge badge-success p-1'>Captured</span>
                                                            <?php elseif ($pay['status'] === 'failed'): ?>
                                                                <span class='badge badge-danger p-1'>Failed</span>
                                                            <?php else: ?>
                                                                <span class='badge badge-warning p-1'>Created</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($pay['method'] ?? '-'); ?></td>
                                                        <td><?php echo date('d M Y h:i A', strtotime($pay['created_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
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
</body>

</html>
