<?php
session_start();
include("db/config.php");

// Function to delete a registration period (registrations keep their period name snapshot)
function deletePeriod($db, $period_id)
{
    $stmt = mysqli_prepare($db, "DELETE FROM registration_periods WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $period_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

// Single delete
if (isset($_GET['id'])) {
    $period_id = base64_decode($_GET['id']);
    $period_id = mysqli_real_escape_string($db, $period_id);

    if (is_numeric($period_id) && deletePeriod($db, (int) $period_id)) {
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong><i class='feather icon-check'></i> Deleted!</strong> Registration Period deleted successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Error!</strong> Failed to delete Registration Period.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    header("Location: manage-registration-periods.php");
    exit();
}

// Multiple delete
if (isset($_POST['period_ids'])) {
    $success = 0;

    foreach ($_POST['period_ids'] as $encoded_id) {
        $period_id = base64_decode($encoded_id);
        if (is_numeric($period_id) && deletePeriod($db, (int) $period_id)) {
            $success++;
        }
    }

    if ($success > 0) {
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Success!</strong> $success Registration Period(s) deleted successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong>Error!</strong> No Registration Period records deleted.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    header("Location: manage-registration-periods.php");
    exit();
}

// If no action, redirect
header("Location: manage-registration-periods.php");
exit();
?>
