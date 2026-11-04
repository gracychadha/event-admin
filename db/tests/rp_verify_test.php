<?php
/**
 * Payment verification endpoint tests (isolated subprocess per call).
 * Requires: payment_settings row, registration + payment attempt from rp_page_test.
 */
$pass = 0;
$fail = 0;

function check3($name, $condition, $detail = '')
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

function run_verify($sessionVars, $postVars)
{
    $env = base64_encode(json_encode([
        'file' => 'C:/laragon/www/apapuindia2027/ajax/verify-registration-payment.php',
        'session' => $sessionVars,
        'post' => $postVars,
        'get' => [],
    ]));
    $out = shell_exec('set RP_ARGS=' . $env . '&& "' . PHP_BINARY . '" "C:\Users\Admin\AppData\Local\Temp\opencode/rp_endpoint_runner.php" 2>NUL');
    return json_decode($out, true);
}

require 'C:/laragon/www/main-admin/db/config.php';
require 'C:/laragon/www/apapuindia2027/include/registration-pricing.php';

// Setup: gateway + registration + payment attempt
mysqli_query($db, "DELETE FROM payment_settings");
mysqli_query($db, "INSERT INTO payment_settings (provider, key_id, key_secret, status) VALUES ('razorpay', 'rzp_test_KEYID123', 'testsecret123', 1)");
$reg = mysqli_fetch_assoc(mysqli_query($db, "SELECT id, payable_amount, currency FROM registrations ORDER BY id DESC LIMIT 1"));
mysqli_query($db, "DELETE FROM registration_payments WHERE registration_id = " . $reg['id']);
mysqli_query($db, "INSERT INTO registration_payments (registration_id, razorpay_order_id, amount, currency, status) VALUES ({$reg['id']}, 'order_ABC123', {$reg['payable_amount']}, '{$reg['currency']}', 'created')");

$userSess = ['user_logged_in' => true, 'user_id' => 1];

// 1. Bad signature -> failed
$res = run_verify($userSess, [
    'razorpay_order_id' => 'order_ABC123',
    'razorpay_payment_id' => 'pay_TEST1',
    'razorpay_signature' => 'invalid_sig',
]);
check3('Bad signature rejected', $res && $res['success'] === false, json_encode($res));
$row = mysqli_fetch_assoc(mysqli_query($db, "SELECT status FROM registration_payments WHERE razorpay_order_id = 'order_ABC123'"));
check3('Failed attempt recorded', $row['status'] === 'failed', $row['status'] ?? 'null');

// 2. Correct HMAC signature -> captured + paid
$sig = hash_hmac('sha256', 'order_ABC123|pay_TEST2', 'testsecret123');
$res = run_verify($userSess, [
    'razorpay_order_id' => 'order_ABC123',
    'razorpay_payment_id' => 'pay_TEST2',
    'razorpay_signature' => $sig,
]);
check3('Valid signature accepted', $res && $res['success'] === true, json_encode($res));
$row = mysqli_fetch_assoc(mysqli_query($db, "SELECT status, razorpay_payment_id FROM registration_payments WHERE razorpay_order_id = 'order_ABC123'"));
check3('Payment marked captured', $row['status'] === 'captured' && $row['razorpay_payment_id'] === 'pay_TEST2');
$regRow = mysqli_fetch_assoc(mysqli_query($db, "SELECT payment_status FROM registrations WHERE id = {$reg['id']}"));
check3('Registration marked paid', $regRow['payment_status'] === 'paid');

// 3. Idempotent double-submit returns success
$res = run_verify($userSess, [
    'razorpay_order_id' => 'order_ABC123',
    'razorpay_payment_id' => 'pay_TEST2',
    'razorpay_signature' => $sig,
]);
check3('Idempotent retry returns ref', $res && $res['success'] === true, json_encode($res));

// 4. Other user's order rejected
$other = mysqli_fetch_assoc(mysqli_query($db, "SELECT id FROM registrations ORDER BY id DESC LIMIT 1"));
mysqli_query($db, "INSERT IGNORE INTO registered_users (student_id, image, first_name, last_name, email) VALUES (2,'','Other','Person','other@example.com')");
mysqli_query($db, "UPDATE registrations SET user_id = 2 WHERE id = {$other['id']}");
$res = run_verify($userSess, [
    'razorpay_order_id' => 'order_ABC123',
    'razorpay_payment_id' => 'pay_X',
    'razorpay_signature' => $sig,
]);
check3('Foreign user blocked', $res && $res['success'] === false, json_encode($res));
mysqli_query($db, "UPDATE registrations SET user_id = 1 WHERE id = {$other['id']}");
mysqli_query($db, "DELETE FROM registered_users WHERE student_id = 2");

// 5. Missing session blocked
$res = run_verify([], ['razorpay_order_id' => 'x']);
check3('Guest blocked on verify', $res && $res['success'] === false);

echo "\n$pass passed, $fail failed\n";
