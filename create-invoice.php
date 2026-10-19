<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");
include("invoice_helpers.php");

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

if (!abstract_can('Create Invoice')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to create invoices.', 'dashboard.php');
}

$profiles = [];
$res = $db->query("SELECT id, profile_name, company_name, tax_label, tax_rate FROM company_profiles WHERE is_active = 1 ORDER BY is_default DESC, profile_name ASC");
while ($row = $res->fetch_assoc()) {
    $profiles[] = $row;
}

$setupIncomplete = false;
$activeProfileCount = (int) $db->query("SELECT COUNT(*) c FROM company_profiles WHERE is_active = 1")->fetch_assoc()['c'];
if ($activeProfileCount === 0) {
    $setupIncomplete = true;
}

$csrf = abstract_csrf_token();
$flash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);
$settings = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM login_settings"));
$favicon = $settings['favicon'] ?? '';
$defaultTaxRate = $profiles ? $profiles[0]['tax_rate'] : '18.00';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Create Invoice / Receipt</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
    <style>
        .red-text { color: red; }
        .items-table td, .items-table th { vertical-align: middle; }
        .items-table input { min-width: 70px; }
        .summary-box { background: #f7f9ff; border: 1px solid #dbe3f5; border-radius: 6px; padding: 12px; }
        .summary-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .summary-row.total { border-top: 2px solid #00349a; font-weight: bold; color: #00349a; margin-top: 6px; padding-top: 6px; }
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
                                <h5 class="m-b-10">Create Invoice / Receipt</h5>
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

            <?php if ($setupIncomplete): ?>
                <div class="alert alert-warning">
                    <i class="feather icon-alert-triangle"></i>
                    Company setup is incomplete. Please <a href="invoice-setup.php">complete Invoice Setup</a> before creating documents.
                </div>
            <?php endif; ?>

            <form method="post" action="generate-invoice.php" id="invoiceForm" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="file-text"></span> Document Information</h6>
                                <hr />
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Company Profile <span class="red-text">*</span></label>
                                        <select name="company_profile_id" id="companyProfile" class="form-control" required>
                                            <option value="" disabled <?php echo $profiles ? '' : 'selected'; ?>>Choose</option>
                                            <?php foreach ($profiles as $p): ?>
                                                <option value="<?php echo (int) $p['id']; ?>"
                                                        data-tax-label="<?php echo htmlspecialchars($p['tax_label']); ?>"
                                                        data-tax-rate="<?php echo htmlspecialchars($p['tax_rate']); ?>">
                                                    <?php echo htmlspecialchars($p['profile_name'] . ' — ' . $p['company_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">The fixed document layout is reused for every profile.</small>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Document Type <span class="red-text">*</span></label>
                                        <select name="document_type" id="documentType" class="form-control" required>
                                            <option value="invoice">Tax Invoice</option>
                                            <option value="receipt">Payment Receipt</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Document Date <span class="red-text">*</span></label>
                                        <input type="date" name="document_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="user"></span> Customer / Event Details</h6>
                                <hr />
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Customer / Delegate Name <span class="red-text">*</span></label>
                                        <input type="text" name="customer_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="customer_email" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" name="customer_contact" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Customer Category</label>
                                        <input type="text" name="customer_category" class="form-control" placeholder="e.g. Delegate / Student / Speaker">
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Billing Address</label>
                                        <input type="text" name="billing_address" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Event / Conference Name</label>
                                        <input type="text" name="event_name" class="form-control">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Registration ID / Delegate ID</label>
                                        <input type="text" name="registration_id" class="form-control">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="list"></span> Line Items</h6>
                                <hr />
                                <div class="table-responsive">
                                    <table class="table table-bordered items-table" id="itemsTable">
                                        <thead>
                                            <tr>
                                                <th style="width:34%;">Description <span class="red-text">*</span></th>
                                                <th style="width:15%;">HSN/SAC</th>
                                                <th style="width:12%;">Qty</th>
                                                <th style="width:16%;">Rate</th>
                                                <th style="width:17%;">Amount</th>
                                                <th style="width:6%;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="itemsBody">
                                            <tr class="item-row">
                                                <td><input type="text" name="items[0][description]" class="form-control item-desc" required></td>
                                                <td><input type="text" name="items[0][hsn_sac]" class="form-control"></td>
                                                <td><input type="number" step="0.01" min="0.01" name="items[0][quantity]" class="form-control item-qty" value="1" required></td>
                                                <td><input type="number" step="0.01" min="0" name="items[0][rate]" class="form-control item-rate" value="0" required></td>
                                                <td><input type="text" class="form-control item-amount" value="0.00" readonly></td>
                                                <td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row"><i class="feather icon-trash-2"></i></button></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="btn btn-sm btn-info" id="addRow"><i class="feather icon-plus"></i> Add Line Item</button>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="credit-card"></span> Payment Details</h6>
                                <hr />
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Payment Reference / Order ID</label>
                                        <input type="text" name="payment_reference" class="form-control">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Payment Mode</label>
                                        <input type="text" name="payment_mode" class="form-control" placeholder="e.g. Online / Cash / NEFT">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">UTR / Transaction Ref</label>
                                        <input type="text" name="utr_reference" class="form-control">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Amount Received</label>
                                        <input type="number" step="0.01" min="0" name="amount_received" class="form-control" value="0">
                                        <small class="text-muted">Only record the amount actually confirmed received.</small>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Payment Date</label>
                                        <input type="date" name="payment_date" class="form-control">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Payment Status</label>
                                        <select name="payment_status" class="form-control">
                                            <option value="pending">Pending</option>
                                            <option value="partial">Partial</option>
                                            <option value="paid">Paid</option>
                                        </select>
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="percent"></span> Discount &amp; Tax</h6>
                                <hr />
                                <div class="row">
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Discount Label</label>
                                        <input type="text" name="discount_label" id="discountLabel" class="form-control" placeholder="e.g. Early Bird Discount">
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Discount (%)</label>
                                        <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="discountPercent" class="form-control" value="0">
                                    </div>
                                    <div class="col-12 mb-2">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input" id="taxIncluded" name="tax_included" value="1" checked>
                                            <label class="custom-control-label" for="taxIncluded">Include <span id="taxLabelText">GST</span></label>
                                        </div>
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Tax Rate (%)</label>
                                        <input type="number" step="0.01" min="0" max="100" name="tax_rate" id="taxRate" class="form-control" value="<?php echo htmlspecialchars($defaultTaxRate); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header table-card-header">
                                <h6><span data-feather="hash"></span> Summary</h6>
                            </div>
                            <div class="card-body">
                                <div class="summary-box">
                                    <div class="summary-row"><span>Subtotal</span><span id="sumSubtotal">0.00</span></div>
                                    <div class="summary-row"><span id="sumDiscountLabel">Discount</span><span id="sumDiscount">0.00</span></div>
                                    <div class="summary-row"><span>Taxable Amount</span><span id="sumTaxable">0.00</span></div>
                                    <div class="summary-row"><span id="sumTaxLabel">GST</span><span id="sumTax">0.00</span></div>
                                    <div class="summary-row total"><span>Grand Total</span><span id="sumGrand">0.00</span></div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-body">
                                <button type="submit" class="btn btn-secondary btn-block" id="generateBtn" <?php echo $setupIncomplete ? 'disabled' : ''; ?>>
                                    <i class="feather icon-file-plus"></i> Generate &amp; Save
                                </button>
                                <button type="button" class="btn btn-info btn-block" id="previewBtn" <?php echo $setupIncomplete ? 'disabled' : ''; ?>>
                                    <i class="feather icon-eye"></i> Preview
                                </button>
                                <a href="dashboard.php" class="btn btn-light btn-block">Cancel</a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="assets/js/pages/invoice-calc.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <?php echo abstract_swal_js(); ?>
    <script>feather.replace();</script>
    <?php echo abstract_swal_render(); ?>
</body>

</html>
