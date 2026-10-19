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

if (!abstract_can('Reviewer Assignments')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to access this page.', 'dashboard.php');
}

$msg = "";

// ---------- Add assignment ----------
$isAssignPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && !isset($_GET['del'])
    && ($_POST['mode'] ?? '') !== 'edit'
    && isset($_POST['reviewer_id']);

if ($isAssignPost) {
    $reviewerId = (int) ($_POST['reviewer_id'] ?? 0);
    $categoryIds = isset($_POST['category_ids']) ? array_values(array_filter(array_map('intval', (array) $_POST['category_ids']))) : [];
    $themeIds    = isset($_POST['theme_ids']) ? array_values(array_filter(array_map('intval', (array) $_POST['theme_ids']))) : [];

    if ($reviewerId <= 0 || count($categoryIds) === 0 || count($themeIds) === 0) {
        abstract_swal_flash('error', 'Missing Selection', 'Please select reviewer, at least one category and at least one theme.');
    } else {
        $stmt = $db->prepare("INSERT INTO abstract_reviewer_assignments (reviewer_id, category_id, theme_id, status)
                              VALUES (?, ?, ?, '1')
                              ON DUPLICATE KEY UPDATE status = '1'");
        $done = 0;
        foreach ($categoryIds as $cid) {
            foreach ($themeIds as $tid) {
                $stmt->bind_param("iii", $reviewerId, $cid, $tid);
                if ($stmt->execute()) {
                    $done++;
                }
            }
        }
        $stmt->close();
        if ($done > 0) {
            abstract_swal_flash('success', 'Reviewer Assigned', "Reviewer assigned to $done combination(s) (" . count($categoryIds) . " categor" . (count($categoryIds) === 1 ? 'y' : 'ies') . " × " . count($themeIds) . " theme" . (count($themeIds) === 1 ? '' : 's') . ").");
        } else {
            abstract_swal_flash('error', 'Assignment Failed', 'Failed to assign reviewer.');
        }
    }
    abstract_reroute_pending($db);
}

// ---------- Edit assignment (replace combos for a reviewer) ----------
$isEditPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && !isset($_GET['del'])
    && ($_POST['mode'] ?? '') === 'edit'
    && isset($_POST['reviewer_id']);

if ($isEditPost) {
    $reviewerId = (int) ($_POST['reviewer_id'] ?? 0);
    $categoryIds = isset($_POST['category_ids']) ? array_values(array_filter(array_map('intval', (array) $_POST['category_ids']))) : [];
    $themeIds    = isset($_POST['theme_ids']) ? array_values(array_filter(array_map('intval', (array) $_POST['theme_ids']))) : [];

    if ($reviewerId <= 0 || count($categoryIds) === 0 || count($themeIds) === 0) {
        abstract_swal_flash('error', 'Missing Selection', 'Please select reviewer, at least one category and at least one theme.');
    } else {
        $stmt = $db->prepare("DELETE FROM abstract_reviewer_assignments WHERE reviewer_id = ?");
        $stmt->bind_param("i", $reviewerId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("INSERT INTO abstract_reviewer_assignments (reviewer_id, category_id, theme_id, status)
                              VALUES (?, ?, ?, '1')
                              ON DUPLICATE KEY UPDATE status = '1'");
        $done = 0;
        foreach ($categoryIds as $cid) {
            foreach ($themeIds as $tid) {
                $stmt->bind_param("iii", $reviewerId, $cid, $tid);
                if ($stmt->execute()) {
                    $done++;
                }
            }
        }
        $stmt->close();
        if ($done > 0) {
            abstract_swal_flash('success', 'Assignments Updated', "Reviewer assignments updated to $done combination(s) (" . count($categoryIds) . " categor" . (count($categoryIds) === 1 ? 'y' : 'ies') . " × " . count($themeIds) . " theme" . (count($themeIds) === 1 ? '' : 's') . ").");
        } else {
            abstract_swal_flash('error', 'Update Failed', 'Failed to update reviewer assignments.');
        }
    }
    abstract_reroute_pending($db);
}

// ---------- Delete assignment ----------
if (isset($_GET['del'])) {
    $delId = (int) base64_decode($_GET['del']);
    if ($delId > 0) {
        $stmt = $db->prepare("DELETE FROM abstract_reviewer_assignments WHERE reviewer_id = ?");
        $stmt->bind_param("i", $delId);
        $stmt->execute();
        $stmt->close();
        abstract_reroute_pending($db);
    }
    header("location: manage-reviewer-assignments.php");
    exit;
}

// ---------- Data ----------
$totalAssignments = (int) mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) c FROM abstract_reviewer_assignments"))['c'];
$totalReviewers   = (int) mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(DISTINCT a._id) c FROM admin a INNER JOIN roles r ON a.admin_role = r.role_id WHERE LOWER(r.role_name) = 'reviewer'"))['c'];
$totalCategories  = (int) mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) c FROM abstract_category WHERE status = '1'"))['c'];
$totalThemes      = (int) mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) c FROM abstract_theme WHERE status = '1'"))['c'];

$reviewers = mysqli_query($db, "SELECT a._id, a.username
                                FROM admin a
                                INNER JOIN roles r ON a.admin_role = r.role_id
                                WHERE LOWER(r.role_name) = 'reviewer'
                                ORDER BY a.username ASC");

$categories = mysqli_query($db, "SELECT id, name FROM abstract_category WHERE status = '1' ORDER BY name ASC");
$themes     = mysqli_query($db, "SELECT id, name FROM abstract_theme WHERE status = '1' ORDER BY name ASC");

$assignments = mysqli_query($db, "SELECT aa.reviewer_id,
                                         a.username AS reviewer_name,
                                         COUNT(*) AS combos,
                                         COUNT(DISTINCT aa.category_id) AS cat_count,
                                         COUNT(DISTINCT aa.theme_id) AS theme_count,
                                         GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR '||') AS categories,
                                         GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '||') AS themes,
                                         GROUP_CONCAT(DISTINCT aa.category_id ORDER BY aa.category_id SEPARATOR ',') AS cat_ids,
                                         GROUP_CONCAT(DISTINCT aa.theme_id ORDER BY aa.theme_id SEPARATOR ',') AS theme_ids
                                  FROM abstract_reviewer_assignments aa
                                  LEFT JOIN admin a ON a._id = aa.reviewer_id
                                  LEFT JOIN abstract_category c ON c.id = aa.category_id
                                  LEFT JOIN abstract_theme t ON t.id = aa.theme_id
                                  GROUP BY aa.reviewer_id, a.username
                                  ORDER BY a.username ASC");

$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Reviewer Assignments</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
    <style>
        :root {
            --brand: #6c5ce7;
            --brand-dark: #5547cf;
            --brand-soft: #eef0fe;
            --gold: #b99a1b;
        }

        .stat-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(20, 20, 60, .08);
            overflow: hidden;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 26px rgba(20, 20, 60, .14);
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
        }

        .stat-card .stat-value {
            font-size: 26px;
            font-weight: 700;
            line-height: 1.1;
            color: #2c2f3b;
        }

        .stat-card .stat-label {
            font-size: 13px;
            color: #8a8f9e;
            letter-spacing: .3px;
        }

        .brand-soft {
            background: var(--brand-soft);
        }

        .assign-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(20, 20, 60, .08);
        }

        .assign-card .card-header {
            background: linear-gradient(135deg, var(--brand) 0%, #8e7bf5 100%);
            color: #fff;
            border-radius: 14px 14px 0 0 !important;
            border-bottom: none;
        }

        .assign-card .card-header h5 {
            color: #fff;
        }

        .field-label {
            font-weight: 600;
            font-size: 13px;
            color: #4a4d5a;
            letter-spacing: .2px;
        }

        .combo-summary {
            border-radius: 12px;
            background: var(--brand-soft);
            border: 1px dashed var(--brand);
            padding: 12px 16px;
            font-size: 14px;
            color: #3b3f4f;
            transition: all .2s ease;
        }

        .combo-summary .combo-num {
            color: var(--brand);
            font-weight: 700;
        }

        .combo-summary.invalid {
            background: #fff0f0;
            border-color: #e5533c;
            color: #a93a2a;
        }

        .combo-summary.invalid .combo-num {
            color: #e5533c;
        }

        .btn-assign {
            background: linear-gradient(135deg, var(--brand) 0%, #8e7bf5 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            letter-spacing: .3px;
            padding: 10px 26px;
            border-radius: 10px;
            box-shadow: 0 6px 16px rgba(108, 92, 231, .35);
            transition: all .2s ease;
        }

        .btn-assign:hover,
        .btn-assign:focus {
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 9px 22px rgba(108, 92, 231, .45);
        }

        .btn-assign:disabled {
            opacity: .6;
            transform: none;
            box-shadow: none;
        }

        .reviewer-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .avatar-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--brand-soft);
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
        }

        .tag-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .tag-cat {
            background: #e7f6ee;
            color: #1d9e5c;
        }

        .tag-theme {
            background: #fff5e0;
            color: var(--gold);
        }

        .table-card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(20, 20, 60, .08);
        }

        .table-card .card-header {
            background: transparent;
            border-bottom: 1px solid #f0f1f5;
        }

        .delete-link {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border: 1px solid #e1e3ec;
            border-radius: 10px;
            min-height: 42px;
            padding: 3px 4px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 34px;
            color: #3b3f4f;
            padding-left: 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 2px 10px;
            margin-top: 6px;
            font-size: 12.5px;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #fff;
            margin-right: 5px;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field:focus {
            outline: none;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background: var(--brand);
        }

        .page-subtitle {
            font-size: 13px;
            color: #8a8f9e;
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
                                <h5 class="m-b-10">Reviewer Assignments</h5>
                                <span class="page-subtitle">Map reviewers to categories &amp; themes so abstracts are routed automatically.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- <div class="row">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card">
                        <div class="card-body d-flex align-items-center">
                            <div class="stat-icon mr-3" style="background:linear-gradient(135deg,#6c5ce7,#8e7bf5);">
                                <i class="feather icon-share-2"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $totalAssignments; ?></div>
                                <div class="stat-label">Total Assignments</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card">
                        <div class="card-body d-flex align-items-center">
                            <div class="stat-icon mr-3" style="background:linear-gradient(135deg,#00b894,#55efc4);">
                                <i class="feather icon-user-check"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $totalReviewers; ?></div>
                                <div class="stat-label">Reviewers</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card">
                        <div class="card-body d-flex align-items-center">
                            <div class="stat-icon mr-3" style="background:linear-gradient(135deg,#0984e3,#74b9ff);">
                                <i class="feather icon-layers"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $totalCategories; ?></div>
                                <div class="stat-label">Categories</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="card stat-card">
                        <div class="card-body d-flex align-items-center">
                            <div class="stat-icon mr-3" style="background:linear-gradient(135deg,#e17055,#ffa29a);">
                                <i class="feather icon-tag"></i>
                            </div>
                            <div>
                                <div class="stat-value"><?php echo $totalThemes; ?></div>
                                <div class="stat-label">Themes</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> -->

            <div class="row">
                <div class="col-sm-12">
                    <div class="card assign-card">
                        <div class="card-header py-3">
                            <h5 class="mb-0"><i class="feather icon-user-plus mr-2"></i>Assign Reviewer</h5>
                        </div>
                        <div class="card-body">
                            <?php echo $msg; ?>
                            <form method="post" action="" autocomplete="off" id="assignForm" novalidate>
                                <div class="row">
                                    <div class="col-lg-4">
                                        <div class="form-group">
                                            <label class="field-label">Reviewer <span style="color:#e5533c">*</span></label>
                                            <select name="reviewer_id" id="pickReviewer" class="form-control reviewer-select">
                                                <option value="">Select Reviewer</option>
                                                <?php while ($r = mysqli_fetch_assoc($reviewers)) { ?>
                                                    <option value="<?php echo (int)$r['_id']; ?>"><?php echo htmlspecialchars($r['username']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="form-group">
                                            <label class="field-label">Categories <span style="color:#e5533c">*</span></label>
                                            <select name="category_ids[]" id="pickCategories" class="form-control cat-select" multiple>
                                                <?php while ($c = mysqli_fetch_assoc($categories)) { ?>
                                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div class="form-group">
                                            <label class="field-label">Themes <span style="color:#e5533c">*</span></label>
                                            <select name="theme_ids[]" id="pickThemes" class="form-control theme-select" multiple>
                                                <?php while ($t = mysqli_fetch_assoc($themes)) { ?>
                                                    <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                                <?php } ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="combo-summary mt-2 mb-3" id="comboSummary">
                                    <i class="feather icon-info mr-1"></i>
                                    Select a reviewer and at least one category &amp; one theme to see how many assignments will be created.
                                </div>

                                <button type="submit" name="submit" id="assignBtn" class="btn btn-assign">
                                    <i class="feather icon-save"></i> <span id="assignBtnText">Assign Reviewer</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card table-card">
                        <div class="card-header d-flex align-items-center justify-content-between py-3">
                            <h5 class="mb-0"><i class="feather icon-list mr-2"></i>Current Assignments</h5>
                            <span class="badge badge-light border">
                                <i class="feather icon-hash mr-1"></i><?php echo $totalAssignments; ?> total
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="dt-responsive table-responsive">
                                <table id="basic-btn" class="table table-hover table-bordered nowrap">
                                    <thead class="thead-light">
                                        <tr>
                                            <th style="width:45px">SNo</th>
                                            <th>Reviewer</th>
                                            <th>Categories</th>
                                            <th>Themes</th>
                                            <th style="width:80px">Combos</th>
                                            <th style="width:70px">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1;
                                        while ($a = mysqli_fetch_assoc($assignments)) {
                                            $initials = strtoupper(substr(trim($a['reviewer_name'] ?? '-'), 0, 1));
                                            $catTags = array_filter(explode('||', $a['categories'] ?? ''));
                                            $themeTags = array_filter(explode('||', $a['themes'] ?? '')); ?>
                                            <tr>
                                                <td><?php echo $i++; ?></td>
                                                <td>
                                                    <span class="reviewer-chip">
                                                        <span class="avatar-circle"><?php echo htmlspecialchars($initials); ?></span>
                                                        <?php echo htmlspecialchars($a['reviewer_name'] ?? '-'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php foreach ($catTags as $ct) { ?>
                                                        <span class="tag-pill tag-cat mt-2"><i class="feather icon-layers mr-1 "></i><?php echo htmlspecialchars($ct); ?></span><br>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <?php foreach ($themeTags as $tt) { ?>
                                                        <span class="tag-pill tag-theme mt-2"><i class="feather icon-tag mr-1"></i><?php echo htmlspecialchars($tt); ?></span><br>
                                                    <?php } ?>
                                                </td>
                                                <td>
                                                    <span class="badge badge-primary-light border" style="background:#eef2ff;color:#6c5ce7;padding:6px 10px;border-radius:8px;">
                                                        <?php echo (int)$a['combos']; ?>
                                                        <small>(<?php echo (int)$a['cat_count']; ?>&times;<?php echo (int)$a['theme_count']; ?>)</small>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="javascript:void(0)"
                                                       class="btn btn-info btn-sm edit-assignment mr-1"
                                                       data-rid="<?php echo (int)$a['reviewer_id']; ?>"
                                                       data-name="<?php echo htmlspecialchars($a['reviewer_name'] ?? 'this reviewer'); ?>"
                                                       data-cats="<?php echo htmlspecialchars($a['cat_ids'] ?? ''); ?>"
                                                       data-themes="<?php echo htmlspecialchars($a['theme_ids'] ?? ''); ?>"
                                                       data-catnames="<?php echo htmlspecialchars($a['categories'] ?? ''); ?>"
                                                       data-themenames="<?php echo htmlspecialchars($a['themes'] ?? ''); ?>"
                                                       title="Edit assignments for this reviewer">
                                                        <i class="feather icon-edit-2"></i>
                                                    </a>
                                                    <a href="javascript:void(0)"
                                                       class="btn btn-danger btn-sm delete-link delete-assignment"
                                                       data-url="manage-reviewer-assignments.php?del=<?php echo urlencode(base64_encode($a['reviewer_id'])); ?>"
                                                       data-name="<?php echo htmlspecialchars($a['reviewer_name'] ?? 'this reviewer'); ?>"
                                                       data-count="<?php echo (int)$a['combos']; ?>"
                                                       title="Remove all assignments for this reviewer">
                                                        <i class="feather icon-trash-2"></i>
                                                    </a>
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
    </section>

    <!-- Edit assignments modal -->
    <div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content" style="border:none;border-radius:14px;">
                <form method="post" action="" autocomplete="off" id="editForm" novalidate>
                    <input type="hidden" name="mode" value="edit">
                    <input type="hidden" name="reviewer_id" id="editReviewerId" value="0">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="feather icon-edit-2 mr-2" style="color:#0984e3"></i>Edit Assignments</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label class="field-label">Reviewer</label>
                            <div class="reviewer-chip">
                                <span class="avatar-circle" id="editReviewerAvatar">-</span>
                                <span id="editReviewerName" style="font-weight:600">-</span>
                            </div>
                        </div>
                        <div class="form-group" id="editCurrentBlock">
                            <label class="field-label">Current Assignments</label>
                            <div class="mb-1">
                                <small class="text-muted d-block mb-1"><i class="feather icon-layers mr-1"></i>Categories</small>
                                <span id="editCurrentCats"></span>
                            </div>
                            <div>
                                <small class="text-muted d-block mb-1"><i class="feather icon-tag mr-1"></i>Themes</small>
                                <span id="editCurrentThemes"></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="field-label">Categories <span style="color:#e5533c">*</span></label>
                            <select name="category_ids[]" id="editCategories" class="form-control edit-cat-select" multiple>
                                <?php $qe = mysqli_query($db, "SELECT id, name FROM abstract_category WHERE status = '1' ORDER BY name ASC");
                                while ($e = mysqli_fetch_assoc($qe)) { ?>
                                    <option value="<?php echo (int)$e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="field-label">Themes <span style="color:#e5533c">*</span></label>
                            <select name="theme_ids[]" id="editThemes" class="form-control edit-theme-select" multiple>
                                <?php $qe = mysqli_query($db, "SELECT id, name FROM abstract_theme WHERE status = '1' ORDER BY name ASC");
                                while ($e = mysqli_fetch_assoc($qe)) { ?>
                                    <option value="<?php echo (int)$e['id']; ?>"><?php echo htmlspecialchars($e['name']); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="combo-summary mt-1 mb-1" id="editSummary">
                            <i class="feather icon-info mr-1"></i>
                            Saving will replace all current combinations for this reviewer.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-assign" id="editSaveBtn">
                            <i class="feather icon-check-circle"></i> <span id="editSaveText">Save Changes</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete confirm modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border:none;border-radius:14px;">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="feather icon-alert-triangle text-danger mr-2"></i>Remove Assignment</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="deleteModalText">
                    Are you sure you want to remove this reviewer assignment?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDelete" class="btn btn-danger">
                        <i class="feather icon-trash-2 mr-1"></i>Remove
                    </a>
                </div>
            </div>
        </div>
    </div>

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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <?php echo abstract_swal_js(); ?>
    <script>
        $(document).ready(function() {

            var $reviewer = $('#pickReviewer');
            var $cats = $('#pickCategories');
            var $themes = $('#pickThemes');
            var $summary = $('#comboSummary');
            var $btn = $('#assignBtn');
            var $btnText = $('#assignBtnText');
            var select2Ok = (typeof $.fn.select2 === 'function');

            if (select2Ok) {
                try {
                    $reviewer.select2({
                        placeholder: 'Select Reviewer',
                        allowClear: true,
                        width: '100%'
                    });
                    $cats.select2({
                        placeholder: 'Select Categories',
                        width: '100%'
                    });
                    $themes.select2({
                        placeholder: 'Select Themes',
                        width: '100%'
                    });
                    $('#editCategories').select2({
                        placeholder: 'Select Categories',
                        width: '100%',
                        dropdownParent: $('#editModal')
                    });
                    $('#editThemes').select2({
                        placeholder: 'Select Themes',
                        width: '100%',
                        dropdownParent: $('#editModal')
                    });
                } catch (e) {
                    select2Ok = false;
                }
            }

            function updateSummary() {
                var catCount = ($cats.val() || []).length;
                var themeCount = ($themes.val() || []).length;
                var reviewerOk = !!$reviewer.val();
                var total = catCount * themeCount;

                if (reviewerOk && catCount > 0 && themeCount > 0) {
                    $summary.removeClass('invalid').html(
                        '<i class="feather icon-check-circle mr-1"></i>' +
                        'This will create <span class="combo-num">' + total + '</span> assignment' + (total === 1 ? '' : 's') +
                        '&nbsp;(' + catCount + ' categor' + (catCount === 1 ? 'y' : 'ies') + ' &times; ' + themeCount + ' theme' + (themeCount === 1 ? '' : 's') + ')' +
                        ' for <strong>' + ($reviewer.find('option:selected').text() || '') + '</strong>.'
                    );
                } else {
                    var why = reviewerOk ? '' : '<br><i class="feather icon-alert-circle mr-1"></i>Choose a reviewer.';
                    if (catCount === 0) why += '<br><i class="feather icon-alert-circle mr-1"></i>Select at least one category.';
                    if (themeCount === 0) why += '<br><i class="feather icon-alert-circle mr-1"></i>Select at least one theme.';
                    $summary.addClass('invalid').html(
                        '<i class="feather icon-info mr-1"></i>Select a reviewer and at least one category &amp; one theme to see how many assignments will be created.' +
                        why
                    );
                }
            }

            $reviewer.on('change', updateSummary);
            $cats.on('change', updateSummary);
            $themes.on('change', updateSummary);
            updateSummary();

            $('#assignForm').on('submit', function(e) {
                var catCount = ($cats.val() || []).length;
                var themeCount = ($themes.val() || []).length;
                var invalid = !$reviewer.val() || catCount === 0 || themeCount === 0;

                if (invalid) {
                    e.preventDefault();
                    updateSummary();
                    $summary.addClass('invalid');
                    $btn.prop('disabled', false);
                    $btnText.html('Assign Reviewer');
                    $('html, body').animate({ scrollTop: $('#comboSummary').offset().top - 16 }, 300);
                    return false;
                }

                $btn.prop('disabled', true);
                $btnText.html('Assigning... <span class="ml-1"><i class="feather icon-loader fa-spin"></i></span>');
                return true;
            });

            var delUrl = '';
            $('.delete-assignment').on('click', function(e) {
                e.preventDefault();
                delUrl = $(this).data('url');
                var unlockName = $(this).data('name') || 'this reviewer';
                var unlockCount = $(this).data('count') || 'all';
                $('#deleteModalText').html(
                    'All <strong>' + unlockCount + '</strong> combination' + (unlockCount === 1 ? '' : 's') +
                    ' for <strong>' + unlockName + '</strong> will be removed.'
                );
                $('#deleteModal').modal('show');
            });
            $('#confirmDelete').on('click', function() {
                if (delUrl) window.location.href = delUrl;
            });

            var $editCats = $('#editCategories');
            var $editThemes = $('#editThemes');
            var $editSummary = $('#editSummary');
            var $editSaveBtn = $('#editSaveBtn');
            var $editSaveText = $('#editSaveText');

            $('.edit-assignment').on('click', function(e) {
                e.preventDefault();
                var $t = $(this);
                var name = $t.data('name') || 'this reviewer';
                $('#editReviewerId').val($t.data('rid'));
                $('#editReviewerName').text(name);
                $('#editReviewerAvatar').text(String(name).charAt(0).toUpperCase());
                var cats = String($t.data('cats') || '').split(',').filter(Boolean).map(Number);
                var themes = String($t.data('themes') || '').split(',').filter(Boolean).map(Number);
                var catNames = String($t.data('catnames') || '').split('||').filter(Boolean);
                var themeNames = String($t.data('themenames') || '').split('||').filter(Boolean);
                $('#editCurrentCats').html(catNames.map(function(n) {
                    return '<span class="tag-pill tag-cat"><i class="feather icon-layers mr-1"></i>' + n + '</span>';
                }).join(' '));
                $('#editCurrentThemes').html(themeNames.map(function(n) {
                    return '<span class="tag-pill tag-theme"><i class="feather icon-tag mr-1"></i>' + n + '</span>';
                }).join(' '));
                $('#editCurrentBlock').toggle(catNames.length > 0 || themeNames.length > 0);
                $editCats.val(cats).trigger('change');
                $editThemes.val(themes).trigger('change');
                var total = cats.length * themes.length;
                $editSummary.removeClass('invalid').html(
                    '<i class="feather icon-check-circle mr-1"></i>' +
                    'Currently <strong>' + total + '</strong> combination' + (total === 1 ? '' : 's') +
                    '. Saving will replace them with the new selection.'
                );
                $editSaveBtn.prop('disabled', false);
                $editSaveText.html('Save Changes');
                $('#editModal').modal('show');
            });

            $('#editModal').on('shown.bs.modal', function() {
                if (select2Ok) {
                    $editCats.trigger('change');
                    $editThemes.trigger('change');
                }
            });

            $('#editForm').on('submit', function(e) {
                var cc = ($editCats.val() || []).length;
                var tc = ($editThemes.val() || []).length;
                if (cc === 0 || tc === 0) {
                    e.preventDefault();
                    $editSummary.addClass('invalid').html(
                        '<i class="feather icon-alert-circle mr-1"></i>Select at least one category and one theme.'
                    );
                    return false;
                }
                $editSaveBtn.prop('disabled', true);
                $editSaveText.html('Saving... <i class="feather icon-loader fa-spin"></i>');
                return true;
            });

            $("#goldmessage").delay(5000).slideUp(300);
        });
    </script>
    <?php abstract_swal_render(); ?>

</body>

</html>