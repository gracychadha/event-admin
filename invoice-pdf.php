<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/vendor/autoload.php';
include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");
include("invoice_helpers.php");
include("invoice_layout.php");

use Mpdf\Mpdf;

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

if (!(abstract_can('Invoice History') || abstract_can('Create Invoice'))) {
    http_response_code(403);
    die('Access denied.');
}

$id = (int) base64_decode($_GET['id'] ?? '');
if ($id <= 0) {
    http_response_code(404);
    die('Document not found.');
}

$invoice = invoice_fetch($db, $id);
if (!$invoice) {
    http_response_code(404);
    die('Document not found.');
}

$isDownload = isset($_GET['download']) && $_GET['download'] == '1';

$company = $invoice['company'];
$doc = [
    'document_number'  => $invoice['document_number'],
    'document_type'    => $invoice['document_type'],
    'document_date'    => $invoice['document_date'],
    'status'           => $invoice['status'],
    'company'          => $company,
    'customer_name'    => $invoice['customer_name'],
    'customer_email'   => $invoice['customer_email'],
    'customer_contact' => $invoice['customer_contact'],
    'billing_address'  => $invoice['billing_address'],
    'customer_category' => $invoice['customer_category'],
    'event_name'       => $invoice['event_name'],
    'registration_id'  => $invoice['registration_id'],
    'payment_reference' => $invoice['payment_reference'],
    'payment_mode'     => $invoice['payment_mode'],
    'utr_reference'    => $invoice['utr_reference'],
    'subtotal'         => $invoice['subtotal'],
    'discount_label'   => $invoice['discount_label'],
    'discount_percent' => $invoice['discount_percent'],
    'discount_amount'  => $invoice['discount_amount'],
    'taxable_amount'   => $invoice['taxable_amount'],
    'tax_label'        => $invoice['tax_label'],
    'tax_rate'         => $invoice['tax_rate'],
    'tax_included'     => $invoice['tax_included'],
    'tax_amount'       => $invoice['tax_amount'],
    'grand_total'      => $invoice['grand_total'],
    'amount_in_words'  => $invoice['amount_in_words'],
    'amount_received'  => $invoice['amount_received'],
    'payment_date'     => $invoice['payment_date'],
    'payment_status'   => $invoice['payment_status'],
    'notes'            => $invoice['notes'],
    'items'            => $invoice['items'],
];

$html = invoice_render_layout($doc, true);

$hasLetterheadBackground = trim((string) ($company['letterhead_path'] ?? '')) !== '';

$mpdf = new Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4',
    'margin_left'   => $hasLetterheadBackground ? 0 : 10,
    'margin_right'  => $hasLetterheadBackground ? 0 : 10,
    'margin_top'    => $hasLetterheadBackground ? 0 : 12,
    'margin_bottom' => $hasLetterheadBackground ? 0 : 15,
    'tempDir'       => sys_get_temp_dir(),
]);
$mpdf->SetTitle($invoice['document_number']);
$mpdf->WriteHTML($html);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice['document_number']) . '.pdf';

if ($isDownload) {
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} else {
    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
}
exit;
