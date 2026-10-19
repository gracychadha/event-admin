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

if (!abstract_can('My Review Queue') && !abstract_can('Abstract Submissions')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to access this page.', 'dashboard.php');
}

$id = isset($_GET['id']) ? (int) base64_decode($_GET['id']) : 0;
if ($id <= 0) {
    abstract_swal_page('error', 'Invalid Abstract', 'The requested abstract could not be identified.', null, true);
}

$submission = abstract_fetch_submission_full($db, $id);
if (!$submission) {
    abstract_swal_page('error', 'Not Found', 'Abstract not found.', null, true);
}

$actorType = $isReviewer ? 'reviewer' : 'organizer';

if (isset($_POST['submit'])) {
    $title    = trim($_POST['title'] ?? '');
    $author   = trim($_POST['presenting_author'] ?? '');
    $coauthors= trim($_POST['co_authors'] ?? '');
    $keywords = trim($_POST['keywords'] ?? '');
    $background = trim($_POST['background'] ?? '');
    $categoryId = (int) ($_POST['category'] ?? $submission['category_id']);
    $themeId    = (int) ($_POST['theme'] ?? $submission['theme_id']);
    $oldCategoryId = (int) $submission['category_id'];

    if ($title === '' || $background === '' || $categoryId <= 0 || $themeId <= 0) {
        abstract_swal_flash('error', 'Missing Fields', 'Title, Background, Category and Theme are required.');
    } else {
        $stmt = $db->prepare("UPDATE abstract_submissions
            SET title = ?, presenting_author = ?, co_authors = ?, keywords = ?, background = ?,
                category_id = ?, theme_id = ?
            WHERE id = ?");
        $stmt->bind_param("sssssiii", $title, $author, $coauthors, $keywords, $background, $categoryId, $themeId, $id);
        if ($stmt->execute()) {
            $stmt->close();
            abstract_log($db, $id, $actorType, $adminId, abstract_admin_name($db, $adminId), 'content_edited', 'Abstract content updated');

            if ($categoryId !== $oldCategoryId) {
                $fresh = abstract_fetch_submission_full($db, $id);
                abstract_log($db, $id, $actorType, $adminId, abstract_admin_name($db, $adminId), 'category_updated',
                    'Category changed to ' . ($fresh['category_name'] ?? $categoryId));
                abstract_notify($db, 'category_updated', $fresh);
            }

            abstract_swal_page('success', 'Updated', 'Abstract updated.', 'manage-abstract-submissions.php');
            exit;
        }
        $stmt->close();
        abstract_swal_flash('error', 'Update Failed', 'Failed to update abstract.');
    }
    $submission = abstract_fetch_submission_full($db, $id);
}

$categories = mysqli_query($db, "SELECT id, name FROM abstract_category ORDER BY name ASC");
$themes     = mysqli_query($db, "SELECT id, name FROM abstract_theme ORDER BY name ASC");

$query = "SELECT * FROM login_settings";
$settings = mysqli_fetch_assoc(mysqli_query($db, $query));
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Abstract Content</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
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
                                <h5 class="m-b-10">Edit Abstract Content</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header">
                            <p class="mb-1"><strong>Author:</strong> <?php echo htmlspecialchars(trim($submission['author_name'])); ?></p>
                            <p class="mb-0"><strong>Category:</strong> <?php echo htmlspecialchars($submission['category_name'] ?? '-'); ?> &nbsp; | &nbsp;
                               <strong>Theme:</strong> <?php echo htmlspecialchars($submission['theme_name'] ?? '-'); ?></p>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label>Title <span style="color:red">*</span></label>
                                            <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($submission['title']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Category <span style="color:red">*</span></label>
                                            <select name="category" class="form-control" required>
                                                <option value="">Select Category</option>
                                                <?php while ($c = mysqli_fetch_assoc($categories)): ?>
                                                    <option value="<?php echo (int) $c['id']; ?>" <?php echo ((int) $submission['category_id'] === (int) $c['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c['name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                            <small class="text-muted">Changing the category notifies the author by email.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Theme <span style="color:red">*</span></label>
                                            <select name="theme" class="form-control" required>
                                                <option value="">Select Theme</option>
                                                <?php while ($t = mysqli_fetch_assoc($themes)): ?>
                                                    <option value="<?php echo (int) $t['id']; ?>" <?php echo ((int) $submission['theme_id'] === (int) $t['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($t['name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Presenting Author</label>
                                            <input type="text" name="presenting_author" class="form-control" value="<?php echo htmlspecialchars($submission['presenting_author'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Keywords</label>
                                            <input type="text" name="keywords" class="form-control" value="<?php echo htmlspecialchars($submission['keywords'] ?? ''); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label>Co-Authors</label>
                                            <textarea name="co_authors" class="form-control" rows="2"><?php echo htmlspecialchars($submission['co_authors'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label>Background / Description <span style="color:red">*</span></label>
                                            <textarea name="background" class="form-control" rows="8" required><?php echo htmlspecialchars($submission['background']); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" name="submit" class="btn btn-secondary">
                                            <i class="feather icon-save"></i> Save Changes
                                        </button>
                                        <a href="manage-abstract-submissions.php" class="btn btn-light">Cancel</a>
                                    </div>
                                </div>
                            </form>
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
