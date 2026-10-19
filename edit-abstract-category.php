<?php
session_start();

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

include("db/config.php");
include("abstract_helpers.php");

$existingName = "";
$existingStatus = "";

if (isset($_GET['id'])) {
    $encodedId = $_GET['id'];
    $categoryId = base64_decode($encodedId);

    if (!is_numeric($categoryId)) {
        abstract_swal_page('error', 'Invalid Category', 'Invalid Category ID!', null, true);
    }

    $stmt = $db->prepare("SELECT * FROM abstract_category WHERE id = ?");
    $stmt->bind_param("i", $categoryId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $existingName = $row['name'];
        $existingStatus = $row['status'];
    } else {
        abstract_swal_page('error', 'Not Found', 'Abstract Category not found!', null, true);
    }
    $stmt->close();
}

if (isset($_POST['submit'])) {
    $name = trim($_POST['name']);
    $status = $_POST['status'];

    if ($name === '') {
        abstract_swal_flash('error', 'Required', 'Category name is required.');
    } elseif (isset($_GET['id'])) {
        $stmt = $db->prepare("UPDATE abstract_category SET name = ?, status = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $status, $categoryId);

        if ($stmt->execute()) {
            $encodedStatus = base64_encode(1);
            echo ("<SCRIPT LANGUAGE='JavaScript'>window.location.href='manage-abstract-categories.php?status=$encodedStatus';</SCRIPT>");
        } else {
            echo "Error executing query: " . $db->error;
        }
        $stmt->close();
    } else {
        $stmt = $db->prepare("INSERT INTO abstract_category (name, status) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $status);

        if ($stmt->execute()) {
            $encodedStatus = base64_encode(1);
            echo ("<SCRIPT LANGUAGE='JavaScript'>window.location.href='manage-abstract-categories.php?status=$encodedStatus';</SCRIPT>");
        } else {
            echo "Error executing query: " . $db->error;
        }
        $stmt->close();
    }
}

$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'];
$helpdeskNumber = $settings['helpdesk_no'];
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title><?php echo isset($_GET['id']) ? 'Update' : 'Add'; ?> Abstract Category</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
    <style>
        .red-text { color: red; }
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
                                <h5 class="m-b-10"><?php echo isset($_GET['id']) ? 'Update' : 'Add'; ?> Abstract Category</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <br />
                            <form method="post" action="" autocomplete="off">
                                <div class="row">
                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label for="name" class="form-label">Category Name <span class="red-text">*</span></label>
                                            <input type="text" name="name" class="form-control" placeholder="Enter Abstract Category name"
                                                value="<?php echo htmlspecialchars($existingName); ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                        <div class="form-group">
                                            <label for="status" class="form-label">Status <span class="red-text">*</span></label>
                                            <select name="status" class="form-control" required>
                                                <option value="" selected disabled>Choose</option>
                                                <option value="1" <?php echo ($existingStatus == 1) ? 'selected' : ''; ?>>Enable</option>
                                                <option value="0" <?php echo ($existingStatus === '0' || $existingStatus === 0) ? 'selected' : ''; ?>>Disable</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                        <button type="submit" class="btn btn-secondary" name="submit">
                                            <i class="feather icon-save"></i>&nbsp; <?php echo isset($_GET['id']) ? 'Update' : 'Add'; ?> Abstract Category
                                        </button>
                                        <a href="manage-abstract-categories.php" class="btn btn-light">Cancel</a>
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
