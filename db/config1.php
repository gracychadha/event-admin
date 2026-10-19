<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DB_HOST', '195.35.61.9');  // ✅ use IP instead of srv1282.hostgr.io
define('DB_USERNAME_TRAINING', 'u141530870_training');
define('DB_PASSWORD_TRAINING', '@x1AYU9@e4');
define('DB_NAME_TRAINING', 'u141530870_training');

$db_training = mysqli_connect(DB_HOST, DB_USERNAME_TRAINING, DB_PASSWORD_TRAINING, DB_NAME_TRAINING);

if (!$db_training) {
    die("Training database connection failed: " . mysqli_connect_error());
}
?>
