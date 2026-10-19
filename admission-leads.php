<?php
// error_reporting(E_ALL);
session_start();
require_once 'db/config.php';

if (!isset($_SESSION['login_user_id'])) {
    header('Location: login.php');
    exit;
}

$login_user_id = $_SESSION['login_user_id'];

// Fetch role and username
$get_admin_query = "SELECT _id, username, admin_role FROM admin WHERE _id = ?";
$stmt = mysqli_prepare($db, $get_admin_query);
mysqli_stmt_bind_param($stmt, "i", $login_user_id);
mysqli_stmt_execute($stmt);
$get_admin_result = mysqli_stmt_get_result($stmt);
$admin_row = mysqli_fetch_assoc($get_admin_result);
mysqli_stmt_close($stmt);

$admin_id = $admin_row['_id'];
$username = $admin_row['username'];
$role_id = $admin_row['admin_role'];

// Get role name
$get_role_query = "SELECT role_name FROM roles WHERE role_id = ?";
$stmt = mysqli_prepare($db, $get_role_query);
mysqli_stmt_bind_param($stmt, "i", $role_id);
mysqli_stmt_execute($stmt);
$get_role_result = mysqli_stmt_get_result($stmt);
$role_row = mysqli_fetch_assoc($get_role_result);
mysqli_stmt_close($stmt);

$role_name = strtolower($role_row['role_name']);

// Set permissions based on role
$can_assign_leads = false;
$can_upload_leads = false;
$can_delete_leads = false;
$can_download_leads = false;

// Logic based on role
if ($role_name == 'admin') {
    $can_assign_leads = true;
    $can_upload_leads = true;
    $can_delete_leads = true;
    $can_download_leads = true;
} elseif ($role_name == 'manager') {
    $can_upload_leads = true;
}

// Build query based on role
if ($role_name == 'admin') {
    $query = "
    SELECT 
        ae.admission_id,
        ae.name,
        ae.email,
        ae.mobile,
        ae.course_type,
        ae.remarks,
        ae.state,
        ae.city,
        ae.lead_status,
        ae.date,
        ae.category_id,
        ae.sub_category_id,
        pc.name AS parent_category_name,
        c.category_name AS sub_category_name,
        a.username AS assigned_admin
    FROM admission_enquiry ae
    LEFT JOIN parent_category pc ON ae.category_id = pc.id
    LEFT JOIN category c ON ae.sub_category_id = c.category_id
    LEFT JOIN lead_assignments la ON ae.admission_id = la.admission_id
    LEFT JOIN admin a ON la.admin_id = a._id
    ORDER BY ae.date DESC
    ";
} elseif ($role_name == 'manager') {
    $query = "
    SELECT 
        ae.admission_id,
        ae.name,
        ae.email,
        ae.mobile,
        ae.course_type,
        ae.remarks,
        ae.state,
        ae.city,
        ae.lead_status,
        ae.date,
        ae.category_id,
        ae.sub_category_id,
        pc.name AS parent_category_name,
        c.category_name AS sub_category_name,
        a.username AS assigned_admin
    FROM admission_enquiry ae
    LEFT JOIN parent_category pc ON ae.category_id = pc.id
    LEFT JOIN category c ON ae.sub_category_id = c.category_id
    LEFT JOIN lead_assignments la ON ae.admission_id = la.admission_id
    LEFT JOIN admin a ON la.admin_id = a._id
    ORDER BY ae.date DESC
    ";
} elseif ($role_name == 'telecaller') {
    $query = "
    SELECT 
        ae.admission_id,
        ae.name,
        ae.email,
        ae.mobile,
        ae.course_type,
        ae.remarks,
        ae.state,
        ae.city,
        ae.lead_status,
        ae.date,
        ae.category_id,
        ae.sub_category_id,
        pc.name AS parent_category_name,
        c.category_name AS sub_category_name,
        a.username AS assigned_admin
    FROM admission_enquiry ae
    LEFT JOIN parent_category pc ON ae.category_id = pc.id
    LEFT JOIN category c ON ae.sub_category_id = c.category_id
    LEFT JOIN lead_assignments la ON ae.admission_id = la.admission_id
    LEFT JOIN admin a ON la.admin_id = a._id
    WHERE la.admin_id = ?
    ORDER BY ae.date DESC
    ";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, "i", $admin_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $query = "
    SELECT 
        ae.admission_id,
        ae.name,
        ae.email,
        ae.mobile,
        ae.course_type,
        ae.remarks,
        ae.state,
        ae.city,
        ae.lead_status,
        ae.date,
        ae.category_id,
        ae.sub_category_id,
        pc.name AS parent_category_name,
        c.category_name AS sub_category_name,
        a.username AS assigned_admin
    FROM admission_enquiry ae
    LEFT JOIN parent_category pc ON ae.category_id = pc.id
    LEFT JOIN category c ON ae.sub_category_id = c.category_id
    LEFT JOIN lead_assignments la ON ae.admission_id = la.admission_id
    LEFT JOIN admin a ON la.admin_id = a._id
    WHERE la.admin_id = ?
    ORDER BY ae.date DESC
    ";
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, "i", $admin_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
}

// Execute query if not already executed
if (!isset($result)) {
    $result = mysqli_query($db, $query);
}

// Handle lead status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editleads'])) {
    $leadid = base64_decode($_POST['admission_id']);
    $leadstatus = $_POST['lead_status'];
    $remarks = trim($_POST['remarks']);

    if (empty($leadstatus)) {
        $_SESSION['msg'] = '<div class="alert alert-danger">Lead status is required.</div>';
        header('Location: admission-leads.php');
        exit;
    }

    $update_query = "UPDATE admission_enquiry SET lead_status = ?, remarks = ? WHERE admission_id = ?";
    if ($stmt = mysqli_prepare($db, $update_query)) {
        mysqli_stmt_bind_param($stmt, "ssi", $leadstatus, $remarks, $leadid);
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['msg'] = '<div class="alert alert-success">Lead updated successfully!</div>';
        } else {
            $_SESSION['msg'] = '<div class="alert alert-danger">Error updating lead.</div>';
        }
        mysqli_stmt_close($stmt);
    }
    header('Location: admission-leads.php');
    exit;
}

// Fetch lead statuses
$status_query = "SELECT status_id, status_name FROM lead_status ORDER BY status_name";
$status_result = mysqli_query($db, $status_query);

// Upload and process CSV
$uploadSuccess = null;
$errorMsg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $filename = $_FILES['csv_file']['tmp_name'];

        if (($handle = fopen($filename, "r")) !== FALSE) {
            $header = fgetcsv($handle); // Skip header
            $rows = [];
            $rowCount = 1;
            $uploadSuccess = true;

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $rowCount++;

                if (count($data) < 7) {
                    $uploadSuccess = false;
                    $errorMsg = "Insufficient columns at line $rowCount.";
                    break;
                }

                $name = trim($data[0]);
                $email = trim($data[1]);
                $phone = trim($data[2]);

                // Validate name
                if (!preg_match("/^[a-zA-Z\s'-]+$/", $name)) {
                    $uploadSuccess = false;
                    $errorMsg = "Invalid name format at line $rowCount.";
                    break;
                }

                // Validate phone
                if (!preg_match("/^\d{10}$/", $phone)) {
                    $uploadSuccess = false;
                    $errorMsg = "Invalid phone number at line $rowCount.";
                    break;
                }

                // Check for duplicate
                $checkQuery = "SELECT admission_id FROM admission_enquiry WHERE email=? OR mobile=?";
                $stmt = mysqli_prepare($db, $checkQuery);
                mysqli_stmt_bind_param($stmt, "ss", $email, $phone);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);

                if (mysqli_stmt_num_rows($stmt) > 0) {
                    mysqli_stmt_close($stmt);
                    $uploadSuccess = false;
                    $errorMsg = "Duplicate entry at line $rowCount.";
                    break;
                }
                mysqli_stmt_close($stmt);

                $rows[] = [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'state' => trim($data[3]),
                    'city' => trim($data[4]),
                    'course_type' => trim($data[5]),
                    'lead_status' => trim($data[6]),
                ];
            }

            fclose($handle);

            if ($uploadSuccess && !empty($rows)) {
                $selectedParentId = intval($_POST['parent_category_id']);
                $selectedCategoryId = intval($_POST['category_id']);

                // Validate category belongs to parent
                $catCheck = mysqli_prepare($db, "SELECT category_id FROM category WHERE category_id = ? AND parent_category = ? AND status = 1");
                mysqli_stmt_bind_param($catCheck, "ii", $selectedCategoryId, $selectedParentId);
                mysqli_stmt_execute($catCheck);
                mysqli_stmt_store_result($catCheck);

                if (mysqli_stmt_num_rows($catCheck) == 0) {
                    $uploadSuccess = false;
                    $errorMsg = "Invalid category selection.";
                }
                mysqli_stmt_close($catCheck);

                if ($uploadSuccess) {
                    foreach ($rows as $row) {
                        $insertQuery = "INSERT INTO admission_enquiry 
                            (name, email, mobile, state, city, course_type, lead_status, category_id, sub_category_id) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

                        $stmt = mysqli_prepare($db, $insertQuery);
                        mysqli_stmt_bind_param(
                            $stmt,
                            "sssssssii",
                            $row['name'],
                            $row['email'],
                            $row['phone'],
                            $row['state'],
                            $row['city'],
                            $row['course_type'],
                            $row['lead_status'],
                            $selectedParentId,
                            $selectedCategoryId
                        );

                        if (!mysqli_stmt_execute($stmt)) {
                            $uploadSuccess = false;
                            $errorMsg = "Database error: " . mysqli_error($db);
                            mysqli_stmt_close($stmt);
                            break;
                        }
                        mysqli_stmt_close($stmt);
                    }
                }
            }
        } else {
            $uploadSuccess = false;
            $errorMsg = "Unable to open CSV file.";
        }
    } else {
        $uploadSuccess = false;
        $errorMsg = "No valid file uploaded.";
    }
}

// Fetch settings
$query = "SELECT * FROM login_settings LIMIT 1";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'] ?? '';
$helpdeskNumber = $settings['helpdesk_no'] ?? '';
$favicon = $settings['favicon'] ?? 'assets/images/favicon.ico';
?>

<!DOCTYPE html>
<html lang="en">

<meta http-equiv="content-type" content="text/html;charset=UTF-8" />

<head>
    <title>Admission Leads</title>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="" />
    <meta name="keywords" content="">
    <meta name="author" content="Codedthemes" />

    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">

    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">

    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .custom-upload-btn {
            display: inline-block;
            background-color: rgb(255, 208, 67);
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .custom-upload-btn i {
            margin-right: 5px;
            vertical-align: middle;
        }

        .btn-custom {
            display: inline-block;
            background-color: rgb(142, 185, 42);
            color: #fff;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            margin-right: 10px;
            text-decoration: none;
        }

        .btn-custom i {
            margin-right: 5px;
            vertical-align: middle;
        }

        input[type="file"] {
            display: none;
        }
    </style>
    <style>
        .filter-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }

        .filter-label {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control-sm {
            font-size: 13px;
            border-radius: 4px;
        }

        .category-badge {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #667eea30;
        }

        .category-badge .parent-cat {
            color: #667eea;
            font-weight: 600;
        }

        .category-badge .separator {
            margin: 0 6px;
            color: #adb5bd;
        }

        .category-badge .sub-cat {
            color: #764ba2;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }


        .btn-action {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 4px;
            margin: 0 2px;
        }

        .upload-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }



        .spinner-border {
            width: 1.5rem;
            height: 1.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.0/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.0/css/dataTables.dataTables.css" />
</head>

<body class="">

    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <!-- Header -->
    <?php
    include("header.php");
    ?>
    <!-- /Header -->

    <!-- navbar -->
    <?php
    include("navbar.php");
    ?>
    <!-- /navbar -->

    <section class="pcoded-main-container">
        <div class="pcoded-content">

            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Admission Lead
                                </h5>
                                <?php

                                if (isset($_SESSION['msg'])) {
                                    echo $_SESSION['msg'];
                                    unset($_SESSION['msg']); // remove after showing
                                }
                                ?>
                            </div>

                        </div>
                    </div>
                </div>
            </div>


            <div class="row">
                <?php
                $countQuery = "
SELECT 
    a.username, COUNT(la.admission_id) AS total_leads
FROM 
    lead_assignments la
JOIN 
    admin a ON la.admin_id = a._id
GROUP BY 
    la.admin_id
";


                ?>
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <!-- Upload Section -->
                            <?php if ($can_upload_leads): ?>
                                <div class="upload-section">
                                    <h6 class="mb-3"><i class="feather icon-upload mr-2"></i>Upload Leads</h6>
                                    <form action="" method="POST" enctype="multipart/form-data" id="leadForm">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="parent_category_id"><strong>Select Parent Category:</strong></label>
                                                    <select name="parent_category_id" id="parent_category_id" class="form-control" required>
                                                        <option value="">-- Select Parent Category --</option>
                                                        <?php
                                                        $parentQuery = "SELECT id, name FROM parent_category WHERE status = 1 ORDER BY name ASC";
                                                        $parentResult = mysqli_query($db, $parentQuery);
                                                        while ($pRow = mysqli_fetch_assoc($parentResult)) {
                                                            echo '<option value="' . htmlspecialchars($pRow['id']) . '">'
                                                                . htmlspecialchars($pRow['name']) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label for="category_id"><strong>Select Category:</strong></label>
                                                    <select name="category_id" id="category_id" class="form-control" required disabled>
                                                        <option value="">-- First Select Parent Category --</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-4 mt-4">
                                                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                                                <label for="csv_file" class="custom-upload-btn mb-0">
                                                    <i class="feather icon-upload"></i> Upload
                                                </label>
                                                <a href="assets/sample.csv" download="sample.csv" class="btn-custom">
                                                    <i class="feather icon-download"></i> Sample
                                                </a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                            <div class="card-body">
                                <?php if ($isAdmin || in_array('Download Leads', $permissions)): ?>

                                    <form method="POST" action="download-leads.php" class="d-flex align-items-end gap-2 flex-nowrap">

                                        <div class="form-group mb-0 ml-2">
                                            <label for="dateFrom" class="small mb-1">Date From:</label>
                                            <input type="date" class="form-control form-control-sm" name="dateFrom" id="dateFrom" style="width: 150px;">
                                        </div>

                                        <div class="form-group mb-0 ml-2">
                                            <label for="dateTo" class="small mb-1">Date To:</label>
                                            <input type="date" class="form-control form-control-sm" name="dateTo" id="dateTo" style="width: 150px;">
                                        </div>

                                        <div class="form-group mb-0 ml-2">
                                            <label for="statusFilter" class="small mb-1">Lead Status:</label>
                                            <select id="statusFilter" name="status" class="form-control form-control-sm" style="width: 160px;">
                                                <option value="">Select Lead Status</option>
                                                <!-- Options will be populated dynamically from the database -->
                                            </select>
                                        </div>

                                        <!-- User Filter -->
                                        <div class="form-group mb-0 ml-2">
                                            <label for="userFilter" class="small mb-1">User:</label>
                                            <select name="userFilter" id="userFilter" class="form-control form-control-sm" style="width: 140px;">
                                                <option value="">Select User</option>
                                                <?php
                                                $users = mysqli_query($db, "SELECT _id, username FROM admin");
                                                while ($u = mysqli_fetch_assoc($users)) {
                                                    $selected = (isset($_POST['userFilter']) && $_POST['userFilter'] == $u['_id']) ? 'selected' : '';
                                                    echo "<option value='{$u['_id']}' $selected>{$u['username']}</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>

                                        <!-- Category Filter -->
                                        <div class="form-group mb-0 ml-2">
                                            <label for="categoryFilter" class="small mb-1">Parent Category:</label>
                                            <select name="categoryFilter" id="categoryFilter" class="form-control form-control-sm" style="width: 160px;">
                                                <option value="">All Categories</option>
                                                <?php
                                                $catQuery = "SELECT id, name FROM parent_category WHERE status = 1 ORDER BY name ASC";
                                                $catResult = mysqli_query($db, $catQuery);
                                                while ($cat = mysqli_fetch_assoc($catResult)) {
                                                    $selected = (isset($_GET['categoryFilter']) && $_GET['categoryFilter'] == $cat['id']) ? 'selected' : '';
                                                    echo '<option value="' . htmlspecialchars($cat['id']) . '" ' . $selected . '>'
                                                        . htmlspecialchars($cat['name']) . '</option>';
                                                }
                                                ?>
                                            </select>
                                        </div>

                                        <div class="form-group mb-0 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary btn-sm mr-2 ml-2" name="download">
                                                <i class='feather icon-download'></i> Download
                                            </button>

                                            <button type="button" id="clearFilters" class="btn btn-warning btn-sm">
                                                <i class="feather icon-x-circle"></i> Clear
                                            </button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                                <form id="deleteMForm" class="mt-4" action="delete-leads.php" method="post">
                                    <div class="d-flex align-items-center mb-2">
                                        <?php if ($isAdmin || in_array('Delete Leads', $permissions)): ?>

                                            <button type="button" id="deleteSelected" class="btn btn-danger">
                                                <i class='feather icon-trash'></i> Delete Selected
                                            </button>

                                        <?php endif; ?> &nbsp;
                                        <?php if ($isAdmin || in_array('Assign Leads', $permissions)): ?>
                                            <button type="button" id="assigselected" class="btn btn-info mr-2" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
                                                <i class="feather icon-user-plus"></i> Assign Selected
                                            </button>
                                        <?php endif; ?>
                                        <!-- <button type="button" id="assigselected" class="btn btn-info mr-2" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
                                            <i class="feather icon-user-plus"></i> Total Leads
                                        </button>
                                        <button type="button" id="assigselected" class="btn btn-info mr-2" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
                                            <i class="feather icon-user-plus"></i> Unassigned Leads
                                        </button>
                                        <button type="button" id="assigselected" class="btn btn-info mr-2" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
                                            <i class="feather icon-user-plus"></i> Assigned Leads
                                        </button> -->



                                    </div>



                                    <!-- Table -->
                                    <div class="dt-responsive table-responsive">
                                        <table class="table table-striped table-bordered nowrap" id="leadsTable">
                                            <thead>
                                                <tr>
                                                    <th><input type='checkbox' id='selectAll'></th>
                                                    <th>SNO</th>
                                                    <th>NAME</th>
                                                    <th>MOBILE</th>
                                                    <th>EMAIL</th>
                                                    <th>STATE</th>
                                                    <th>CITY</th>
                                                    <th>SERVICES</th>
                                                    <th>CATEGORY</th>
                                                    <th>REMARKS</th>
                                                    <th>STATUS</th>
                                                    <th>DATE</th>
                                                    <?php if ($can_assign_leads) echo "<th>ASSIGNED TO</th>"; ?>
                                                    <th>ACTION</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $count = 1;
                                                while ($row = mysqli_fetch_assoc($result)) {
                                                    $encoded_id = base64_encode($row['admission_id']);
                                                    $status = strtolower($row['lead_status']);

                                                    $badgeMap = [
                                                        'untouched' => '#FF0B55',
                                                        'verified' => '#28a745',
                                                        'hot' => '#E52020',
                                                        'cold' => '#17a2b8',
                                                        'followup' => '#FE7743',
                                                        'warm' => '#FFB22C',
                                                        'not answering' => '#A76545',
                                                        'call after sometime' => '#ffc107',
                                                        'not reached' => '#854836',
                                                        'lead own' => '#096B68'
                                                    ];
                                                    $badgeColor = $badgeMap[$status] ?? '#6c757d';

                                                    $remarks = $row['remarks'];
                                                    $shortRemarks = strlen($remarks) > 40 ? substr($remarks, 0, 40) . '...' : $remarks;

                                                    $parentCat = !empty($row['parent_category_name']) ? htmlspecialchars($row['parent_category_name']) : '-';
                                                    $subCat = !empty($row['sub_category_name']) ? htmlspecialchars($row['sub_category_name']) : '-';

                                                    echo "<tr>";
                                                    echo "<td><input type='checkbox' class='menuCheckbox' name='advt_ids[]' value='$encoded_id'></td>";
                                                    echo "<td>$count</td>";
                                                    echo "<td><strong>" . htmlspecialchars($row['name']) . "</strong></td>";
                                                    echo "<td>" . htmlspecialchars($row['mobile']) . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['state']) . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['city']) . "</td>";
                                                    echo "<td>" . htmlspecialchars($row['course_type']) . "</td>";
                                                    echo "<td><div class='category-badge'><span class='parent-cat'>$parentCat</span><span class='separator'>›</span><span class='sub-cat'>$subCat</span></div></td>";
                                                    echo "<td title='" . htmlspecialchars($row['remarks']) . "'>" . htmlspecialchars($shortRemarks) . "</td>";
                                                    echo "<td><span class='badge status-badge' style='background-color: $badgeColor; color: #fff;'>" . ucfirst($status) . "</span></td>";
                                                    echo "<td>" . date("d-m-Y", strtotime($row['date'])) . "</td>";

                                                    if ($can_assign_leads) {
                                                        echo "<td>";
                                                        $assignedAdminId = '';
                                                        $assignQuery = "SELECT admin_id FROM lead_assignments WHERE admission_id = ?";
                                                        $stmt = $db->prepare($assignQuery);
                                                        $stmt->bind_param("i", $row['admission_id']);
                                                        $stmt->execute();
                                                        $stmt->bind_result($assignedAdminId);
                                                        $stmt->fetch();
                                                        $stmt->close();

                                                        echo '<select class="form-control form-control-sm" onchange="saveUserId(this, ' . $row['admission_id'] . ')">';
                                                        echo '<option value="">Select User</option>';
                                                        $query_admin = "SELECT _id, username FROM admin ORDER BY username";
                                                        $admin_result = $db->query($query_admin);
                                                        while ($admin_row = $admin_result->fetch_assoc()) {
                                                            $selected = ($admin_row['_id'] == $assignedAdminId) ? 'selected' : '';
                                                            echo '<option value="' . $admin_row['_id'] . '" ' . $selected . '>' . htmlspecialchars($admin_row['username']) . '</option>';
                                                        }
                                                        echo '</select>';
                                                        echo "</td>";
                                                    }

                                                    echo "<td>";
                                                    echo "<a href='javascript:void(0)' class='btn btn-sm btn-warning btn-action viewLead' data-id='$encoded_id' title='View'><i class='feather icon-eye'></i></a>";
                                                    echo "<a href='javascript:void(0)' class='btn btn-sm btn-success btn-action editLead' data-lead-id='$encoded_id' data-status='" . htmlspecialchars($row['lead_status']) . "' data-remark='" . htmlspecialchars($row['remarks']) . "' title='Edit'><i class='feather icon-edit'></i></a>";
                                                    if ($can_delete_leads) {
                                                        echo "<a href='javascript:void(0)' class='btn btn-sm btn-danger btn-action delete-btn' data-id='$encoded_id' data-bs-toggle='modal' data-bs-target='#deleteModal' title='Delete'><i class='feather icon-trash'></i></a>";
                                                    }
                                                    echo "</td>";
                                                    echo "</tr>";

                                                    $count++;
                                                }

                                                // if ($count == 1) {
                                                //     echo "<tr><td colspan='20' class='text-center py-5'><div class='empty-state'><i class='feather icon-inbox'></i><h5>No Leads Found</h5><p class='text-muted'>Upload leads or adjust your filters</p></div></td></tr>";
                                                // }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <br />
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    </section>
    <!-- Modal -->
    <!-- Bulk Assign Modal -->
    <div class="modal fade" id="bulkAssignModal" tabindex="-1" aria-labelledby="bulkAssignModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form id="bulkAssignForm">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="bulkAssignModalLabel">Assign Selected Leads</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="selected_ids" id="selected_ids_input">

                        <label for="bulkUser">Select Admin</label>
                        <select class="form-control" id="bulkUser" name="admin_id" required>
                            <option value="">Select Admin</option>
                            <?php
                            $query_admin = "SELECT _id, username FROM admin";
                            $admin_result = $db->query($query_admin);
                            while ($admin_row = $admin_result->fetch_assoc()) {
                                echo '<option value="' . $admin_row['_id'] . '">' . htmlspecialchars($admin_row['username']) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">Assign</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

























    <div class="modal fade" id="viewLeadModal" tabindex="-1" aria-labelledby="viewLeadModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewLeadModalLabel">Lead Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">X</button>
                </div>
                <div class="modal-body" id="leadDetails">
                    Loading details...
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Lead Status Modal -->
    <div class="modal" id="editLeadStatusModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">Update Lead Status</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">X</button>
                </div>
                <div class="modal-body">
                    <form method="post">
                        <input type="hidden" id="admission_id" name="admission_id">
                        <div class="form-group">
                            <label for="lead_status">Lead Status:</label>
                            <select id="lead_status" name="lead_status" class="form-control">
                                <!-- Options will be dynamically loaded here -->
                            </select>
                        </div>

                        <div class="form-group mt-3">
                            <label for="remarks">Remarks:</label>
                            <textarea id="remarks" name="remarks" class="form-control" rows="3" placeholder="Enter remarks..."></textarea>
                        </div>

                        <button type="submit" name="editleads" class="btn btn-primary">Update</button>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Displaying Filtered Leads -->
    <div class="modal fade" id="viewFilteredLeadsModal" tabindex="-1" aria-labelledby="viewFilteredLeadsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewFilteredLeadsModalLabel">Filtered Leads</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">X</button>
                </div>
                <div class="modal-body" id="filteredLeadsResults">
                    <!-- Filtered leads will be shown here in a table -->
                </div>
            </div>
        </div>
    </div>
    <!-- Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete</h5>
                    <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    Are you sure you want to delete the leads?
                    <input type="hidden" id="deleteId">
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>

                </div>

            </div>
        </div>
    </div>
    <!-- Alert Modal -->
    <div class="modal fade" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="alertModalLabel">No Selection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">X</button>
                </div>
                <div class="modal-body">
                    Please select at least one lead to delete.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Multi Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">X</button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete the selected leads?
                </div>
                <div class="modal-footer">
                    <button type="button" id="confirmMultiDelete" class="btn btn-danger">Delete</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/vendor-all.min.js"></script>
    <!-- <script src="assets/js/plugins/bootstrap.min.js"></script> -->
    <script src="assets/js/pcoded.min.js"></script>
    <!--<script src="assets/js/menu-setting.min.js"></script>-->
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
    <!-- <script src="assets/js/pages/data-export-custom.js"></script> -->
    <!-- jQuery (Ensure it is loaded first) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.0/js/dataTables.js"></script>
    <script>
        let table = new DataTable('#leadsTable');
    </script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.0/dist/sweetalert2.min.js"></script>

    <!-- Bootstrap Bundle (Includes Popper.js and Bootstrap JS) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        $(document).ready(function() {
            $("#updateuser").delay(5000).slideUp(300);
        });
    </script>



    <script>
        // JavaScript to handle deletion of selected categories
        document.getElementById("deleteSelected").addEventListener("click", function() {
            var form = document.getElementById("deleteForm");
            var checkboxes = form.querySelectorAll("input[type=checkbox]:checked");

            // Check if any checkboxes are selected
            if (checkboxes.length === 0) {
                alert("Please select at least one user to delete.");
            } else {
                // Show the delete confirmation modal
                $('#deleteModal').modal('show');
            }
        });

        // JavaScript to confirm deletion when the "Delete" button is clicked in the modal
        document.getElementById("confirmDelete").addEventListener("click", function() {
            var form = document.getElementById("deleteForm");
            form.submit(); // Submit the form to delete the selected users
        });
    </script>

    <script>
        function toggleCheckboxes(source) {
            const checkboxes = document.querySelectorAll('.menuCheckbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
        }
    </script>
    <script>
        $(document).on('click', '.viewLead', function() {
            var encodedId = $(this).data('id'); // Get the encoded ID from the button

            // Show the modal
            var modal = new bootstrap.Modal(document.getElementById('viewLeadModal'));
            modal.show();

            // Fetch details with AJAX
            $.ajax({
                url: 'fetch-lead-details.php', // Backend script
                type: 'POST',
                data: {
                    id: encodedId
                },
                success: function(response) {
                    // Populate modal body with response
                    $('#leadDetails').html(response);
                },
                error: function() {
                    $('#leadDetails').html('<p>Erroa fetching details.</p>');
                }
            });
        });
    </script>
    <script>
        $(document).ready(function() {
            $(document).on("click", ".editLead", function() {
                let admissionId = $(this).data("lead-id");
                let currentStatus = $(this).data("status")?.toLowerCase().trim();
                let remark = $(this).data("remark");

                $("#admission_id").val(admissionId);
                $("#remarks").val(remark);

                // Fetch lead status options
                $.ajax({
                    url: "fetch_lead_status.php",
                    method: "GET",
                    success: function(data) {
                        $("#lead_status").html(data);

                        // Find and select the matching option
                        $("#lead_status option").each(function() {
                            if ($(this).val().toLowerCase().trim() === currentStatus) {
                                $(this).prop("selected", true);
                                return false;
                            }
                        });

                        $("#editLeadStatusModal").modal("show");
                    },
                    error: function() {
                        alert("Error fetching lead statuses.");
                    }
                });
            });
        });
    </script>

    <script>
        $(document).ready(function() {
            // Populate the dropdown with lead statuses
            $.ajax({
                url: 'fetch_lead_status.php',
                type: 'GET',
                success: function(response) {
                    $('#statusFilter').append(response);
                }
            });

            // ✅ Trigger fetch on ANY filter change
            $('#statusFilter, #dateFrom, #dateTo, #userFilter, #categoryFilter').on('change', function() {
                fetchLeads();
            });

            // ✅ Unified fetch function - reads values from DOM
            function fetchLeads() {
                const status = $('#statusFilter').val();
                const dateFrom = $('#dateFrom').val();
                const dateTo = $('#dateTo').val();
                const userFilter = $('#userFilter').val();
                const categoryFilter = $('#categoryFilter').val();

                $.ajax({
                    url: 'fetch_filtered_leads.php',
                    type: 'POST',
                    data: {
                        status: status,
                        dateFrom: dateFrom,
                        dateTo: dateTo,
                        userFilter: userFilter,
                        categoryFilter: categoryFilter
                    },
                    success: function(response) {
                        // ✅ Just update tbody - don't touch DataTable instance
                        $('#leadsTable tbody').html(response);
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error:", error);
                        $('#leadsTable tbody').html('<tr><td colspan="12" class="text-center text-danger">Error fetching leads. Please try again.</td></tr>');
                    }
                });
            }

            // ✅ Clear filters - reset all inputs AND trigger fetch
            $('#clearFilters').on('click', function() {
                $('#dateFrom').val('');
                $('#dateTo').val('');
                $('#statusFilter').val('');
                $('#userFilter').val('');
                $('#categoryFilter').val(''); // ✅ Clear category too

                fetchLeads(); // ✅ Call with no params - function reads from DOM
            });

            // ✅ Support category filter from URL on initial page load
            const urlParams = new URLSearchParams(window.location.search);
            const urlCategory = urlParams.get('categoryFilter');
            if (urlCategory) {
                $('#categoryFilter').val(urlCategory);
                // Optional: auto-fetch if you want AJAX loading on page load with filters
                // fetchLeads();
            }
        });
    </script>

    <script>
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
    <!-- Auto-submit on file selection -->
    <script>
        document.getElementById('csv_file').addEventListener('change', function() {
            document.getElementById('leadForm').submit();
        });
    </script>
    <!-- Your custom JS code -->
    <script>
        <?php if ($uploadSuccess === true): ?>
            Swal.fire({
                title: 'Success!',
                text: 'Leads uploaded successfully.',
                icon: 'success',
                confirmButtonText: 'OK'
            }).then(() => {
                window.location = 'admission-leads.php';
            });
        <?php elseif ($uploadSuccess === false): ?>
            Swal.fire({
                title: 'Error!',
                text: '<?= addslashes($errorMsg) ?>',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        <?php endif; ?>
    </script>
    <script>
        // When trash icon is clicked
        document.querySelectorAll('.delete-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                document.getElementById('deleteId').value = id;
            });
        });

        // When Delete button in modal is clicked
        document.getElementById('confirmDelete').addEventListener('click', function() {
            const id = document.getElementById('deleteId').value;
            if (id) {
                window.location.href = 'delete-leads.php?id=' + encodeURIComponent(id);
            } else {
                alert('No ID found to delete.');
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function saveUserId(selectElement, admissionId) {
            const adminId = selectElement.value;

            fetch('save-user-id.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `admin_id=${adminId}&admission_id=${admissionId}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error("HTTP error " + response.status);
                    }
                    return response.text(); // read raw response
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: data.message,
                                confirmButtonText: 'OK'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message,
                                confirmButtonText: 'OK'
                            });
                        }
                    } catch (e) {
                        console.error("Invalid JSON:", text);
                        Swal.fire({
                            icon: 'error',
                            title: 'Server Error',
                            text: 'Unexpected server response. Please try again.',
                            confirmButtonText: 'OK'
                        });
                    }
                })
                .catch(error => {
                    console.error("Fetch error:", error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Request Failed',
                        text: 'Network/server error occurred.',
                        confirmButtonText: 'OK'
                    });
                });

        }
    </script>
    <script>
        document.getElementById('assigselected').addEventListener('click', function() {
            const checkboxes = document.querySelectorAll('input.menuCheckbox:checked');
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            document.getElementById('selected_ids_input').value = selectedIds.join(',');
        });

        document.getElementById('bulkAssignForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('bulk-assign.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async res => {
                    const text = await res.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error("Invalid JSON response: " + text);
                    }
                })
                .then(data => {
                    if (data.status === 'success') {
                        const modalEl = document.getElementById('bulkAssignModal');
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        modal.hide();

                        Swal.fire({
                            title: 'Success!',
                            text: data.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            title: 'Error',
                            text: data.message,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({
                        title: 'Error',
                        text: err.message,
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                });

        });
    </script>


    <script>
        document.getElementById("deleteSelected").addEventListener("click", function() {
            var form = document.getElementById("deleteMForm");
            var checkboxes = form.querySelectorAll("input[name='advt_ids[]']:checked");

            if (checkboxes.length === 0) {
                var alertModal = new bootstrap.Modal(document.getElementById('alertModal'));
                alertModal.show();
            } else {
                var deleteSelectedModal = new bootstrap.Modal(document.getElementById('deleteSelectedModal'));
                deleteSelectedModal.show();
            }
        });

        document.getElementById("confirmMultiDelete").addEventListener("click", function() {
            document.getElementById("deleteMForm").submit();
        });
    </script>
    <script>
        $(document).ready(function() {
            // When parent category changes, load child categories
            $('#parent_category_id').on('change', function() {
                var parentId = $(this).val();
                var $childSelect = $('#category_id');

                $childSelect.html('<option value="">-- Loading categories... --</option>').prop('disabled', true);

                if (parentId) {
                    $.ajax({
                        url: 'fetch_categories.php',
                        type: 'POST',
                        data: {
                            parent_id: parentId
                        },
                        dataType: 'json',
                        success: function(response) {
                            $childSelect.html('<option value="">-- Select Category --</option>');
                            if (response.success && response.data.length > 0) {
                                $.each(response.data, function(index, category) {
                                    $childSelect.append(
                                        '<option value="' + category.category_id + '">' +
                                        category.category_name + '</option>'
                                    );
                                });
                                $childSelect.prop('disabled', false);
                            } else {
                                $childSelect.html('<option value="">No categories found</option>');
                            }
                        },
                        error: function() {
                            $childSelect.html('<option value="">Error loading categories</option>');
                            Swal.fire('Error', 'Failed to load categories.', 'error');
                        }
                    });
                } else {
                    $childSelect.html('<option value="">-- First Select Parent Category --</option>');
                }
            });

            // Form validation
            $('#leadForm').on('submit', function(e) {
                if ($('#category_id').prop('disabled') || !$('#category_id').val()) {
                    e.preventDefault();
                    Swal.fire('Selection Required', 'Please select both Parent Category and Category.', 'warning');
                    return false;
                }
            });
        });
    </script>

</body>

</html>