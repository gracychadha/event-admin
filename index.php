<?php

session_start();
error_reporting(E_ALL);

include("db/config.php");

if (!isset($_SESSION["login_user"])) {
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        include 'db/config.php';

        $myusername = mysqli_real_escape_string($db, $_POST['username']);
        $mypassword = mysqli_real_escape_string($db, $_POST['password']);
        $mypassword = md5($mypassword);

        // Check username & password
        $sql = "SELECT * FROM admin WHERE username = '$myusername' AND password = '$mypassword' AND status = 1";
        $result = mysqli_query($db, $sql);
        $adminData = mysqli_fetch_assoc($result);
        $count = mysqli_num_rows($result);

        // Fetch active reCAPTCHA settings
        $captcha_query = "SELECT * FROM recaptcha_settings WHERE status = 'active' LIMIT 1";
        $captcha_result = mysqli_query($db, $captcha_query);
        $captcha_data = mysqli_fetch_assoc($captcha_result);

        $captcha_active = !empty($captcha_data); // true if captcha row exists
        $captcha_provider = $captcha_data['provider'] ?? '';
        $captcha_secret = $captcha_data['secret_key'] ?? '';

        $verify_success = false;

        if ($captcha_active) {
            // Get reCAPTCHA response from form (Google or Cloudflare)
            $recaptcha_response = $_POST['g-recaptcha-response'] ?? $_POST['cf-turnstile-response'] ?? '';

            if ($recaptcha_response && $captcha_provider && $captcha_secret) {
                if ($captcha_provider === 'google') {
                    // Google reCAPTCHA verification
                    $url = 'https://www.google.com/recaptcha/api/siteverify?secret='
                        . $captcha_secret . '&response=' . $recaptcha_response;
                    $response = file_get_contents($url);
                    $verify_result = json_decode($response);
                    $verify_success = $verify_result->success ?? false;
                } elseif ($captcha_provider === 'cloudflare') {
                    // Cloudflare Turnstile verification
                    $verify_url = "https://challenges.cloudflare.com/turnstile/v0/siteverify";
                    $data = [
                        'secret' => $captcha_secret,
                        'response' => $recaptcha_response
                    ];
                    $options = [
                        'http' => [
                            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                            'method'  => 'POST',
                            'content' => http_build_query($data),
                        ]
                    ];
                    $context  = stream_context_create($options);
                    $result_raw = file_get_contents($verify_url, false, $context);
                    $verify_result = json_decode($result_raw);
                    $verify_success = $verify_result->success ?? false;
                }
            }
        } else {
            // Captcha is inactive, auto-pass verification
            $verify_success = true;
        }

        // Final login decision
        // Final login decision
        if ($verify_success) {
            if ($count == 1) {
                // Set session data
                $_SESSION['login_user']    = $adminData['username'];
                $_SESSION['login_user_id'] = $adminData['_id'];         // Use actual DB user ID
                $_SESSION['admin_role']    = $adminData['admin_role'];
                $_SESSION['employee_id']   = $adminData['employee_id'];

                $uip = $_SERVER['REMOTE_ADDR'];
                date_default_timezone_set('Asia/Kolkata');
                $login_time = date('Y-m-d H:i:s A');

                // Get Browser / OS / Device info
$user_agent = $_SERVER['HTTP_USER_AGENT'];

// Get IP (safer way with fallback)
$uip = '';
if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    $uip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $uip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]; // first IP if multiple
} else {
    $uip = $_SERVER['REMOTE_ADDR'];
}

// Detect Browser
function getBrowser($user_agent)
{
    if (strpos($user_agent, 'Chrome') !== false && strpos($user_agent, 'Edg') === false) return "Chrome";
    elseif (strpos($user_agent, 'Firefox') !== false) return "Firefox";
    elseif (strpos($user_agent, 'Edg') !== false) return "Edge";
    elseif (strpos($user_agent, 'Safari') !== false && strpos($user_agent, 'Chrome') === false) return "Safari";
    elseif (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Trident') !== false) return "Internet Explorer";
    else return "Unknown";
}

// Detect OS
function getOS($user_agent)
{
    if (strpos($user_agent, 'Windows') !== false) return "Windows";
    elseif (strpos($user_agent, 'Macintosh') !== false) return "MacOS";
    elseif (strpos($user_agent, 'Linux') !== false) return "Linux";
    elseif (strpos($user_agent, 'Android') !== false) return "Android";
    elseif (strpos($user_agent, 'iPhone') !== false) return "iOS";
    else return "Unknown";
}

// Detect Device
function getDeviceType($user_agent)
{
    if (preg_match('/mobile|android|iphone|ipad|tablet/i', $user_agent)) return "Mobile";
    else return "Desktop";
}

// Detect Country (using ip-api.com)
function getCountry($ip)
{
    if (!$ip || $ip === 'UNKNOWN') return "Unknown";
    $url = "http://ip-api.com/json/{$ip}?fields=status,country";
    $response = @file_get_contents($url);
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            return $data['country'];
        }
    }
    return "Unknown";
}

$browser = getBrowser($user_agent);
$os = getOS($user_agent);
$device = getDeviceType($user_agent);
$country = getCountry($uip);

// Insert into user_logs (added country column)
$stmt = $db->prepare("INSERT INTO user_logs (user_id, username, user_ip, login_time, browser, os, device_type, country) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param(
    "isssssss",
    $_SESSION['login_user_id'], // <-- use this instead of session_id()
    $_SESSION['login_user'],
    $uip,
    $login_time,
    $browser,
    $os,
    $device,
    $country
);
$stmt->execute();


                session_regenerate_id(true);
                $st = base64_encode(1);
                header("location: dashboard.php?loginin=$st");
                exit();
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Captcha verification failed.";
        }
    }

    $query = "SELECT * FROM login_settings";
    $settingsResult = mysqli_query($db, $query);
    $settings = mysqli_fetch_assoc($settingsResult);

    $logoPath = $settings['backend_panel_logo'];
    $helpdeskNumber = $settings['helpdesk_no'];
    $favicon = $settings['favicon'];
    // echo $favicon;
    // exit;

    // Function to delete old records
    function deleteOldRecords($db)
    {
        date_default_timezone_set('Asia/Kolkata');
        $dateLimit = date('Y-m-d H:i:s', strtotime('-15 days'));
        $deleteQuery = "DELETE FROM user_logs WHERE login_time < '$dateLimit'";
        mysqli_query($db, $deleteQuery);
    }
    deleteOldRecords($db);
} else {
    header("location: dashboard.php");
}


$copy = mysqli_query($db, "SELECT * FROM copyright WHERE status = 1");
$copyres = mysqli_fetch_assoc($copy);
$Name = isset($copyres['name']) ? $copyres['name'] : '';
$link = isset($copyres['link']) ? $copyres['link'] : '';



$recaptcha_query = "SELECT * FROM recaptcha_settings WHERE status = 'active' LIMIT 1";
$recaptcha_result = mysqli_query($db, $recaptcha_query);

$recaptcha_provider = '';
$recaptcha_site_key = '';

if ($recaptcha_result && mysqli_num_rows($recaptcha_result) > 0) {
    $recaptcha = mysqli_fetch_assoc($recaptcha_result);
    $recaptcha_provider = $recaptcha['provider'];
    $recaptcha_site_key = $recaptcha['site_key'];
}


$recaptcha_query = "SELECT * FROM recaptcha_settings WHERE status = 'active' LIMIT 1";
$recaptcha_result = mysqli_query($db, $recaptcha_query);
$recaptcha_data = mysqli_fetch_assoc($recaptcha_result);

$recaptcha_provider = $recaptcha_data['provider'] ?? '';
$recaptcha_site_key = $recaptcha_data['site_key'] ?? '';
$captcha_active = !empty($recaptcha_provider) && !empty($recaptcha_site_key);
?>

<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=UTF-8" />

<head>
    <title>Welcome to Admin Panel</title>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="" />
    <meta name="keywords" content="">
    <meta name="author" content="#" />

    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">

    <link rel="stylesheet" href="assets/css/style.css">
    <script language="javascript" type="text/javascript">
        window.history.forward();
    </script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

</head>

<body>
    <?php
    // SQL query to fetch general settings and company logo
    $sql = "SELECT imgurl FROM cover_photo where status = 1 LIMIT 1";
    $result = $db->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $bgImage = 'popup/' . $row['imgurl']; // Assuming images are in the 'popup' directory

        // Check if the file exists
        if (!file_exists($bgImage)) {
            $bgImage = 'assets/images/default-background.jpg'; // Fallback to a default image
        }
    } else {
        $bgImage = 'assets/images/default-background.jpg'; // Default background image
    }

    // Fetch the logo from company_settings table
    $logoQuery = "SELECT imgurl FROM cover_photo where status = 1";
    $logoResult = $db->query($logoQuery);
    $logoRow = $logoResult->fetch_assoc();
    $logoPat = 'popup/' . $logoRow['imgurl']; // Assuming logo is stored in assets/img
    ?>
    <div class="auth-wrapper align-items-stretch">
        <div class="h-100 d-md-flex align-items-center auth-side-img">
   <img src="<?php echo  $bgImage; ?>" alt="Side Image" />
</div>
<style>
    .auth-side-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;   /* Stretch hone se bachayega */
    object-position: center; 
    border-radius: 0;    /* Optional */
}

</style>
        <div class="flex-grow-1">
            <div class="h-100 d-md-flex align-items-center auth-side-img"></div>
            <div class="auth-side-form">
                <form action="" method="post">
                    <div class=" auth-content" style="background-color:#f8f7f2;">
                        <img src="<?php echo $logoPath; ?>" alt="" class="img-fluid">
                        <hr />
                        <h3 class="mb-4 f-w-400">Signin</h3>
                        <?php


// $app_id = "2327236491109328";
// $redirect_uri = "http://localhost/login/fb-callback.php";
// $scope = "email";

// $loginURL =
//     "https://www.facebook.com/v18.0/dialog/oauth?" .
//     "client_id=" . $app_id .
//     "&redirect_uri=" . urlencode($redirect_uri) .
//     "&state=" . md5(uniqid()) .
//     "&scope=" . $scope;
// ?>

<!-- // <a href="<?php echo $loginURL; ?>" class="btn btn-primary">
//     Login with Facebook
// </a> -->

                        <?php if (isset($error)) {
                            echo " <div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;' id='successMessage'>
                                   <strong><i class=' feather  icon icon-info'></i>Error!</strong> $error.
                                    <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                                    <span aria-hidden='true'>&times;</span>
                                    </button>
                                    </div> ";
                        }
                        ?>
                        <div class="input-group mb-3">
                            <div class="input-group-prepend">
                                <span class="input-group-text" style="background-color:#d8b24f;color:#fff;"><i class="feather icon-mail"></i></span>
                            </div>
                            <input type="text" class="form-control" placeholder="Username" name="username" required
                                oninvalid="this.setCustomValidity('Please Enter Username')" oninput="setCustomValidity('')" style="border-color:#d8b24f">
                        </div>
                        <div class="input-group mb-4">
                            <div class="input-group-prepend">
                                <span class="input-group-text" style="background-color:#d8b24f;color:#fff;"><i class="feather icon-lock"></i></span>
                            </div>
                            <input type="password" class="form-control" placeholder="Password" name="password" required
                                oninvalid="this.setCustomValidity('Please Enter Password')" oninput="setCustomValidity('')" style="border-color:#d8b24f">
                        </div>
                        <?php if ($captcha_active && $recaptcha_provider === 'google'): ?>
                            <!-- Google reCAPTCHA -->
                            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                            <div class="g-recaptcha mb-3" data-sitekey="<?php echo $recaptcha_site_key; ?>" data-callback="enableSubmit"></div>

                        <?php elseif ($captcha_active && $recaptcha_provider === 'cloudflare'): ?>
                            <!-- Cloudflare Turnstile -->
                            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                            <div class="cf-turnstile" data-sitekey="<?php echo $recaptcha_site_key; ?>" data-callback="enableSubmit"></div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-secondary" name="submit" id="submit" <?php echo $captcha_active ? 'disabled' : ''; ?>>
                            <i class="feather icon-save lg"></i>&nbsp;Sign In
                        </button>
                </form>
                <div class="text-center">
                    <br />
                    <br />

                    <p style="color:#000;">HelpDesk/Helpline No: <?php echo $helpdeskNumber; ?></p>
                    <hr / style="border-color:#d8b24f">
                    <div class="copy-right align-items-center">
                        &copy; <?php echo date("Y"); ?>
                        Developed by <a href="<?php echo $link ?>" target="_blank" style="color: #000000;" onmouseover="this.style.color='#002561';" onmouseout="this.style.color='black';"> <?php echo $Name ?></a>
                        </p>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/waves.min.js"></script>
    <script>
        $(document).ready(function() {
            $("#successMessage").delay(5000).slideUp(300);
        });
    </script>
    <script type="text/javascript">
        function callback() {
            const submitButton = document.getElementById("submit");
            submitButton.removeAttribute("disabled");
        }
    </script>
    <script>
        function enableSubmit() {
            document.getElementById('submit').disabled = false;
        }
        <?php if (!$captcha_active): ?>
            // If captcha is not active, enable submit immediately
            document.getElementById('submit').disabled = false;
        <?php endif; ?>
    </script>
</body>

</html>