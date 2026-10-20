<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");
include("invoice_helpers.php");
include("invoice_layout.php");

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

if (!abstract_can('Create Invoice')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to preview invoices.', 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: create-invoice.php");
    exit;
}

if (!abstract_csrf_check($_POST['csrf'] ?? '')) {
    abstract_swal_page('warning', 'Session Expired', 'Your session token expired. Please try again.', 'create-invoice.php');
}

$payload = invoice_build_from_post($db, $_POST);
$errors  = invoice_validate_payload($payload);
$totals  = $payload['totals'];

$doc = [
    'document_number'  => invoice_type_prefix($payload['document_type']) . '-PREVIEW-0000',
    'document_type'    => $payload['document_type'],
    'document_date'    => $payload['document_date'] ?: date('Y-m-d'),
    'status'           => 'issued',
    'company'          => $payload['profile'] ?: [],
    'customer_name'    => $payload['customer_name'],
    'customer_email'   => $payload['customer_email'],
    'customer_contact' => $payload['customer_contact'],
    'billing_address'  => $payload['billing_address'],
    'customer_category' => $payload['customer_category'],
    'event_name'       => $payload['event_name'],
    'registration_id'  => $payload['registration_id'],
    'payment_reference' => $payload['payment_reference'],
    'payment_mode'     => $payload['payment_mode'],
    'utr_reference'    => $payload['utr_reference'],
    'subtotal'         => $totals['subtotal'],
    'discount_label'   => $payload['discount_label'],
    'discount_percent' => $totals['discount_percent'],
    'discount_amount'  => $totals['discount_amount'],
    'taxable_amount'   => $totals['taxable_amount'],
    'tax_label'        => $payload['profile']['tax_label'] ?? 'GST',
    'tax_rate'         => $totals['tax_rate'],
    'tax_included'     => $payload['tax_included'],
    'tax_amount'       => $totals['tax_amount'],
    'grand_total'      => $totals['grand_total'],
    'amount_in_words'  => invoice_amount_in_words($totals['grand_total']),
    'amount_received'  => $payload['amount_received'],
    'payment_date'     => $payload['payment_date'],
    'payment_status'   => $payload['payment_status'],
    'notes'            => $payload['notes'],
    'items'            => $totals['items'],
];

$settings = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM login_settings"));
$favicon = $settings['favicon'] ?? '';

$hasLetterheadBackground = trim((string) ($payload['profile']['letterhead_path'] ?? '')) !== '';
$sheetStyle = $hasLetterheadBackground
    ? 'background: transparent; width: 820px; min-height: 1160px;'
    : 'background:#fff;';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview — <?php echo htmlspecialchars(invoice_type_label($payload['document_type'])); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #eceff5; padding: 20px; }
        .preview-toolbar { max-width: 820px; margin: 0 auto 14px; display: flex; justify-content: space-between; align-items: center; }
        .preview-sheet { max-width: 820px; margin: 0 auto; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,.12); <?php echo $sheetStyle; ?> }
        .preview-errors { max-width: 820px; margin: 0 auto 14px; }
        @media print {
            body { background: #fff; padding: 0; }
            .preview-toolbar, .preview-errors { display: none; }
            .preview-sheet { max-width: none; margin: 0; padding: 0; box-shadow: none; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
    <div class="preview-toolbar">
        <strong>Document Preview</strong>
        <div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="window.print();"><i class="feather icon-printer"></i> Print</button>
            <button type="button" class="btn btn-light btn-sm" onclick="window.close();">Close</button>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-warning preview-errors">
            <strong>Please fix the following before generating:</strong>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="preview-sheet">
        <?php echo invoice_render_layout($doc, false, false); ?>
    </div>
</body>
</html>
