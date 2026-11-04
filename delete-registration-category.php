<?php
session_start(); // ✅ Needed for session messages
include("db/config.php");

// Function to delete the permission record
function deletePermission($db, $permission_id) {
    $delete_query = "DELETE FROM registration_categories WHERE id = '$permission_id'";
    return mysqli_query($db, $delete_query); // ✅ Return true/false
}

// ✅ Single delete
if (isset($_GET['id'])) {
    $permission_id = base64_decode($_GET['id']);
    $permission_id = mysqli_real_escape_string($db, $permission_id);

    if (deletePermission($db, $permission_id)) {
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong><i class='feather icon-check'></i> Deleted!</strong> Registration Category deleted successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Error!</strong> Failed to delete Registration Category.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    header("Location: manage-registration-category.php");
    exit();
}

// ✅ Multiple delete
if (isset($_POST['Permission_ids'])) {
    $success = 0;

    foreach ($_POST['Permission_ids'] as $encoded_id) {
        $permission_id = base64_decode($encoded_id);
        $permission_id = mysqli_real_escape_string($db, $permission_id);

        if (deletePermission($db, $permission_id)) {
            $success++;
        }
    }

    if ($success > 0) {
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Success!</strong> $success Registration Category(s) deleted successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Error!</strong> No Registration Category records deleted.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    header("Location: manage-registration-category.php");
    exit();
}

// If no action, redirect
header("Location: manage-registration-category.php");
exit();
?>
