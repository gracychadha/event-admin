<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}

include("db/config.php");
include("registration_pricing_helpers.php");

$msg = "";
$regId = isset($_GET['id']) ? (int) base64_decode($_GET['id']) : (int) ($_POST['reg_id'] ?? 0);

// ---- Load registration + full user row ----
$stmt = mysqli_prepare($db, "SELECT r.*, u.* FROM registrations r
    LEFT JOIN registered_users u ON u.student_id = r.user_id WHERE r.id = ?");
mysqli_stmt_bind_param($stmt, "i", $regId);
mysqli_stmt_execute($stmt);
$reg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$reg) {
    echo "<div class='alert alert-danger m-4'>Registration not found.</div>";
    exit;
}
$userId = (int) $reg['user_id'];

// ---- Lookups ----
$categories = mysqli_fetch_all(mysqli_query($db, "SELECT id, category_name FROM registration_categories WHERE status=1 ORDER BY id"), MYSQLI_ASSOC);
$parentWorkshops = mysqli_fetch_all(mysqli_query($db, "SELECT id, option_name FROM registration_category_options WHERE parent_id IS NULL AND status=1 AND availability=1 ORDER BY option_name"), MYSQLI_ASSOC);

$childrenByParent = [];
foreach ($parentWorkshops as $pw) {
    $childrenByParent[$pw['id']] = [];
}
if ($parentWorkshops) {
    $in = implode(',', array_map('intval', array_column($parentWorkshops, 'id')));
    $res = mysqli_query($db, "SELECT id, parent_id, option_name, amount, currency FROM registration_category_options WHERE parent_id IN ($in) AND status=1 AND availability=1 ORDER BY option_name");
    while ($c = mysqli_fetch_assoc($res)) {
        $childrenByParent[$c['parent_id']][] = $c;
    }
}

// ---- Latest + all payment rows ----
$pay = null;
$payRows = [];
$s = mysqli_prepare($db, "SELECT * FROM registration_payments WHERE registration_id=? ORDER BY id DESC");
mysqli_stmt_bind_param($s, "i", $regId);
mysqli_stmt_execute($s);
$payRows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
mysqli_stmt_close($s);
if ($payRows) {
    $pay = $payRows[0];
}

// ---- Repopulate state: POST wins, else DB ----
$old = $_POST;
$selectedCategory   = (int) ($old['category_id'] ?? $reg['category_id']);
$selectedWorkshopId = (int) ($old['workshop_option_id'] ?? ($reg['option_id'] ?? 0));
$selectedParentId   = (int) ($old['workshop_parent_id'] ?? 0);

if (!isset($old['workshop_parent_id']) && $selectedWorkshopId > 0) {
    $s = mysqli_prepare($db, "SELECT parent_id FROM registration_category_options WHERE id=?");
    mysqli_stmt_bind_param($s, "i", $selectedWorkshopId);
    mysqli_stmt_execute($s);
    $selChild = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    $selectedParentId = $selChild ? (int) $selChild['parent_id'] : 0;
}
$accommodation = $old['accommodation'] ?? ($reg['accommodation'] ?? 'No');

// Helper: POST value, else DB value
function ev($postKey, $dbKey, $fallback = '')
{
    global $old, $reg;
    return $old[$postKey] ?? ($reg[$dbKey] ?? $fallback);
}

// ---- Approval update (ledger dropdown) ----
if (isset($_POST['update_payment'])) {
    $pid = (int) $_POST['payment_id'];
    $ap = in_array($_POST['approval_status'] ?? '', ['Pending', 'Approved', 'Rejected']) ? $_POST['approval_status'] : 'Pending';

    $s = mysqli_prepare($db, "UPDATE registration_payments SET approval_status=? WHERE id=? AND registration_id=?");
    mysqli_stmt_bind_param($s, "sii", $ap, $pid, $regId);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    if ($ap === 'Approved') {
        $s = mysqli_prepare($db, "UPDATE registrations SET payment_status='paid' WHERE id=?");
        mysqli_stmt_bind_param($s, "i", $regId);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
    $msg = "<div class='alert alert-success'>Payment status updated.</div>";
}

// ---- Main update ----
if (isset($_POST['update_reg'])) {
    $firstName  = trim(ev('first_name', 'first_name'));
    $lastName   = trim(ev('last_name', 'last_name'));
    $email      = strtolower(trim(ev('email', 'email')));
    $contact    = trim(ev('contact_number', 'contact_number'));
    $password   = $_POST['password'] ?? '';
    $membership = trim(ev('membership_number', 'membership_number'));

    $errors = [];
    if (!$firstName || !$lastName || !$email || !$contact || $selectedCategory <= 0) {
        $errors[] = "All required (*) fields must be filled.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email.";
    }

    // Email unique check (exclude this user)
    $s = mysqli_prepare($db, "SELECT student_id FROM registered_users WHERE email=? AND student_id<>?");
    mysqli_stmt_bind_param($s, "si", $email, $userId);
    mysqli_stmt_execute($s);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
        $errors[] = "Email already used by another user.";
    }
    mysqli_stmt_close($s);

    // EFI membership validation
    $isEfi = false;
    foreach ($categories as $c) {
        if ($c['id'] == $selectedCategory && stripos($c['category_name'], 'EFI') !== false) {
            $isEfi = true;
        }
    }
    if ($isEfi && $membership === '') {
        $errors[] = "Membership Number is required for EFI Members.";
    }

    if ($selectedParentId > 0 && $selectedWorkshopId == 0) {
        $errors[] = "Please select a specific workshop.";
    }

    $paymentStatus = in_array($_POST['payment_status'] ?? '', ['paid', 'failed']) ? $_POST['payment_status'] : 'failed';

    // If Success, Payment Type is mandatory
    if ($paymentStatus === 'paid' && empty($_POST['method'])) {
        $errors[] = "Payment Type (Internal/External) is required for Success status.";
    }

    if (empty($errors)) {
        // Recalculate pricing against the SAME period
        $periodId = (int) $reg['period_id'];
        $periodName = $reg['period_name'];
        $baseFee = (float) $reg['original_fee'];
        $currency = $reg['currency'];
        $gstType = $reg['gst_type'];
        $gstPct = (float) $reg['gst_percentage'];
        $workshopFee = 0.0;
        $workshopName = 'None';
        $workshopId = null;

        $s = mysqli_prepare($db, "SELECT amount, currency, gst_type, gst_percentage FROM registration_category_prices WHERE category_id=? AND period_id=?");
        mysqli_stmt_bind_param($s, "ii", $selectedCategory, $periodId);
        mysqli_stmt_execute($s);
        if ($p = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
            $baseFee = (float) $p['amount'];
            $currency = $p['currency'];
            $gstType = $p['gst_type'];
            $gstPct = (float) $p['gst_percentage'];
        }
        mysqli_stmt_close($s);

        if ($selectedWorkshopId > 0) {
            $s = mysqli_prepare($db, "SELECT id, option_name, amount FROM registration_category_options WHERE id=? AND parent_id=? AND status=1");
            mysqli_stmt_bind_param($s, "ii", $selectedWorkshopId, $selectedParentId);
            mysqli_stmt_execute($s);
            if ($w = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
                $workshopId = (int) $w['id'];
                $workshopName = $w['option_name'];
                $workshopFee = (float) $w['amount'];
            }
            mysqli_stmt_close($s);
            if (!$workshopId) {
                $errors[] = "Selected workshop is not available.";
            }
        }

        $catName = '';
        foreach ($categories as $c) {
            if ($c['id'] == $selectedCategory) {
                $catName = $c['category_name'];
                break;
            }
        }

        $fee = $baseFee + $workshopFee;
        $discountAmount = 0.0;
        $discountName = '';
        $today = date('Y-m-d');

        $s = mysqli_prepare($db, "SELECT d.* FROM registration_discounts d
            INNER JOIN registration_discount_categories dc ON dc.discount_id=d.id
            WHERE d.status=1 AND dc.category_id=? AND ? BETWEEN d.start_date AND d.end_date
            ORDER BY d.discount_value DESC LIMIT 1");
        mysqli_stmt_bind_param($s, "is", $selectedCategory, $today);
        mysqli_stmt_execute($s);
        if ($disc = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
            $discountAmount = $disc['discount_type'] === 'percentage' ? $fee * $disc['discount_value'] / 100 : min($disc['discount_value'], $fee);
            $discountName = $disc['discount_name'];
        }
        mysqli_stmt_close($s);

        $net = $fee - $discountAmount;
        $gst = 0.0;
        if ($gstType === 'excluded') {
            $gst = round($net * $gstPct / 100, 2);
        } elseif ($gstType === 'included') {
            $gst = round($net * $gstPct / (100 + $gstPct), 2);
        }
        $payable = round($gstType === 'excluded' ? $net + $gst : $net, 2);

        $userStatus   = (int) ev('status', 'status', 1);
        $emailStatus  = ev('email_status', 'email_status', 'Not Verified');
        $userComment  = trim(ev('user_comment', 'description'));

        // Assign all vars (PHP 8.1 bind_param needs variables, not inline expressions)
        $prefixVal    = ev('prefix', 'prefix');
        $middleVal    = trim(ev('middle_name', 'middle_name'));
        $genderVal    = ev('gender', 'gender');
        $dialVal      = ev('dial_code', 'dial_code', '+91');
        $companyVal   = trim(ev('company_name', 'company_name'));
        $accomVal     = $accommodation;
        $addrTypeVal  = ev('address_type', 'address_type', 'Personal');
        $addrVal      = trim(ev('address', 'address'));
        $countryVal   = ev('country', 'country');
        $stateVal     = trim(ev('state', 'state'));
        $cityVal      = trim(ev('city', 'city'));
        $zipVal       = trim(ev('postal_code', 'postal_code'));

        $methodVal    = $_POST['mode_of_payment'] ?? '';
        $ptypeVal     = $_POST['method'] ?? '';
        $pdateVal     = $_POST['payment_date'] ?? null;
        $orderVal     = trim($_POST['order_id'] ?? '');
        $trackVal     = trim($_POST['tracking_id'] ?? '');
        $bankVal      = trim($_POST['bank_ref_no'] ?? '');
        $gwStatus     = $paymentStatus === 'paid' ? 'captured' : 'created';

        mysqli_begin_transaction($db);
        try {
            // 1) Update user profile (21 params)
            $s = mysqli_prepare($db, "UPDATE registered_users SET prefix=?, first_name=?, middle_name=?, last_name=?,
                email=?, gender=?, dial_code=?, contact_number=?, company_name=?, membership_number=?, accommodation=?,
                address_type=?, address=?, country=?, state=?, city=?, postal_code=?, status=?, email_status=?, description=?
                WHERE student_id=?");

            // FIXED: 17 's', 1 'i', 2 's', 1 'i' = 21 characters exactly
            mysqli_stmt_bind_param(
                $s,
                "sssssssssssssssssissi",
                $prefixVal,
                $firstName,
                $middleVal,
                $lastName,
                $email,
                $genderVal,
                $dialVal,
                $contact,
                $companyVal,
                $membership,
                $accomVal,
                $addrTypeVal,
                $addrVal,
                $countryVal,
                $stateVal,
                $cityVal,
                $zipVal,
                $userStatus,
                $emailStatus,
                $userComment,
                $userId
            );
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);

            // 2) Update password only if provided
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $s = mysqli_prepare($db, "UPDATE registered_users SET password=? WHERE student_id=?");
                mysqli_stmt_bind_param($s, "si", $hash, $userId);
                mysqli_stmt_execute($s);
                mysqli_stmt_close($s);
            }

            // 3) Update registration snapshot (18 params)
            $s = mysqli_prepare($db, "UPDATE registrations SET category_id=?, category_name=?, period_id=?, period_name=?,
                original_fee=?, currency=?, option_id=?, option_name=?, option_amount=?, discount_name=?, discount_amount=?,
                taxable_amount=?, gst_type=?, gst_percentage=?, gst_amount=?, payable_amount=?, payment_status=? WHERE id=?");

            // CORRECTED: exactly 18 characters matching the 18 variables below
            mysqli_stmt_bind_param(
                $s,
                "isisdsisdsddsdddsi",
                $selectedCategory,
                $catName,
                $periodId,
                $periodName,
                $baseFee,
                $currency,
                $workshopId,
                $workshopName,
                $workshopFee,
                $discountName,
                $discountAmount,
                $net,
                $gstType,
                $gstPct,
                $gst,
                $payable,
                $paymentStatus,
                $regId
            );
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);

            // 4) Save payment details: update existing ledger row, or insert a new one
            $approvalStatus = $paymentStatus === 'paid' ? 'Approved' : 'Pending';
            $pdateVal       = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;

            // FIX: Extract cast to a variable to avoid "passed by reference" error
            $payId = (int) $pay['id'];

            if ($pay) {
                // ---- Existing row: UPDATE (Changed 'method' to 'payment_type') ----
                $s = mysqli_prepare($db, "UPDATE registration_payments SET method=?, status=?, razorpay_order_id=?,
                    razorpay_payment_id=?, bank_ref=?, payment_date=? WHERE id=?");
                mysqli_stmt_bind_param(
                    $s,
                    "ssssssi",
                    $ptypeVal,
                    $gwStatus,
                    $orderVal,
                    $trackVal,
                    $bankVal,
                    $pdateVal,
                    $payId
                );
                mysqli_stmt_execute($s);
                mysqli_stmt_close($s);
            } else {
                // ---- No row yet: INSERT (Changed 'method' to 'payment_type') ----
                $s = mysqli_prepare($db, "INSERT INTO registration_payments
                    (registration_id, amount, currency, status, method,
                     razorpay_order_id, razorpay_payment_id, bank_ref, payment_date, approval_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                mysqli_stmt_bind_param(
                    $s,
                    "idssssssss",
                    $regId,
                    $payable,
                    $currency,
                    $gwStatus,
                    $ptypeVal,
                    $orderVal,
                    $trackVal,
                    $bankVal,
                    $pdateVal,
                    $approvalStatus
                );
                mysqli_stmt_execute($s);
                mysqli_stmt_close($s);
            }

            mysqli_commit($db);


            $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                <strong><i class='feather icon-check'></i> Success!</strong> Registration updated successfully.
                <button type='button' class='close' data-dismiss='alert' aria-label='Close'><span aria-hidden='true'>&times;</span></button>
            </div>";


            header("location: manage-registrations.php");
            exit;
        } catch (Exception $e) {
            mysqli_rollback($db);
            $msg = "<div class='alert alert-danger'>" . $e->getMessage() . "</div>";
        }
    } else {
        $msg = "<div class='alert alert-danger'>" . implode('<br>', $errors) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Registration</title>
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
                                <h5 class="m-b-10">Edit Registration — <?= htmlspecialchars($reg['registration_ref']) ?></h5>
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
                            <form method="post" autocomplete="off">
                                <input type="hidden" name="reg_id" value="<?= $regId ?>">
                                <div class="row">
                                    <!-- ===== Full profile form ===== -->
                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Prefix <span class="red-text">(*)</span></label>
                                            <select name="prefix" class="form-control">
                                                <option value="">Select</option>
                                                <?php foreach (['Mr', 'Mrs', 'Ms', 'Dr', 'Prof'] as $p): ?><option <?= ev('prefix', 'prefix') === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">First Name <span class="red-text">(*)</span></label>
                                            <input name="first_name" class="form-control" value="<?= htmlspecialchars(ev('first_name', 'first_name')) ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Middle Name</label>
                                            <input name="middle_name" class="form-control" value="<?= htmlspecialchars(ev('middle_name', 'middle_name')) ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Last Name <span class="red-text">(*)</span></label>
                                            <input name="last_name" class="form-control" value="<?= htmlspecialchars(ev('last_name', 'last_name')) ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Email <span class="red-text">(*)</span></label>
                                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars(ev('email', 'email')) ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Gender</label>
                                            <select name="gender" class="form-control">
                                                <option value="">Select</option>
                                                <?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= ev('gender', 'gender') === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Password</label>
                                            <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Mobile <span class="red-text">(*)</span></label>
                                            <div class="input-group"><select name="dial_code" class="form-control" style="max-width:90px">
                                                    <?php foreach (['+91', '+1', '+44', '+971'] as $d): ?><option <?= ev('dial_code', 'dial_code', '+91') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select>
                                                <input name="contact_number" class="form-control" value="<?= htmlspecialchars(ev('contact_number', 'contact_number')) ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Current/Affiliations</label>
                                            <input name="company_name" class="form-control" value="<?= htmlspecialchars(ev('company_name', 'company_name')) ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-12">
                                        <div class="form-group"><label class="form-label">Address Type <span class="red-text">(*)</span></label><br>
                                            <label class="mr-3"><input type="radio" name="address_type" value="Personal" <?= ev('address_type', 'address_type', 'Personal') === 'Personal' ? 'checked' : '' ?>> Personal</label>
                                            <label><input type="radio" name="address_type" value="Official" <?= ev('address_type', 'address_type') === 'Official' ? 'checked' : '' ?>> Official</label>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Address 1</label>
                                            <input name="address" class="form-control" value="<?= htmlspecialchars(ev('address', 'address')) ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Country <span class="red-text">*</span></label>
                                            <select name="country" class="form-control">
                                                <?php foreach (['INDIA', 'USA', 'UK', 'UAE', 'CANADA', 'AUSTRALIA'] as $c): ?><option <?= ev('country', 'country', 'INDIA') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">State</label>
                                            <input name="state" class="form-control" value="<?= htmlspecialchars(ev('state', 'state')) ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">City</label>
                                            <input name="city" class="form-control" value="<?= htmlspecialchars(ev('city', 'city')) ?>">
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Zipcode</label>
                                            <input name="postal_code" class="form-control" value="<?= htmlspecialchars(ev('postal_code', 'postal_code')) ?>">
                                        </div>
                                    </div>

                                    <!-- ===== Category ===== -->
                                    <div class="col-xl-12">
                                        <div class="form-group">
                                            <label class="form-label">Registration Category <span class="red-text">*</span></label><br>
                                            <?php foreach ($categories as $c): ?>
                                                <label class="mr-3"><input type="radio" name="category_id" value="<?= $c['id'] ?>"
                                                        class="cat-radio" <?= $selectedCategory == $c['id'] ? 'checked' : '' ?>>
                                                    <?= htmlspecialchars($c['category_name']) ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- ===== EFI membership ===== -->
                                    <div class="col-xl-6" id="efiBox" style="display:none">
                                        <div class="form-group">
                                            <label class="form-label">Membership Number <span class="red-text">*</span></label>
                                            <input name="membership_number" class="form-control" value="<?= htmlspecialchars(ev('membership_number', 'membership_number')) ?>">
                                        </div>
                                    </div>

                                    <!-- ===== Workshop parents ===== -->
                                    <div class="col-xl-12" id="workshopBox">
                                        <div class="form-group">
                                            <label class="form-label">Want To Register for Pre Conference CME/Workshops? <span class="red-text">*</span></label><br>
                                            <?php foreach ($parentWorkshops as $pw): ?>
                                                <label class="mr-3"><input type="radio" name="workshop_parent_id" value="<?= $pw['id'] ?>"
                                                        class="ws-parent" <?= $selectedParentId == $pw['id'] ? 'checked' : '' ?>>
                                                    <?= htmlspecialchars($pw['option_name']) ?></label>
                                            <?php endforeach; ?>
                                            <label><input type="radio" name="workshop_parent_id" value="0"
                                                    class="ws-parent" <?= $selectedParentId == 0 ? 'checked' : '' ?>> NO WORKSHOP</label>
                                        </div>
                                    </div>

                                    <!-- ===== Workshop children ===== -->
                                    <?php foreach ($parentWorkshops as $pw): ?>
                                        <div class="col-xl-12 ws-children" id="wsChildren_<?= $pw['id'] ?>">
                                            <div class="form-group"><label class="form-label">Select Workshop</label>
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

                                    <!-- ===== Accommodation ===== -->
                                    <div class="col-xl-12" id="accomBox">
                                        <div class="form-group">
                                            <label class="form-label">Want To Register for Accommodation? <span class="red-text">*</span></label><br>
                                            <label class="mr-3"><input type="radio" name="accommodation" value="Yes" <?= $accommodation === 'Yes' ? 'checked' : '' ?>> Yes</label>
                                            <label><input type="radio" name="accommodation" value="No" <?= $accommodation === 'No' ? 'checked' : '' ?>> No</label>
                                        </div>
                                    </div>

                                    <!-- ===== Status / Email / Payment header ===== -->
                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="1" <?= (int)ev('status', 'status', 1) == 1 ? 'selected' : '' ?>>Active</option>
                                                <option value="0" <?= (int)ev('status', 'status', 1) == 0 ? 'selected' : '' ?>>Deactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-xl-4">
                                        <div class="form-group"><label class="form-label">Email Status</label>
                                            <select name="email_status" class="form-control">
                                                <option <?= ev('email_status', 'email_status') === 'Verified' ? 'selected' : '' ?>>Verified</option>
                                                <option <?= ev('email_status', 'email_status') !== 'Verified' ? 'selected' : '' ?>>Not Verified</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- ===== Payment Status & Details ===== -->
                                    <div class="col-xl-4">
                                        <div class="form-group">
                                            <label class="form-label">Payment Status <span class="red-text">*</span></label>
                                            <select name="payment_status" id="payment_status" class="form-control">
                                                <option value="failed" <?= $reg['payment_status'] === 'failed' ? 'selected' : '' ?>>Failure</option>
                                                <option value="paid" <?= $reg['payment_status'] === 'paid' ? 'selected' : '' ?>>Success</option>
                                            </select>
                                            <small class="text-muted">Details must be filled when Success is selected.</small>
                                        </div>
                                    </div>

                                    <!-- This box will only be visible when Payment Status is Success (paid) -->
                                    <div id="paymentDetailsBox" class="col-xl-12" style="display: <?= $reg['payment_status'] === 'paid' ? 'block' : 'none'; ?>; background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">
                                        <div class="row">
                                            <div class="col-xl-4">
                                                <div class="form-group"><label class="form-label">Payment Type <span class="red-text">*</span></label>
                                                    <select name="method" class="form-control" <?= $reg['payment_status'] === 'paid' ? 'required' : '' ?>>
                                                        <option value="">Select</option>
                                                        <option value="Internal" <?= ($pay['method'] ?? '') === 'Internal' ? 'selected' : '' ?>>Internal</option>
                                                        <option value="External" <?= ($pay['method'] ?? '') === 'External' ? 'selected' : '' ?>>External</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="col-xl-4">
                                                <div class="form-group"><label class="form-label">Payment Date</label>
                                                    <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($pay['payment_date'] ?? date('Y-m-d')) ?>">
                                                </div>
                                            </div>

                                            <div class="col-xl-4">
                                                <div class="form-group"><label class="form-label">Order ID</label>
                                                    <input name="order_id" class="form-control" value="<?= htmlspecialchars($pay['razorpay_order_id'] ?? '') ?>">
                                                </div>
                                            </div>

                                            <div class="col-xl-4">
                                                <div class="form-group"><label class="form-label">Tracking ID / Reference</label>
                                                    <input name="tracking_id" class="form-control" value="<?= htmlspecialchars($pay['razorpay_payment_id'] ?? '') ?>">
                                                </div>
                                            </div>

                                            <div class="col-xl-4">
                                                <div class="form-group"><label class="form-label">Bank Ref No</label>
                                                    <input name="bank_ref_no" class="form-control" value="<?= htmlspecialchars($pay['bank_ref'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-xl-12">
                                        <div class="form-group"><label class="form-label">User Comment</label>
                                            <textarea name="user_comment" class="form-control" rows="2"><?= htmlspecialchars(ev('user_comment', 'description')) ?></textarea>
                                        </div>
                                    </div>

                                    <div class="col-xl-12"><button type="submit" name="update_reg" class="btn btn-primary"><i class="feather icon-refresh-cw"></i> Update</button></div>
                                </div>
                            </form>

                            <!-- ===== Payment ledger table ===== -->
                            <hr>
                            <h6><i class="feather icon-credit-card"></i> Payment Status</h6>
                            <table class="table table-striped table-bordered">
                                <thead>
                                    <tr>
                                        <th>S No.</th>
                                        <th>Payment Date</th>
                                        <th>Amount</th>
                                        <th>ScreenShot</th>
                                        <th>Tracking Id</th>
                                        <th>Pending Status</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payRows as $i => $p): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= htmlspecialchars($p['payment_date'] ?? date('Y-m-d', strtotime($p['created_at']))) ?></td>
                                            <td><?= rp_money($p['amount'], $p['currency']) ?></td>
                                            <td><?php if (!empty($p['screenshot'])): ?><img src="<?= htmlspecialchars($p['screenshot']) ?>" style="height:30px" alt="proof"><?php else: ?>-<?php endif; ?></td>
                                            <td><?= htmlspecialchars($p['razorpay_payment_id'] ?? '-') ?></td>
                                            <td>
                                                <form method="post" class="d-flex">
                                                    <input type="hidden" name="reg_id" value="<?= $regId ?>">
                                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                                    <select name="approval_status" class="form-control form-control-sm mr-2">
                                                        <?php foreach (['Pending', 'Approved', 'Rejected'] as $as): ?>
                                                            <option <?= ($p['approval_status'] ?? 'Pending') === $as ? 'selected' : '' ?>><?= $as ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button name="update_payment" class="btn btn-sm btn-secondary"><i class="feather icon-check"></i></button>
                                                </form>
                                            </td>
                                            <td><span class="badge badge-<?= ($p['approval_status'] ?? '') === 'Approved' ? 'success' : (($p['approval_status'] ?? '') === 'Rejected' ? 'danger' : 'warning') ?>"><?= htmlspecialchars($p['approval_status'] ?? 'Pending') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$payRows): ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">No payments recorded.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>

                            <!-- ===== Change log ===== -->
                            <hr>
                            <h6><i class="feather icon-activity"></i> Change Log</h6>
                            <ul class="text-muted small">
                                <li>Registered on: <?= date('d M Y h:i A', strtotime($reg['created_at'])) ?></li>
                                <li>Last updated: <?= date('d M Y h:i A', strtotime($reg['updated_at'])) ?></li>
                                <?php foreach ($payRows as $p): ?>
                                    <li>Payment #<?= $p['id'] ?> — <?= rp_money($p['amount'], $p['currency']) ?> — <?= htmlspecialchars($p['approval_status'] ?? 'Pending') ?> (<?= date('d M Y', strtotime($p['updated_at'])) ?>)</li>
                                <?php endforeach; ?>
                            </ul>
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
        // Visibility toggling only — no data filled by JS. Server validates on submit.
        function toggleSections() {
            var cat = document.querySelector('input[name="category_id"]:checked');
            document.getElementById('efiBox').style.display = (cat && cat.dataset.efi === '1') ? 'block' : 'none';
            document.getElementById('accomBox').style.display = (cat && cat.dataset.student === '1') ? 'block' : 'none';
            showChildrenForSelectedParent();
        }

        function showChildrenForSelectedParent() {
            var parent = document.querySelector('input[name="workshop_parent_id"]:checked');
            document.querySelectorAll('.ws-children').forEach(function(el) {
                el.style.display = 'none';
            });
            if (!parent || parent.value === '0') {
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
                document.querySelectorAll('.ws-children input[type="radio"]').forEach(function(x) {
                    x.checked = false;
                });
                showChildrenForSelectedParent();
            });
        });

        // Mark category radios with flags for toggling
        document.querySelectorAll('input[name="category_id"]').forEach(function(r) {
            var lbl = r.value;
            r.dataset.efi = /EFI/i.test(r.nextSibling ? r.nextSibling.textContent : '') ? '1' : '0';
        });
        toggleSections();

        // Toggle details box on Payment Status change
        var paymentStatusSelect = document.getElementById('payment_status');
        var paymentDetailsBox = document.getElementById('paymentDetailsBox');
        var paymentTypeSelect = paymentDetailsBox.querySelector('select[name="method"]');

        function togglePaymentDetails() {
            if (paymentStatusSelect.value === 'paid') {
                paymentDetailsBox.style.display = 'block';
                paymentTypeSelect.setAttribute('required', 'required'); // Payment Type is mandatory on Success
            } else {
                paymentDetailsBox.style.display = 'none';
                paymentTypeSelect.removeAttribute('required'); // Not mandatory on Failure
                // Optional: To clear values on Failure:
                // paymentDetailsBox.querySelectorAll('input, select').forEach(el => el.value = '');
            }
        }

        // Attach event listener
        paymentStatusSelect.addEventListener('change', togglePaymentDetails);

        // Set initial state on page load
        togglePaymentDetails();
    </script>
</body>

</html>