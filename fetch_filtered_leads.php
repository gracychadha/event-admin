<?php
require_once 'db/config.php';
session_start();

// ✅ Get current user permissions (same logic as main page)
$login_user_id = $_SESSION['login_user_id'] ?? 0;
$isAdmin = false;
$permissions = [];
$role_name = '';

if ($login_user_id) {
    $get_admin_query = "SELECT admin_role FROM admin WHERE _id = ?";
    $stmt = mysqli_prepare($db, $get_admin_query);
    mysqli_stmt_bind_param($stmt, "i", $login_user_id);
    mysqli_stmt_execute($stmt);
    $get_admin_result = mysqli_stmt_get_result($stmt);
    $admin_row = mysqli_fetch_assoc($get_admin_result);
    mysqli_stmt_close($stmt);
    
    if ($admin_row) {
        $role_id = $admin_row['admin_role'];
        $role_query = "SELECT role_name FROM roles WHERE role_id = ?";
        $stmt = mysqli_prepare($db, $role_query);
        mysqli_stmt_bind_param($stmt, "i", $role_id);
        mysqli_stmt_execute($stmt);
        $role_result = mysqli_stmt_get_result($stmt);
        $role_row = mysqli_fetch_assoc($role_result);
        mysqli_stmt_close($stmt);
        $role_name = strtolower($role_row['role_name'] ?? '');
        
        if ($role_name == 'admin') {
            $isAdmin = true;
            $permissions = ['Upload Leads', 'Download Leads', 'Delete Leads', 'Assign Leads'];
        } elseif ($role_name == 'manager') {
            $permissions = ['Upload Leads'];
        }
    }
}

// ✅ Get filter parameters
$status = $_POST['status'] ?? '';
$dateFrom = $_POST['dateFrom'] ?? '';
$dateTo = $_POST['dateTo'] ?? '';
$userFilter = $_POST['userFilter'] ?? '';
$categoryFilter = $_POST['categoryFilter'] ?? '';

// ✅ Base query - SAME as main page
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
    WHERE 1=1
";

// ✅ Apply filters with prepared statements (secure)
$params = [];
$types = '';

if (!empty($categoryFilter)) {
    $query .= " AND ae.category_id = ?";
    $params[] = intval($categoryFilter);
    $types .= 'i';
}
if (!empty($status)) {
    $query .= " AND ae.lead_status = ?";
    $params[] = $status;
    $types .= 's';
}
if (!empty($dateFrom)) {
    $query .= " AND DATE(ae.date) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if (!empty($dateTo)) {
    $query .= " AND DATE(ae.date) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
if (!empty($userFilter)) {
    $query .= " AND la.admin_id = ?";
    $params[] = intval($userFilter);
    $types .= 'i';
}

// ✅ Role-based restrictions
if ($role_name == 'telecaller') {
    $query .= " AND la.admin_id = ?";
    $params[] = $login_user_id;
    $types .= 'i';
} elseif (!in_array($role_name, ['admin', '']) && !empty($role_name)) {
    $query .= " AND la.admin_id = ?";
    $params[] = $login_user_id;
    $types .= 'i';
}

$query .= " ORDER BY ae.date DESC";

// ✅ Execute query
if (!empty($params)) {
    $stmt = mysqli_prepare($db, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);
} else {
    $result = mysqli_query($db, $query);
}

// ✅ Badge color map - SAME as main page
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

// ✅ Output table rows - EXACT same HTML structure as main page
$count = 1;
while ($row = mysqli_fetch_assoc($result)) {
    $encoded_id = base64_encode($row['admission_id']);
    $status_val = strtolower($row['lead_status']);
    $badgeColor = $badgeMap[$status_val] ?? '#6c757d';
    
    // Remarks truncation - SAME 40 chars + title attribute
    $remarks = $row['remarks'] ?? '';
    $shortRemarks = strlen($remarks) > 40 ? substr($remarks, 0, 40) . '...' : $remarks;
    
    // Category display with badge styling - SAME structure
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
    
    // ✅ Category badge - EXACT same HTML
    echo "<td><div class='category-badge'><span class='parent-cat'>$parentCat</span><span class='separator'>›</span><span class='sub-cat'>$subCat</span></div></td>";
    
    // ✅ Remarks with title - SAME
    echo "<td title='" . htmlspecialchars($row['remarks']) . "'>" . htmlspecialchars($shortRemarks) . "</td>";
    
    // ✅ Status badge with class and style - SAME
    echo "<td><span class='badge status-badge' style='background-color: $badgeColor; color: #fff;'>" . ucfirst($status_val) . "</span></td>";
    
    echo "<td>" . date("d-m-Y", strtotime($row['date'])) . "</td>";
    
    // ✅ Assigned To dropdown - ONLY if permission + SAME structure
    if ($isAdmin || in_array('Assign Leads', $permissions)) {
        echo "<td>";
        $assignedAdminId = '';
        $assignQuery = "SELECT admin_id FROM lead_assignments WHERE admission_id = ?";
        $stmt = $db->prepare($assignQuery);
        if ($stmt) {
            $stmt->bind_param("i", $row['admission_id']);
            $stmt->execute();
            $stmt->bind_result($assignedAdminId);
            $stmt->fetch();
            $stmt->close();
        }
        
        echo '<select class="form-control form-control-sm" onchange="saveUserId(this, '.$row['admission_id'].')">';
        echo '<option value="">Select User</option>';
        $query_admin = "SELECT _id, username FROM admin ORDER BY username";
        $admin_result = $db->query($query_admin);
        while ($admin_row = $admin_result->fetch_assoc()) {
            $selected = ($admin_row['_id'] == $assignedAdminId) ? 'selected' : '';
            echo '<option value="'.$admin_row['_id'].'" '.$selected.'>'.htmlspecialchars($admin_row['username']).'</option>';
        }
        echo '</select>';
        echo "</td>";
    }
    
    // ✅ Action buttons - SAME classes and structure
    echo "<td>";
    echo "<a href='javascript:void(0)' class='btn btn-sm btn-warning btn-action viewLead' data-id='$encoded_id' title='View'><i class='feather icon-eye'></i></a>";
    echo "<a href='javascript:void(0)' class='btn btn-sm btn-success btn-action editLead' data-lead-id='$encoded_id' data-status='".htmlspecialchars($row['lead_status'])."' data-remark='".htmlspecialchars($row['remarks'])."' title='Edit'><i class='feather icon-edit'></i></a>";
    
    if ($isAdmin || in_array('Delete Leads', $permissions)) {
        echo "<a href='javascript:void(0)' class='btn btn-sm btn-danger btn-action delete-btn' data-id='$encoded_id' data-bs-toggle='modal' data-bs-target='#deleteModal' title='Delete'><i class='feather icon-trash'></i></a>";
    }
    echo "</td>";
    echo "</tr>";
    
    $count++;
}


?>