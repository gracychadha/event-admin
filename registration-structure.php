<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("db/config.php");
include("abstract_auth.php");
include("abstract_helpers.php");
include("registration_pricing_helpers.php");

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

if (!abstract_can('Registration Pricing')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to view the registration structure.', 'dashboard.php');
}

// ---- Data (mirrors the delegate-facing view: active periods + active categories only) ----
$periods = rp_list_periods($db, true);
$activePeriod = rp_get_active_period($db);

$categories = [];
$catResult = mysqli_query($db, "SELECT id, category_name FROM registration_categories WHERE status = 1 ORDER BY category_name ASC");
if ($catResult) {
    while ($row = mysqli_fetch_assoc($catResult)) {
        $categories[] = $row;
    }
}

// Price map: [category_id][period_id] => row
$prices = [];
$priceResult = mysqli_query($db, "SELECT * FROM registration_category_prices WHERE status = 1");
if ($priceResult) {
    while ($row = mysqli_fetch_assoc($priceResult)) {
        $prices[$row['category_id']][$row['period_id']] = $row;
    }
}

// Currency-grouped fee map like the delegate view: [currency][category_id][period_id]
$feeMap = [];
foreach ($prices as $catId => $perPeriod) {
    foreach ($perPeriod as $pId => $price) {
        $cur = strtoupper($price['currency']);
        $feeMap[$cur][$catId][$pId] = $price;
    }
}

// Display order: INR first (national), then USD / EUR / GBP
$currencies = array_keys($feeMap);
usort($currencies, function ($a, $b) {
    $order = ['INR' => 0, 'USD' => 1, 'EUR' => 2, 'GBP' => 3];
    return ($order[$a] ?? 5) - ($order[$b] ?? 5);
});

function rs_currency_details($currency)
{
    $map = [
        'INR' => ['symbol' => '₹', 'label' => 'National Delegates', 'note' => 'Fees in Indian Rupees (INR)'],
        'USD' => ['symbol' => '$', 'label' => 'International Delegates', 'note' => 'Fees in US Dollars (USD)'],
        'EUR' => ['symbol' => '€', 'label' => 'International Delegates', 'note' => 'Fees in Euros (EUR)'],
        'GBP' => ['symbol' => '£', 'label' => 'International Delegates', 'note' => 'Fees in GBP (£)'],
    ];
    return $map[$currency] ?? ['symbol' => $currency . ' ', 'label' => 'Delegates [' . $currency . ']', 'note' => 'Fees in ' . $currency];
}

function rs_format_amount($amount, $currency)
{
    $d = rs_currency_details($currency);
    $formatted = number_format((float) $amount, ((float) $amount == floor((float) $amount)) ? 0 : 2);
    return $d['symbol'] . $formatted;
}

$settings = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM login_settings"));
$favicon = $settings['favicon'] ?? '';

$hasContent = !empty($periods) && !empty($feeMap);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Registration Structure</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
    <style>
        .structure-table small {
            white-space: nowrap;
        }

        .current-period-cell {
            background-color: rgba(24, 135, 84, 0.07);
        }
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
                                <h5 class="m-b-10">Registration Structure</h5>
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
                                <div class="col-md-8">
                                    <h5 class="m-b-0">Delegate Fee Structure Preview</h5>
                                    <span class="text-muted">Shows active periods and active categories exactly as delegates will see them
                                        (<?php echo count($periods); ?> period<?php echo count($periods) === 1 ? '' : 's'; ?>,
                                        <?php echo count($categories); ?> categor<?php echo count($categories) === 1 ? 'y' : 'ies'; ?>).
                                        <?php if ($activePeriod): ?>Current slot: <strong><?php echo htmlspecialchars($activePeriod['period_name']); ?></strong><?php endif; ?>
                                    </span>
                                </div>
                                <div class="col-md-4 text-right">
                                    <a href="registration-pricing.php" class="btn btn-primary"><i class="feather icon-edit"></i> Edit Prices</a>
                                    <a href="manage-registration-periods.php" class="btn btn-outline-secondary"><i class="feather icon-list"></i> Manage Periods</a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!$hasContent): ?>
                                <div class="text-center text-muted py-4">
                                    No published fees yet. Activate a <a href="add-registration-period.php">registration period</a>
                                    and set prices in <a href="registration-pricing.php">Registration Pricing</a> —
                                    delegates will see the structure here once it is ready.
                                </div>
                            <?php else: ?>
                                <?php foreach ($currencies as $currency):
                                    $det = rs_currency_details($currency);
                                    $groupCats = [];
                                    foreach ($categories as $cat) {
                                        $perPeriod = $feeMap[$currency][$cat['id']] ?? [];
                                        $any = false;
                                        foreach ($periods as $p) {
                                            if (isset($perPeriod[(int) $p['id']]) && (int) $perPeriod[(int) $p['id']]['availability'] === 1) {
                                                $any = true;
                                                break;
                                            }
                                        }
                                        if ($any) {
                                            $groupCats[] = $cat;
                                        }
                                    }
                                ?>
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h5 class="m-b-0">
                                                <?php echo htmlspecialchars($det['label']); ?>
                                                <span class="badge badge-info ml-1"><?php echo htmlspecialchars($currency); ?></span>
                                            </h5>
                                            <span class="text-muted"><?php echo htmlspecialchars($det['note']); ?></span>
                                        </div>
                                        <div class="card-body table-responsive">
                                            <table class="table table-striped table-bordered structure-table text-center">
                                                <thead>
                                                    <tr>
                                                        <th>Category</th>
                                                        <?php foreach ($periods as $p): ?>
                                                            <th>
                                                                <?php echo htmlspecialchars($p['period_name']); ?><br>
                                                                <small class="text-muted">(<?php echo date('d M Y', strtotime($p['start_date'])); ?> &ndash; <?php echo date('d M Y', strtotime($p['end_date'])); ?>)</small>
                                                                <?php if ($activePeriod && (int) $p['id'] === (int) $activePeriod['id']): ?>
                                                                    <br><small class="badge badge-success">Current Period</small>
                                                                <?php endif; ?>
                                                            </th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($groupCats as $cat): ?>
                                                        <tr>
                                                            <td class="text-left" style="font-weight: 600; min-width: 180px;"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                                            <?php foreach ($periods as $p):
                                                                $price = $feeMap[$currency][$cat['id']][(int) $p['id']] ?? null;
                                                                $isCurrent = $activePeriod && (int) $p['id'] === (int) $activePeriod['id'];
                                                            ?>
                                                                <td class="<?php echo $isCurrent ? 'current-period-cell' : ''; ?>">
                                                                    <?php if ($price && (int) $price['availability'] === 1): ?>
                                                                        <strong><?php echo rs_format_amount($price['amount'], $currency); ?></strong>
                                                                        <?php if ($price['gst_type'] !== 'not_applicable'): ?>
                                                                            <small class="text-muted d-block">
                                                                                <?php echo $price['gst_type']; ?> GST
                                                                                <?php echo rtrim(rtrim($price['gst_percentage'], '0'), '.'); ?>%
                                                                            </small>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        NA
                                                                    <?php endif; ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div class="card">
                                    <div class="card-body">
                                        <h5>Notes (as shown to delegates)</h5>
                                        <ul style="list-style: disc; padding-left: 20px;" class="m-b-0">
                                            <li>The applicable fee is assigned automatically based on the registration date &mdash; current period highlighted.</li>
                                            <li>NA means registration under that category is not open for that period.</li>
                                            <li>Discounts (earnings / early-bird offers) apply to the registration fee only, before GST, and the best single discount is chosen automatically during registration.</li>
                                            <li>Workshops and add-on options, once priced, are selectable separately during registration.</li>
                                        </ul>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>feather.replace();</script>
</body>

</html>
