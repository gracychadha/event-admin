<?php
session_start();
include("db/config.php");

// Function to delete a registration option (historical registrations keep snapshots)
function deleteOption($db, $option_id)
{
    $stmt = mysqli_prepare($db, "DELETE FROM registration_category_options WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $option_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

// Single delete
if (isset($_GET['id'])) {
    $option_id = base64_decode($_GET['id']);
    $option_id = mysqli_real_escape_string($db, $option_id);

    if (is_numeric($option_id) && deleteOption($db, (int) $option_id)) {
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong><i class='feather icon-check'></i> Deleted!</strong> Registration Option deleted successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Error!</strong> Failed to delete Registration Option.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    header("Location: manage-registration-options.php");
    exit();
}

// Multiple delete
if (isset($_POST['option_ids'])) {
    $success = 0;

    foreach ($_POST['option_ids'] as $encoded_id) {
        $option_id = base64_decode($encoded_id);
        if (is_numeric($option_id) && deleteOption($db, (int) $option_id)) {
            $success++;
        }
    }

    if ($success > 0) {
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Success!</strong> $success Registration Option(s) deleted successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Error!</strong> No Registration Option records deleted.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    header("Location: manage-registration-options.php");
    exit();
}

// If no action, redirect
header("Location: manage-registration-options.php");
exit();
?>
