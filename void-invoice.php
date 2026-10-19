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
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to void documents.', 'dashboard.php');
}

$redirect = 'manage-invoices.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . $redirect);
    exit;
}

if (!abstract_csrf_check($_POST['csrf'] ?? '')) {
    $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    header("Location: " . $redirect);
    exit;
}

$id     = (int) base64_decode($_POST['id'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if ($id <= 0) {
    $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Invalid document.'];
    header("Location: " . $redirect);
    exit;
}
if ($reason === '') {
    $_SESSION['invoice_flash'] = ['type' => 'warning', 'text' => 'A reason is required to void a document.'];
    header("Location: " . $redirect);
    exit;
}

$invoice = invoice_fetch($db, $id);
if (!$invoice) {
    $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Document not found.'];
    header("Location: " . $redirect);
    exit;
}
if ($invoice['status'] === 'void') {
    $_SESSION['invoice_flash'] = ['type' => 'warning', 'text' => 'This document is already void.'];
    header("Location: " . $redirect);
    exit;
}

$stmt = $db->prepare(
    "UPDATE invoices SET status = 'void', void_reason = ?, voided_by = ?, voided_at = NOW()
     WHERE id = ? AND status = 'issued'"
);
$stmt->bind_param('sii', $reason, $adminId, $id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected < 1) {
    $_SESSION['invoice_flash'] = ['type' => 'warning', 'text' => 'Document could not be voided (it may have changed).'];
    header("Location: " . $redirect);
    exit;
}

invoice_audit_log($db, $id, (int) $adminId, invoice_admin_name($db, $adminId), 'voided', $reason);

$_SESSION['invoice_flash'] = ['type' => 'success', 'text' => 'Document ' . $invoice['document_number'] . ' has been voided.'];
header("Location: " . $redirect);
exit;
