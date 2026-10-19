<?php
session_start();

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

include('db/config.php');

// Handle single delete (GET or POST)
if (isset($_GET['id']) || isset($_POST['id'])) {
    $id = isset($_GET['id']) ? $_GET['id'] : $_POST['id'];
    $id = base64_decode($id);

    if (!is_numeric($id)) {
        header("location: manage-abstract-themes.php?status=" . base64_encode(-1));
        exit();
    }

    $stmt = $db->prepare("DELETE FROM abstract_theme WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("location: manage-abstract-themes.php?status=" . base64_encode(1));
    } else {
        header("location: manage-abstract-themes.php?status=" . base64_encode(-1));
    }
    $stmt->close();
    exit();
}

// Handle multiple theme deletes
if (isset($_POST['theme_ids'])) {
    $ids = $_POST['theme_ids'];
    $success_count = 0;

    $stmt = $db->prepare("DELETE FROM abstract_theme WHERE id = ?");

    foreach ($ids as $encoded_id) {
        $id = base64_decode($encoded_id);
        if (!is_numeric($id)) {
            continue;
        }
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success_count++;
        }
    }
    $stmt->close();

    if ($success_count > 0) {
        header("location: manage-abstract-themes.php?status=" . base64_encode(1));
    } else {
        header("location: manage-abstract-themes.php?status=" . base64_encode(-1));
    }
    exit();
}

// Default fallback
header("location: manage-abstract-themes.php?status=" . base64_encode(-1));
exit();
?>
