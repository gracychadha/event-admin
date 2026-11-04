<?php
/**
 * Page render + snapshot flow smoke tests (CLI).
 */
error_reporting(E_ALL);

// Shared session/config bootstrap
function boot($sessionVars = [])
{
    if (session_status() === PHP_SESSION_NONE) {
        // CLI: provide a session array manually
    }
    require 'C:/laragon/www/main-admin/db/config.php';
    return $db;
}

$pass = 0;
$fail = 0;

function check2($name, $condition, $detail = '')
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name" . ($detail ? " :: $detail" : "") . "\n";
    }
}

// ---------------- Seed minimal data for pages ----------------
$db = boot();
mysqli_query($db, "SET FOREIGN_KEY_CHECKS = 0");
foreach (['registrations', 'registration_payments', 'registration_discount_categories', 'registration_discounts', 'registration_category_prices', 'registration_category_options', 'registration_periods', 'registration_categories', 'registered_users'] as $t) {
    mysqli_query($db, "TRUNCATE TABLE $t");
}
mysqli_query($db, "SET FOREIGN_KEY_CHECKS = 1");

mysqli_query($db, "INSERT INTO registration_categories (id, category_name, status) VALUES (1,'Faculty / Consultants',1),(2,'PG Students',1)");
mysqli_query($db, "INSERT INTO registration_periods (id, period_name, start_date, end_date, display_order, status) VALUES (1,'Early Bird','2026-11-01','2026-12-31',1,1),(2,'Regular','2027-01-01','2027-03-31',2,1)");
mysqli_query($db, "INSERT INTO registration_category_prices (category_id, period_id, amount, currency, availability, gst_type, gst_percentage, status) VALUES
    (1,1,6000,'INR',1,'excluded',18,1),(1,2,7000,'INR',1,'excluded',18,1),
    (2,1,4000,'INR',1,'not_applicable',0,1),(2,2,5000,'INR',1,'not_applicable',0,1)");
mysqli_query($db, "INSERT INTO registration_discounts (id, discount_name, start_date, end_date, discount_type, discount_value, status) VALUES (1,'Diwali Special Offer','2026-11-01','2026-11-30','percentage',10,1)");
mysqli_query($db, "INSERT INTO registration_discount_categories (discount_id, category_id) VALUES (1,1),(1,2)");
mysqli_query($db, "INSERT INTO registered_users (student_id, image, first_name, last_name, email) VALUES (1,'', 'Test','Delegate','testdelegate@example.com')");

// ---------------- Admin page render tests ----------------
function session_seed($vars)
{
    // Write a real session store the pages can pick up on their own session_start()
    session_id('RPTESTSESS');
    $_SESSION = [];
    session_start();
    $_SESSION = $vars;
    session_write_close();
}

function render_admin_page($file, $extraSession = [])
{
    session_seed(array_merge([
        'login_user' => 'admin',
        'login_user_id' => 12, // real admin row => role 'Admin' => $isAdmin true
    ], $extraSession));
    ob_start();
    $ok = true;
    try {
        include $file;
        // Strip the trailing exit()-guarded parts: pages that redirect will just end output
    } catch (Throwable $e) {
        $ok = false;
        echo 'EXCEPTION: ' . $e->getMessage();
    }
    $html = ob_get_clean();
    return [$ok, $html];
}

list($ok, $html) = render_admin_page('C:/laragon/www/main-admin/manage-registration-periods.php');
check2('manage-registration-periods renders', $ok && strpos($html, 'Early Bird') !== false && strpos($html, 'Fatal error') === false);

list($ok, $html) = render_admin_page('C:/laragon/www/main-admin/registration-pricing.php');
check2('registration-pricing matrix renders', $ok && strpos($html, 'Faculty / Consultants') !== false && strpos($html, 'price-edit') !== false);

list($ok, $html) = render_admin_page('C:/laragon/www/main-admin/manage-registration-discounts.php');
check2('manage-registration-discounts renders', $ok && strpos($html, 'Diwali Special Offer') !== false);

list($ok, $html) = render_admin_page('C:/laragon/www/main-admin/manage-registration-options.php');
check2('manage-registration-options renders', $ok);

list($ok, $html) = render_admin_page('C:/laragon/www/main-admin/manage-registrations.php');
check2('manage-registrations renders (empty list)', $ok && strpos($html, 'No registrations found') !== false);

list($ok, $html) = render_admin_page('C:/laragon/www/main-admin/add-registration-discount.php');
check2('add-registration-discount renders', $ok && strpos($html, 'Applicable Categories') !== false);

// ---------------- Frontend page render tests ----------------
function render_frontend_page($file, $sessionVars = [], $postVars = [])
{
    session_seed($sessionVars);
    $_POST = $postVars;
    ob_start();
    $ok = true;
    try {
        include $file;
    } catch (Throwable $e) {
        $ok = false;
        echo 'EXCEPTION: ' . $e->getMessage();
    }
    $html = ob_get_clean();
    return [$ok, $html];
}

list($ok, $html) = render_frontend_page('C:/laragon/www/apapuindia2027/registration-structure.php', []);
check2('registration-structure dynamic render', $ok && strpos($html, 'Early Bird') !== false && strpos($html, 'Faculty / Consultants') !== false && strpos($html, 'Current Period') !== false, substr($html, 0, 300));

list($ok, $html) = render_frontend_page('C:/laragon/www/apapuindia2027/conference-registration.php', [
    'user_logged_in' => true, 'user_id' => 1, 'user_name' => 'Test Delegate', 'user_email' => 'testdelegate@example.com',
]);
check2('conference-registration renders categories + period', $ok && strpos($html, 'Early Bird') !== false && strpos($html, 'Faculty / Consultants') !== false && strpos($html, 'Diwali') === false, '');
check2('conference-registration shows GST hint', strpos($html, '+ GST') !== false);

// Run a page/endpoint in an isolated subprocess (its exit() must not kill this harness)
function run_isolated($file, $sessionVars = [], $postVars = [], $getVars = [])
{
    $env = base64_encode(json_encode([
        'file' => $file,
        'session' => $sessionVars,
        'post' => $postVars,
        'get' => $getVars,
    ]));
    $php = PHP_BINARY;
    $out = shell_exec("set RP_ARGS=$env&& \"$php\" \"C:\Users\Admin\AppData\Local\Temp\opencode/rp_endpoint_runner.php\" 2>NUL");
    return [$out];
}

// Quote endpoint: faculty in Early Bird with Diwali 10% discount active in Nov
list($out) = run_isolated('C:/laragon/www/apapuindia2027/ajax/get-registration-quote.php', [
    'user_logged_in' => true, 'user_id' => 1,
], ['category_id' => '1', 'option_id' => '']);
$quote = json_decode($out, true);
check2('quote endpoint: JSON with breakdown', is_array($quote) && $quote['available'] === true, $out);
check2('quote endpoint: 10% Diwali discount applied (5400)', isset($quote['breakdown']) && (float) $quote['breakdown']['discount_amount'] === 600.0 && (float) $quote['breakdown']['final_amount'] === 5400.0 + 972.0, $out); // 6000-600=5400 taxable; GST18% 972; final 6372
check2('quote endpoint: server-computed GST', isset($quote['breakdown']) && (float) $quote['breakdown']['gst_amount'] === 972.0);

// PG in Nov: Diwali discount IS mapped to PG -> 10% of 4000 = 400 off, no GST
list($out) = run_isolated('C:/laragon/www/apapuindia2027/ajax/get-registration-quote.php', [
    'user_logged_in' => true, 'user_id' => 1,
], ['category_id' => '2', 'option_id' => '']);
$quote = json_decode($out, true);
check2('quote endpoint: PG 3600 after 10% discount, no GST', is_array($quote) && (float) $quote['breakdown']['discount_amount'] === 400.0 && (float) $quote['breakdown']['final_amount'] === 3600.0 && (float) $quote['breakdown']['gst_amount'] === 0.0, $out);

// Unauthenticated access blocked
list($out) = run_isolated('C:/laragon/www/apapuindia2027/ajax/get-registration-quote.php', [], ['category_id' => '1']);
$quote = json_decode($out, true);
check2('quote endpoint blocks guests', is_array($quote) && $quote['available'] === false, $out);

// ---------------- Snapshot flow (create-registration DB logic) ----------------
// Simulate what create-registration.php writes (order creation needs live Razorpay keys, so DB path is exercised here)
include 'C:/laragon/www/apapuindia2027/include/registration-pricing.php';
$quote = rp_get_category_quote($db, 1, null, date('Y-m-d'));
$b = $quote['breakdown'];

mysqli_begin_transaction($db);
$stmt = mysqli_prepare($db, "INSERT INTO registrations
    (registration_ref, user_id, category_id, category_name, period_id, period_name,
     original_fee, currency, option_id, option_name, option_amount,
     discount_id, discount_name, discount_amount,
     taxable_amount, gst_type, gst_percentage, gst_amount, payable_amount, payment_status)
    VALUES ('', 1, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
$discount = $quote['discount'];
$catId = $quote['category']['id'];
$catName = $quote['category']['category_name'];
$perId = $quote['period']['id'];
$perName = $quote['period']['period_name'];
$origFee = $b['original_fee'];
$discId = $discount ? (int) $discount['id'] : null;
$discName = $discount ? $discount['discount_name'] : null;
$taxAmt = $b['taxable_amount'];
$gstPct = $b['gst_percentage'];
$gstAmt = $b['gst_amount'];
$finalAmt = $b['final_amount'];
mysqli_stmt_bind_param($stmt, "isisdsisddsddd",
    $catId,
    $catName,
    $perId,
    $perName,
    $origFee,
    $b['currency'],
    $discId,
    $discName,
    $b['discount_amount'],
    $taxAmt,
    $b['gst_type'],
    $gstPct,
    $gstAmt,
    $finalAmt
);
check2('snapshot insert executes', mysqli_stmt_execute($stmt));
$regId = mysqli_insert_id($db);
$ref = sprintf('REG-%s-%05d', date('Y'), $regId);
mysqli_query($db, "UPDATE registrations SET registration_ref = '$ref' WHERE id = $regId");
$stmtPay = mysqli_prepare($db, "INSERT INTO registration_payments (registration_id, razorpay_order_id, amount, currency, status) VALUES (?, 'order_TEST123', ?, ?, 'created')");
$payAmt = $b['final_amount'];
mysqli_stmt_bind_param($stmtPay, "ids", $regId, $payAmt, $b['currency']);
check2('payment row insert executes', mysqli_stmt_execute($stmtPay));
mysqli_commit($db);

// Verify snapshot data integrity
$res = mysqli_query($db, "SELECT * FROM registrations WHERE id = $regId");
$row = mysqli_fetch_assoc($res);
check2('snapshot ref format', $row['registration_ref'] === $ref, $row['registration_ref']);
check2('snapshot amounts frozen', (float) $row['original_fee'] === (float) $b['original_fee'] && (float) $row['payable_amount'] === (float) $b['final_amount']);

// Admin view page renders the snapshot (isolated process; endpoint uses $_GET['id'])
list($out) = run_isolated('C:/laragon/www/main-admin/view-registration.php', [
    'login_user' => 'admin',
    'login_user_id' => 12,
], [], ['id' => base64_encode($regId)]);
check2('view-registration renders snapshot', strpos($out, $ref) !== false, substr($out, 0, 400));

// Historical consistency: change pricing now, snapshot must NOT change
mysqli_query($db, "UPDATE registration_category_prices SET amount = 99999 WHERE category_id = 1 AND period_id = 1");
mysqli_query($db, "UPDATE registration_discounts SET discount_value = 99 WHERE id = 1");
$res = mysqli_query($db, "SELECT original_fee, payable_amount FROM registrations WHERE id = $regId");
$row = mysqli_fetch_assoc($res);
check2('historical snapshot unchanged after price/discount edits', (float) $row['original_fee'] === (float) $b['original_fee'] && (float) $row['payable_amount'] === (float) $b['final_amount']);

echo "\n$pass passed, $fail failed\n";
