<?php
// Allow browser testing with a secret key, otherwise require CLI
if (php_sapi_name() !== 'cli' && (!isset($_GET['run']) || $_GET['run'] !== 'test123')) {
    die("This script is designed to run via Cron Job. To test in browser, add ?run=test123 to the URL.");
}

// ==========================================
// FILE-BASED LOCK (Prevent concurrent execution)
// ==========================================
$lockFile = __DIR__ . '/cron_reminder.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    // If lock is older than 5 minutes, it's stale - remove it
    if ((time() - $lockTime) > 300) {
        unlink($lockFile);
        echo "Removed stale lock file.\n";
    } else {
        die("Script is already running. Please wait.\n");
    }
}
// Create lock file
file_put_contents($lockFile, getmypid());

include("db/config.php");

// ==========================================
// WATI HELPER FUNCTION
// ==========================================
function sendWatiReminder($dialCode, $contactNumber, $firstName, $ref, $eventDate, $eventVenue, $conferenceName) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $dialCode . $contactNumber);
    $cleanPhone = ltrim($cleanPhone, '0');
    
    if (strlen($cleanPhone) < 10) return false;

    $apiToken     = 'wati_67bd825c-b791-422b-b038-3ee160a9d6d2.MdKUAxDwy8ix0dhKtRsYChKqaGJqV9MUzhQ_q8guzTls_jbbqXiouvnzlB9aycywEgIqimKm8KFmvMOiaGkrjHNIxGzIv9Z5PaA56lAGT0VIEro4NwCExKgAN9_yw1rO';
    $templateName = 'registration_confirmation_crm';

    $url = "https://live-mt-server.wati.io/api/ext/v3/messageTemplates/send";

    $payload = [
        'template_name'  => $templateName,
        'broadcast_name' => '1 Day Reminder Broadcast',
        'recipients' => [
            [
                'phone_number'  => $cleanPhone,
                'custom_params' => [
                    ['name' => '1', 'value' => $firstName],
                    ['name' => '2', 'value' => $conferenceName],
                    ['name' => '3', 'value' => $eventDate],
                    ['name' => '4', 'value' => $eventVenue],
                    ['name' => '5', 'value' => $ref]
                ]
            ]
        ]
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "accept: application/json",
            "content-type: application/json",
            "authorization: Bearer " . $apiToken
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return ($httpCode == 200 || $httpCode == 201);
}

// ==========================================
// MAIN CRON LOGIC
// ==========================================
$logFile = __DIR__ . '/cron_reminder_log.txt';
$log = "\n========== " . date('Y-m-d H:i:s') . " ==========\n";

// 1. Check if there is an event starting EXACTLY tomorrow
$stmt = mysqli_prepare($db, "SELECT event_name, start_date, event_venue FROM event_settings 
                             WHERE start_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) LIMIT 1");
mysqli_stmt_execute($stmt);
$event = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$event) {
    $log .= "No event starting tomorrow. Skipping.\n";
    file_put_contents($logFile, $log, FILE_APPEND);
    echo "No event tomorrow. Done.\n";
    unlink($lockFile); // Remove lock
    exit;
}

$log .= "Event found for tomorrow: " . $event['event_name'] . "\n";
$formattedDate = date('d M Y', strtotime($event['start_date']));

// 2. Fetch users who HAVEN'T received reminder yet (reminder_sent_at IS NULL)
$query = "SELECT r.id, u.first_name, u.dial_code, u.contact_number, r.registration_ref 
          FROM registrations r
          JOIN registered_users u ON r.user_id = u.student_id
          WHERE r.registration_ref IS NOT NULL 
          AND r.reminder_sent_at IS NULL";
          
$result = mysqli_query($db, $query);

if (!$result) {
    $log .= "Database query failed: " . mysqli_error($db) . "\n";
    file_put_contents($logFile, $log, FILE_APPEND);
    echo "Query failed. Check log.\n";
    unlink($lockFile);
    exit;
}

$count = 0;
$successCount = 0;
$failCount = 0;

while ($user = mysqli_fetch_assoc($result)) {
    $count++;
    $log .= "[$count] Sending to: {$user['first_name']} ({$user['contact_number']}) - Ref: {$user['registration_ref']} ... ";
    
    $sent = sendWatiReminder(
        $user['dial_code'] ?? '+91',
        $user['contact_number'],
        $user['first_name'],
        $user['registration_ref'],
        $formattedDate,
        $event['event_venue'],
        $event['event_name']
    );
    
    if ($sent) {
        // ✅ Mark as sent with timestamp (use NOW() from MySQL)
        $updateStmt = mysqli_prepare($db, "UPDATE registrations SET reminder_sent_at = NOW() WHERE id = ?");
        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, "i", $user['id']);
            $updateResult = mysqli_stmt_execute($updateStmt);
            
            if ($updateResult) {
                $log .= "SUCCESS (reminder_sent_at updated)\n";
                $successCount++;
            } else {
                $log .= "SENT BUT UPDATE FAILED: " . mysqli_stmt_error($updateStmt) . "\n";
                $failCount++;
            }
            mysqli_stmt_close($updateStmt);
        } else {
            $log .= "SENT BUT PREPARE FAILED: " . mysqli_error($db) . "\n";
            $failCount++;
        }
    } else {
        $log .= "FAILED (API error)\n";
        $failCount++;
    }
    
    // Small delay to avoid rate limits
    usleep(300000);
}

$log .= "Total Processed: {$count} | Successful: {$successCount} | Failed: {$failCount}\n";
file_put_contents($logFile, $log, FILE_APPEND);

// Remove lock file
unlink($lockFile);

echo "Cron job completed. Check {$logFile} for details.\n";
?>