<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Connection Test</h2>";

// Test 1: MySQLi Extension Check
if (extension_loaded('mysqli')) {
    echo "✅ MySQLi Extension: Loaded<br>";
} else {
    echo "❌ MySQLi Extension: NOT Loaded<br>";
}

// Test 2: Try Connection
$host = "127.0.0.1"; 
$username ="u603275522_leadmatrix"; 
$password = "Leads@2026vis"; 
$database = "u603275522_leadmatrix";

echo "<hr><h3>Trying to connect...</h3>";

try {
    $conn = @mysqli_connect($host, $username, $password, $database);
    
    if ($conn) {
        echo "✅ Connection SUCCESSFUL!<br>";
        echo "Server Info: " . mysqli_get_server_info($conn) . "<br>";
        mysqli_close($conn);
    } else {
        echo "❌ Connection FAILED!<br>";
        echo "Error: " . mysqli_connect_error() . "<br>";
        echo "Error Number: " . mysqli_connect_errno() . "<br>";
    }
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "<br>";
}

// Test 3: Check if MySQL port is accessible
echo "<hr><h3>Port Check (3306):</h3>";
$fp = @fsockopen($host, 3306, $errno, $errstr, 5);
if ($fp) {
    echo "✅ Port 3306 is OPEN<br>";
    fclose($fp);
} else {
    echo "❌ Port 3306 is CLOSED or BLOCKED<br>";
    echo "Error: $errstr ($errno)<br>";
}
?>