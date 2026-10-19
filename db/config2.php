<?php
define('DB_SERVER', '195.35.61.9');
define('DB_USERNAME_VIBRANTICK', 'u141530870_vibrantick');
define('DB_PASSWORD_VIBRANTICK', 'o0IQr8=*]:');
define('DB_NAME_VIBRANTICK', 'u141530870_vibrantick');

$db_vibrantick = mysqli_connect(DB_SERVER, DB_USERNAME_VIBRANTICK, DB_PASSWORD_VIBRANTICK, DB_NAME_VIBRANTICK);
if (!$db_vibrantick) {
    die("Vibrantick database connection failed: " . mysqli_connect_error());
}
?>