<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}
$name = $_SESSION['login_user'];

include("db/config.php");
include("registration_pricing_helpers.php");

$periods = rp_list_periods($db, false); // all periods incl. inactive
$categoriesResult = mysqli_query($db, "SELECT id, category_name, status FROM registration_categories ORDER BY category_name ASC");
$categories = [];
while ($row = mysqli_fetch_assoc($categoriesResult)) {
    $categories[] = $row;
}

// Price map: [category_id][period_id] => row
$prices = [];
$priceResult = mysqli_query($db, "SELECT * FROM registration_category_prices");
while ($row = mysqli_fetch_assoc($priceResult)) {
    $prices[$row['category_id']][$row['period_id']] = $row;
}

$activePeriod = rp_get_active_period($db);
$selectedPeriodId = isset($_GET['period_id']) ? (int) $_GET['period_id'] : ($activePeriod ? (int) $activePeriod['id'] : (isset($periods[0]) ? (int) $periods[0]['id'] : 0));

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
    <title>Registration Pricing</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .price-cell {
            cursor: pointer;
            min-width: 130px;
        }

        .price-cell .amount {
            font-weight: 600;
        }

        .price-cell.na .amount {
            color: #999;
            font-weight: 400;
        }

        .price-badge {
            font-size: 10px;
            padding: 1px 6px;
            margin-left: 4px;
        }

        .badge-unavailable {
            background: #6c757d;
        }

        .badge-inactive-row {
            background: #f0ad4e;
        }
    </style>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <!-- Header -->
    <?php include("header.php"); ?>
    <!-- /Header -->

    <!-- Navbar -->
    <?php include("navbar.php"); ?>
    <!-- /Navbar -->

    <section class="pcoded-main-container">
        <div class="pcoded-content">
            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Registration Pricing (Category &times; Period)</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <div class="row align-items-center w-100">
                                <div class="col-md-5">
                                    <label class="m-b-0 mr-2">Highlight Period:</label>
                                    <select id="periodFilter" class="form-control" style="max-width:340px;display:inline-block;">
                                        <?php foreach ($periods as $p): ?>
                                            <option value="<?php echo (int) $p['id']; ?>" <?php echo ((int) $p['id'] === $selectedPeriodId) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['period_name']); ?>
                                                (<?php echo date('d M Y', strtotime($p['start_date'])); ?> - <?php echo date('d M Y', strtotime($p['end_date'])); ?>)
                                                <?php if ($activePeriod && (int) $p['id'] === (int) $activePeriod['id']) echo ' - CURRENT'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if (empty($periods)): ?>
                                            <option value="">No periods defined yet</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-7 text-right">
                                    <a href="add-registration-period.php" class="btn btn-primary"><i class="feather icon-plus"></i> Add Registration Period</a>
                                    <a href="manage-registration-periods.php" class="btn btn-outline-secondary"><i class="feather icon-list"></i> Manage Periods</a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (isset($_SESSION['msg'])) {
                                echo $_SESSION['msg'];
                                unset($_SESSION['msg']);
                            } ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered" id="pricingMatrix">
                                    <thead>
                                        <tr>
                                            <th>CATEGORY</th>
                                            <?php foreach ($periods as $p): ?>
                                                <th>
                                                    <?php echo htmlspecialchars($p['period_name']); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo date('d M Y', strtotime($p['start_date'])); ?> - <?php echo date('d M Y', strtotime($p['end_date'])); ?></small>
                                                    <?php if ($p['status'] != 1): ?>
                                                        <br><span class="badge badge-inactive-row">Inactive</span>
                                                    <?php endif; ?>
                                                    <?php if ($activePeriod && (int) $p['id'] === (int) $activePeriod['id']): ?>
                                                        <br><span class="badge badge-success">Current</span>
                                                    <?php endif; ?>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($periods)): ?>
                                            <tr>
                                                <td colspan="<?php echo count($categories) + 1; ?>" class="text-center text-muted">
                                                    No registration periods defined. <a href="add-registration-period.php">Create a period first</a>.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($categories as $cat): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                                        <?php if ($cat['status'] != 1): ?>
                                                            <span class="badge badge-inactive-row">Category Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php foreach ($periods as $p): ?>
                                                        <?php
                                                        $pr = $prices[$cat['id']][$p['id']] ?? null;
                                                        $isNa = !$pr || (int) $pr['availability'] !== 1;
                                                        ?>
                                                        <td class="price-cell <?php echo $isNa ? 'na' : ''; ?>">
                                                            <div class="price-edit"
                                                                data-category="<?php echo (int) $cat['id']; ?>"
                                                                data-period="<?php echo (int) $p['id']; ?>"
                                                                data-category-name="<?php echo htmlspecialchars($cat['category_name']); ?>"
                                                                data-period-name="<?php echo htmlspecialchars($p['period_name']); ?>">
                                                                <?php if ($pr): ?>
                                                                    <span class="amount"><?php echo rp_money($pr['amount'], $pr['currency']); ?></span>
                                                                    <?php if ($pr['gst_type'] !== 'not_applicable'): ?>
                                                                        <span class="badge badge-info price-badge">GST <?php echo $pr['gst_type']; ?> <?php echo rtrim(rtrim($pr['gst_percentage'], '0'), '.'); ?>%</span>
                                                                    <?php endif; ?>
                                                                    <?php if ((int) $pr['availability'] !== 1): ?>
                                                                        <span class="badge badge-unavailable price-badge">NA</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($pr['status'] != 1): ?>
                                                                        <span class="badge badge-inactive-row price-badge">Off</span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="amount text-muted">NA</span>
                                                                    <small class="text-muted d-block">click to set</small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted"><i class="feather icon-info"></i> Click any cell to configure the price for that category &amp; period. NA means the category is unavailable (registration blocked) for that period.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Price Edit Modal -->
    <div class="modal fade" id="priceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="priceModalTitle">Configure Price</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">X</button>
                </div>
                <div class="modal-body">
                    <p><strong id="priceModalContext"></strong></p>
                    <input type="hidden" id="fCategoryId">
                    <input type="hidden" id="fPeriodId">
                    <div class="form-group">
                        <label>Registration Amount <span class="red-text">*</span></label>
                        <input type="number" step="0.01" min="0" id="fAmount" class="form-control" placeholder="e.g. 5000.00">
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>Currency</label>
                            <select id="fCurrency" class="form-control">
                                <option value="INR">INR (&#8377;)</option>
                                <option value="USD">USD ($)</option>
                                <option value="EUR">EUR (&euro;)</option>
                                <option value="GBP">GBP (&pound;)</option>
                            </select>
                        </div>
                        <div class="form-group col-4">
                            <label>Availability</label>
                            <select id="fAvailability" class="form-control">
                                <option value="1">Available</option>
                                <option value="0">NA (Unavailable)</option>
                            </select>
                        </div>
                        <div class="form-group col-4">
                            <label>Status</label>
                            <select id="fStatus" class="form-control">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>GST Type</label>
                            <select id="fGstType" class="form-control">
                                <option value="not_applicable">Not Applicable</option>
                                <option value="included">GST Included</option>
                                <option value="excluded">GST Excluded</option>
                            </select>
                        </div>
                        <div class="form-group col-6">
                            <label>GST Percentage (%)</label>
                            <input type="number" step="0.01" min="0" max="100" id="fGstPercentage" class="form-control" value="0" disabled>
                            <small class="text-muted" id="fGstHint"></small>
                        </div>
                    </div>
                    <div id="priceModalMsg"></div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" id="deletePrice" class="btn btn-danger">Remove Price</button>
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" id="savePrice" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('#periodFilter').forEach(function(sel) {
            sel.addEventListener('change', function() {
                window.location.href = 'registration-pricing.php?period_id=' + encodeURIComponent(this.value);
            });
        });

        var priceModal = new bootstrap.Modal(document.getElementById('priceModal'));
        var gstTypeSel = document.getElementById('fGstType');
        var gstPctInput = document.getElementById('fGstPercentage');
        var gstHint = document.getElementById('fGstHint');

        gstTypeSel.addEventListener('change', function() {
            var disabled = (this.value === 'not_applicable');
            gstPctInput.disabled = disabled;
            gstHint.textContent = disabled ? '' : (this.value === 'included' ? 'GST is part of the amount above' : 'GST added on top of the amount above');
        });

        document.querySelectorAll('.price-edit').forEach(function(cell) {
            cell.addEventListener('click', function() {
                var categoryId = this.getAttribute('data-category');
                var periodId = this.getAttribute('data-period');
                var catName = this.getAttribute('data-category-name');
                var perName = this.getAttribute('data-period-name');

                document.getElementById('fCategoryId').value = categoryId;
                document.getElementById('fPeriodId').value = periodId;
                document.getElementById('priceModalContext').textContent = catName + ' - ' + perName;
                document.getElementById('priceModalMsg').innerHTML = '';

                // Prefill from cell data via a fetch (single source of truth: server)
                fetch('save-registration-pricing.php?action=get', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'category_id=' + encodeURIComponent(categoryId) + '&period_id=' + encodeURIComponent(periodId)
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success && data.price) {
                            document.getElementById('fAmount').value = data.price.amount;
                            document.getElementById('fCurrency').value = data.price.currency;
                            document.getElementById('fAvailability').value = data.price.availability;
                            document.getElementById('fStatus').value = data.price.status;
                            gstTypeSel.value = data.price.gst_type;
                            gstPctInput.value = data.price.gst_percentage;
                            gstPctInput.disabled = (data.price.gst_type === 'not_applicable');
                            gstHint.textContent = (data.price.gst_type === 'not_applicable') ? '' : (data.price.gst_type === 'included' ? 'GST is part of the amount above' : 'GST added on top of the amount above');
                        } else {
                            document.getElementById('fAmount').value = '';
                            document.getElementById('fCurrency').value = 'INR';
                            document.getElementById('fAvailability').value = '1';
                            document.getElementById('fStatus').value = '1';
                            gstTypeSel.value = 'not_applicable';
                            gstPctInput.value = '0';
                            gstPctInput.disabled = true;
                            gstHint.textContent = '';
                        }
                        priceModal.show();
                    })
                    .catch(function() {
                        document.getElementById('priceModalMsg').innerHTML = '<div class="alert alert-danger">Failed to load price.</div>';
                    });
            });
        });

        document.getElementById('savePrice').addEventListener('click', function() {
            var payload = new URLSearchParams({
                action: 'save',
                category_id: document.getElementById('fCategoryId').value,
                period_id: document.getElementById('fPeriodId').value,
                amount: document.getElementById('fAmount').value,
                currency: document.getElementById('fCurrency').value,
                availability: document.getElementById('fAvailability').value,
                gst_type: gstTypeSel.value,
                gst_percentage: gstPctInput.disabled ? '0' : gstPctInput.value,
                status: document.getElementById('fStatus').value
            });

            fetch('save-registration-pricing.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: payload.toString()
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        document.getElementById('priceModalMsg').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                    }
                })
                .catch(function() {
                    document.getElementById('priceModalMsg').innerHTML = '<div class="alert alert-danger">Request failed.</div>';
                });
        });

        document.getElementById('deletePrice').addEventListener('click', function() {
            var payload = new URLSearchParams({
                action: 'delete',
                category_id: document.getElementById('fCategoryId').value,
                period_id: document.getElementById('fPeriodId').value
            });

            fetch('save-registration-pricing.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: payload.toString()
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        document.getElementById('priceModalMsg').innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
                    }
                });
        });
    </script>

</body>

</html>
