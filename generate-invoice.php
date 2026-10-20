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

if (!abstract_can('Create Invoice')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to create invoices.', 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: create-invoice.php");
    exit;
}

if (!abstract_csrf_check($_POST['csrf'] ?? '')) {
    $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    header("Location: create-invoice.php");
    exit;
}

$payload = invoice_build_from_post($db, $_POST);
$errors  = invoice_validate_payload($payload);

if ($errors) {
    $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => implode(' ', $errors)];
    header("Location: create-invoice.php");
    exit;
}

$profile = $payload['profile'];
$totals  = $payload['totals'];
$year    = (int) date('Y', strtotime($payload['document_date'] ?: date('Y-m-d')));

$companySnapshot = json_encode([
    'id'             => $profile['id'] ?? null,
    'profile_name'   => $profile['profile_name'] ?? '',
    'company_name'   => $profile['company_name'] ?? '',
    'address'        => $profile['address'] ?? '',
    'city'           => $profile['city'] ?? '',
    'state'          => $profile['state'] ?? '',
    'country'        => $profile['country'] ?? '',
    'postal_code'    => $profile['postal_code'] ?? '',
    'email'          => $profile['email'] ?? '',
    'contact_number' => $profile['contact_number'] ?? '',
    'gstin'          => $profile['gstin'] ?? '',
    'logo_path'      => $profile['logo_path'] ?? '',
    'letterhead_path' => $profile['letterhead_path'] ?? '',
    'signature_path' => $profile['signature_path'] ?? '',
    'signatory_name' => $profile['signatory_name'] ?? '',
    'footer_text'    => $profile['footer_text'] ?? '',
    'tax_label'      => $profile['tax_label'] ?? 'GST',
    'tax_rate'       => $profile['tax_rate'] ?? '0.00',
    'bg_margin_top'    => $profile['bg_margin_top'] ?? '45.00',
    'bg_margin_bottom' => $profile['bg_margin_bottom'] ?? '20.00',
    'bg_margin_side'   => $profile['bg_margin_side'] ?? '12.00',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$amountInWords = invoice_amount_in_words($totals['grand_total']);

$db->begin_transaction();
try {
    // 1. Allocate number atomically (before any other insert).
    $documentNumber = invoice_next_number($db, $payload['company_profile_id'], $payload['document_type'], $year);
    if (!$documentNumber) {
        throw new RuntimeException('Could not allocate a document number.');
    }

    // 2. Insert the invoice header with frozen values.
    $stmt = $db->prepare(
        "INSERT INTO invoices
            (document_number, document_type, company_profile_id, document_date, company_snapshot,
             customer_name, customer_email, customer_contact, billing_address, customer_category,
             event_name, registration_id, payment_reference, payment_mode, utr_reference,
             subtotal, discount_label, discount_percent, discount_amount, taxable_amount,
             tax_label, tax_rate, tax_included, tax_amount, grand_total, amount_in_words,
             amount_received, payment_date, payment_status, status, notes, created_by)
         VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?, ?,?,?, 'issued', ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Database error: ' . $db->error);
    }

    $vNumber    = $documentNumber;
    $vType      = $payload['document_type'];
    $vProfileId = (int) $payload['company_profile_id'];
    $vDocDate   = $payload['document_date'];
    $vSnapshot  = $companySnapshot;
    $vCustName  = $payload['customer_name'];
    $vCustEmail = $payload['customer_email'];
    $vCustPhone = $payload['customer_contact'];
    $vBilling   = $payload['billing_address'];
    $vCategory  = $payload['customer_category'];
    $vEvent     = $payload['event_name'];
    $vRegId     = $payload['registration_id'];
    $vPayRef    = $payload['payment_reference'];
    $vPayMode   = $payload['payment_mode'];
    $vUtr       = $payload['utr_reference'];
    $vSubtotal  = $totals['subtotal'];
    $vDiscLabel = $payload['discount_label'];
    $vDiscPct   = $totals['discount_percent'];
    $vDiscAmt   = $totals['discount_amount'];
    $vTaxable   = $totals['taxable_amount'];
    $vTaxLabel  = $profile['tax_label'] ?? 'GST';
    $vTaxRate   = $totals['tax_rate'];
    $vTaxIncl   = (int) $payload['tax_included'];
    $vTaxAmt    = $totals['tax_amount'];
    $vGrand     = $totals['grand_total'];
    $vWords     = $amountInWords;
    $vReceived  = $payload['amount_received'];
    $paymentDate = $payload['payment_date'] !== '' ? $payload['payment_date'] : null;
    $vPayStatus = $payload['payment_status'];
    $vNotes     = $payload['notes'];
    $vCreatedBy = (int) $adminId;

    // Types (31): ssissssssssssss d s d d d s d i d d s d s s s i
    $stmt->bind_param(
        'ssissssssssssssdsdddsdiddsdsssi',
        $vNumber,
        $vType,
        $vProfileId,
        $vDocDate,
        $vSnapshot,
        $vCustName,
        $vCustEmail,
        $vCustPhone,
        $vBilling,
        $vCategory,
        $vEvent,
        $vRegId,
        $vPayRef,
        $vPayMode,
        $vUtr,
        $vSubtotal,
        $vDiscLabel,
        $vDiscPct,
        $vDiscAmt,
        $vTaxable,
        $vTaxLabel,
        $vTaxRate,
        $vTaxIncl,
        $vTaxAmt,
        $vGrand,
        $vWords,
        $vReceived,
        $paymentDate,
        $vPayStatus,
        $vNotes,
        $vCreatedBy
    );
    $stmt->execute();
    $invoiceId = $stmt->insert_id;
    $stmt->close();

    if (!$invoiceId) {
        throw new RuntimeException('Could not save the document.');
    }

    // 3. Insert line items.
    $istmt = $db->prepare(
        "INSERT INTO invoice_items (invoice_id, description, hsn_sac, quantity, rate, discount, taxable_amount, line_total, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    if (!$istmt) {
        throw new RuntimeException('Database error: ' . $db->error);
    }
    foreach ($totals['items'] as $item) {
        $istmt->bind_param(
            'issdddddi',
            $invoiceId,
            $item['description'],
            $item['hsn_sac'],
            $item['quantity'],
            $item['rate'],
            $item['discount'],
            $item['taxable_amount'],
            $item['line_total'],
            $item['sort_order']
        );
        $istmt->execute();
    }
    $istmt->close();

    // 4. Record payment row when payment info exists.
    if ($payload['amount_received'] > 0 || $payload['payment_status'] !== 'pending') {
        $pstmt = $db->prepare(
            "INSERT INTO invoice_payments (invoice_id, payment_date, amount, payment_mode, transaction_reference, status, created_by)
             VALUES (?,?,?,?,?,?,?)"
        );
        if ($pstmt) {
            $pstmt->bind_param(
                'isdsssi',
                $invoiceId,
                $paymentDate,
                $payload['amount_received'],
                $payload['payment_mode'],
                $payload['utr_reference'],
                $payload['payment_status'],
                $adminId
            );
            $pstmt->execute();
            $pstmt->close();
        }
    }

    // 5. Audit log.
    invoice_audit_log($db, $invoiceId, (int) $adminId, invoice_admin_name($db, $adminId), 'created', $documentNumber);

    $db->commit();
} catch (Throwable $ex) {
    $db->rollback();
    $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Could not generate the document: ' . $ex->getMessage()];
    header("Location: create-invoice.php");
    exit;
}

header("Location: invoice-pdf.php?id=" . base64_encode((string) $invoiceId) . "&download=1");
exit;
