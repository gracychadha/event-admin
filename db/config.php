<?php

$host = "127.0.0.1"; 
$username ="root"; 
$password = ""; 
$database = "main_admin";
// $host = "127.0.0.1"; 
// $username ="u927086856_login"; 
// $password = "|jksQ5Ct5"; 
// $database = "u927086856_login";

$db = mysqli_connect($host, $username, $password, $database);

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

?>