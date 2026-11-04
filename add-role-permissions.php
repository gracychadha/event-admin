<?php
session_start();

error_reporting(E_ALL);
if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

$name = $_SESSION['login_user'];
include("db/config.php");

$assignedPermissions = [];
if (isset($_GET['id'])) {
    $roleId = base64_decode($_GET['id']);
    $roleQuery = "SELECT * FROM roles WHERE role_id = $roleId";
    $roleResult = mysqli_query($db, $roleQuery);

    if ($roleResult && mysqli_num_rows($roleResult) > 0) {
        $row = mysqli_fetch_assoc($roleResult);
        $existingName = $row['role_name'];

        // Get assigned permissions
        $permQuery = "SELECT permission_id FROM role_permissions WHERE role_name = '$existingName'";
        $permResult = mysqli_query($db, $permQuery);
        while ($permRow = mysqli_fetch_assoc($permResult)) {
            $assignedPermissions[] = $permRow['permission_id'];
        }
    }
}

if (isset($_POST['submit'])) {
    $role_id = mysqli_real_escape_string($db, $_POST['role_name']);
    $new_permissions = isset($_POST['type']) ? $_POST['type'] : [];

    $success = true;

    // Step 1: Get existing permissions from DB
    $existing_permissions = [];
    $result = mysqli_query($db, "SELECT permission_id FROM role_permissions WHERE role_name = '$role_id'");
    while ($row = mysqli_fetch_assoc($result)) {
        $existing_permissions[] = $row['permission_id'];
    }

    // Step 2: Find permissions to DELETE (in DB but not in new)
    $to_delete = array_diff($existing_permissions, $new_permissions);
    foreach ($to_delete as $permission_id) {
        $permission_id = mysqli_real_escape_string($db, $permission_id);
        $delete_query = "DELETE FROM role_permissions WHERE role_name = '$role_id' AND permission_id = '$permission_id'";
        if (!mysqli_query($db, $delete_query)) {
            $success = false;
            break;
        }
    }

    // Step 3: Find permissions to INSERT (in new but not in DB)
    $to_add = array_diff($new_permissions, $existing_permissions);
    foreach ($to_add as $permission_id) {
        $permission_id = mysqli_real_escape_string($db, $permission_id);
        $insert_query = "INSERT INTO role_permissions(role_name, permission_id) VALUES('$role_id', '$permission_id')";
        if (!mysqli_query($db, $insert_query)) {
            $success = false;
            break;
        }
    }

    // Step 4: Show session message
    if ($success) {
        $_SESSION['msg'] = "
        <div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
            <strong><i class='feather icon-check'></i> Thanks!</strong> Role Permission(s) updated successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    } else {
        $_SESSION['msg'] = "
        <div class='alert alert-danger alert-dismissible fade show' role='alert' style='font-size:16px;' id='goldmessage'>
            <strong><i class='feather icon-check'></i> Error!</strong> Failed to update Role Permission.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }

    // Redirect
    $redirectUrl = $_SERVER['PHP_SELF'] . '?id=' . urlencode($_GET['id']);
    header("Location: $redirectUrl");
    exit();
}



// Fetch state from the database
$permissionquery = "SELECT id, title FROM navigation_menus WHERE status = 1"; // Assuming your table is `city_type`
$permissionresult = mysqli_query($db, $permissionquery);
$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'];
$helpdeskNumber = $settings['helpdesk_no'];
$favicon = $settings['favicon'];
// echo $favicon;
// exit;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title><?php echo isset($_GET['id']) ? 'Update' : 'Add'; ?> </title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="" />
    <meta name="keywords" content="">
    <meta name="author" content="" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">

    <style>
        .select2-container--default .select2-selection--multiple .select2-selection__rendered li {
            list-style: none;
            color: #003399;
            ;
            background: #fff;
        }

        .red-text {
            color: red;
        }

        .red-text {
            color: red;
        }

        .permission-dropdown {
            position: relative;
            width: 100%;
        }

        .permission-select-box {
            min-height: 42px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            padding: 6px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            background: #fff;
        }

        .selected-permissions {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .placeholder {
            color: #999;
        }

        .permission-tag {
            background: #e8efff;
            color: #003399;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .permission-tag span {
            cursor: pointer;
            margin-left: 5px;
            font-weight: bold;
        }

        .permission-options {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            width: 100%;
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 5px;
            margin-top: 5px;
            z-index: 999;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .12);
        }

        .permission-options.active {
            display: block;
        }

        .permission-search {
            margin: 10px;
            width: calc(100% - 20px);
        }

        .permission-actions {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            font-size: 13px;
            border-bottom: 1px solid #eee;
        }

        .permission-actions a {
            color: #003399;
        }

        .permission-list {
            max-height: 220px;
            overflow-y: auto;
        }

        .permission-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .permission-option:hover {
            background: #f5f7fb;
        }

        .permission-option input {
            cursor: pointer;
        }

        .permission-option.hidden {
            display: none;
        }
    </style>

</head>

<body class="">
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <!-- Header -->
    <?php include("header.php"); ?>
    <!-- /Header -->
    <!-- navbar -->
    <?php include("navbar.php"); ?>
    <!-- /navbar -->
    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10"><?php echo isset($_GET['id']) ? 'Update' : 'Add'; ?> Role
                                </h5>
                            </div>
                            <!--                             <ul class="breadcrumb"> -->
                            <!--                                 <li class="breadcrumb-item"><a href="index.php"><i class="feather icon-home"></i></a> -->
                            <!--                                 </li> -->
                            <!--                             </ul> -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">

                            <br />
                            <form class="contact-us" method="post" action=""
                                enctype="multipart/form-data" autocomplete="off">
                                <div class=" ">
                                    <!-- Text input-->
                                    <div class="row">
                                        <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                            <div class="form-group">
                                                Role Name<span class="red-text">*</span>
                                                <label class="sr-only control-label" for="course_name"> Role Name</label>
                                                <input id="role_name" name="role_name" type="text"
                                                    placeholder="Enter the Name" class="form-control input-md"
                                                    value="<?php echo $existingName; ?>" required
                                                    oninvalid="this.setCustomValidity('Please Enter Name')"
                                                    oninput="setCustomValidity('')">
                                            </div>
                                        </div>

                                        <!-- <div class="col-xl-6 col-lg-6 col-md-4 col-sm-12 col-12">
                                            <div class="form-group">
                                                Permission Type <span class="red-text">*</span>
                                                <select id="multiSelect" name="type[]" multiple="multiple" required class="form-control">
                                                    <?php
                                                    while ($row = mysqli_fetch_assoc($permissionresult)) {
                                                        // Check if the permission is assigned to the role
                                                        $selected = in_array($row['id'], $assignedPermissions) ? 'selected' : '';
                                                        echo "<option value='{$row['id']}' $selected>{$row['title']}</option>";
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                        </div> -->
                                        <div class="col-xl-6 col-lg-6 col-md-12 col-sm-12 col-12">
                                            <div class="form-group">
                                                <label class="control-label">
                                                    Permission Type <span class="red-text">*</span>
                                                </label>

                                                <div class="permission-dropdown" id="permissionDropdown">

                                                    <div class="permission-select-box" id="permissionSelectBox">
                                                        <div class="selected-permissions" id="selectedPermissions">
                                                            <span class="placeholder">Select permissions</span>
                                                        </div>
                                                        <i class="feather icon-chevron-down"></i>
                                                    </div>

                                                    <div class="permission-options" id="permissionOptions">

                                                        <input type="text"
                                                            class="form-control permission-search"
                                                            id="permissionSearch"
                                                            placeholder="Search permissions...">

                                                        <div class="permission-actions">
                                                            <span>Available Permissions</span>
                                                            <a href="javascript:void(0)" id="selectAllPermissions">Select All</a>
                                                        </div>

                                                        <div class="permission-list">
                                                            <?php
                                                            mysqli_data_seek($permissionresult, 0);

                                                            while ($row = mysqli_fetch_assoc($permissionresult)) {
                                                                $selected = in_array($row['id'], $assignedPermissions) ? 'checked' : '';

                                                                echo '
                        <label class="permission-option">
                            <input type="checkbox"
                                name="type[]"
                                value="' . htmlspecialchars($row['id']) . '"
                                ' . $selected . '>
                            <span>' . htmlspecialchars($row['title']) . '</span>
                        </label>';
                                                            }
                                                            ?>
                                                        </div>

                                                    </div>
                                                </div>
                                            </div>
                                        </div>







                                        <!-- Text input-->
                                        <!-- Button -->
                                        <div class="col-xl-12 col-lg-12 col-md-12 col-sm-12 col-12">
                                            <button type="submit" class="btn btn-secondary" name="submit"
                                                id="submit">
                                                <i class="feather icon-save"></i>&nbsp; <?php echo isset($_GET['id']) ? 'Update' : 'Add'; ?> Permission
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="card-body">
                            <div class="dt-responsive table-responsive">
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#multiSelect').select2({
                width: '100%',
                closeOnSelect: false,
                templateSelection: function(data, container) {
                    // Add a cross icon to selected items    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">

                    var $option = $(data.element);
                    var text = $option.text();
                    container.text(text);
                    container.append('<span class="remove-item" data-value="' + data.id + '">&times;</span>');
                }
            });

            // Handle removal of selected items
            $('#multiSelect').on('click', '.remove-item', function() {
                var valueToRemove = $(this).data('value');
                var $select = $('#multiSelect');
                $select.find('option[value="' + valueToRemove + '"]').prop('selected', false);
                $select.trigger('change.select2');
            });
        });

        $(document).ready(function() {
            $("#goldmessage").delay(5000).slideUp(300);
        });

        // Function to show the preview button when a file is selected
        function showPreviewButton() {
            var previewBtn = document.getElementById('previewBtn');
            previewBtn.style.display = 'block';
        }

        // Function to show the preview modal
        function showPreviewModal() {
            var modal = document.getElementById('imagePreviewModal');
            var modalImg = document.getElementById('previewImage');
            var files = document.getElementsByName('uploaded_file')[0].files;

            // Check if any file is selected
            if (files.length > 0) {
                var file = files[0];
                var reader = new FileReader();

                reader.onload = function(e) {
                    modalImg.src = e.target.result;
                    $(modal).modal('show'); // Show the modal
                }

                reader.readAsDataURL(file);
            }
        }
        $(document).ready(function() {

            const dropdown = $('#permissionDropdown');
            const selectBox = $('#permissionSelectBox');
            const options = $('#permissionOptions');

            function updateSelectedPermissions() {

                let selected = [];

                $('.permission-option input:checked').each(function() {
                    selected.push({
                        id: $(this).val(),
                        name: $(this).siblings('span').text()
                    });
                });

                let html = '';

                if (selected.length === 0) {
                    html = '<span class="placeholder">Select permissions</span>';
                } else {
                    selected.forEach(function(item) {
                        html += `
                    <span class="permission-tag">
                        ${$('<div>').text(item.name).html()}
                        <span class="remove-permission"
                              data-id="${item.id}">&times;</span>
                    </span>`;
                    });
                }

                $('#selectedPermissions').html(html);

                let total = $('.permission-option input').length;

                $('#selectAllPermissions').text(
                    selected.length === total ? 'Deselect All' : 'Select All'
                );
            }

            selectBox.on('click', function() {
                options.toggleClass('active');
                $('#permissionSearch').focus();
            });

            $(document).on('click', function(e) {
                if (!dropdown.is(e.target) && dropdown.has(e.target).length === 0) {
                    options.removeClass('active');
                }
            });

            $(document).on('change', '.permission-option input', function() {
                updateSelectedPermissions();
            });

            $('#selectAllPermissions').on('click', function() {

                let checkboxes = $('.permission-option input:visible');
                let allChecked = checkboxes.length > 0 &&
                    checkboxes.filter(':checked').length === checkboxes.length;

                checkboxes.prop('checked', !allChecked);

                updateSelectedPermissions();
            });

            $('#permissionSearch').on('keyup', function() {

                let search = $(this).val().toLowerCase();

                $('.permission-option').each(function() {
                    let text = $(this).text().toLowerCase();

                    $(this).toggleClass('hidden', !text.includes(search));
                });
            });

            $(document).on('click', '.remove-permission', function(e) {

                e.stopPropagation();

                let id = $(this).data('id');

                $('.permission-option input[value="' + id + '"]')
                    .prop('checked', false);

                updateSelectedPermissions();
            });

            updateSelectedPermissions();

        });
    </script>

</body>

</html>