<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");
include("invoice_helpers.php");
include("invoice_layout.php");

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

if (!abstract_can('Invoice Setup')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to manage invoice setup.', 'dashboard.php');
}

$canEdit = $isAdmin || abstract_can('Invoice Setup');

// ------------------------------------------------------------------
// Handle save / default / toggle
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!abstract_csrf_check($_POST['csrf'] ?? '')) {
        $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
        header("Location: invoice-setup.php");
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $editId      = (int) ($_POST['id'] ?? 0);
        $profileName = trim($_POST['profile_name'] ?? '');
        $companyName = trim($_POST['company_name'] ?? '');

        if ($profileName === '' || $companyName === '') {
            $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Profile name and company name are required.'];
            header("Location: invoice-setup.php" . ($editId ? "?edit=" . base64_encode((string) $editId) : ""));
            exit;
        }

        $logoPath = $letterheadPath = $signaturePath = null;
        try {
            $logoPath       = invoice_safe_upload($_FILES['logo'] ?? null, 'company', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
            $letterheadPath = invoice_safe_upload($_FILES['letterhead'] ?? null, 'company', ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 3145728);
            $signaturePath  = invoice_safe_upload($_FILES['signature'] ?? null, 'company', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        } catch (RuntimeException $ex) {
            $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => $ex->getMessage()];
            header("Location: invoice-setup.php" . ($editId ? "?edit=" . base64_encode((string) $editId) : ""));
            exit;
        }

        $fields = [
            'profile_name'   => $profileName,
            'company_name'   => $companyName,
            'address'        => trim($_POST['address'] ?? ''),
            'city'           => trim($_POST['city'] ?? ''),
            'state'          => trim($_POST['state'] ?? ''),
            'country'        => trim($_POST['country'] ?? ''),
            'postal_code'    => trim($_POST['postal_code'] ?? ''),
            'email'          => trim($_POST['email'] ?? ''),
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'gstin'          => trim($_POST['gstin'] ?? ''),
            'signatory_name' => trim($_POST['signatory_name'] ?? ''),
            'footer_text'    => trim($_POST['footer_text'] ?? ''),
            'tax_label'      => trim($_POST['tax_label'] ?? 'GST') ?: 'GST',
            'tax_rate'       => max(0, min(100, (float) ($_POST['tax_rate'] ?? 18))),
            'bg_margin_top'    => max(0, min(200, (float) ($_POST['bg_margin_top'] ?? 45))),
            'bg_margin_bottom' => max(0, min(200, (float) ($_POST['bg_margin_bottom'] ?? 20))),
            'bg_margin_side'   => max(0, min(200, (float) ($_POST['bg_margin_side'] ?? 12))),
            'is_active'      => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($editId > 0) {
            $existing = null;
            $stmt = $db->prepare("SELECT * FROM company_profiles WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $editId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$existing) {
                $_SESSION['invoice_flash'] = ['type' => 'danger', 'text' => 'Company profile not found.'];
                header("Location: invoice-setup.php");
                exit;
            }
            $logoPath       = $logoPath ?: $existing['logo_path'];
            $letterheadPath = $letterheadPath ?: $existing['letterhead_path'];
            $signaturePath  = $signaturePath ?: $existing['signature_path'];

            $stmt = $db->prepare(
                "UPDATE company_profiles SET
                    profile_name=?, company_name=?, address=?, city=?, state=?, country=?, postal_code=?,
                    email=?, contact_number=?, gstin=?, logo_path=?, letterhead_path=?, signature_path=?,
                    signatory_name=?, footer_text=?, tax_label=?, tax_rate=?,
                    bg_margin_top=?, bg_margin_bottom=?, bg_margin_side=?, is_active=?
                 WHERE id=?"
            );
            $stmt->bind_param(
                'ssssssssssssssssssdddi',
                $fields['profile_name'], $fields['company_name'], $fields['address'], $fields['city'],
                $fields['state'], $fields['country'], $fields['postal_code'], $fields['email'],
                $fields['contact_number'], $fields['gstin'], $logoPath, $letterheadPath, $signaturePath,
                $fields['signatory_name'], $fields['footer_text'], $fields['tax_label'], $fields['tax_rate'],
                $fields['bg_margin_top'], $fields['bg_margin_bottom'], $fields['bg_margin_side'],
                $fields['is_active'], $editId
            );
            $stmt->execute();
            $stmt->close();
            $_SESSION['invoice_flash'] = ['type' => 'success', 'text' => 'Company profile updated successfully.'];
        } else {
            $stmt = $db->prepare(
                "INSERT INTO company_profiles
                    (profile_name, company_name, address, city, state, country, postal_code,
                     email, contact_number, gstin, logo_path, letterhead_path, signature_path,
                     signatory_name, footer_text, tax_label, tax_rate,
                     bg_margin_top, bg_margin_bottom, bg_margin_side, is_active, is_default, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $isDefault = 0;
            $stmt->bind_param(
                'sssssssssssssssssdddsiii',
                $fields['profile_name'], $fields['company_name'], $fields['address'], $fields['city'],
                $fields['state'], $fields['country'], $fields['postal_code'], $fields['email'],
                $fields['contact_number'], $fields['gstin'], $logoPath, $letterheadPath, $signaturePath,
                $fields['signatory_name'], $fields['footer_text'], $fields['tax_label'], $fields['tax_rate'],
                $fields['bg_margin_top'], $fields['bg_margin_bottom'], $fields['bg_margin_side'],
                $fields['is_active'], $isDefault, $adminId
            );
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            // First profile becomes default automatically.
            $count = (int) $db->query("SELECT COUNT(*) c FROM company_profiles")->fetch_assoc()['c'];
            if ($count === 1) {
                $db->query("UPDATE company_profiles SET is_default = 1 WHERE id = " . (int) $newId);
            }
            $_SESSION['invoice_flash'] = ['type' => 'success', 'text' => 'Company profile created successfully.'];
        }

        header("Location: invoice-setup.php");
        exit;
    }

    if ($action === 'set_default') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->query("UPDATE company_profiles SET is_default = 0");
        $stmt = $db->prepare("UPDATE company_profiles SET is_default = 1 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['invoice_flash'] = ['type' => 'success', 'text' => 'Default company profile updated.'];
        header("Location: invoice-setup.php");
        exit;
    }
}

$flash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);

$profiles = [];
$res = $db->query("SELECT * FROM company_profiles ORDER BY is_default DESC, profile_name ASC");
while ($row = $res->fetch_assoc()) {
    $profiles[] = $row;
}

// Editing profile (optional).
$edit = null;
if (isset($_GET['edit'])) {
    $editId = (int) base64_decode($_GET['edit']);
    foreach ($profiles as $p) {
        if ((int) $p['id'] === $editId) {
            $edit = $p;
            break;
        }
    }
}

// Preview: use selected profile or first/default.
$previewId = isset($_GET['preview']) ? (int) $_GET['preview'] : 0;
$previewProfile = null;
foreach ($profiles as $p) {
    if ($previewId && (int) $p['id'] === $previewId) {
        $previewProfile = $p;
        break;
    }
}
if (!$previewProfile && $profiles) {
    $previewProfile = $profiles[0];
}

$csrf = abstract_csrf_token();

$query = "SELECT * FROM login_settings";
$settings = mysqli_fetch_assoc(mysqli_query($db, $query));
$favicon = $settings['favicon'] ?? '';

// Build a sample preview document.
$previewDoc = null;
if ($previewProfile) {
    $sampleItems = [[
        'description'    => 'Conference Registration Fee',
        'hsn_sac'        => '9992',
        'quantity'       => 1,
        'rate'           => 5000,
        'discount'       => 0,
        'taxable_amount' => 5000,
        'line_total'     => 5000,
    ]];
    $totals = invoice_calc_totals($sampleItems, 0, true, (float) $previewProfile['tax_rate']);
    $previewDoc = [
        'document_number' => invoice_type_prefix('invoice') . '-PREVIEW-0001',
        'document_type'   => 'invoice',
        'document_date'   => date('Y-m-d'),
        'status'          => 'issued',
        'company'         => $previewProfile,
        'customer_name'   => 'Sample Delegate',
        'customer_email'  => 'delegate@example.com',
        'customer_contact' => '+91 90000 00000',
        'billing_address' => 'Sample Street, Sample City',
        'customer_category' => 'Delegate',
        'event_name'      => 'Sample Conference 2026',
        'registration_id' => 'REG-0001',
        'payment_reference' => 'ORDER-0001',
        'payment_mode'    => 'Online',
        'utr_reference'   => '',
        'subtotal'        => $totals['subtotal'],
        'discount_label'  => '',
        'discount_percent' => 0,
        'discount_amount' => 0,
        'taxable_amount'  => $totals['taxable_amount'],
        'tax_label'       => $previewProfile['tax_label'],
        'tax_rate'        => $totals['tax_rate'],
        'tax_included'    => 1,
        'tax_amount'      => $totals['tax_amount'],
        'grand_total'     => $totals['grand_total'],
        'amount_in_words' => invoice_amount_in_words($totals['grand_total']),
        'notes'           => '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Invoice Setup</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
    <style>
        .red-text { color: red; }
        .preview-frame { border: 1px solid #ddd; background: #fff; padding: 10px; overflow: auto; max-height: 900px; }
    </style>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track"><div class="loader-fill"></div></div>
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
                                <h5 class="m-b-10">Invoice Setup</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flash['text']); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
            <?php endif; ?>

            <?php if (!$profiles): ?>
                <div class="alert alert-info">
                    <i class="feather icon-info"></i> No company profile yet. Create one below — it will be used for all invoices and receipts.
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <h6><span data-feather="briefcase"></span> <?php echo $edit ? 'Edit Company Profile' : 'Add Company Profile'; ?></h6>
                            <hr />
                            <form method="post" action="invoice-setup.php" enctype="multipart/form-data" autocomplete="off">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?php echo $edit ? (int) $edit['id'] : 0; ?>">
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Profile / Brand Name <span class="red-text">*</span></label>
                                        <input type="text" name="profile_name" class="form-control" placeholder="e.g. TMC" value="<?php echo htmlspecialchars($edit['profile_name'] ?? ''); ?>" required>
                                        <small class="text-muted">Shown in the company dropdown when creating documents.</small>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Company Name <span class="red-text">*</span></label>
                                        <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($edit['company_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Address</label>
                                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($edit['address'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">City</label>
                                        <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($edit['city'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">State</label>
                                        <input type="text" name="state" class="form-control" value="<?php echo htmlspecialchars($edit['state'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">PIN / Postal Code</label>
                                        <input type="text" name="postal_code" class="form-control" value="<?php echo htmlspecialchars($edit['postal_code'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Country</label>
                                        <input type="text" name="country" class="form-control" value="<?php echo htmlspecialchars($edit['country'] ?? 'India'); ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">GSTIN <span class="text-muted">(optional)</span></label>
                                        <input type="text" name="gstin" class="form-control" value="<?php echo htmlspecialchars($edit['gstin'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit['email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($edit['contact_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Authorised Signatory Name</label>
                                        <input type="text" name="signatory_name" class="form-control" value="<?php echo htmlspecialchars($edit['signatory_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">Tax Label</label>
                                        <input type="text" name="tax_label" class="form-control" value="<?php echo htmlspecialchars($edit['tax_label'] ?? 'GST'); ?>">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">Tax Rate (%)</label>
                                        <input type="number" step="0.01" min="0" max="100" name="tax_rate" class="form-control" value="<?php echo htmlspecialchars($edit['tax_rate'] ?? '18.00'); ?>">
                                    </div>
                                    <div class="col-12"><hr class="my-2"><small class="text-muted">Letterhead background insets (mm) — space kept around content so it sits inside the letterhead design. Only applies when a letterhead image is set.</small></div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">Top Inset (mm)</label>
                                        <input type="number" step="1" min="0" max="200" name="bg_margin_top" class="form-control" value="<?php echo htmlspecialchars($edit['bg_margin_top'] ?? '45'); ?>">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">Bottom Inset (mm)</label>
                                        <input type="number" step="1" min="0" max="200" name="bg_margin_bottom" class="form-control" value="<?php echo htmlspecialchars($edit['bg_margin_bottom'] ?? '20'); ?>">
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">Side Inset (mm)</label>
                                        <input type="number" step="1" min="0" max="200" name="bg_margin_side" class="form-control" value="<?php echo htmlspecialchars($edit['bg_margin_side'] ?? '12'); ?>">
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Footer Text</label>
                                        <textarea name="footer_text" class="form-control" rows="2" placeholder="e.g. This is a computer-generated document."><?php echo htmlspecialchars($edit['footer_text'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Logo <span class="text-muted">(image)</span></label>
                                        <input type="file" name="logo" class="form-control" accept="image/*">
                                        <?php if (!empty($edit['logo_path'])): ?><img src="<?php echo htmlspecialchars($edit['logo_path']); ?>" style="max-height:40px;margin-top:6px;" alt="logo"><?php endif; ?>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Letterhead / Header Image</label>
                                        <input type="file" name="letterhead" class="form-control" accept="image/*">
                                        <?php if (!empty($edit['letterhead_path'])): ?><img src="<?php echo htmlspecialchars($edit['letterhead_path']); ?>" style="max-height:40px;margin-top:6px;" alt="letterhead"><?php endif; ?>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Signature Image</label>
                                        <input type="file" name="signature" class="form-control" accept="image/*">
                                        <?php if (!empty($edit['signature_path'])): ?><img src="<?php echo htmlspecialchars($edit['signature_path']); ?>" style="max-height:40px;margin-top:6px;" alt="signature"><?php endif; ?>
                                    </div>

                                    <div class="col-md-6 mb-2">
                                        <div class="custom-control custom-switch mt-4">
                                            <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php echo (($edit['is_active'] ?? 1) ? 'checked' : ''); ?>>
                                            <label class="custom-control-label" for="is_active">Active</label>
                                        </div>
                                    </div>

                                    <div class="col-12 mt-2">
                                        <button type="submit" class="btn btn-secondary"><i class="feather icon-save"></i> <?php echo $edit ? 'Update Profile' : 'Save Profile'; ?></button>
                                        <?php if ($edit): ?><a href="invoice-setup.php" class="btn btn-light">Cancel</a><?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <h6><span data-feather="layers"></span> Company Profiles</h6>
                            <hr />
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr><th>Profile</th><th>GSTIN</th><th>Status</th><th>Actions</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$profiles): ?>
                                            <tr><td colspan="4" class="text-center text-muted">No profiles yet.</td></tr>
                                        <?php else: foreach ($profiles as $p): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($p['profile_name']); ?>
                                                    <?php if ($p['is_default']): ?><span class="badge bg-primary">Default</span><?php endif; ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($p['company_name']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($p['gstin'] ?: '-'); ?></td>
                                                <td><?php echo $p['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'; ?></td>
                                                <td>
                                                    <a href="invoice-setup.php?edit=<?php echo base64_encode($p['id']); ?>" class="btn btn-sm btn-primary"><i class="feather icon-edit"></i></a>
                                                    <a href="invoice-setup.php?preview=<?php echo (int) $p['id']; ?>" class="btn btn-sm btn-info"><i class="feather icon-eye"></i></a>
                                                    <?php if (!$p['is_default']): ?>
                                                        <form method="post" action="invoice-setup.php" style="display:inline;" onsubmit="return confirm('Set this as the default profile?');">
                                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                                            <input type="hidden" name="action" value="set_default">
                                                            <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-secondary" title="Set default"><i class="feather icon-star"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php if ($previewDoc): ?>
                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="file-text"></span> Preview</h6>
                                <small class="text-muted">Fixed document layout with sample data.</small>
                            </div>
                            <div class="card-body">
                                <div class="preview-frame">
                                    <?php echo invoice_render_layout($previewDoc, false); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <?php echo abstract_swal_js(); ?>
    <script>feather.replace();</script>
    <?php echo abstract_swal_render(); ?>
</body>

</html>
