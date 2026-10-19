<?php
session_start();

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

error_reporting(E_ALL);
include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");

$back = "manage-abstract-submissions.php";

// Admin only
if (!$isAdmin) {
    abstract_swal_page('error', 'Not Allowed', 'Only an administrator can delete abstracts.', $back);
}

$id = 0;
if (isset($_GET['id'])) {
    $id = (int) base64_decode($_GET['id']);
} elseif (isset($_POST['id'])) {
    $id = (int) base64_decode($_POST['id']);
}

if ($id <= 0) {
    abstract_swal_page('error', 'Invalid Abstract', 'The requested abstract could not be identified.', $back);
}

$submission = abstract_fetch_submission_full($db, $id);
if (!$submission) {
    abstract_swal_page('error', 'Not Found', 'Abstract not found.', $back);
}

// Remove the uploaded file if present
if (!empty($submission['file_path'])) {
    $file = __DIR__ . '/../apapuindia2027/uploads/abstracts/' . basename($submission['file_path']);
    if (is_file($file)) {
        @unlink($file);
    }
}

// Delete associated logs, then the submission
$stmt = $db->prepare("DELETE FROM abstract_logs WHERE submission_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

$stmt = $db->prepare("DELETE FROM abstract_submissions WHERE id = ?");
$stmt->bind_param("i", $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    abstract_swal_page('success', 'Deleted', 'Abstract deleted.', $back);
} else {
    abstract_swal_page('error', 'Delete Failed', 'Failed to delete abstract.', $back);
}
