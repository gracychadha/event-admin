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

if (!abstract_can('My Review Queue')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to access this page.', 'dashboard.php');
}

$id = isset($_GET['id']) ? (int) base64_decode($_GET['id']) : 0;
if ($id <= 0) {
    header("location: review-abstracts.php");
    exit;
}

$submission = abstract_fetch_submission_full($db, $id);
if (!$submission) {
    abstract_swal_page('error', 'Not Found', 'Abstract not found.', 'review-abstracts.php');
}

// A reviewer may only review abstracts assigned to them; admin may review any.
if (!$isAdmin && (int) $submission['reviewer_id'] !== (int) $adminId) {
    abstract_swal_page('error', 'Not Assigned', 'This abstract is not assigned to you.', 'review-abstracts.php');
}

$msg = "";

if (isset($_POST['submit'])) {
    $decision = $_POST['decision'] ?? '';
    $score    = isset($_POST['score']) && $_POST['score'] !== '' ? (int) $_POST['score'] : null;
    $remark   = trim($_POST['remark'] ?? '');

    if ($decision === 'accept') {
        if ($score === null || $score < 0 || $score > 10) {
            abstract_swal_flash('error', 'Invalid Score', 'Please enter a valid score between 0 and 10.');
        } else {
            $stmt = $db->prepare("UPDATE abstract_submissions
                SET status = 'reviewer_accepted', reviewer_id = ?, reviewer_score = ?, reviewer_remark = ?, reviewed_at = NOW()
                WHERE id = ?");
            $stmt->bind_param("iisi", $adminId, $score, $remark, $id);
            $stmt->execute();
            $stmt->close();

            abstract_log($db, $id, 'reviewer', $adminId, abstract_admin_name($db, $adminId), 'reviewer_accepted', $remark);

            $fresh = abstract_fetch_submission_full($db, $id);
            abstract_notify($db, 'reviewer_accepted', $fresh, ['score' => $score]);

            abstract_swal_page('success', 'Accepted', "Abstract accepted with score {$score}/10.", 'review-abstracts.php');
            exit;
        }
    } elseif ($decision === 'reject') {
        if ($remark === '') {
            abstract_swal_flash('error', 'Remark Required', 'Please provide a remark when rejecting.');
        } else {
            $stmt = $db->prepare("UPDATE abstract_submissions
                SET status = 'reviewer_rejected', reviewer_id = ?, reviewer_remark = ?, reviewed_at = NOW()
                WHERE id = ?");
            $stmt->bind_param("isi", $adminId, $remark, $id);
            $stmt->execute();
            $stmt->close();

            abstract_log($db, $id, 'reviewer', $adminId, abstract_admin_name($db, $adminId), 'reviewer_rejected', $remark);

            $fresh = abstract_fetch_submission_full($db, $id);
            abstract_notify($db, 'reviewer_rejected', $fresh, ['remark' => $remark]);

            abstract_swal_page('success', 'Rejected', 'Abstract rejected. Author has been notified.', 'review-abstracts.php');
            exit;
        }
    }
}

$submission = abstract_fetch_submission_full($db, $id);
$lbl = abstract_status_label($submission['status']);

$query = "SELECT * FROM login_settings";
$settings = mysqli_fetch_assoc(mysqli_query($db, $query));
$favicon = $settings['favicon'];

$readonly = !in_array($submission['status'], ['submitted', 'resubmitted', 'sent_to_reviewer']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Review Abstract</title>
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
                                <h5 class="m-b-10">Review Abstract</h5>
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
                            <a href="review-abstracts.php" class="btn btn-light">Back to Queue</a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Review Decision</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($readonly) { ?>
                                <p class="text-muted">This abstract has already been reviewed.</p>
                                <table class="table table-sm">
                                    <tr>
                                        <th>Score</th>
                                        <td><?php echo ($submission['reviewer_score'] !== null) ? (int)$submission['reviewer_score'] . ' / 10' : '-'; ?></td>
                                    </tr>
                                    <tr>
                                        <th>Remark</th>
                                        <td><?php echo nl2br(htmlspecialchars($submission['reviewer_remark'] ?? '-')); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Reviewed At</th>
                                        <td><?php echo htmlspecialchars($submission['reviewed_at'] ?? '-'); ?></td>
                                    </tr>
                                </table>
                            <?php } else { ?>
                                <form method="post" action="">
                                    <div class="form-group">
                                        <label>Score (out of 10) <span style="color:red">*</span></label>
                                        <input type="number" name="score" class="form-control" min="0" max="10" placeholder="0 - 10">
                                        <small class="text-muted">Required when accepting.</small>
                                    </div>
                                    <div class="form-group">
                                        <label>Remark</label>
                                        <textarea name="remark" class="form-control" rows="4" placeholder="Remarks for the author / organizers"></textarea>
                                        <small class="text-muted">Required when rejecting.</small>
                                    </div>
                                    <input type="hidden" name="decision" id="decision" value="">
                                    <button type="submit" name="submit" class="btn btn-success btn-block"
                                        onclick="document.getElementById('decision').value='accept';">
                                        <i class="feather icon-check"></i> Accept
                                    </button>
                                    <button type="submit" name="submit" class="btn btn-danger btn-block mt-2"
                                        onclick="document.getElementById('decision').value='reject';">
                                        <i class="feather icon-x"></i> Reject
                                    </button>
                                </form>
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

</body>

</html>