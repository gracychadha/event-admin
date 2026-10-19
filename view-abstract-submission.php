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

if (!abstract_can('Abstract Submissions')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to access this page.', 'dashboard.php');
}

$id = isset($_GET['id']) ? (int) base64_decode($_GET['id']) : 0;
if ($id <= 0) {
    header("location: manage-abstract-submissions.php");
    exit;
}

$submission = abstract_fetch_submission_full($db, $id);
if (!$submission) {
    abstract_swal_page('error', 'Not Found', 'Abstract not found.', 'manage-abstract-submissions.php');
}

if ($isOrganizer && !$isAdmin && in_array($submission['status'], ['submitted', 'resubmitted'])) {
    abstract_swal_page('warning', 'Not Available Yet', 'This abstract is still awaiting review and is not visible to organizers at this stage.', 'manage-abstract-submissions.php');
}

$msg = "";

if (isset($_POST['submit'])) {
    $action = $_POST['action'] ?? '';
    $remark = trim($_POST['remark'] ?? '');
    $adminName = abstract_admin_name($db, $adminId);

    if ($action === 'accept' || $action === 'reject') {
        $decision = ($action === 'accept') ? 'accept' : 'decline';
        if ($decision === 'decline' && $remark === '') {
            abstract_swal_flash('error', 'Remark Required', 'Please provide a remark when rejecting.');
        } elseif (abstract_decide_submission($db, $id, $decision, $adminId, $remark)) {
            $verb = ($decision === 'accept') ? 'accepted' : 'rejected';
            abstract_swal_page('success', 'Decision Recorded', "Abstract {$verb}.", 'view-abstract-submission.php?id=' . base64_encode($id));
            exit;
        } else {
            abstract_swal_flash('warning', 'Not Available', 'This abstract is no longer awaiting a decision.');
        }
    } elseif ($action === 'send_back') {
        if ($remark === '') {
            abstract_swal_flash('error', 'Reason Required', 'Please provide a reason for sending back.');
        } else {
            $stmt = $db->prepare("UPDATE abstract_submissions
                SET status = 'sent_back', organizer_id = ?, sent_back_reason = ?, organizer_decided_at = NOW()
                WHERE id = ?");
            $stmt->bind_param("isi", $adminId, $remark, $id);
            $stmt->execute();
            $stmt->close();
            abstract_log($db, $id, 'organizer', $adminId, $adminName, 'sent_back', $remark);

            $fresh = abstract_fetch_submission_full($db, $id);
            $reviewerId = (int) ($fresh['reviewer_id'] ?? 0);
            $notify = ['reason' => $remark];
            if ($reviewerId > 0) {
                $notify['to'] = abstract_admin_email($db, $reviewerId);
                $notify['to_name'] = abstract_admin_name($db, $reviewerId);
            }
            abstract_notify($db, 'sent_back', $fresh, $notify);

            abstract_swal_page('success', 'Sent Back', 'Abstract sent back to reviewer for correction/re-review.', 'view-abstract-submission.php?id=' . base64_encode($id));
            exit;
        }
    } elseif ($action === 'assign') {
        $reviewerId = (int) ($_POST['reviewer_id'] ?? 0);
        $stmt = $db->prepare("UPDATE abstract_submissions SET reviewer_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $reviewerId, $id);
        $stmt->execute();
        $stmt->close();
        abstract_log($db, $id, 'organizer', $adminId, $adminName, 'reviewer_assigned', 'Reviewer ID: ' . $reviewerId);
        abstract_swal_page('success', 'Reviewer Updated', 'Reviewer updated.', 'view-abstract-submission.php?id=' . base64_encode($id));
        exit;
    }
}

$submission = abstract_fetch_submission_full($db, $id);

$reviewers = mysqli_query($db, "SELECT a._id, a.username
                                FROM admin a
                                INNER JOIN roles r ON a.admin_role = r.role_id
                                WHERE LOWER(r.role_name) = 'reviewer'
                                ORDER BY a.username ASC");

$logsStmt = $db->prepare("SELECT * FROM abstract_logs WHERE submission_id = ? ORDER BY id DESC");
$logsStmt->bind_param("i", $id);
$logsStmt->execute();
$logs = $logsStmt->get_result();
$logsStmt->close();

$query = "SELECT * FROM login_settings";
$settings = mysqli_fetch_assoc(mysqli_query($db, $query));
$favicon = $settings['favicon'];

$lbl = abstract_status_label($submission['status']);
$canDecide = in_array($submission['status'], ['reviewer_accepted']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Abstract Submission</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
    <style>
        .table-bordered td,
        .table-bordered th {
            word-wrap: break-word;
            white-space: break-spaces;
        }
    </style>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <?php include("header.php"); ?>
    <?php include("navbar.php"); ?>

    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Abstract Submission</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Abstract Details</h5>
                            <span class="<?php echo $lbl['class']; ?>"><?php echo $lbl['label']; ?></span>
                        </div>
                        <div class="card-body">
                            <?php echo $msg; ?>
                            <table class="table table-bordered">
                                <tr>
                                    <th style="width:200px;">Title</th>
                                    <td><?php echo htmlspecialchars($submission['title']); ?></td>
                                </tr>
                                <tr>
                                    <th>Author</th>
                                    <td><?php echo htmlspecialchars(trim($submission['author_name'])); ?> (<?php echo htmlspecialchars($submission['author_email']); ?>)</td>
                                </tr>
                                <tr>
                                    <th>Category</th>
                                    <td><?php echo htmlspecialchars($submission['category_name'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <th>Theme</th>
                                    <td><?php echo htmlspecialchars($submission['theme_name'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <th>Presenting Author</th>
                                    <td><?php echo htmlspecialchars($submission['presenting_author'] ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <th>Co-Authors</th>
                                    <td><?php echo nl2br(htmlspecialchars($submission['co_authors'] ?? '-')); ?></td>
                                </tr>
                                <tr>
                                    <th>Keywords</th>
                                    <td><?php echo htmlspecialchars($submission['keywords'] ?: '-'); ?></td>
                                </tr>
                                <tr>
                                    <th>Background</th>
                                    <td><?php echo nl2br(htmlspecialchars($submission['background'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Reviewer Score</th>
                                    <td><?php echo ($submission['reviewer_score'] !== null) ? (int)$submission['reviewer_score'] . ' / 10' : '-'; ?></td>
                                </tr>
                                <tr>
                                    <th>Reviewer Remark</th>
                                    <td><?php echo nl2br(htmlspecialchars($submission['reviewer_remark'] ?? '-')); ?></td>
                                </tr>
                                <?php if (!empty($submission['sent_back_reason'])) { ?>
                                    <tr>
                                        <th>Sent-Back Reason (to Reviewer)</th>
                                        <td><?php echo nl2br(htmlspecialchars($submission['sent_back_reason'])); ?></td>
                                    </tr>
                                <?php } ?>
                                <?php if (!empty($submission['file_path'])) { ?>
                                    <tr>
                                        <th>Attached File</th>
                                        <td>
                                            <a href="../apapuindia2027/uploads/abstracts/<?php echo htmlspecialchars($submission['file_path']); ?>" target="_blank">View / Download</a>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </table>
                            <a href="edit-abstract-submission.php?id=<?php echo base64_encode($id); ?>" class="btn btn-warning">
                                <i class="feather icon-edit"></i> Edit Content
                            </a>
                            <?php if ($isAdmin) { ?>
                                <a href="delete-abstract-submission.php?id=<?php echo base64_encode($id); ?>" class="btn btn-danger"
                                    onclick="return confirmDeleteAbstract(event, this.getAttribute('href'));">
                                    <i class="feather icon-trash"></i> Delete
                                </a>
                            <?php } ?>
                            <a href="manage-abstract-submissions.php" class="btn btn-light">Back</a>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Activity Log</h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>By</th>
                                        <th>Action</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $hasLog = false;
                                    while ($log = $logs->fetch_assoc()) {
                                        $hasLog = true; ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                            <td><?php echo htmlspecialchars($log['actor_name'] ?: ucfirst($log['actor_type'])); ?><br><small><?php echo htmlspecialchars($log['actor_type']); ?></small></td>
                                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['action']))); ?></td>
                                            <td><?php echo nl2br(htmlspecialchars($log['remarks'] ?? '')); ?></td>
                                        </tr>
                                    <?php }
                                    if (!$hasLog) { ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">No activity yet.</td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Assign Reviewer</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <input type="hidden" name="action" value="assign">
                                <div class="form-group">
                                    <label>Current Reviewer</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($submission['reviewer_id'] ? 'ID ' . (int)$submission['reviewer_id'] : 'Unassigned'); ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label>Reviewer</label>
                                    <select name="reviewer_id" class="form-control">
                                        <option value="0">Unassigned</option>
                                        <?php
                                        mysqli_data_seek($reviewers, 0);
                                        while ($rv = mysqli_fetch_assoc($reviewers)) { ?>
                                            <option value="<?php echo (int)$rv['_id']; ?>" <?php echo ((int)$submission['reviewer_id'] === (int)$rv['_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($rv['username']); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <button type="submit" name="submit" class="btn btn-secondary btn-block">
                                    <i class="feather icon-user-check"></i> Update Reviewer
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Organizer Decision</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($canDecide) { ?>
                                <form method="post" action="">
                                    <div class="form-group">
                                        <label>Remarks</label>
                                        <textarea name="remark" class="form-control" rows="4" placeholder="Optional for accept, required to reject / send back"></textarea>
                                    </div>
                                    <input type="hidden" name="action" id="org_action" value="">
                                    <button type="submit" name="submit" class="btn btn-success btn-block"
                                        onclick="document.getElementById('org_action').value='accept';">
                                        <i class="feather icon-check"></i> Accept
                                    </button>
                                    <button type="submit" name="submit" class="btn btn-danger btn-block mt-2"
                                        onclick="return confirmReject(event);">
                                        <i class="feather icon-x"></i> Reject
                                    </button>
                                    <button type="submit" name="submit" class="btn btn-warning btn-block mt-2"
                                        onclick="document.getElementById('org_action').value='send_back';">
                                        <i class="feather icon-corner-up-left"></i> Send Back to Reviewer
                                    </button>
                                </form>
                            <?php } else { ?>
                                <p class="text-muted mb-2">Decision already recorded or not yet ready.</p>
                                <table class="table table-sm">
                                    <tr>
                                        <th>Decision</th>
                                        <td><?php echo htmlspecialchars($lbl['label']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Organizer Remark</th>
                                        <td><?php echo nl2br(htmlspecialchars($submission['organizer_remark'] ?? '-')); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Decided At</th>
                                        <td><?php echo htmlspecialchars($submission['organizer_decided_at'] ?? '-'); ?></td>
                                    </tr>
                                </table>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <?php echo abstract_swal_js(); abstract_swal_render(); ?>
    <script>
        function confirmDeleteAbstract(e, url) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Delete this abstract?',
                text: 'This cannot be undone.',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Delete'
            }).then(function (result) {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
            return false;
        }

        function confirmReject(e) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Reject this abstract?',
                text: 'The author will be notified.',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Reject'
            }).then(function (result) {
                if (result.isConfirmed) {
                    document.getElementById('org_action').value = 'reject';
                    e.target.form.submit();
                }
            });
            return false;
        }
    </script>

</body>

</html>