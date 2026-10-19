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

$redirect = "manage-abstract-submissions.php?tab=needs_decision";

// ------------------------------------------------------------------
// Handle organizer decisions (inline single + bulk)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abstract_action'])) {

    if (!($isAdmin || $isOrganizer)) {
        abstract_swal_page('error', 'Not Allowed', 'Only organizers can record a decision.', 'manage-abstract-submissions.php');
    }

    if (!abstract_csrf_check($_POST['csrf'] ?? '')) {
        abstract_swal_page('warning', 'Session Expired', 'Your session token expired. Please try again.', 'manage-abstract-submissions.php');
    }

    $decision = (($_POST['decision'] ?? '') === 'accept') ? 'accept' : 'decline';
    $remark   = trim($_POST['remark'] ?? '');

    if ($decision === 'decline' && $remark === '') {
        abstract_swal_page('warning', 'Remark Required', 'Please provide a remark when declining.', $redirect);
    }

    if (($_POST['abstract_action'] ?? '') === 'bulk') {
        $ids = array_filter(array_map(function ($value) {
            return (int) base64_decode($value);
        }, (array) ($_POST['ids'] ?? [])));

        if (!$ids) {
            abstract_swal_page('warning', 'No Selection', 'No abstracts selected.', $redirect);
        }

        $res   = abstract_bulk_decide($db, $ids, $decision, $adminId, $remark);
        $label = ($decision === 'accept') ? 'accepted' : 'declined';
        $note  = $res['processed'] . " abstract(s) {$label}."
               . ($res['skipped'] ? " {$res['skipped']} skipped (already decided)." : '');
        abstract_swal_page($res['processed'] > 0 ? 'success' : 'warning', 'Bulk Decision', $note, $redirect);
    }

    // Single inline decision
    $id = (int) base64_decode($_POST['single_id'] ?? '');
    if (abstract_decide_submission($db, $id, $decision, $adminId, $remark)) {
        $label = ($decision === 'accept') ? 'accepted' : 'declined';
        abstract_swal_page('success', 'Decision Recorded', "Abstract {$label}.", $redirect);
    } else {
        abstract_swal_page('warning', 'Not Available', 'This abstract is no longer awaiting a decision.', $redirect);
    }
}

// ------------------------------------------------------------------
// Filters
// ------------------------------------------------------------------
$categoryFilter = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$themeFilter    = isset($_GET['theme']) ? (int) $_GET['theme'] : 0;
$search         = trim($_GET['search'] ?? '');
$activeTab      = $_GET['tab'] ?? 'needs_decision';
if (!in_array($activeTab, ['needs_decision', 'accepted', 'others'], true)) {
    $activeTab = 'needs_decision';
}

$where  = [];
$params = [];
$types  = '';

if ($categoryFilter > 0) {
    $where[]  = "s.category_id = ?";
    $params[] = $categoryFilter;
    $types   .= 'i';
}
if ($themeFilter > 0) {
    $where[]  = "s.theme_id = ?";
    $params[] = $themeFilter;
    $types   .= 'i';
}
if ($search !== '') {
    $where[] = "(s.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($isOrganizer && !$isAdmin) {
    $where[] = "s.status NOT IN ('submitted','resubmitted')";
}

$sql = "SELECT s.id, s.title, s.status, s.created_at, s.reviewer_score, s.reviewer_id,
               s.reviewer_remark, s.organizer_decided_at,
               c.name AS category_name, t.name AS theme_name,
               CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS author_name,
               u.email AS author_email,
               a.username AS reviewer_name
        FROM abstract_submissions s
        LEFT JOIN abstract_category c ON c.id = s.category_id
        LEFT JOIN abstract_theme t ON t.id = s.theme_id
        LEFT JOIN registered_users u ON u.student_id = s.user_id
        LEFT JOIN admin a ON a._id = s.reviewer_id";
if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.id DESC";

$stmt = $db->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

$needsDecision = [];
$acceptedRows  = [];
$otherRows     = [];
while ($row = $rows->fetch_assoc()) {
    if ($row['status'] === 'reviewer_accepted') {
        $needsDecision[] = $row;
    } elseif ($row['status'] === 'organizer_accepted') {
        $acceptedRows[] = $row;
    } else {
        $otherRows[] = $row;
    }
}

$categories = mysqli_query($db, "SELECT id, name FROM abstract_category ORDER BY name ASC");
$themes     = mysqli_query($db, "SELECT id, name FROM abstract_theme ORDER BY name ASC");

$query = "SELECT * FROM login_settings";
$settings = mysqli_fetch_assoc(mysqli_query($db, $query));
$favicon = $settings['favicon'];

$csrf = abstract_csrf_token();

function abstract_reviewer_cell($row)
{
    return htmlspecialchars($row['reviewer_name'] ?? 'Unassigned');
}
function abstract_score_cell($row)
{
    return ($row['reviewer_score'] !== null) ? (int) $row['reviewer_score'] . '/10' : '-';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Abstract Submissions</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
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
                                <h5 class="m-b-10">Abstract Submissions</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header">
                            <form method="get" action="" class="row">
                                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                                <div class="col-md-4 mb-2">
                                    <select name="category" class="form-control">
                                        <option value="0">All Categories</option>
                                        <?php while ($c = mysqli_fetch_assoc($categories)) { ?>
                                            <option value="<?php echo (int)$c['id']; ?>" <?php echo ($categoryFilter === (int)$c['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($c['name']); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <select name="theme" class="form-control">
                                        <option value="0">All Themes</option>
                                        <?php while ($t = mysqli_fetch_assoc($themes)) { ?>
                                            <option value="<?php echo (int)$t['id']; ?>" <?php echo ($themeFilter === (int)$t['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['name']); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" name="search" class="form-control" placeholder="Search title/author/email" value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-secondary"><i class="feather icon-search"></i> Filter</button>
                                    <a href="manage-abstract-submissions.php" class="btn btn-light">Reset</a>
                                </div>
                            </form>
                        </div>

                        <div class="card-body">
                            <ul class="nav nav-tabs mb-3" id="abstractTabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $activeTab === 'needs_decision' ? 'active' : ''; ?>" data-toggle="tab" href="#pane-needs" role="tab">
                                        Needs Decision <span class="badge badge-warning"><?php echo count($needsDecision); ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $activeTab === 'accepted' ? 'active' : ''; ?>" data-toggle="tab" href="#pane-accepted" role="tab">
                                        Accepted <span class="badge badge-success"><?php echo count($acceptedRows); ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link <?php echo $activeTab === 'others' ? 'active' : ''; ?>" data-toggle="tab" href="#pane-others" role="tab">
                                        Others <span class="badge badge-secondary"><?php echo count($otherRows); ?></span>
                                    </a>
                                </li>
                            </ul>

                            <div class="tab-content">
                                <!-- ================= Needs Decision ================= -->
                                <div class="tab-pane fade <?php echo $activeTab === 'needs_decision' ? 'show active' : ''; ?>" id="pane-needs" role="tabpanel">
                                    <?php if (!($isAdmin || $isOrganizer)) { ?>
                                        <div class="alert alert-info">Only organizers can record a decision.</div>
                                    <?php } ?>

                                    <form method="post" id="decisionForm" action="">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                        <input type="hidden" name="abstract_action" value="bulk">
                                        <input type="hidden" name="decision" value="">
                                        <input type="hidden" name="single_id" value="">
                                        <input type="hidden" name="remark" value="">

                                        <?php if ($isAdmin || $isOrganizer) { ?>
                                            <div class="mb-2">
                                                <button type="button" class="btn btn-success btn-sm" onclick="bulkDecide('accept')">
                                                    <i class="feather icon-check"></i> Approve Selected
                                                </button>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="bulkDecide('decline')">
                                                    <i class="feather icon-x"></i> Decline Selected
                                                </button>
                                                <span id="selCount" class="ml-2 text-muted">0 selected</span>
                                            </div>
                                        <?php } ?>

                                        <div class="dt-responsive table-responsive">
                                            <table class="table table-striped table-bordered nowrap">
                                                <thead>
                                                    <tr>
                                                        <th style="width:36px;"><input type="checkbox" id="checkAll"></th>
                                                        <th>SNO</th>
                                                        <th>TITLE</th>
                                                        <th>AUTHOR</th>
                                                        <th>CATEGORY</th>
                                                        <th>THEME</th>
                                                        <th>REVIEWER</th>
                                                        <th>SCORE</th>
                                                        <th>REVIEWER REMARK</th>
                                                        <th>ACTION</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (!$needsDecision) { ?>
                                                        <tr><td colspan="10" class="text-center text-muted">No abstracts awaiting a decision.</td></tr>
                                                    <?php } else { $i = 1; foreach ($needsDecision as $row) { ?>
                                                        <tr>
                                                            <td><input type="checkbox" class="row-check" name="ids[]" value="<?php echo base64_encode($row['id']); ?>"></td>
                                                            <td><?php echo $i++; ?></td>
                                                            <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                            <td><?php echo htmlspecialchars(trim($row['author_name'])); ?><br><small><?php echo htmlspecialchars($row['author_email']); ?></small></td>
                                                            <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                                                            <td><?php echo htmlspecialchars($row['theme_name'] ?? '-'); ?></td>
                                                            <td><?php echo abstract_reviewer_cell($row); ?></td>
                                                            <td><?php echo abstract_score_cell($row); ?></td>
                                                            <td><?php echo $row['reviewer_remark'] ? nl2br(htmlspecialchars($row['reviewer_remark'])) : '-'; ?></td>
                                                            <td>
                                                                <?php if ($isAdmin || $isOrganizer) { ?>
                                                                    <button type="button" class="btn btn-success btn-sm" onclick="decideOne('<?php echo base64_encode($row['id']); ?>','accept')">
                                                                        <i class="feather icon-check"></i> Accept
                                                                    </button>
                                                                    <button type="button" class="btn btn-danger btn-sm" onclick="decideOne('<?php echo base64_encode($row['id']); ?>','decline')">
                                                                        <i class="feather icon-x"></i> Decline
                                                                    </button>
                                                                <?php } ?>
                                                                <a href="view-abstract-submission.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-primary btn-sm">
                                                                    <i class="feather icon-eye"></i> View
                                                                </a>
                                                                <?php if ($isAdmin) { ?>
                                                                <a href="delete-abstract-submission.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-danger btn-sm"
                                                                   onclick="return confirmDeleteAbstract(event, this.getAttribute('href'));">
                                                                    <i class="feather icon-trash"></i> Delete
                                                                </a>
                                                                <?php } ?>
                                                            </td>
                                                        </tr>
                                                    <?php } } ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </form>
                                </div>

                                <!-- ================= Accepted ================= -->
                                <div class="tab-pane fade <?php echo $activeTab === 'accepted' ? 'show active' : ''; ?>" id="pane-accepted" role="tabpanel">
                                    <div class="dt-responsive table-responsive">
                                        <table id="acceptedTable" class="table table-striped table-bordered nowrap">
                                            <thead>
                                                <tr>
                                                    <th>SNO</th>
                                                    <th>TITLE</th>
                                                    <th>AUTHOR</th>
                                                    <th>CATEGORY</th>
                                                    <th>THEME</th>
                                                    <th>REVIEWER</th>
                                                    <th>SCORE</th>
                                                    <!-- <th>DECIDED AT</th> -->
                                                    <th>ACTION</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $i = 1; foreach ($acceptedRows as $row) { ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                        <td><?php echo htmlspecialchars(trim($row['author_name'])); ?><br><small><?php echo htmlspecialchars($row['author_email']); ?></small></td>
                                                        <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['theme_name'] ?? '-'); ?></td>
                                                        <td><?php echo abstract_reviewer_cell($row); ?></td>
                                                        <td><?php echo abstract_score_cell($row); ?></td>
                                                        <!-- <td><?php echo htmlspecialchars($row['organizer_decided_at'] ?? '-'); ?></td> -->
                                                        <td>
                                                            <a href="view-abstract-submission.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-primary btn-sm">
                                                                <i class="feather icon-eye"></i> View
                                                            </a>
                                                            <?php if ($isAdmin) { ?>
                                                                <a href="delete-abstract-submission.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-danger btn-sm"
                                                                   onclick="return confirmDeleteAbstract(event, this.getAttribute('href'));">
                                                                    <i class="feather icon-trash"></i> Delete
                                                                </a>
                                                            <?php } ?>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- ================= Others ================= -->
                                <div class="tab-pane fade <?php echo $activeTab === 'others' ? 'show active' : ''; ?>" id="pane-others" role="tabpanel">
                                    <div class="dt-responsive table-responsive">
                                        <table id="othersTable" class="table table-striped table-bordered nowrap">
                                            <thead>
                                                <tr>
                                                    <th>SNO</th>
                                                    <th>TITLE</th>
                                                    <th>AUTHOR</th>
                                                    <th>CATEGORY</th>
                                                    <th>THEME</th>
                                                    <th>REVIEWER</th>
                                                    <th>SCORE</th>
                                                    <th>STATUS</th>
                                                    <th>ACTION</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $i = 1; foreach ($otherRows as $row) {
                                                    $lbl = abstract_status_label($row['status']); ?>
                                                    <tr>
                                                        <td><?php echo $i++; ?></td>
                                                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                                                        <td><?php echo htmlspecialchars(trim($row['author_name'])); ?><br><small><?php echo htmlspecialchars($row['author_email']); ?></small></td>
                                                        <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                                                        <td><?php echo htmlspecialchars($row['theme_name'] ?? '-'); ?></td>
                                                        <td><?php echo abstract_reviewer_cell($row); ?></td>
                                                        <td><?php echo abstract_score_cell($row); ?></td>
                                                        <td><span class="<?php echo $lbl['class']; ?>"><?php echo $lbl['label']; ?></span></td>
                                                        <td>
                                                            <a href="view-abstract-submission.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-primary btn-sm">
                                                                <i class="feather icon-eye"></i> View
                                                            </a>
                                                            <?php if ($isAdmin) { ?>
                                                                <a href="delete-abstract-submission.php?id=<?php echo base64_encode($row['id']); ?>" class="btn btn-danger btn-sm"
                                                                   onclick="return confirmDeleteAbstract(event, this.getAttribute('href'));">
                                                                    <i class="feather icon-trash"></i> Delete
                                                                </a>
                                                            <?php } ?>
                                                        </td>
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
            </div>
        </div>
    </section>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="assets/js/plugins/jquery.dataTables.min.js"></script>
    <script src="assets/js/plugins/dataTables.bootstrap4.min.js"></script>
    <script src="assets/js/plugins/buttons.colVis.min.js"></script>
    <script src="assets/js/plugins/buttons.print.min.js"></script>
    <script src="assets/js/plugins/pdfmake.min.js"></script>
    <script src="assets/js/plugins/vfs_fonts.js"></script>
    <script src="assets/js/plugins/jszip.min.js"></script>
    <script src="assets/js/plugins/dataTables.buttons.min.js"></script>
    <script src="assets/js/plugins/buttons.html5.min.js"></script>
    <script src="assets/js/plugins/buttons.bootstrap4.min.js"></script>
    <script src="assets/js/pages/data-export-custom.js"></script>
    <?php echo abstract_swal_js(); ?>

    <script>
        function updateSelCount() {
            document.getElementById('selCount').textContent =
                document.querySelectorAll('.row-check:checked').length + ' selected';
        }

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

        function submitDecision(abstractAction, decision, singleId, remark) {
            var f = document.getElementById('decisionForm');
            f.elements['abstract_action'].value = abstractAction;
            f.elements['decision'].value = decision;
            f.elements['single_id'].value = singleId;
            f.elements['remark'].value = remark;
            f.submit();
        }

        function askDeclineRemark(title, onConfirm) {
            Swal.fire({
                icon: 'question',
                title: title,
                input: 'textarea',
                inputPlaceholder: 'Reason for declining...',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Decline',
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return 'A remark is required to decline.';
                    }
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    onConfirm(result.value.trim());
                }
            });
        }

        function decideOne(idB64, decision) {
            if (decision === 'decline') {
                askDeclineRemark('Decline this abstract?', function (remark) {
                    submitDecision('single', 'decline', idB64, remark);
                });
            } else {
                submitDecision('single', 'accept', idB64, '');
            }
        }

        function bulkDecide(decision) {
            var checked = document.querySelectorAll('.row-check:checked');
            if (!checked.length) {
                Swal.fire({ icon: 'warning', title: 'No Selection', text: 'Select at least one abstract.', confirmButtonColor: '#ffc107' });
                return;
            }
            if (decision === 'decline') {
                askDeclineRemark('Decline the selected abstract(s)?', function (remark) {
                    submitDecision('bulk', 'decline', '', remark);
                });
            } else {
                submitDecision('bulk', 'accept', '', '');
            }
        }

        $(document).ready(function () {
            var checkAll = document.getElementById('checkAll');
            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = checkAll.checked; });
                    updateSelCount();
                });
                document.querySelectorAll('.row-check').forEach(function (cb) {
                    cb.addEventListener('change', updateSelCount);
                });
            }

            function initExportTable(selector) {
                if (!$(selector).length) return;
                $(selector).DataTable({
                    dom: 'Bfrtip',
                    buttons: [
                        { extend: 'excelHtml5', text: '<i class="feather icon-file-text"></i> Export Excel', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } },
                        { extend: 'pdfHtml5', text: '<i class="feather icon-file"></i> Export PDF', className: 'btn btn-secondary btn-sm', orientation: 'landscape', pageSize: 'A4', exportOptions: { columns: ':not(:last)' } },
                        { extend: 'copyHtml5', text: 'Copy', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } },
                        { extend: 'csvHtml5', text: 'CSV', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } },
                        { extend: 'print', text: 'Print', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } }
                    ]
                });
            }

            initExportTable('#acceptedTable');
            initExportTable('#othersTable');

            // Keep filters scoped to the tab the user is currently viewing.
            var tabMap = { '#pane-needs': 'needs_decision', '#pane-accepted': 'accepted', '#pane-others': 'others' };
            $('#abstractTabs a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                var key = tabMap[$(e.target).attr('href')];
                var input = document.querySelector('input[name="tab"]');
                if (key && input) input.value = key;
            });
        });
    </script>

</body>

</html>
