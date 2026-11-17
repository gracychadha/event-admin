<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test values
$dialCode       = '+91';
$contactNumber  = '7837575742'; // 10-digit mobile only
$firstName      = 'Shweta';
$ref            = 'APAP26TEST';
$eventDate      = '15 Dec 2024 - 17 Dec 2024';
$eventVenue     = 'Jalandhar, Punjab';
$conferenceName = 'Annual Health Conference 2024';

// WATI Credentials
$apiToken     = 'wati_67bd825c-b791-422b-b038-3ee160a9d6d2.MdKUAxDwy8ix0dhKtRsYChKqaGJqV9MUzhQ_q8guzTls_jbbqXiouvnzlB9aycywEgIqimKm8KFmvMOiaGkrjHNIxGzIv9Z5PaA56lAGT0VIEro4NwCExKgAN9_yw1rO';
$templateName = 'registration_confirmation_crm';

// Clean phone (must be 12 digits: 91 + 10)
$cleanPhone = preg_replace('/[^0-9]/', '', $dialCode . $contactNumber);
$cleanPhone = ltrim($cleanPhone, '0');

$url = "https://live-mt-server.wati.io/api/ext/v3/messageTemplates/send";

// CORRECT structure per official OpenAPI schema:
// parameters go INSIDE each recipient as custom_params [{name, value}]
$payload = [
    'template_name'  => $templateName,
    'broadcast_name' => $templateName,
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

echo "<h2>WATI v3 Test Request</h2>";
echo "<pre>URL: " . htmlspecialchars($url) . "</pre>";
echo "<pre>Payload:\n" . htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)) . "</pre>";

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

echo "<h2>WATI v3 Test Response</h2>";
echo "<pre>HTTP Code: $httpCode\n";
$res = json_decode($response, true);
echo print_r($res, true);
echo "</pre>";

// Real success = HTTP 200 AND success true AND no recipient errors
$ok = ($httpCode == 200)
    && !empty($res['success'])
    && empty($res['recipients'][0]['errors']);

if ($ok) {
    echo "<h3 style='color:green;background:#d4edda;padding:10px;'>✅ SUCCESS! Message sent. Check your phone.</h3>";
} else {
    echo "<h3 style='color:red;background:#f8d7da;padding:10px;'>❌ Failed. See response above.</h3>";
}
?>