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

$pendingSql = "SELECT s.id, s.title, s.status, s.created_at, s.reviewer_score,
                      c.name AS category_name, t.name AS theme_name,
                      CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name
               FROM abstract_submissions s
               LEFT JOIN abstract_category c ON c.id = s.category_id
               LEFT JOIN abstract_theme t ON t.id = s.theme_id
               LEFT JOIN registered_users u ON u.student_id = s.user_id
               WHERE s.reviewer_id = ? AND s.status IN ('submitted','resubmitted','sent_to_reviewer')
               ORDER BY s.id ASC";
$stmt = $db->prepare($pendingSql);
$stmt->bind_param("i", $adminId);
$stmt->execute();
$pending = $stmt->get_result();
$stmt->close();


$doneSql = "SELECT s.id, s.title, s.status, s.reviewer_score, s.reviewed_at,
                   c.name AS category_name, t.name AS theme_name
            FROM abstract_submissions s
            LEFT JOIN abstract_category c ON c.id = s.category_id
            LEFT JOIN abstract_theme t ON t.id = s.theme_id
            WHERE s.reviewer_id = ?
              AND s.status IN ('reviewer_accepted','reviewer_rejected','organizer_accepted','organizer_rejected','sent_back')
            ORDER BY COALESCE(s.organizer_decided_at, s.reviewed_at) DESC, s.id DESC";
$stmt = $db->prepare($doneSql);
$stmt->bind_param("i", $adminId);
$stmt->execute();
$done = $stmt->get_result();
$stmt->close();

$query = "SELECT * FROM login_settings";
$settings = mysqli_fetch_assoc(mysqli_query($db, $query));
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>My Review Queue</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
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
                                <h5 class="m-b-10">My Review Queue</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header">
                            <h5>Pending Review</h5>
                        </div>
                        <div class="card-body">
                            <div class="dt-responsive table-responsive">
                                <table class="table table-striped table-bordered nowrap">
                                    <thead>
                                        <tr>
                                            <th>SNO</th>
                                            <th>TITLE</th>
                                            <th>AUTHOR</th>
                                            <th>CATEGORY</th>
                                            <th>THEME</th>
                                            <!-- <th>STATUS</th> -->
                                            <th>SUBMITTED</th>
                                            <th>ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1;
                                        $hasPending = false;
                                        while ($row = $pending->fetch_assoc()) {
                                            $hasPending = true;
                                            $lbl = abstract_status_label($row['status']); ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                <td><?php echo htmlspecialchars(trim($row['author_name'])); ?></td>
                                                <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($row['theme_name'] ?? '-'); ?></td>
                                                <!-- <td><span class="<?php echo $lbl['class']; ?>"><?php echo $lbl['label']; ?></span></td> -->
                                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                                <td style="white-space:nowrap">
                                                    <a href="review-abstract.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-primary btn-sm" title="Review this abstract" style="padding:.25rem .55rem;font-size:.78rem;line-height:1.4">
                                                        <i class="feather icon-check-circle" style="font-size:.82rem;vertical-align:-2px"></i> Review
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php }
                                        if (!$hasPending) { ?>
                                            <tr>
                                                <td colspan="8" class="text-center">No abstracts pending review.</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5>All Reviewed</h5>
                        </div>
                        <div class="card-body">
                            <div class="dt-responsive table-responsive">
                                <table class="table table-striped table-bordered nowrap">
                                    <thead>
                                        <tr>
                                            <th>SNO</th>
                                            <th>TITLE</th>
                                            <th>CATEGORY</th>
                                            <th>THEME</th>
                                            <th>SCORE</th>
                                            <th>DECISION</th>
                                            <th>REVIEWED AT</th>
                                            <th>ACTION</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $j = 1;
                                        $hasDone = false;
                                        while ($row = $done->fetch_assoc()) {
                                            $hasDone = true;
                                            $lbl = abstract_status_label($row['status']); ?>
                                            <tr>
                                                <td><?php echo $j++; ?></td>
                                                <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($row['theme_name'] ?? '-'); ?></td>
                                                <td><?php echo ($row['reviewer_score'] !== null) ? (int)$row['reviewer_score'] . ' / 10' : '-'; ?></td>
                                                <td><span class="<?php echo $lbl['class']; ?>"><?php echo $lbl['label']; ?></span></td>
                                                <td><?php echo htmlspecialchars($row['reviewed_at'] ?? '-'); ?></td>
                                                <td style="white-space:nowrap">
                                                    <a href="review-abstract.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-info btn-sm" title="View this abstract" style="padding:.25rem .55rem;font-size:.78rem;line-height:1.4">
                                                        <i class="feather icon-eye" style="font-size:.82rem;vertical-align:-2px"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php }
                                        if (!$hasDone) { ?>
                                            <tr>
                                                <td colspan="8" class="text-center">No reviews completed yet.</td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="assets/js/plugins/jquery.dataTables.min.js"></script>
    <script src="assets/js/plugins/dataTables.bootstrap4.min.js"></script>
    <script>
        $(document).ready(function() {
            $('table.table').DataTable({
                pageLength: 25,
                order: []
            });
        });
    </script>

</body>

</html>
