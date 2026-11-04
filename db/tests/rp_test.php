<?php
/**
 * Pricing engine test suite (CLI).
 * Seeds test data, runs quote/calculation scenarios, reports results, cleans up.
 * Usage: php rp_test.php
 */

error_reporting(E_ALL);

require 'C:/laragon/www/main-admin/db/config.php';
include 'C:/laragon/www/main-admin/registration_pricing_helpers.php';

$pass = 0;
$fail = 0;
$failures = [];

function check($name, $condition, $detail = '')
{
    global $pass, $fail, $failures;
    if ($condition) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        $failures[] = "$name :: $detail";
        echo "FAIL  $name" . ($detail ? " :: $detail" : "") . "\n";
    }
}

// ---------------- SETUP ----------------
mysqli_query($db, "SET FOREIGN_KEY_CHECKS = 0");
foreach (['registrations', 'registration_payments', 'registration_discount_categories', 'registration_discounts', 'registration_category_prices', 'registration_category_options', 'registration_periods', 'payment_settings', 'registration_categories'] as $t) {
    mysqli_query($db, "TRUNCATE TABLE $t");
}
mysqli_query($db, "SET FOREIGN_KEY_CHECKS = 1");

mysqli_query($db, "INSERT INTO registration_categories (id, category_name, status) VALUES (10,'Test Faculty',1),(20,'Test PG Student',1),(30,'Test UG Student',1),(40,'Test Inactive',0)");

// Periods covering 2027 conference cycle (non-overlapping, inclusive dates)
mysqli_query($db, "INSERT INTO registration_periods (id, period_name, start_date, end_date, display_order, status) VALUES
    (1,'Early Bird','2026-12-01','2026-12-31',1,1),
    (2,'Regular','2027-01-01','2027-02-28',2,1),
    (3,'Late / On-Spot','2027-03-01','2027-12-31',3,1)");

// Prices
mysqli_query($db, "INSERT INTO registration_category_prices (category_id, period_id, amount, currency, availability, gst_type, gst_percentage, status) VALUES
    (10, 1, 6000, 'INR', 1, 'not_applicable', 0, 1),   -- Faculty EB 6000
    (10, 2, 7000, 'INR', 1, 'excluded', 18, 1),       -- Faculty Regular 7000 + 18% GST
    (10, 3, 8000, 'INR', 1, 'included', 18, 1),       -- Faculty Late 8000 incl 18% GST
    (20, 1, 4000, 'INR', 1, 'not_applicable', 0, 1),  -- PG 4000 all periods
    (20, 2, 4000, 'INR', 1, 'not_applicable', 0, 1),
    (20, 3, 4000, 'INR', 0, 'not_applicable', 0, 1),  -- PG NA in Late
    (30, 1, 2500, 'INR', 0, 'not_applicable', 0, 1),  -- UG unavailable in EB
    (30, 2, 3000, 'INR', 1, 'not_applicable', 0, 1),
    (30, 3, 3500, 'INR', 1, 'not_applicable', 0, 1),
    (40, 1, 9999, 'INR', 1, 'not_applicable', 0, 1)");

// Options (CME workshops)
mysqli_query($db, "INSERT INTO registration_category_options (id, category_id, option_name, amount, currency, availability, gst_type, gst_percentage, start_date, end_date, status) VALUES
    (5, 10, 'Full Day Workshop', 3000, 'INR', 1, 'excluded', 18, NULL, NULL, 1),
    (6, 10, 'Half Day Workshop', 2000, 'INR', 1, 'excluded', 18, NULL, NULL, 1),
    (7, 10, 'Expired Workshop',   999,  'INR', 1, 'excluded', 18, '2025-01-01', '2025-06-30', 1)");

// Discounts
mysqli_query($db, "INSERT INTO registration_discounts (id, discount_name, start_date, end_date, discount_type, discount_value, status) VALUES
    (1, 'Test Percentage', '2027-01-10', '2027-01-15', 'percentage', 20, 1),
    (2, 'Test Fixed',      '2026-12-10', '2026-12-15', 'fixed', 500, 1),
    (3, 'Test Inactive',   '2027-01-10', '2027-01-15', 'percentage', 50, 0)");
mysqli_query($db, "INSERT INTO registration_discount_categories (discount_id, category_id) VALUES
    (1, 10), (1, 20),
    (2, 10),
    (3, 20)");

// Category with NO price rows at all
mysqli_query($db, "INSERT INTO registration_categories (id, category_name, status) VALUES (50,'Test Unpriced',1)");

// ---------------- TESTS ----------------

// 1-3. Different prices across periods (Early Bird / Regular / Late)
$q = rp_get_category_quote($db, 10, null, '2026-12-05');
check('Faculty Early Bird price', $q['available'] && (float) $q['breakdown']['final_amount'] === 6000.0, json_encode($q['breakdown'] ?? []));
check('Faculty EB period name', $q['period']['period_name'] === 'Early Bird');

$q = rp_get_category_quote($db, 10, null, '2027-01-05');
check('Faculty Regular price + GST 18% excluded', $q['available'] && (float) $q['breakdown']['final_amount'] === 8260.0, json_encode($q['breakdown'] ?? []));
check('Faculty Regular GST amount', (float) $q['breakdown']['gst_amount'] === 1260.0);

$q = rp_get_category_quote($db, 10, null, '2027-03-15');
check('Faculty Late price (8000 incl. GST) final', $q['available'] && (float) $q['breakdown']['final_amount'] === 8000.0, json_encode($q['breakdown'] ?? []));
check('Faculty Late GST extracted', (float) $q['breakdown']['gst_amount'] === 1220.34 && $q['breakdown']['gst_type'] === 'included');

// 4. No active period
$q = rp_get_category_quote($db, 10, null, '2026-11-20');
check('No active period => unavailable', !$q['available']);

// 5. No discount -> plain slab price
$q = rp_get_category_quote($db, 20, null, '2026-12-05');
check('PG Early Bird 4000 no discount', $q['available'] && (float) $q['breakdown']['discount_amount'] === 0.0 && (float) $q['breakdown']['final_amount'] === 4000.0);

// 6. Percentage discount (20% on 4000 in window)
$q = rp_get_category_quote($db, 20, null, '2027-01-12');
check('PG percentage discount 20% (4000->3200)', $q['available'] && (float) $q['breakdown']['discount_amount'] === 800.0 && (float) $q['breakdown']['final_amount'] === 3200.0, json_encode($q['breakdown'] ?? []));

// 7. Fixed discount (500 on 6000)
$q = rp_get_category_quote($db, 10, null, '2026-12-12');
check('Faculty fixed discount 500 (6000->5500)', $q['available'] && (float) $q['breakdown']['final_amount'] === 5500.0);
check('Faculty discount name carried', $q['breakdown']['discount_name'] === 'Test Fixed');

// 8. Discount before start date
$q = rp_get_category_quote($db, 20, null, '2027-01-09');
check('Discount not applied before start date', (float) $q['breakdown']['discount_amount'] === 0.0);

// 9. Discount after end date
$q = rp_get_category_quote($db, 20, null, '2027-01-16');
check('Discount not applied after end date', (float) $q['breakdown']['discount_amount'] === 0.0);

// 10. Discount applies to multiple categories (pct discount covers 10,20)
$q = rp_get_category_quote($db, 20, null, '2027-01-12');
check('Discount valid for second category', (float) $q['breakdown']['discount_amount'] === 800.0);
$q2 = rp_get_category_quote($db, 10, null, '2027-01-12');
// Discount applies to reg fee only, then GST recalculated on the discounted taxable amount:
// 7000 - 1400 = 5600 taxable, GST 18% excluded = 1008, final = 6608 (spec example)
check('Faculty discount then GST on discounted amount (6608)', (float) $q2['breakdown']['discount_amount'] === 1400.0 && (float) $q2['breakdown']['final_amount'] === 6608.0, json_encode($q2['breakdown'] ?? []));

// 11. Discount NOT applicable to UG (not mapped)
$q = rp_get_category_quote($db, 30, null, '2027-01-12');
check('Discount not applied to unmapped category', (float) $q['breakdown']['discount_amount'] === 0.0);

// 12. Inactive discount ignored
$q = rp_get_category_quote($db, 20, null, '2027-01-12');
check('Inactive discount ignored', $q['discount']['discount_name'] !== 'Test Inactive');

// NA availability blocks registration
$q = rp_get_category_quote($db, 30, null, '2026-12-05');
check('UG marked NA in Early Bird => unavailable', !$q['available']);

$q = rp_get_category_quote($db, 20, null, '2027-03-15');
check('PG marked NA in Late => unavailable', !$q['available']);

$q = rp_get_category_quote($db, 50, null, '2026-12-05');
check('Category with no price rows => unavailable', !$q['available']);

$q = rp_get_category_quote($db, 40, null, '2026-12-05');
check('Inactive category => unavailable', !$q['available']);

// Options (date 2027-01-05 = Regular, no discount window: 7000 + 3000, GST 18% excluded on each)
$q = rp_get_category_quote($db, 10, 5, '2027-01-05');
check('Full day workshop selected: taxable = 10000', abs($q['breakdown']['taxable_amount'] - 10000.0) < 0.01, json_encode($q['breakdown'] ?? []));
check('Option GST union (1260 + 540 = 1800)', (float) $q['breakdown']['gst_amount'] === 1800.0, 'gst=' . $q['breakdown']['gst_amount']);
check('Option final adds up (11800)', (float) $q['breakdown']['final_amount'] === 11800.0);

// Options WITH percentage discount (2027-01-12: 20% of 7000 = 1400)
// taxable = (7000-1400) + 3000 = 8600, GST = 1008 + 540 = 1548, final = 10148
$q = rp_get_category_quote($db, 10, 5, '2027-01-12');
check('Discounted workshop quote: taxable 8600', abs($q['breakdown']['taxable_amount'] - 8600.0) < 0.01, json_encode($q['breakdown'] ?? []));
check('Discounted workshop GST 1548', (float) $q['breakdown']['gst_amount'] === 1548.0);
check('Discounted workshop final 10148', (float) $q['breakdown']['final_amount'] === 10148.0);

$q = rp_get_category_quote($db, 10, 7, '2027-01-05');
check('Expired option rejected', !$q['available']);

// Pure calc: fixed discount never exceeds base
$base = ['amount' => 1000, 'currency' => 'INR', 'gst_type' => 'not_applicable', 'gst_percentage' => 0];
$disc = ['id' => 9, 'discount_name' => 'Huge', 'discount_type' => 'fixed', 'discount_value' => 5000];
$b = rp_calculate_breakdown($base, null, $disc);
check('Fixed discount capped at base (never negative)', $b['discount_amount'] === 1000.0 && $b['final_amount'] === 0.0);

// Pure calc: percentage > 100 clamped
$discP = ['id' => 9, 'discount_name' => 'Crazy', 'discount_type' => 'percentage', 'discount_value' => 250];
$b = rp_calculate_breakdown($base, null, $discP);
check('Percentage > 100 clamped to 100 (final 0)', $b['final_amount'] === 0.0);

// Pure calc: GST included math
$inc = ['amount' => 5000, 'currency' => 'INR', 'gst_type' => 'included', 'gst_percentage' => 18];
$b = rp_calculate_breakdown($inc, null, null);
check('GST included 5000: gst = 762.71, final = 5000', $b['gst_amount'] === 762.71 && $b['final_amount'] === 5000.0);

// Conflict: two active discounts covering same category+date => best single applied, no stacking
mysqli_query($db, "UPDATE registration_discounts SET status = 1 WHERE id = 3"); // 50% pct overlaps pct window
$q = rp_get_category_quote($db, 20, null, '2027-01-12');
check('Conflict resolution: best single discount 50% (2000), not stacked', (float) $q['breakdown']['discount_amount'] === 2000.0 && (float) $q['breakdown']['final_amount'] === 2000.0, json_encode($q['breakdown'] ?? []));
mysqli_query($db, "UPDATE registration_discounts SET status = 0 WHERE id = 3");

// Overlap validation SQL semantics (used by period CRUD)
$r = mysqli_query($db, "SELECT period_name FROM registration_periods WHERE status = 1 AND NOT (end_date < '2027-02-15' OR start_date > '2027-03-10') LIMIT 1");
$row = mysqli_fetch_assoc($r);
check('Overlap detection finds Regular (2027-02-15..03-10 overlaps)', $row['period_name'] === 'Regular', $row['period_name'] ?? '');
$r = mysqli_query($db, "SELECT period_name FROM registration_periods WHERE status = 1 AND NOT (end_date < '2028-01-01' OR start_date > '2028-01-31') LIMIT 1");
check('No overlap for disjoint range', mysqli_num_rows($r) === 0);

// Currency check: USD price path works through quote
mysqli_query($db, "UPDATE registration_category_prices SET currency = 'USD' WHERE category_id = 10 AND period_id = 2");
$q = rp_get_category_quote($db, 10, 5, '2027-01-05'); // option 5 is INR => currency mismatch must reject
check('Option currency mismatch rejected', !$q['available']);
mysqli_query($db, "UPDATE registration_category_prices SET currency = 'INR' WHERE category_id = 10 AND period_id = 2");

echo "\n$pass passed, $fail failed\n";
if ($fail > 0) {
    echo implode("\n", $failures) . "\n";
}
