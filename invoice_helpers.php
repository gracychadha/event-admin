<?php
/**
 * Invoice / Payment Receipt module helpers.
 * Requires: db/config.php loaded first.
 * Provides: calculation, numbering, words, fetch, snapshot, audit.
 */

if (file_exists(__DIR__ . '/abstract_auth.php')) {
    require_once __DIR__ . '/abstract_auth.php';
}
if (file_exists(__DIR__ . '/abstract_helpers.php')) {
    require_once __DIR__ . '/abstract_helpers.php';
}

if (!function_exists('invoice_type_prefix')) {
    function invoice_type_prefix($type)
    {
        return ($type === 'receipt') ? 'REC' : 'INV';
    }
}

if (!function_exists('invoice_type_label')) {
    function invoice_type_label($type)
    {
        return ($type === 'receipt') ? 'Payment Receipt' : 'Tax Invoice';
    }
}

if (!function_exists('invoice_payment_status_label')) {
    function invoice_payment_status_label($status)
    {
        $map = [
            'paid'    => ['label' => 'Paid', 'class' => 'badge bg-success'],
            'partial' => ['label' => 'Partial', 'class' => 'badge bg-warning text-dark'],
            'pending' => ['label' => 'Pending', 'class' => 'badge bg-secondary'],
        ];
        return $map[$status] ?? ['label' => ucfirst((string) $status), 'class' => 'badge bg-secondary'];
    }
}

if (!function_exists('invoice_status_label')) {
    function invoice_status_label($status)
    {
        $map = [
            'issued' => ['label' => 'Issued', 'class' => 'badge bg-success'],
            'void'   => ['label' => 'Void', 'class' => 'badge bg-danger'],
        ];
        return $map[$status] ?? ['label' => ucfirst((string) $status), 'class' => 'badge bg-secondary'];
    }
}

if (!function_exists('invoice_money')) {
    function invoice_money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}

if (!function_exists('invoice_calc_totals')) {
    /**
     * Single source of truth for financial calculations.
     *
     * @param array $items  Each: ['description','hsn_sac','quantity','rate','discount']
     * @param float $discountPercent Document-level discount percentage (applied to subtotal)
     * @param bool  $taxEnabled      Whether tax is added
     * @param float $taxRate         Tax percentage
     * @return array
     */
    function invoice_calc_totals(array $items, $discountPercent, $taxEnabled, $taxRate)
    {
        $discountPercent = max(0, min(100, (float) $discountPercent));
        $taxRate         = max(0, min(100, (float) $taxRate));

        $clean      = [];
        $subtotal   = 0.0;
        $lineDisc   = 0.0;
        foreach ($items as $index => $item) {
            $qty   = (float) ($item['quantity'] ?? 0);
            $rate  = (float) ($item['rate'] ?? 0);
            $disc  = (float) ($item['discount'] ?? 0);
            $qty   = $qty <= 0 ? 0.0 : $qty;
            $rate  = $rate < 0 ? 0.0 : $rate;
            $disc  = $disc < 0 ? 0.0 : $disc;

            $gross     = round($qty * $rate, 2);
            $lineDisc += $disc;
            $taxable   = round($gross - $disc, 2);
            if ($taxable < 0) {
                $taxable = 0.0;
            }
            $subtotal += $gross;

            $clean[] = [
                'description'    => (string) ($item['description'] ?? ''),
                'hsn_sac'        => (string) ($item['hsn_sac'] ?? ''),
                'quantity'       => $qty,
                'rate'           => $rate,
                'discount'       => $disc,
                'taxable_amount' => $taxable,
                'line_total'     => $taxable,
                'sort_order'     => (int) $index,
            ];
        }

        $subtotal        = round($subtotal, 2);
        $lineDisc        = round($lineDisc, 2);
        $discountAmount  = round($subtotal * ($discountPercent / 100), 2);
        $taxableAmount   = round($subtotal - $lineDisc - $discountAmount, 2);
        if ($taxableAmount < 0) {
            $taxableAmount = 0.0;
        }
        $taxAmount = $taxEnabled ? round($taxableAmount * ($taxRate / 100), 2) : 0.0;
        $grandTotal = round($taxableAmount + $taxAmount, 2);

        return [
            'items'           => $clean,
            'subtotal'        => $subtotal,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'taxable_amount'  => $taxableAmount,
            'tax_rate'        => $taxRate,
            'tax_amount'      => $taxAmount,
            'grand_total'     => $grandTotal,
        ];
    }
}

if (!function_exists('invoice_amount_in_words')) {
    /**
     * Convert a number to Indian-English words (Rupees / Paise).
     */
    function invoice_amount_in_words($number, $prefix = 'Rupees')
    {
        $number  = round((float) $number, 2);
        $rupees  = (int) floor($number);
        $paise   = (int) round(($number - $rupees) * 100);
        if ($paise === 100) {
            $paise = 0;
            $rupees++;
        }

        $words = invoice_number_to_words_indian($rupees);
        $out   = trim($prefix . ' ' . ($words === '' ? 'Zero' : $words));
        if ($paise > 0) {
            $out .= ' and ' . invoice_number_to_words_indian($paise) . ' Paise';
        }
        return $out . ' Only';
    }
}

if (!function_exists('invoice_number_to_words_indian')) {
    function invoice_number_to_words_indian($num)
    {
        if ($num < 0) {
            return '';
        }
        if ($num === 0) {
            return 'Zero';
        }

        $ones = [
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
            11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
            15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
            19 => 'Nineteen',
        ];
        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
        ];

        $belowHundred = function ($n) use ($ones, $tens) {
            if ($n < 20) {
                return $ones[$n];
            }
            $t = intdiv($n, 10);
            $o = $n % 10;
            return trim($tens[$t] . ($o ? ' ' . $ones[$o] : ''));
        };

        $belowThousand = function ($n) use ($belowHundred) {
            $h = intdiv($n, 100);
            $r = $n % 100;
            $out = '';
            if ($h > 0) {
                $out .= $belowHundred($h) . ' Hundred';
            }
            if ($r > 0) {
                $out .= ($out ? ' ' : '') . $belowHundred($r);
            }
            return $out;
        };

        $parts = [];
        $crore = intdiv($num, 10000000);
        $num %= 10000000;
        $lakh = intdiv($num, 100000);
        $num %= 100000;
        $thousand = intdiv($num, 1000);
        $num %= 1000;

        if ($crore > 0) {
            $parts[] = $belowThousand($crore) . ' Crore';
        }
        if ($lakh > 0) {
            $parts[] = $belowHundred($lakh) . ' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = $belowHundred($thousand) . ' Thousand';
        }
        if ($num > 0) {
            $parts[] = $belowThousand($num);
        }

        return trim(implode(' ', $parts));
    }
}

if (!function_exists('invoice_next_number')) {
    /**
     * Atomically allocate the next document number.
     * MUST be called inside an active transaction.
     */
    function invoice_next_number($db, $profileId, $type, $year)
    {
        $profileId = (int) $profileId;
        $type      = ($type === 'receipt') ? 'receipt' : 'invoice';
        $year      = (int) $year;

        // Upsert acquires a row lock; the following SELECT reads the locked value.
        // This is safe against concurrent requests inside a transaction.
        $stmt = $db->prepare(
            "INSERT INTO document_sequences (company_profile_id, document_type, year, last_number)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('isi', $profileId, $type, $year);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT last_number FROM document_sequences
             WHERE company_profile_id = ? AND document_type = ? AND year = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('isi', $profileId, $type, $year);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        $seq = $row ? (int) $row['last_number'] : 1;
        if ($seq < 1) {
            $seq = 1;
        }

        return invoice_type_prefix($type) . '-' . $year . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('invoice_admin_name')) {
    function invoice_admin_name($db, $adminId)
    {
        $stmt = $db->prepare("SELECT username FROM admin WHERE _id = ? LIMIT 1");
        if (!$stmt) {
            return 'Admin';
        }
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row['username'] ?? 'Admin';
    }
}

if (!function_exists('invoice_audit_log')) {
    function invoice_audit_log($db, $invoiceId, $actorId, $actorName, $action, $remarks = null)
    {
        $stmt = $db->prepare(
            "INSERT INTO invoice_audit_logs (invoice_id, actor_id, actor_name, action, remarks)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iisss', $invoiceId, $actorId, $actorName, $action, $remarks);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('invoice_fetch')) {
    /**
     * Fetch an invoice with its items and decoded company snapshot.
     */
    function invoice_fetch($db, $id)
    {
        $id   = (int) $id;
        $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res     = $stmt->get_result();
        $invoice = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$invoice) {
            return null;
        }

        $invoice['company'] = json_decode($invoice['company_snapshot'] ?? '', true) ?: [];

        $items = [];
        $istmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order ASC, id ASC");
        if ($istmt) {
            $istmt->bind_param('i', $id);
            $istmt->execute();
            $ires = $istmt->get_result();
            while ($row = $ires->fetch_assoc()) {
                $items[] = $row;
            }
            $istmt->close();
        }
        $invoice['items'] = $items;

        return $invoice;
    }
}

if (!function_exists('invoice_normalize_items')) {
    /**
     * Normalize posted line items into a clean array.
     */
    function invoice_normalize_items($raw)
    {
        $items = [];
        if (!is_array($raw)) {
            return $items;
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $desc = trim((string) ($row['description'] ?? ''));
            $qty  = trim((string) ($row['quantity'] ?? ''));
            $rate = trim((string) ($row['rate'] ?? ''));
            if ($desc === '' && $qty === '' && $rate === '') {
                continue;
            }
            $items[] = [
                'description' => $desc,
                'hsn_sac'     => trim((string) ($row['hsn_sac'] ?? '')),
                'quantity'    => $qty === '' ? 0 : (float) $qty,
                'rate'        => $rate === '' ? 0 : (float) $rate,
                'discount'    => 0.0,
            ];
        }
        return $items;
    }
}

if (!function_exists('invoice_build_from_post')) {
    /**
     * Build a normalized invoice payload from POST (used by preview + generate).
     */
    function invoice_build_from_post($db, array $post)
    {
        $profileId = (int) ($post['company_profile_id'] ?? 0);
        $type      = (($post['document_type'] ?? 'invoice') === 'receipt') ? 'receipt' : 'invoice';

        $profile = null;
        if ($profileId > 0) {
            $stmt = $db->prepare("SELECT * FROM company_profiles WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $profileId);
                $stmt->execute();
                $res     = $stmt->get_result();
                $profile = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            }
        }

        $taxEnabled     = !empty($post['tax_included']);
        $taxRate        = isset($post['tax_rate']) ? (float) $post['tax_rate'] : 0.0;
        $discountLabel  = trim((string) ($post['discount_label'] ?? ''));
        $discountPct    = isset($post['discount_percent']) ? (float) $post['discount_percent'] : 0.0;

        $items   = invoice_normalize_items($post['items'] ?? []);
        $totals  = invoice_calc_totals($items, $discountPct, $taxEnabled, $taxRate);

        $amountReceived = isset($post['amount_received']) ? (float) $post['amount_received'] : 0.0;
        if ($amountReceived < 0) {
            $amountReceived = 0.0;
        }

        return [
            'document_type'     => $type,
            'company_profile_id' => $profileId,
            'profile'           => $profile,
            'document_date'     => trim((string) ($post['document_date'] ?? date('Y-m-d'))),
            'customer_name'     => trim((string) ($post['customer_name'] ?? '')),
            'customer_email'    => trim((string) ($post['customer_email'] ?? '')),
            'customer_contact'  => trim((string) ($post['customer_contact'] ?? '')),
            'billing_address'   => trim((string) ($post['billing_address'] ?? '')),
            'customer_category' => trim((string) ($post['customer_category'] ?? '')),
            'event_name'        => trim((string) ($post['event_name'] ?? '')),
            'registration_id'   => trim((string) ($post['registration_id'] ?? '')),
            'payment_reference' => trim((string) ($post['payment_reference'] ?? '')),
            'payment_mode'      => trim((string) ($post['payment_mode'] ?? '')),
            'utr_reference'     => trim((string) ($post['utr_reference'] ?? '')),
            'discount_label'    => $discountLabel,
            'tax_included'      => $taxEnabled ? 1 : 0,
            'payment_status'    => trim((string) ($post['payment_status'] ?? 'pending')),
            'amount_received'   => $amountReceived,
            'payment_date'      => trim((string) ($post['payment_date'] ?? '')),
            'notes'             => trim((string) ($post['notes'] ?? '')),
            'totals'            => $totals,
        ];
    }
}

if (!function_exists('invoice_validate_payload')) {
    /**
     * Server-side validation. Returns array of error strings (empty = ok).
     */
    function invoice_validate_payload(array $payload)
    {
        $errors = [];

        if (empty($payload['company_profile_id']) || empty($payload['profile'])) {
            $errors[] = 'Please select a valid company profile.';
        }
        if ($payload['document_type'] === 'invoice' && empty($payload['profile']['gstin'])) {
            // Informational only (not blocking) - tax invoice without GSTIN still allowed.
        }
        if ($payload['document_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['document_date'])) {
            $errors[] = 'A valid document date is required.';
        }
        if ($payload['customer_name'] === '') {
            $errors[] = 'Customer / delegate name is required.';
        }
        if ($payload['customer_email'] !== '' && !filter_var($payload['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Customer email is not valid.';
        }

        $totals = $payload['totals'];
        if (!$totals['items']) {
            $errors[] = 'At least one line item is required.';
        }
        foreach ($totals['items'] as $i => $item) {
            $n = $i + 1;
            if (trim($item['description']) === '') {
                $errors[] = "Line {$n}: description is required.";
            }
            if ($item['quantity'] <= 0) {
                $errors[] = "Line {$n}: quantity must be greater than zero.";
            }
            if ($item['rate'] < 0) {
                $errors[] = "Line {$n}: rate cannot be negative.";
            }
        }
        if ($totals['discount_percent'] < 0 || $totals['discount_percent'] > 100) {
            $errors[] = 'Discount percentage must be between 0 and 100.';
        }
        if ($payload['tax_included'] && ($totals['tax_rate'] < 0 || $totals['tax_rate'] > 100)) {
            $errors[] = 'Tax rate must be between 0 and 100.';
        }
        if (!in_array($payload['payment_status'], ['pending', 'partial', 'paid'], true)) {
            $errors[] = 'Invalid payment status.';
        }
        if ($payload['payment_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['payment_date'])) {
            $errors[] = 'Payment date is not valid.';
        }

        return $errors;
    }
}

if (!function_exists('invoice_safe_upload')) {
    /**
     * Validate + store an uploaded image/PDF securely.
     * Returns relative path on success, null when no file, or throws on invalid.
     */
    function invoice_safe_upload($file, $subdir, array $allowedMime, $maxBytes = 2097152)
    {
        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (error code ' . $file['error'] . ').');
        }
        if ($file['size'] > $maxBytes) {
            throw new RuntimeException('File exceeds the maximum allowed size of ' . round($maxBytes / 1048576, 1) . ' MB.');
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : ($file['type'] ?? '');
        if (!in_array($mime, $allowedMime, true)) {
            throw new RuntimeException('Unsupported file type: ' . htmlspecialchars((string) $mime));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);
        if ($safeExt === '') {
            $safeExt = 'bin';
        }
        $dir = __DIR__ . '/uploads/' . trim($subdir, '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $safeExt;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }
        return 'uploads/' . trim($subdir, '/') . '/' . $name;
    }
}

if (!function_exists('invoice_asset_url')) {
    /**
     * Turn a stored relative path into something renderable by the browser / mPDF.
     */
    function invoice_asset_url($path, $absolute = false)
    {
        if (empty($path)) {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($absolute) {
            return __DIR__ . '/' . ltrim($path, '/');
        }
        return ltrim($path, '/');
    }
}
