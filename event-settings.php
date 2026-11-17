<?php
session_start();
if (!isset($_SESSION["login_user"])) { 
    header("location: index.php"); 
}
include("db/config.php");

$msg = "";

// Fetch the single existing event (if any)
$event = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM event_settings LIMIT 1"));
$eventId = $event ? (int) $event['id'] : 0;

// ---- HANDLE DELETE / CLEAR EVENT ----
if (isset($_GET['delete']) && $eventId > 0) {
    $s = mysqli_prepare($db, "DELETE FROM event_settings WHERE id=?");
    mysqli_stmt_bind_param($s, "i", $eventId);
    mysqli_stmt_execute($s); 
    mysqli_stmt_close($s);
    header("Location: event-settings.php");
    exit;
}

// ---- HANDLE SAVE (INSERT OR UPDATE) ----
if (isset($_POST['save_event'])) {
    $name      = trim($_POST['event_name'] ?? '');
    $venue     = trim($_POST['event_venue'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';

    // Validation
    if ($name === '' || $venue === '' || $startDate === '' || $endDate === '') {
        $msg = "<div class='alert alert-danger'>All fields are required.</div>";
    } elseif (strtotime($endDate) < strtotime($startDate)) {
        $msg = "<div class='alert alert-danger'>End Date cannot be earlier than Start Date.</div>";
    } else {
        if ($eventId > 0) {
            // Update the existing single event
            $s = mysqli_prepare($db, "UPDATE event_settings SET event_name=?, event_venue=?, start_date=?, end_date=? WHERE id=?");
            mysqli_stmt_bind_param($s, "ssssi", $name, $venue, $startDate, $endDate, $eventId);
            mysqli_stmt_execute($s); 
            mysqli_stmt_close($s);
            $msg = "<div class='alert alert-success'>Event settings updated successfully.</div>";
        } else {
            // Insert the first and only event
            $s = mysqli_prepare($db, "INSERT INTO event_settings (event_name, event_venue, start_date, end_date) VALUES (?,?,?,?)");
            mysqli_stmt_bind_param($s, "ssss", $name, $venue, $startDate, $endDate);
            mysqli_stmt_execute($s); 
            mysqli_stmt_close($s);
            $msg = "<div class='alert alert-success'>Event settings saved successfully.</div>";
            
            // Redirect to prevent duplicate inserts on page refresh
            header("Location: event-settings.php");
            exit;
        }
        
        // Refresh event data after a successful save
        $event = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM event_settings LIMIT 1"));
        $eventId = $event ? (int) $event['id'] : 0;
    }
}

// Helper function to safely output form values
$v = function ($k, $d = '') use ($event) {
    return htmlspecialchars($event[$k] ?? $d);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Event Settings</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .form-label { font-weight: 600; margin-bottom: 5px; display: block; }
        .red-text { color: red; }
    </style>
</head>
<body>
    <div class="loader-bg"><div class="loader-track"><div class="loader-fill"></div></div></div>
    <?php include("header.php"); include("navbar.php"); ?>

    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Event Settings</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <h5><i class="feather icon-settings"></i> <?= $eventId > 0 ? 'Update Current Event' : 'Setup New Event' ?></h5>
                        </div>
                        <div class="card-body">
                            <?= $msg ?>
                            
                            <form method="post" autocomplete="off">
                                <div class="form-group">
                                    <label class="form-label">Event / Conference Name <span class="red-text">*</span></label>
                                    <input name="event_name" class="form-control" value="<?= $v('event_name') ?>" required placeholder="e.g., Annual Tech Conference 2024">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Venue <span class="red-text">*</span></label>
                                    <input name="event_venue" class="form-control" value="<?= $v('event_venue') ?>" required placeholder="e.g., Jalandhar, Punjab">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Start Date <span class="red-text">*</span></label>
                                            <input type="date" name="start_date" class="form-control" value="<?= $v('start_date') ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">End Date <span class="red-text">*</span></label>
                                            <input type="date" name="end_date" class="form-control" value="<?= $v('end_date') ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="save_event" class="btn btn-primary">
                                        <i class="feather icon-save"></i> <?= $eventId > 0 ? 'Update Event' : 'Save Event' ?>
                                    </button>
                                    
                                    <?php if ($eventId > 0): ?>
                                        <a href="event-settings.php?delete=1" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to clear the current event settings?');">
                                            <i class="feather icon-trash-2"></i> Clear Event
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </form>

                            <?php if ($eventId > 0): ?>
                                <hr class="my-4">
                                <div class="alert alert-info mb-0">
                                    <strong><i class="feather icon-info"></i> Note:</strong> 
                                    This event's details will be automatically used in the WhatsApp registration confirmation messages.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
</body>
</html>