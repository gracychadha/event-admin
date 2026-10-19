<?php
/**
 * Admin workflow access helper.
 * Include AFTER session_start() and db/config.php.
 * Populates: $adminId, $roleId, $roleName, $permissions[], $isAdmin, $isReviewer, $isOrganizer
 */

if (!isset($adminId)) {
    $adminId = $_SESSION['login_user_id'] ?? null;
}

$roleId = '';
$roleName = '';
$permissions = [];
$isAdmin = false;
$isReviewer = false;
$isOrganizer = false;

if ($adminId) {
    $adminQuery = "SELECT admin_role FROM admin WHERE _id = '" . (int) $adminId . "'";
    $adminResult = mysqli_query($db, $adminQuery);
    if ($adminRow = mysqli_fetch_assoc($adminResult)) {
        $roleId = $adminRow['admin_role'];
    }

    if ($roleId !== '') {
        $roleQuery = "SELECT role_name FROM roles WHERE role_id = '" . (int) $roleId . "'";
        $roleResult = mysqli_query($db, $roleQuery);
        if ($roleRow = mysqli_fetch_assoc($roleResult)) {
            $roleName = strtolower($roleRow['role_name']);
        }
    }

    $isAdmin = ($roleName === 'admin');
    $isReviewer = ($roleName === 'reviewer');
    $isOrganizer = ($roleName === 'organizer');

    $permQuery = "SELECT p.title
                  FROM navigation_menus p
                  INNER JOIN role_permissions rp ON p.id = rp.permission_id
                  WHERE rp.role_name = '" . mysqli_real_escape_string($db, $roleName) . "'";
    $permResult = mysqli_query($db, $permQuery);
    while ($permRow = mysqli_fetch_assoc($permResult)) {
        $permissions[] = $permRow['title'];
    }
}

if (!function_exists('abstract_can')) {
    function abstract_can($permission)
    {
        global $isAdmin, $permissions;
        return $isAdmin || in_array($permission, $permissions) || in_array('All', $permissions);
    }
}
