<?php
/**
 * Registration Pricing Helpers (admin side)
 *
 * Core pricing engine for period-wise category pricing with GST,
 * add-on options and special occasion discounts.
 *
 * The pure calculation functions (rp_gst_component, rp_calculate_breakdown)
 * must stay identical to apapuindia2027/include/registration-pricing.php
 * so the frontend and backend always compute the same amounts.
 */

if (!function_exists('rp_get_active_period')) {

if (!function_exists('rp_db_safe')) {
    function rp_db_safe($db)
    {
        return ($db instanceof mysqli) ? $db : null;
    }
}

/**
 * Active registration period covering the given date (inclusive).
 */
function rp_get_active_period(mysqli $db, $date = null)
{
    $date = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
    $stmt = mysqli_prepare($db, "SELECT * FROM registration_periods
        WHERE status = 1 AND start_date <= ? AND end_date >= ?
        ORDER BY display_order ASC, start_date ASC LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $date, $date);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/**
 * List all active periods (ordered) - used by the pricing matrix.
 */
function rp_list_periods(mysqli $db, $onlyActive = true)
{
    $sql = "SELECT * FROM registration_periods";
    if ($onlyActive) {
        $sql .= " WHERE status = 1";
    }
    $sql .= " ORDER BY display_order ASC, start_date ASC";
    $result = mysqli_query($db, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Active discounts valid for a category on a date.
 * (Caller decides how to resolve conflicts - this engine applies the single best.)
 */
function rp_get_applicable_discounts(mysqli $db, $categoryId, $date = null)
{
    $date = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
    $stmt = mysqli_prepare($db, "SELECT d.* FROM registration_discounts d
        INNER JOIN registration_discount_categories dc ON dc.discount_id = d.id
        WHERE d.status = 1 AND d.start_date <= ? AND d.end_date >= ? AND dc.category_id = ?
        ORDER BY d.id ASC");
    mysqli_stmt_bind_param($stmt, "ssi", $date, $date, $categoryId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Discount amount a given discount row would produce on a base fee.
 */
function rp_discount_amount(array $discountRow, $baseFee)
{
    $baseFee = (float) $baseFee;
    if ($discountRow['discount_type'] === 'percentage') {
        $pct = min(100.0, max(0.0, (float) $discountRow['discount_value']));
        return round($baseFee * $pct / 100.0, 2);
    }
    // fixed - never exceed the base fee
    return round(min(max(0.0, (float) $discountRow['discount_value']), $baseFee), 2);
}

/**
 * GST component for a taxable amount.
 * excluded        => gst = taxable * pct / 100 (added on top)
 * included        => gst = taxable - taxable / (1 + pct/100) (extracted from total)
 * not_applicable  => 0
 */
function rp_gst_component($taxable, $gstType, $gstPercentage)
{
    $taxable = round((float) $taxable, 2);
    $pct     = (float) $gstPercentage;
    if ($gstType === 'excluded') {
        return round($taxable * $pct / 100.0, 2);
    }
    if ($gstType === 'included') {
        if ($pct <= 0) {
            return 0.0;
        }
        return round($taxable - ($taxable / (1 + $pct / 100.0)), 2);
    }
    return 0.0;
}

/**
 * Pure price calculation.
 *
 * $priceRow   : row from registration_category_prices (registration fee)
 * $optionRow  : row from registration_category_options or null (add-on / workshop)
 * $discountRow: row from registration_discounts or null
 *
 * Rules:
 *  - Discount applies to the registration fee only (not to add-ons).
 *  - GST computed per component from its own gst_type / gst_percentage.
 *  - Amounts can never become negative.
 *  - Original pricing inputs are never modified.
 */
function rp_calculate_breakdown(array $priceRow, ?array $optionRow = null, ?array $discountRow = null)
{
    $originalFee = round((float) $priceRow['amount'], 2);
    $optionAmt   = $optionRow ? round((float) $optionRow['amount'], 2) : 0.0;
    $currency    = $optionRow && !empty($optionRow['currency'])
        ? strtoupper($optionRow['currency'])
        : strtoupper($priceRow['currency'] ?? 'INR');

    // 1. Discount (on registration fee, before GST)
    $discountAmount = 0.0;
    $discountName   = null;
    if ($discountRow) {
        $discountAmount = rp_discount_amount($discountRow, $originalFee);
        $discountName   = $discountRow['discount_name'];
    }

    $regTaxable = round(max(0.0, $originalFee - $discountAmount), 2);
    $optTaxable = round(max(0.0, $optionAmt), 2);

    // 2. GST per component
    $regGstType = $priceRow['gst_type'] ?? 'not_applicable';
    $regGstPct  = (float) ($priceRow['gst_percentage'] ?? 0);
    $optGstType = $optionRow ? ($optionRow['gst_type'] ?? 'not_applicable') : 'not_applicable';
    $optGstPct  = $optionRow ? (float) ($optionRow['gst_percentage'] ?? 0) : 0.0;

    $regGst = rp_gst_component($regTaxable, $regGstType, $regGstPct);
    $optGst = rp_gst_component($optTaxable, $optGstType, $optGstPct);

    // 3. Final contributions
    $regFinal = ($regGstType === 'excluded') ? round($regTaxable + $regGst, 2) : $regTaxable;
    $optFinal = ($optGstType === 'excluded') ? round($optTaxable + $optGst, 2) : $optTaxable;

    // 4. Totals
    $taxable = round($regTaxable + $optTaxable, 2);
    $gstAmt  = round($regGst + $optGst, 2);
    $payable = round($regFinal + $optFinal, 2);

    // Effective summary type for snapshots: excluded > included > not_applicable
    if ($regGstType === 'excluded' || $optGstType === 'excluded') {
        $effectiveType = 'excluded';
    } elseif ($regGstType === 'included' || $optGstType === 'included') {
        $effectiveType = 'included';
    } else {
        $effectiveType = 'not_applicable';
    }

    return [
        'currency'        => $currency,
        'original_fee'    => $originalFee,
        'option_amount'   => $optionAmt,
        'discount_id'     => $discountRow['id'] ?? null,
        'discount_name'   => $discountName,
        'discount_amount' => $discountAmount,
        'taxable_amount'  => $taxable,
        'gst_type'        => $effectiveType,
        'gst_percentage'  => $regGstPct,
        'gst_amount'      => $gstAmt,
        'final_amount'    => $payable,
    ];
}

/**
 * Available add-on options for a category on a date.
 */
function rp_get_available_options(mysqli $db, $categoryId, $date = null)
{
    $date = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
    $stmt = mysqli_prepare($db, "SELECT * FROM registration_category_options
        WHERE category_id = ? AND status = 1 AND availability = 1
          AND (start_date IS NULL OR start_date <= ?)
          AND (end_date IS NULL OR end_date >= ?)
        ORDER BY id ASC");
    mysqli_stmt_bind_param($stmt, "iss", $categoryId, $date, $date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Full quote for a category on a date.
 *
 * Returns:
 *  - ['available' => false, 'reason' => ...] when registration is not allowed
 *  - ['available' => true, 'period' => ..., 'price' => ..., 'option' => ...,
 *     'discount' => ..., 'breakdown' => [...]] otherwise
 */
function rp_get_category_quote(mysqli $db, $categoryId, $optionId = null, $date = null)
{
    $categoryId = (int) $categoryId;
    $date       = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');

    // 1. Current period
    $period = rp_get_active_period($db, $date);
    if (!$period) {
        return ['available' => false, 'reason' => 'Registration is currently closed. No active registration period found.'];
    }

    // 2. Category must exist and be enabled
    $stmt = mysqli_prepare($db, "SELECT * FROM registration_categories WHERE id = ? AND status = 1");
    mysqli_stmt_bind_param($stmt, "i", $categoryId);
    mysqli_stmt_execute($stmt);
    $category = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$category) {
        return ['available' => false, 'reason' => 'Selected category is not available.'];
    }

    // 3. Price row for this category + period (missing or NA => blocked)
    $stmt = mysqli_prepare($db, "SELECT * FROM registration_category_prices
        WHERE category_id = ? AND period_id = ? AND status = 1");
    mysqli_stmt_bind_param($stmt, "ii", $categoryId, $period['id']);
    mysqli_stmt_execute($stmt);
    $priceRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$priceRow) {
        return ['available' => false, 'reason' => 'Pricing not configured for this category in the current period.'];
    }
    if ((int) $priceRow['availability'] !== 1) {
        return ['available' => false, 'reason' => 'This category is not available in the current registration period.'];
    }
    if ((float) $priceRow['amount'] <= 0) {
        return ['available' => false, 'reason' => 'Invalid price configured for this category.'];
    }

    // 4. Add-on option (must belong to the same category, same currency, valid on date)
    $optionRow = null;
    if ($optionId) {
        $stmt = mysqli_prepare($db, "SELECT * FROM registration_category_options
            WHERE id = ? AND category_id = ? AND status = 1 AND availability = 1
              AND (start_date IS NULL OR start_date <= ?)
              AND (end_date IS NULL OR end_date >= ?)");
        mysqli_stmt_bind_param($stmt, "iiss", $optionId, $categoryId, $date, $date);
        mysqli_stmt_execute($stmt);
        $optionRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$optionRow) {
            return ['available' => false, 'reason' => 'Selected add-on option is not available.'];
        }
        if (strtoupper($optionRow['currency']) !== strtoupper($priceRow['currency'])) {
            return ['available' => false, 'reason' => 'Selected add-on option currency does not match the registration fee currency.'];
        }
    }

    // 5. Best single discount (no stacking - largest benefit wins on legacy conflicts)
    $discountRow = null;
    $candidates  = rp_get_applicable_discounts($db, $categoryId, $date);
    foreach ($candidates as $candidate) {
        $amount = rp_discount_amount($candidate, (float) $priceRow['amount']);
        if ($amount > 0 && (!$discountRow || $amount > rp_discount_amount($discountRow, (float) $priceRow['amount']))) {
            $discountRow = $candidate;
        }
    }

    // 6. Compose
    $breakdown = rp_calculate_breakdown($priceRow, $optionRow, $discountRow);

    return [
        'available'  => true,
        'date'       => $date,
        'period'     => $period,
        'category'   => $category,
        'price'      => $priceRow,
        'option'     => $optionRow,
        'discount'   => $discountRow,
        'breakdown'  => $breakdown,
    ];
}

/**
 * Razorpay payment settings (single active row).
 */
function rp_get_payment_settings(mysqli $db)
{
    $result = mysqli_query($db, "SELECT * FROM payment_settings WHERE provider = 'razorpay' AND status = 1 LIMIT 1");
    return mysqli_fetch_assoc($result) ?: null;
}

/**
 * HTML currency formatter (admin display).
 */
function rp_money($amount, $currency = 'INR')
{
    $symbols = ['INR' => '₹', 'USD' => '$'];
    $symbol  = $symbols[strtoupper($currency)] ?? ($currency . ' ');
    return $symbol . number_format((float) $amount, 2);
}

} // end include guard
