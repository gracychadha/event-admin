<?php
include("db/config.php");
include("db/config2.php");
// Check if a single or multiple advertisement IDs are provided for deletion
if (isset($_GET['id'])) {
    // Single deletion
    $advt_id = base64_decode($_GET['id']);
    $ad_id = mysqli_real_escape_string($db_vibrantick, $advt_id);
    // Delete the advertisement image and entry
    deleteHorizontalAd($db_vibrantick, $ad_id);
    // Redirect to the vis-career.php page
    header("Location: vis-career.php");
    exit(); // Terminate script execution after redirection
} elseif (isset($_POST['advt_ids'])) {
    // Multiple deletion
    $success_count = 0;
    $ad_ids = $_POST['advt_ids'];
    foreach ($ad_ids as $encoded_id) {
        $ad_id = base64_decode($encoded_id);
        $ad_id = mysqli_real_escape_string($db_vibrantick, $ad_id);
        // Delete the advertisement image and entry
        deleteHorizontalAd($db_vibrantick, $ad_id);
        $success_count++;
    }
    if ($success_count > 0) {
        // At least one deletion successful
        header("Location: vis-career.php?status=" . base64_encode(1));
    } else {
        // Error in multiple deletion
        header("Location: vis-career.php?status=" . base64_encode(0));
    }
    exit(); // Terminate script execution after redirection
}
// If no advertisement ID provided, redirect to vis-career.php
header("Location: vis-career.php");
exit(); // Terminate script execution after redirection
// Function to delete horizontal advertisement image and entry
function deleteHorizontalAd($db_vibrantick, $id) {
    // Delete entry from the horizontal_ad table
    $delete_sql = "DELETE FROM career WHERE career_id  = '$id'";
    mysqli_query($db_vibrantick, $delete_sql);
}
?>