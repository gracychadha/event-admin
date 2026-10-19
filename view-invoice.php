<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");
include("invoice_helpers.php");

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

if (!abstract_can('Invoice History')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to view this document.', 'dashboard.php');
}

$id = (int) base64_decode($_GET['id'] ?? '');
$invoice = $id > 0 ? invoice_fetch($db, $id) : null;
if (!$invoice) {
    abstract_swal_page('error', 'Not Found', 'The requested document could not be found.', 'manage-invoices.php');
}

$company = $invoice['company'];
$pstatus = invoice_payment_status_label($invoice['payment_status']);
$istatus = invoice_status_label($invoice['status']);

// Payments
$payments = [];
$pstmt = $db->prepare("SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY id ASC");
$pstmt->bind_param('i', $id);
$pstmt->execute();
$pres = $pstmt->get_result();
while ($row = $pres->fetch_assoc()) {
    $payments[] = $row;
}
$pstmt->close();

// Audit trail
$audit = [];
$astmt = $db->prepare("SELECT * FROM invoice_audit_logs WHERE invoice_id = ? ORDER BY id ASC");
$astmt->bind_param('i', $id);
$astmt->execute();
$ares = $astmt->get_result();
while ($row = $ares->fetch_assoc()) {
    $audit[] = $row;
}
$astmt->close();

$csrf = abstract_csrf_token();
$idEnc = base64_encode($invoice['id']);
$settings = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM login_settings"));
$favicon = $settings['favicon'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>View <?php echo htmlspecialchars(invoice_type_label($invoice['document_type'])); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track"><div class="loader-fill"></div></div>
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
                                <h5 class="m-b-10">
                                    <?php echo htmlspecialchars(invoice_type_label($invoice['document_type'])); ?>
                                    — <?php echo htmlspecialchars($invoice['document_number']); ?>
                                    <span class="<?php echo $istatus['class']; ?>"><?php echo htmlspecialchars($istatus['label']); ?></span>
                                </h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h6><span data-feather="info"></span> Document Details</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-bordered">
                                <tr><th style="width:35%;">Document Number</th><td><?php echo htmlspecialchars($invoice['document_number']); ?></td></tr>
                                <tr><th>Type</th><td><?php echo htmlspecialchars(invoice_type_label($invoice['document_type'])); ?></td></tr>
                                <tr><th>Date</th><td><?php echo htmlspecialchars(date('d-m-Y', strtotime($invoice['document_date']))); ?></td></tr>
                                <tr><th>Company Profile</th><td><?php echo htmlspecialchars(($company['profile_name'] ?? '-') . ' — ' . ($company['company_name'] ?? '')); ?></td></tr>
                                <tr><th>GSTIN</th><td><?php echo htmlspecialchars($company['gstin'] ?? '-'); ?></td></tr>
                                <tr><th>Customer / Delegate</th><td><?php echo htmlspecialchars($invoice['customer_name']); ?></td></tr>
                                <tr><th>Email</th><td><?php echo htmlspecialchars($invoice['customer_email'] ?: '-'); ?></td></tr>
                                <tr><th>Contact</th><td><?php echo htmlspecialchars($invoice['customer_contact'] ?: '-'); ?></td></tr>
                                <tr><th>Billing Address</th><td><?php echo htmlspecialchars($invoice['billing_address'] ?: '-'); ?></td></tr>
                                <tr><th>Category</th><td><?php echo htmlspecialchars($invoice['customer_category'] ?: '-'); ?></td></tr>
                                <tr><th>Event / Conference</th><td><?php echo htmlspecialchars($invoice['event_name'] ?: '-'); ?></td></tr>
                                <tr><th>Registration / Delegate ID</th><td><?php echo htmlspecialchars($invoice['registration_id'] ?: '-'); ?></td></tr>
                                <tr><th>Payment Reference / Order ID</th><td><?php echo htmlspecialchars($invoice['payment_reference'] ?: '-'); ?></td></tr>
                                <tr><th>Payment Mode</th><td><?php echo htmlspecialchars($invoice['payment_mode'] ?: '-'); ?></td></tr>
                                <tr><th>UTR / Transaction Ref</th><td><?php echo htmlspecialchars($invoice['utr_reference'] ?: '-'); ?></td></tr>
                                <tr><th>Payment Status</th><td><span class="<?php echo $pstatus['class']; ?>"><?php echo htmlspecialchars($pstatus['label']); ?></span></td></tr>
                                <tr><th>Amount Received</th><td><?php echo htmlspecialchars(number_format((float) $invoice['amount_received'], 2)); ?></td></tr>
                                <?php if (!empty($invoice['void_reason'])): ?>
                                    <tr><th>Void Reason</th><td class="text-danger"><?php echo htmlspecialchars($invoice['void_reason']); ?></td></tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h6><span data-feather="list"></span> Line Items</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr><th>#</th><th>Description</th><th>HSN/SAC</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($invoice['items'] as $item): ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo htmlspecialchars($item['description']); ?></td>
                                                <td><?php echo htmlspecialchars($item['hsn_sac']); ?></td>
                                                <td><?php echo htmlspecialchars(number_format((float) $item['quantity'], 2)); ?></td>
                                                <td><?php echo htmlspecialchars(number_format((float) $item['rate'], 2)); ?></td>
                                                <td><?php echo htmlspecialchars(number_format((float) $item['line_total'], 2)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr><th colspan="5" class="text-right">Subtotal</th><td><?php echo htmlspecialchars(number_format((float) $invoice['subtotal'], 2)); ?></td></tr>
                                        <?php if ((float) $invoice['discount_amount'] > 0): ?>
                                            <tr><th colspan="5" class="text-right"><?php echo htmlspecialchars($invoice['discount_label'] ?: 'Discount'); ?> (<?php echo htmlspecialchars(number_format((float) $invoice['discount_percent'], 2)); ?>%)</th><td>- <?php echo htmlspecialchars(number_format((float) $invoice['discount_amount'], 2)); ?></td></tr>
                                        <?php endif; ?>
                                        <tr><th colspan="5" class="text-right">Taxable Amount</th><td><?php echo htmlspecialchars(number_format((float) $invoice['taxable_amount'], 2)); ?></td></tr>
                                        <?php if ($invoice['tax_included']): ?>
                                            <tr><th colspan="5" class="text-right"><?php echo htmlspecialchars($invoice['tax_label']); ?> @ <?php echo htmlspecialchars(number_format((float) $invoice['tax_rate'], 2)); ?>%</th><td><?php echo htmlspecialchars(number_format((float) $invoice['tax_amount'], 2)); ?></td></tr>
                                        <?php endif; ?>
                                        <tr><th colspan="5" class="text-right">Grand Total</th><td><strong><?php echo htmlspecialchars(number_format((float) $invoice['grand_total'], 2)); ?></strong></td></tr>
                                    </tfoot>
                                </table>
                            </div>
                            <p class="mb-0"><strong>Amount in words:</strong> <?php echo htmlspecialchars($invoice['amount_in_words']); ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <a href="invoice-pdf.php?id=<?php echo $idEnc; ?>" target="_blank" class="btn btn-info btn-block"><i class="feather icon-eye"></i> View PDF</a>
                            <a href="invoice-pdf.php?id=<?php echo $idEnc; ?>&download=1" class="btn btn-success btn-block"><i class="feather icon-download"></i> Download PDF</a>
                            <?php if ($invoice['status'] !== 'void'): ?>
                                <button type="button" class="btn btn-danger btn-block" onclick="voidInvoice('<?php echo $idEnc; ?>', '<?php echo htmlspecialchars($invoice['document_number']); ?>');"><i class="feather icon-x-circle"></i> Void Document</button>
                            <?php endif; ?>
                            <a href="manage-invoices.php" class="btn btn-light btn-block">Back to History</a>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6><span data-feather="credit-card"></span> Payments</h6></div>
                        <div class="card-body">
                            <?php if (!$payments): ?>
                                <p class="text-muted mb-0">No payment records.</p>
                            <?php else: ?>
                                <table class="table table-sm table-bordered">
                                    <thead><tr><th>Date</th><th>Amount</th><th>Mode</th><th>Reference</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($payments as $pay): ?>
                                            <tr>
                                                <td><?php echo $pay['payment_date'] ? htmlspecialchars(date('d-m-Y', strtotime($pay['payment_date']))) : '-'; ?></td>
                                                <td><?php echo htmlspecialchars(number_format((float) $pay['amount'], 2)); ?></td>
                                                <td><?php echo htmlspecialchars($pay['payment_mode'] ?: '-'); ?></td>
                                                <td><?php echo htmlspecialchars($pay['transaction_reference'] ?: '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6><span data-feather="clock"></span> Audit Trail</h6></div>
                        <div class="card-body">
                            <?php if (!$audit): ?>
                                <p class="text-muted mb-0">No audit records.</p>
                            <?php else: ?>
                                <ul class="list-group">
                                    <?php foreach ($audit as $log): ?>
                                        <li class="list-group-item">
                                            <strong><?php echo htmlspecialchars(ucfirst($log['action'])); ?></strong>
                                            by <?php echo htmlspecialchars($log['actor_name'] ?: 'Admin'); ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($log['created_at']))); ?></small>
                                            <?php if (!empty($log['remarks'])): ?><br><small><?php echo htmlspecialchars($log['remarks']); ?></small><?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <form method="post" action="void-invoice.php" id="voidForm">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="reason" value="">
    </form>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/plugins/pcoded.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <?php echo abstract_swal_js(); ?>
    <script>feather.replace();</script>
    <script>
        function voidInvoice(idEnc, number) {
            Swal.fire({
                icon: 'warning',
                title: 'Void ' + number + '?',
                text: 'Issued documents are never deleted. Voiding keeps an audit trail.',
                input: 'textarea',
                inputPlaceholder: 'Reason for voiding...',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Void',
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return 'A reason is required.';
                    }
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    var f = document.getElementById('voidForm');
                    f.elements['id'].value = idEnc;
                    f.elements['reason'].value = result.value.trim();
                    f.submit();
                }
            });
        }
    </script>
    <?php echo abstract_swal_render(); ?>
</body>

</html>
