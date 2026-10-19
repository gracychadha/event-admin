<?php
session_start();

$appId = "740150892272064";
$redirectUri = "https://leadmatrix.technocoderz.com/facebook_callback.php";

$loginUrl = "https://www.facebook.com/v19.0/dialog/oauth?" . http_build_query([
    'client_id' => $appId,
    'redirect_uri' => $redirectUri,
    'scope' => implode(',', [
        'public_profile',
        'email',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_ads',        
        'pages_manage_metadata',
        'leads_retrieval'
    ])
]);

header("Location: " . $loginUrl);
exit;