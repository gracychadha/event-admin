'use strict';
/* Invoice / Receipt create form: dynamic line items + live totals.
   Client-side figures are for preview only; the server recalculates. */
(function ($) {
    var rowTemplate = function (index) {
        return '<tr class="item-row">' +
            '<td><input type="text" name="items[' + index + '][description]" class="form-control item-desc" required></td>' +
            '<td><input type="text" name="items[' + index + '][hsn_sac]" class="form-control"></td>' +
            '<td><input type="number" step="0.01" min="0.01" name="items[' + index + '][quantity]" class="form-control item-qty" value="1" required></td>' +
            '<td><input type="number" step="0.01" min="0" name="items[' + index + '][rate]" class="form-control item-rate" value="0" required></td>' +
            '<td><input type="text" class="form-control item-amount" value="0.00" readonly></td>' +
            '<td class="text-center"><button type="button" class="btn btn-sm btn-danger remove-row"><i class="feather icon-trash-2"></i></button></td>' +
            '</tr>';
    };

    function reindexRows() {
        $('#itemsBody .item-row').each(function (i) {
            $(this).find('input, select').each(function () {
                var name = $(this).attr('name');
                if (name) {
                    $(this).attr('name', name.replace(/items\[\d+\]/, 'items[' + i + ']'));
                }
            });
        });
    }

    function num(val) {
        var n = parseFloat(val);
        return isNaN(n) ? 0 : n;
    }

    function money(n) {
        return (Math.round(n * 100) / 100).toFixed(2);
    }

    function recalc() {
        var subtotal = 0;
        $('#itemsBody .item-row').each(function () {
            var $row = $(this);
            var qty = num($row.find('.item-qty').val());
            var rate = num($row.find('.item-rate').val());
            var amount = qty * rate;
            if (amount < 0) { amount = 0; }
            $row.find('.item-amount').val(money(amount));
            subtotal += amount;
        });
        subtotal = Math.round(subtotal * 100) / 100;

        var discountPct = Math.min(100, Math.max(0, num($('#discountPercent').val())));
        var discountAmount = Math.round(subtotal * (discountPct / 100) * 100) / 100;
        var taxable = Math.round((subtotal - discountAmount) * 100) / 100;
        if (taxable < 0) { taxable = 0; }

        var taxEnabled = $('#taxIncluded').is(':checked');
        var taxRate = Math.min(100, Math.max(0, num($('#taxRate').val())));
        var tax = taxEnabled ? Math.round(taxable * (taxRate / 100) * 100) / 100 : 0;
        var grand = Math.round((taxable + tax) * 100) / 100;

        $('#sumSubtotal').text(money(subtotal));
        $('#sumDiscount').text(money(discountAmount));
        $('#sumDiscountLabel').text(($('#discountLabel').val() || 'Discount') + (discountPct ? ' (' + money(discountPct) + '%)' : ''));
        $('#sumTaxable').text(money(taxable));
        $('#sumTax').text(money(tax));
        $('#sumGrand').text(money(grand));
    }

    function updateTaxMeta() {
        var $opt = $('#companyProfile option:selected');
        var label = $opt.data('tax-label') || 'GST';
        $('#taxLabelText').text(label);
        $('#sumTaxLabel').text(label);
    }

    $(document).ready(function () {
        var rowIndex = $('#itemsBody .item-row').length;

        $('#addRow').on('click', function () {
            $('#itemsBody').append(rowTemplate(rowIndex++));
            feather.replace();
            recalc();
        });

        $('#itemsBody').on('click', '.remove-row', function () {
            if ($('#itemsBody .item-row').length <= 1) {
                Swal.fire({ icon: 'warning', title: 'At least one line item is required.', confirmButtonColor: '#ffc107' });
                return;
            }
            $(this).closest('.item-row').remove();
            reindexRows();
            recalc();
        });

        $(document).on('input change', '.item-qty, .item-rate, #discountPercent, #discountLabel, #taxRate, #taxIncluded', recalc);

        $('#companyProfile').on('change', function () {
            var $opt = $(this).find('option:selected');
            var rate = $opt.data('tax-rate');
            if (rate !== undefined && rate !== null && rate !== '') {
                $('#taxRate').val(rate);
            }
            updateTaxMeta();
            recalc();
        });

        $('#taxIncluded').on('change', function () {
            $('#taxRate').prop('disabled', !$(this).is(':checked'));
            recalc();
        });

        $('#previewBtn').on('click', function () {
            var form = document.getElementById('invoiceForm');
            form.setAttribute('action', 'preview-invoice.php');
            form.setAttribute('target', '_blank');
            form.submit();
            // restore for the next action
            form.setAttribute('action', 'generate-invoice.php');
            form.setAttribute('target', '_self');
        });

        $('#invoiceForm').on('submit', function () {
            var btn = document.getElementById('generateBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="feather icon-loader"></i> Generating...';
            }
        });

        updateTaxMeta();
        recalc();
    });
})(jQuery);
