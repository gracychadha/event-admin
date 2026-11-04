<?php
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include("db/config.php");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Validate and normalise a price payload; returns [ok, dataOrError]. */
function validatePriceInput($db, $post)
{
    $categoryId = (int) ($post['category_id'] ?? 0);
    $periodId   = (int) ($post['period_id'] ?? 0);
    $amount     = round((float) ($post['amount'] ?? 0), 2);
    $currency   = strtoupper(trim($post['currency'] ?? 'INR'));
    $availability = (int) ($post['availability'] ?? 1);
    $gstType    = $post['gst_type'] ?? 'not_applicable';
    $gstPct     = round((float) ($post['gst_percentage'] ?? 0), 2);
    $status     = (int) ($post['status'] ?? 1);

    if ($categoryId <= 0 || $periodId <= 0) {
        return [false, 'Invalid category or period.'];
    }
    if ($amount < 0) {
        return [false, 'Amount cannot be negative.'];
    }
    if (!in_array($currency, ['INR', 'USD', 'EUR', 'GBP'], true)) {
        return [false, 'Invalid currency.'];
    }
    if (!in_array($gstType, ['included', 'excluded', 'not_applicable'], true)) {
        return [false, 'Invalid GST type.'];
    }
    if ($gstType !== 'not_applicable' && ($gstPct < 0 || $gstPct > 100)) {
        return [false, 'GST percentage must be between 0 and 100.'];
    }
    if ($gstType === 'not_applicable') {
        $gstPct = 0;
    }

    // Category and period must exist
    $stmt = mysqli_prepare($db, "SELECT id FROM registration_categories WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $categoryId);
    mysqli_stmt_execute($stmt);
    $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    if (!$exists) {
        return [false, 'Category not found.'];
    }

    $stmt = mysqli_prepare($db, "SELECT id, status FROM registration_periods WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $periodId);
    mysqli_stmt_execute($stmt);
    $periodRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$periodRow) {
        return [false, 'Period not found.'];
    }

    return [true, [
        'category_id'  => $categoryId,
        'period_id'    => $periodId,
        'amount'       => $amount,
        'currency'     => $currency,
        'availability' => $availability ? 1 : 0,
        'gst_type'     => $gstType,
        'gst_percentage' => $gstPct,
        'status'       => $status ? 1 : 0,
        'period_active' => (int) $periodRow['status'],
    ]];
}

if ($action === 'get') {
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $periodId   = (int) ($_POST['period_id'] ?? 0);

    $stmt = mysqli_prepare($db, "SELECT amount, currency, availability, gst_type, gst_percentage, status
        FROM registration_category_prices WHERE category_id = ? AND period_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $periodId);
    mysqli_stmt_execute($stmt);
    $price = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => true, 'price' => $price ?: null]);
    exit;
}

if ($action === 'save') {
    list($ok, $data) = validatePriceInput($db, $_POST);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => $data]);
        exit;
    }

    $amountNa = ($data['availability'] === 0) ? 0 : $data['amount'];
    if ($data['availability'] === 1 && $amountNa <= 0) {
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero for an available category.']);
        exit;
    }

    // Prevent saving an ACTIVE price under an INACTIVE period
    if ($data['status'] === 1 && $data['period_active'] !== 1) {
        echo json_encode(['success' => false, 'message' => 'Cannot save an active price under an inactive period. Activate the period first.']);
        exit;
    }

    $stmt = mysqli_prepare($db, "INSERT INTO registration_category_prices
        (category_id, period_id, amount, currency, availability, gst_type, gst_percentage, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          amount = VALUES(amount), currency = VALUES(currency), availability = VALUES(availability),
          gst_type = VALUES(gst_type), gst_percentage = VALUES(gst_percentage), status = VALUES(status)");
    mysqli_stmt_bind_param(
        $stmt,
        "iidsisdi",
        $data['category_id'],
        $data['period_id'],
        $amountNa,
        $data['currency'],
        $data['availability'],
        $data['gst_type'],
        $data['gst_percentage'],
        $data['status']
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode($ok
        ? ['success' => true]
        : ['success' => false, 'message' => 'Database error while saving price.']);
    exit;
}

if ($action === 'delete') {
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $periodId   = (int) ($_POST['period_id'] ?? 0);

    if ($categoryId <= 0 || $periodId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid category or period.']);
        exit;
    }

    $stmt = mysqli_prepare($db, "DELETE FROM registration_category_prices WHERE category_id = ? AND period_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $periodId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode($ok ? ['success' => true] : ['success' => false, 'message' => 'Failed to remove price.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;
?>
