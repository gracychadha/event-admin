<?php
/**
 * Shared invoice / receipt document layout.
 * One fixed design reused by preview (browser) and PDF (mPDF).
 *
 * Usage:
 *   $html = invoice_render_layout($doc, $forPdf = false);
 *
 * $doc keys: document_number, document_type, document_date, status,
 *   company[ ], customer_*, event_name, registration_id,
 *   payment_reference, payment_mode, utr_reference, amount_received,
 *   payment_date, payment_status, items[ ], subtotal, discount_label,
 *   discount_percent, discount_amount, taxable_amount, tax_label,
 *   tax_rate, tax_included, tax_amount, grand_total, amount_in_words,
 *   notes.
 */

if (!function_exists('invoice_render_layout')) {
    function invoice_render_layout(array $doc, $forPdf = false, $fullDocument = true)
    {
        $e = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        $company = is_array($doc['company'] ?? null) ? $doc['company'] : [];
        $type    = ($doc['document_type'] ?? 'invoice') === 'receipt' ? 'receipt' : 'invoice';

        $companyName = $company['company_name'] ?? '';
        $companyAddr = trim(implode(', ', array_filter([
            $company['address'] ?? '',
            $company['city'] ?? '',
            $company['state'] ?? '',
            $company['country'] ?? '',
            $company['postal_code'] ?? '',
        ])));
        $companyEmail   = $company['email'] ?? '';
        $companyContact = $company['contact_number'] ?? '';
        $gstin          = $company['gstin'] ?? '';
        $signatory      = $company['signatory_name'] ?? '';
        $footerText     = $company['footer_text'] ?? '';
        $logo           = invoice_asset_url($company['logo_path'] ?? '', $forPdf);
        $letterhead     = invoice_asset_url($company['letterhead_path'] ?? '', $forPdf);
        $signature      = invoice_asset_url($company['signature_path'] ?? '', $forPdf);

        // When a letterhead image is present it is used as a full-page background
        // and the duplicated header block (logo + company details) is suppressed.
        $hasBackground = ($letterhead !== '');

        $taxLabel = $company['tax_label'] ?? 'GST';
        if ($type === 'invoice' && !empty($doc['tax_label'])) {
            $taxLabel = $doc['tax_label'];
        }

        $title       = ($type === 'receipt') ? 'PAYMENT RECEIPT' : 'TAX INVOICE';
        $docNumber   = $doc['document_number'] ?? ($forPdf ? '' : 'DRAFT');
        $docDate     = !empty($doc['document_date']) ? date('d-m-Y', strtotime($doc['document_date'])) : '';
        $isVoid      = ($doc['status'] ?? 'issued') === 'void';

        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];

        // Build header block.
        $headerHtml = '';
        if ($hasBackground) {
            // Letterhead is rendered as a full-page background; header suppressed.
            $headerHtml = '';
        } else {
            $logoHtml = $logo !== ''
                ? '<img src="' . $e($logo) . '" style="max-height:80px;max-width:200px;" alt="Logo">'
                : '';
            $headerHtml .= '<table style="width:100%;border-collapse:collapse;"><tr>'
                . '<td style="width:22%;vertical-align:middle;border:none;">' . $logoHtml . '</td>'
                . '<td style="vertical-align:middle;border:none;text-align:right;">'
                . '<div style="font-size:20px;font-weight:bold;color:#00349a;">' . $e($companyName) . '</div>'
                . ($companyAddr !== '' ? '<div style="font-size:11px;color:#444;">' . $e($companyAddr) . '</div>' : '')
                . '<div style="font-size:11px;color:#444;">'
                . ($companyEmail !== '' ? 'Email: ' . $e($companyEmail) : '')
                . ($companyContact !== '' ? ' &nbsp;|&nbsp; Ph: ' . $e($companyContact) : '')
                . '</div>'
                . ($gstin !== '' ? '<div style="font-size:11px;color:#444;">GSTIN: ' . $e($gstin) . '</div>' : '')
                . '</td></tr></table>';
        }

        // Customer / event detail rows.
        $detailRows = '';
        $addRow = function ($label, $value) use (&$detailRows, $e) {
            if ($value === '' || $value === null) {
                return;
            }
            $detailRows .= '<tr><td style="padding:2px 6px;font-weight:bold;width:32%;border:none;">'
                . $e($label) . '</td><td style="padding:2px 6px;border:none;">' . $e($value) . '</td></tr>';
        };
        $addRow('Customer / Delegate', $doc['customer_name'] ?? '');
        $addRow('Email', $doc['customer_email'] ?? '');
        $addRow('Contact', $doc['customer_contact'] ?? '');
        $addRow('Category', $doc['customer_category'] ?? '');
        $addRow('Billing Address', $doc['billing_address'] ?? '');
        $addRow('Event / Conference', $doc['event_name'] ?? '');
        $addRow('Registration / Delegate ID', $doc['registration_id'] ?? '');
        $addRow('Payment Reference / Order ID', $doc['payment_reference'] ?? '');
        $addRow('Payment Mode', $doc['payment_mode'] ?? '');
        $addRow('UTR / Transaction Ref', $doc['utr_reference'] ?? '');

        // Item rows.
        $itemRows = '';
        $i = 1;
        foreach ($items as $item) {
            $itemRows .= '<tr>'
                . '<td style="text-align:center;">' . $i++ . '</td>'
                . '<td>' . $e($item['description'] ?? '') . '</td>'
                . '<td style="text-align:center;">' . $e($item['hsn_sac'] ?? '') . '</td>'
                . '<td style="text-align:center;">' . $e(number_format((float) ($item['quantity'] ?? 0), 2)) . '</td>'
                . '<td style="text-align:right;">' . $e(number_format((float) ($item['rate'] ?? 0), 2)) . '</td>'
                . '<td style="text-align:right;">' . $e(number_format((float) ($item['line_total'] ?? $item['taxable_amount'] ?? 0), 2)) . '</td>'
                . '</tr>';
        }
        if ($itemRows === '') {
            $itemRows = '<tr><td colspan="6" style="text-align:center;color:#888;">No items.</td></tr>';
        }

        // Totals block.
        $totalsRows = '<tr><td style="text-align:right;padding:4px 8px;border:none;">Subtotal</td>'
            . '<td style="text-align:right;padding:4px 8px;width:120px;border:none;">'
            . $e(number_format((float) ($doc['subtotal'] ?? 0), 2)) . '</td></tr>';

        $discountPct  = (float) ($doc['discount_percent'] ?? 0);
        $discountAmt  = (float) ($doc['discount_amount'] ?? 0);
        if ($discountPct > 0 || $discountAmt > 0) {
            $discLabel = trim((string) ($doc['discount_label'] ?? ''));
            $discLabel = $discLabel !== '' ? $discLabel : 'Discount';
            $totalsRows .= '<tr><td style="text-align:right;padding:4px 8px;border:none;">'
                . $e($discLabel) . ' (' . $e(number_format($discountPct, 2)) . '%)</td>'
                . '<td style="text-align:right;padding:4px 8px;border:none;">- '
                . $e(number_format($discountAmt, 2)) . '</td></tr>';
        }

        $totalsRows .= '<tr><td style="text-align:right;padding:4px 8px;border:none;">Taxable Amount</td>'
            . '<td style="text-align:right;padding:4px 8px;border:none;">'
            . $e(number_format((float) ($doc['taxable_amount'] ?? 0), 2)) . '</td></tr>';

        if (!empty($doc['tax_included'])) {
            $totalsRows .= '<tr><td style="text-align:right;padding:4px 8px;border:none;">'
                . $e($taxLabel) . ' @ ' . $e(number_format((float) ($doc['tax_rate'] ?? 0), 2)) . '%</td>'
                . '<td style="text-align:right;padding:4px 8px;border:none;">'
                . $e(number_format((float) ($doc['tax_amount'] ?? 0), 2)) . '</td></tr>';
        }

        // Payment received block (receipts / confirmed payments).
        $paymentHtml = '';
        $amountReceived = (float) ($doc['amount_received'] ?? 0);
        $statusLabel = invoice_payment_status_label($doc['payment_status'] ?? 'pending');
        if ($type === 'receipt' || $amountReceived > 0) {
            $paymentHtml .= '<table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:12px;">'
                . '<tr><td style="padding:2px 6px;font-weight:bold;border:none;width:32%;">Amount Received</td>'
                . '<td style="padding:2px 6px;border:none;">' . $e(number_format($amountReceived, 2)) . '</td></tr>'
                . '<tr><td style="padding:2px 6px;font-weight:bold;border:none;">Payment Status</td>'
                . '<td style="padding:2px 6px;border:none;">' . $e($statusLabel['label']) . '</td></tr>';
            if (!empty($doc['payment_date'])) {
                $paymentHtml .= '<tr><td style="padding:2px 6px;font-weight:bold;border:none;">Payment Date</td>'
                    . '<td style="padding:2px 6px;border:none;">' . $e(date('d-m-Y', strtotime($doc['payment_date']))) . '</td></tr>';
            }
            $paymentHtml .= '</table>';
        }

        $signatureHtml = '';
        if ($signature !== '') {
            $signatureHtml .= '<img src="' . $e($signature) . '" style="max-height:50px;max-width:160px;" alt="Signature"><br>';
        }
        $signatureHtml .= '<div style="border-top:1px solid #333;display:inline-block;padding-top:3px;min-width:160px;text-align:center;">'
            . ($signatory !== '' ? $e($signatory) : 'Authorised Signatory')
            . '<br><span style="font-size:10px;color:#555;">Authorised Signatory</span></div>';

        $notesHtml = '';
        if (!empty($doc['notes'])) {
            $notesHtml = '<div style="margin-top:8px;font-size:11px;color:#444;"><strong>Notes:</strong> '
                . nl2br($e($doc['notes'])) . '</div>';
        }

        $voidHtml = $isVoid
            ? '<div style="text-align:center;margin:10px 0;"><span style="color:#c00;border:2px solid #c00;padding:4px 14px;font-weight:bold;font-size:16px;letter-spacing:2px;">VOID</span></div>'
            : '';

        $base = $forPdf ? 'font-family:dejavusans,sans-serif;' : 'font-family:Arial,Helvetica,sans-serif;';

        // Full-page letterhead background + printable-area insets.
        // mPDF paints a body background across the whole page on every page break,
        // so there we attach it to body. In the browser preview the fragment is
        // injected into an existing page, so we attach it to the .doc sheet instead.
        $topPad    = isset($company['bg_margin_top']) && $company['bg_margin_top'] !== '' ? (float) $company['bg_margin_top'] : 45; // mm
        $bottomPad = isset($company['bg_margin_bottom']) && $company['bg_margin_bottom'] !== '' ? (float) $company['bg_margin_bottom'] : 20; // mm
        $sidePad   = isset($company['bg_margin_side']) && $company['bg_margin_side'] !== '' ? (float) $company['bg_margin_side'] : 12; // mm
        $bgTarget  = $forPdf ? 'body' : '.doc';
        if ($hasBackground) {
            $bgUrl = str_replace(['\\', '"'], ['/', '\\"'], $letterhead);
            $bodyRules = 'body { ' . $base . ' color:#222; font-size:12px; margin:0; padding:0; }'
                . ' ' . $bgTarget . ' { '
                . 'background-image:url("' . $bgUrl . '"); '
                . 'background-repeat:no-repeat; background-position:top left; background-size:100% 100%; }'
                . ' .doc { padding:' . $topPad . 'mm ' . $sidePad . 'mm ' . $bottomPad . 'mm ' . $sidePad . 'mm; }';
        } else {
            $bodyRules = 'body { ' . $base . ' color:#222; font-size:12px; margin:0; padding:0; }'
                . ' .doc { padding:6px; }';
        }

        $html = '<style>
            ' . $bodyRules . '
            h1.title { text-align:center; font-size:19px; letter-spacing:1px; margin:10px 0 4px; color:#00349a; }
            table.items { width:100%; border-collapse:collapse; margin-top:8px; }
            table.items th, table.items td { border:1px solid #999; padding:6px; font-size:11.5px; vertical-align:top; }
            table.items th { background:#eef2fb; color:#00349a; }
            table.meta { width:100%; border-collapse:collapse; margin-top:6px; font-size:12px; }
            .muted { color:#666; }
            .section-title { font-weight:bold; color:#00349a; border-bottom:1px solid #ccc; padding-bottom:2px; margin:10px 0 4px; font-size:12px; }
        </style><div class="doc">
            ' . $headerHtml . '
            <h1 class="title">' . $e($title) . '</h1>
            ' . $voidHtml . '
            <table class="meta"><tr>
                <td style="border:none;vertical-align:top;">' . $e($docNumber) . '</td>
                <td style="border:none;text-align:right;vertical-align:top;">Date: <strong>' . $e($docDate) . '</strong></td>
            </tr></table>

            <div class="section-title">Billing Details</div>
            <table class="meta">' . $detailRows . '</table>

            <div class="section-title">Itemised Details</div>
            <table class="items">
                <thead><tr>
                    <th style="width:6%;">#</th>
                    <th>Description</th>
                    <th style="width:14%;">HSN/SAC</th>
                    <th style="width:12%;">Qty</th>
                    <th style="width:15%;">Rate</th>
                    <th style="width:18%;">Amount</th>
                </tr></thead>
                <tbody>' . $itemRows . '</tbody>
            </table>

            <table class="meta" style="margin-top:8px;">
                <tr>
                    <td style="border:none;vertical-align:top;width:58%;">
                        <div class="muted" style="font-size:11px;">Amount in words:</div>
                        <div style="font-weight:bold;">' . $e($doc['amount_in_words'] ?? invoice_amount_in_words($doc['grand_total'] ?? 0)) . '</div>' . $paymentHtml . $notesHtml . '
                    </td>
                    <td style="border:none;vertical-align:top;padding:0;">
                        <table style="width:100%;border-collapse:collapse;">' . $totalsRows . '
                            <tr><td style="text-align:right;padding:6px 8px;border-top:2px solid #00349a;font-weight:bold;color:#00349a;">Grand Total</td>
                            <td style="text-align:right;padding:6px 8px;border-top:2px solid #00349a;width:120px;font-weight:bold;color:#00349a;">'
                            . $e(number_format((float) ($doc['grand_total'] ?? 0), 2)) . '</td></tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div style="margin-top:24px;text-align:right;">' . $signatureHtml . '</div>
            ' . ($footerText !== '' ? '<div style="margin-top:18px;border-top:1px solid #ccc;padding-top:6px;font-size:10.5px;color:#555;text-align:center;">' . nl2br($e($footerText)) . '</div>' : '') . '
        </div>';

        if ($fullDocument) {
            return '<!DOCTYPE html><html><head><meta charset="utf-8">'
                . '<style>' . ($forPdf ? 'body{margin:0;}' : 'body{margin:0;background:#fff;}') . '</style>'
                . '</head><body>' . $html . '</body></html>';
        }

        return $html;
    }
}
