<?php
session_start();
error_reporting(E_ALL);
if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}
include("db/config.php");
include("registration_pricing_helpers.php");

$msg = "";
$old = $_POST;
$selectedCategory   = (int)($_POST['category_id'] ?? 0);
$selectedParentId   = (int)($_POST['workshop_parent_id'] ?? 0);
$selectedWorkshopId = (int)($_POST['workshop_option_id'] ?? 0);
$accommodation      = $_POST['accommodation'] ?? 'No';

// ---- Load categories ----
$categories = mysqli_fetch_all(mysqli_query(
    $db,
    "SELECT id, category_name FROM registration_categories WHERE status=1 ORDER BY id"
), MYSQLI_ASSOC);

// ---- Active period ----
$today = date('Y-m-d');
$stmt = mysqli_prepare($db, "SELECT id, period_name FROM registration_periods
    WHERE status=1 AND ? BETWEEN start_date AND end_date ORDER BY display_order LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $today);
mysqli_stmt_execute($stmt);
$currentPeriod = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// ---- Load ALL parent workshops with their children (rendered once, toggled by JS) ----
$parentWorkshops = [];
$stmt = mysqli_prepare($db, "SELECT id, option_name FROM registration_category_options
    WHERE parent_id IS NULL AND status=1 AND availability=1 ORDER BY option_name");
mysqli_stmt_execute($stmt);
$parentWorkshops = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Group children under each parent so they can be shown/hidden instantly
$childrenByParent = [];
foreach ($parentWorkshops as $pw) {
    $childrenByParent[$pw['id']] = [];
}
if ($parentWorkshops) {
    $ids = array_column($parentWorkshops, 'id');
    $in  = implode(',', array_map('intval', $ids));
    $res = mysqli_query($db, "SELECT id, parent_id, option_name, amount, currency
        FROM registration_category_options
        WHERE parent_id IN ($in) AND status=1 AND availability=1 ORDER BY option_name");
    while ($c = mysqli_fetch_assoc($res)) {
        $childrenByParent[$c['parent_id']][] = $c;
    }
}

// ---- Base price for selected category ----
$basePrice = null;
if ($selectedCategory && $currentPeriod) {
    $stmt = mysqli_prepare($db, "SELECT * FROM registration_category_prices WHERE category_id=? AND period_id=?");
    mysqli_stmt_bind_param($stmt, "ii", $selectedCategory, $currentPeriod['id']);
    mysqli_stmt_execute($stmt);
    $basePrice = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

// ---- Selected workshop fee (child row) ----
$workshopFee = 0.0;
$workshopName = 'None';
$workshopId = null;
if ($selectedWorkshopId > 0) {
    $stmt = mysqli_prepare($db, "SELECT id, option_name, amount FROM registration_category_options WHERE id=?");
    mysqli_stmt_bind_param($stmt, "i", $selectedWorkshopId);
    mysqli_stmt_execute($stmt);
    if ($w = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $workshopFee = (float)$w['amount'];
        $workshopName = $w['option_name'];
        $workshopId = (int)$w['id'];
    }
    mysqli_stmt_close($stmt);
}

// ---- Submit ----
if (isset($_POST['submit_user'])) {
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $contact    = trim($_POST['contact_number'] ?? '');
    $password   = $_POST['password'] ?? '';
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $membership = trim($_POST['membership_number'] ?? '');
    $parentId   = (int)($_POST['workshop_parent_id'] ?? 0);
    $childId    = (int)($_POST['workshop_option_id'] ?? 0);

    $errors = [];
    if (!$firstName || !$lastName || !$email || !$contact || $categoryId <= 0)
        $errors[] = "All required (*) fields must be filled.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";

    // EFI members need a membership number
    $isEfi = false;
    foreach ($categories as $c) {
        if ($c['id'] == $categoryId && stripos($c['category_name'], 'EFI') !== false) $isEfi = true;
    }
    if ($isEfi && $membership === '') $errors[] = "Membership Number is required for EFI Members.";

    if ($parentId > 0 && $childId == 0) $errors[] = "Please select a specific workshop.";

    $stmt = mysqli_prepare($db, "SELECT student_id FROM registered_users WHERE email=?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) $errors[] = "Email already registered.";
    mysqli_stmt_close($stmt);

    if (empty($errors) && !$currentPeriod) $errors[] = "No active registration period.";

    if (empty($errors)) {
        // Re-validate workshop selection on server (never trust hidden inputs)
        $workshopFee = 0.0;
        $workshopName = 'None';
        $workshopId = null;
        if ($childId > 0) {
            $stmt = mysqli_prepare($db, "SELECT id, option_name, amount FROM registration_category_options
                WHERE id=? AND parent_id=? AND status=1 AND availability=1");
            mysqli_stmt_bind_param($stmt, "ii", $childId, $parentId);
            mysqli_stmt_execute($stmt);
            if ($w = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                $workshopFee = (float)$w['amount'];
                $workshopName = $w['option_name'];
                $workshopId = (int)$w['id'];
            }
            mysqli_stmt_close($stmt);
            if (!$workshopId) $errors[] = "Selected workshop is not available.";
        }
    }

           if (empty($errors)) {
        $baseFee = $basePrice ? (float)$basePrice['amount'] : 0;
        $fee = $baseFee + $workshopFee;

        $discountAmount = 0.0;
        $discountName = '';
        if ($basePrice) {
            $stmt = mysqli_prepare($db, "SELECT d.* FROM registration_discounts d
                INNER JOIN registration_discount_categories dc ON dc.discount_id=d.id
                WHERE d.status=1 AND dc.category_id=? AND ? BETWEEN d.start_date AND d.end_date
                ORDER BY d.discount_value DESC LIMIT 1");
            mysqli_stmt_bind_param($stmt, "is", $categoryId, $today);
            mysqli_stmt_execute($stmt);
            if ($disc = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                $discountAmount = $disc['discount_type'] === 'percentage'
                    ? $fee * $disc['discount_value'] / 100 : min($disc['discount_value'], $fee);
                $discountName = $disc['discount_name'];
            }
            mysqli_stmt_close($stmt);
        }

        $net = $fee - $discountAmount;
        $gst = 0.0;
        $gstType = 'not_applicable';
        $gstPct = 0;
        if ($basePrice) {
            $gstType = $basePrice['gst_type'];
            $gstPct = (float)$basePrice['gst_percentage'];
            if ($gstType === 'excluded') $gst = round($net * $gstPct / 100, 2);
            elseif ($gstType === 'included') $gst = round($net * $gstPct / (100 + $gstPct), 2);
        }
        $payable = round($gstType === 'excluded' ? $net + $gst : $net, 2);

        mysqli_begin_transaction($db);
        try {
            // 1. Assign ALL Variables (21 total for registered_users)
            $prefixVal        = $_POST['prefix'] ?? '';
            $middleNameVal    = trim($_POST['middle_name'] ?? '');
            $genderVal        = $_POST['gender'] ?? '';
            $passwordHash     = password_hash($password, PASSWORD_DEFAULT);
            $dialCodeVal      = $_POST['dial_code'] ?? '+91';
            $companyNameVal   = trim($_POST['company_name'] ?? ''); // <-- ADDED THIS
            $accommodationVal = $_POST['accommodation'] ?? 'No';
            $addressTypeVal   = $_POST['address_type'] ?? 'Personal';
            $addressVal       = trim($_POST['address'] ?? '');
            $countryVal       = $_POST['country'] ?? '';
            $stateVal         = trim($_POST['state'] ?? '');
            $cityVal          = trim($_POST['city'] ?? '');
            $postalCodeVal    = trim($_POST['postal_code'] ?? '');
            $statusVal        = (int)($_POST['status'] ?? 0);
            $emailStatusVal   = $_POST['email_status'] ?? 'Not Verified';
            $userCommentVal   = trim($_POST['user_comment'] ?? '');

            // Insert user (21 columns = 21 placeholders)
            $stmt = mysqli_prepare($db, "INSERT INTO registered_users
                (prefix, first_name, middle_name, last_name, email, gender, password,
                 dial_code, contact_number, company_name, membership_number, accommodation,
                 address_type, address, country, state, city, postal_code, status, email_status, description)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssssssssiss", // 20 's' + 1 'i' + 1 's' = 21 chars
                $prefixVal,
                $firstName,
                $middleNameVal,
                $lastName,
                $email,
                $genderVal,
                $passwordHash,
                $dialCodeVal,
                $contact,
                $companyNameVal,   // <-- ADDED HERE (10th position)
                $membership,
                $accommodationVal,
                $addressTypeVal,
                $addressVal,
                $countryVal,
                $stateVal,
                $cityVal,
                $postalCodeVal,
                $statusVal,
                $emailStatusVal,
                $userCommentVal
            );
            mysqli_stmt_execute($stmt);
            $userId = mysqli_insert_id($db);

            $catName = '';
            foreach ($categories as $c) {
                if ($c['id'] == $categoryId) {
                    $catName = $c['category_name'];
                    break;
                }
            }
            $ref = 'REG-' . date('Ym') . '-' . random_int(1000, 9999);
            $currencyVal = $basePrice['currency'] ?? 'INR';

            // Insert registration (18 placeholders)
            $stmt = mysqli_prepare($db, "INSERT INTO registrations
                (registration_ref, user_id, category_id, category_name, period_id, period_name,
                 original_fee, currency, option_id, option_name, option_amount,
                 discount_name, discount_amount, taxable_amount,
                 gst_type, gst_percentage, gst_amount, payable_amount, payment_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending')");
            mysqli_stmt_bind_param(
                $stmt,
                "siisisdsdsdsdsdddd", // FIXED: period_name is 's', original_fee is 'd'
                $ref,
                $userId,
                $categoryId,
                $catName,
                $currentPeriod['id'],
                $currentPeriod['period_name'],
                $baseFee,
                $currencyVal,
                $workshopId,
                $workshopName,
                $workshopFee,
                $discountName,
                $discountAmount,
                $net,
                $gstType,
                $gstPct,
                $gst,
                $payable
            );
            mysqli_stmt_execute($stmt);
            mysqli_commit($db);

            $msg = "<div class='alert alert-success'>User added — Ref <strong>$ref</strong>, Payable <strong>"
                . rp_money($payable, $currencyVal) . "</strong></div>";
        } catch (Exception $e) {
            mysqli_rollback($db);
            $msg = "<div class='alert alert-danger'>" . $e->getMessage() . "</div>";
        }
    } else {
        $msg = "<div class='alert alert-danger'>" . implode('<br>', $errors) . "</div>";
    }
}

$v = function ($k, $d = '') {
    global $old;
    return htmlspecialchars($old[$k] ?? $d);
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Add User</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .red-text {
            color: red;
        }

        .form-label {
            font-weight: 600;
            display: block;
            margin-bottom: 5px;
        }

        .ws-children {
            display: none;
        }
    </style>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>
    <?php include("header.php");
    include("navbar.php"); ?>

    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Add User</h5>
                            </div>
                            <a href="registrations.php" class="btn btn-primary btn-sm float-right">View User</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <h5><i class="feather icon-edit"></i> Input</h5>
                        </div>
                        <div class="card-body">
                            <?= $msg ?>
                            <form method="post" autocomplete="off" id="userForm">
                                <div class="row">
                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Prefix <span class="red-text">(*)</span></label>
                                            <select name="prefix" class="form-control">
                                                <option value="">Select</option>
                                                <?php foreach (['Mr', 'Mrs', 'Ms', 'Dr', 'Prof'] as $p): ?><option <?= ($old['prefix'] ?? '') === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">First Name <span class="red-text">(*)</span></label>
                                            <input name="first_name" class="form-control" value="<?= $v('first_name') ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Middle Name</label>
                                            <input name="middle_name" class="form-control" value="<?= $v('middle_name') ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Last Name <span class="red-text">(*)</span></label>
                                            <input name="last_name" class="form-control" value="<?= $v('last_name') ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Email <span class="red-text">(*)</span></label>
                                            <input type="email" name="email" class="form-control" value="<?= $v('email') ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Gender</label>
                                            <select name="gender" class="form-control">
                                                <option value="">Select</option>
                                                <?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= ($old['gender'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Password <span class="red-text">(*)</span></label>
                                            <input type="password" name="password" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Mobile <span class="red-text">(*)</span></label>
                                            <div class="input-group"><select name="dial_code" class="form-control" style="max-width:90px">
                                                    <?php foreach (['+91', '+1', '+44', '+971'] as $d): ?><option <?= ($old['dial_code'] ?? '+91') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select>
                                                <input name="contact_number" class="form-control" value="<?= $v('contact_number') ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Current/Affiliations</label>
                                            <input name="company_name" class="form-control" value="<?= $v('company_name') ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-12">
                                        <div class="form-group"><label class="form-label">Address Type <span class="red-text">(*)</span></label><br>
                                            <label class="mr-3"><input type="radio" name="address_type" value="Personal" <?= ($old['address_type'] ?? 'Personal') === 'Personal' ? 'checked' : '' ?>> Personal</label>
                                            <label><input type="radio" name="address_type" value="Official" <?= ($old['address_type'] ?? '') === 'Official' ? 'checked' : '' ?>> Official</label>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Address 1</label>
                                            <input name="address" class="form-control" value="<?= $v('address') ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Country <span class="red-text">*</span></label>
                                            <select name="country" class="form-control">
                                                <?php foreach (['INDIA', 'USA', 'UK', 'UAE', 'CANADA', 'AUSTRALIA'] as $c): ?><option <?= ($old['country'] ?? 'INDIA') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">State</label>
                                            <input name="state" class="form-control" value="<?= $v('state') ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">City</label>
                                            <input name="city" class="form-control" value="<?= $v('city') ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Zipcode</label>
                                            <input name="postal_code" class="form-control" value="<?= $v('postal_code') ?>">
                                        </div>
                                    </div>

                                    <!-- Registration Category: data-* flags drive instant section toggling -->
                                    <div class="col-xl-12">
                                        <div class="form-group">
                                            <label class="form-label">Registration Category <span class="red-text">(*)</span></label><br>
                                            <?php foreach ($categories as $c):
                                                $isEfi = stripos($c['category_name'], 'EFI') !== false;
                                                $isStudent = stripos($c['category_name'], 'STUDENT') !== false; ?>
                                                <label class="mr-3"><input type="radio" name="category_id" value="<?= $c['id'] ?>"
                                                        class="cat-radio" data-efi="<?= $isEfi ? 1 : 0 ?>" data-student="<?= $isStudent ? 1 : 0 ?>"
                                                        <?= $selectedCategory == $c['id'] ? 'checked' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- EFI membership (shown only for EFI category) -->
                                    <div class="col-xl-6" id="efiBox" style="display:none">
                                        <div class="form-group"><label class="form-label">Membership Number <span class="red-text">*</span></label>
                                            <input name="membership_number" class="form-control" value="<?= $v('membership_number') ?>">
                                            <small class="text-muted">Find membership number</small>
                                        </div>
                                    </div>

                                    <!-- Workshop section (shown once a category is picked) -->
                                    <div class="col-xl-12" id="workshopBox" style="display:none">
                                        <div class="form-group">
                                            <label class="form-label">Want To Register for Pre Conference CME/Workshops? <span class="red-text">*</span></label><br>
                                            <?php foreach ($parentWorkshops as $pw): ?>
                                                <label class="mr-3"><input type="radio" name="workshop_parent_id" value="<?= $pw['id'] ?>"
                                                        class="ws-parent" <?= $selectedParentId == $pw['id'] ? 'checked' : '' ?>>
                                                    <?= htmlspecialchars($pw['option_name']) ?></label>
                                            <?php endforeach; ?>
                                            <label><input type="radio" name="workshop_parent_id" value="0"
                                                    class="ws-parent" <?= $selectedParentId == 0 && $selectedCategory ? 'checked' : '' ?>> NO WORKSHOP</label>
                                        </div>
                                    </div>

                                    <!-- Children of each parent, pre-rendered and toggled instantly -->
                                    <?php foreach ($parentWorkshops as $pw): ?>
                                        <div class="col-xl-12 ws-children" id="wsChildren_<?= $pw['id'] ?>">
                                            <div class="form-group">
                                                <label class="form-label">Select Workshop</label>
                                                <?php if (empty($childrenByParent[$pw['id']])): ?>
                                                    <p class="text-muted">No workshops listed under this option.</p>
                                                    <?php else: foreach ($childrenByParent[$pw['id']] as $cw): ?>
                                                        <label class="d-block mb-1"><input type="radio" name="workshop_option_id" value="<?= $cw['id'] ?>"
                                                                <?= $selectedWorkshopId == $cw['id'] ? 'checked' : '' ?>>
                                                            <?= htmlspecialchars($cw['option_name']) ?> (<?= rp_money($cw['amount'], $cw['currency']) ?>)</label>
                                                <?php endforeach;
                                                endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                    <!-- Accommodation (shown only for student categories) -->
                                    <div class="col-xl-12" id="accomBox" style="display:none">
                                        <div class="form-group">
                                            <label class="form-label">Want To Register for Accommodation? <span class="red-text">*</span></label><br>
                                            <label class="mr-3"><input type="radio" name="accommodation" value="Yes" <?= $accommodation === 'Yes' ? 'checked' : '' ?>> Yes</label>
                                            <label><input type="radio" name="accommodation" value="No" <?= $accommodation === 'No' ? 'checked' : '' ?>> No</label>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="0" <?= ($old['status'] ?? '0') == '0' ? 'selected' : '' ?>>Deactive</option>
                                                <option value="1" <?= ($old['status'] ?? '') == '1' ? 'selected' : '' ?>>Active</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Email Status</label>
                                            <select name="email_status" class="form-control">
                                                <option <?= ($old['email_status'] ?? '') === 'Not Verified' ? 'selected' : '' ?>>Not Verified</option>
                                                <option <?= ($old['email_status'] ?? '') === 'Verified' ? 'selected' : '' ?>>Verified</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-12">
                                        <div class="form-group"><label class="form-label">User Comment</label>
                                            <textarea name="user_comment" class="form-control" rows="3"><?= $v('user_comment') ?></textarea>
                                        </div>
                                    </div>

                                    <div class="col-xl-12"><button type="submit" name="submit_user" class="btn btn-primary">
                                            <i class="feather icon-save"></i> Submit</button></div>
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
    <script>
        // Pure visibility toggling only — no data is filled or modified by JS.
        // Server re-validates everything on submit.
        function toggleSections() {
            var cat = document.querySelector('input[name="category_id"]:checked');
            var workshopBox = document.getElementById('workshopBox');
            var efiBox = document.getElementById('efiBox');
            var accomBox = document.getElementById('accomBox');

            if (!cat) {
                workshopBox.style.display = 'none';
                efiBox.style.display = 'none';
                accomBox.style.display = 'none';
                hideAllChildren();
                return;
            }

            // Workshops apply to every category
            workshopBox.style.display = 'block';

            // Category-specific extras
            efiBox.style.display = cat.dataset.efi === '1' ? 'block' : 'none';
            accomBox.style.display = cat.dataset.student === '1' ? 'block' : 'none';

            showChildrenForSelectedParent();
        }

        function hideAllChildren() {
            document.querySelectorAll('.ws-children').forEach(function(el) {
                el.style.display = 'none';
            });
        }

        function showChildrenForSelectedParent() {
            var parent = document.querySelector('input[name="workshop_parent_id"]:checked');
            hideAllChildren();
            if (!parent || parent.value === '0') {
                // Clear any child selection that is no longer visible
                document.querySelectorAll('.ws-children input[type="radio"]').forEach(function(r) {
                    r.checked = false;
                });
                return;
            }
            var box = document.getElementById('wsChildren_' + parent.value);
            if (box) box.style.display = 'block';
        }

        document.querySelectorAll('input[name="category_id"]').forEach(function(r) {
            r.addEventListener('change', toggleSections);
        });
        document.querySelectorAll('input[name="workshop_parent_id"]').forEach(function(r) {
            r.addEventListener('change', function() {
                // Deselect child when switching parent
                document.querySelectorAll('.ws-children input[type="radio"]').forEach(function(x) {
                    x.checked = false;
                });
                showChildrenForSelectedParent();
            });
        });

        // Restore state on load (after a validation-error re-render)
        toggleSections();
    </script>
</body>

</html>