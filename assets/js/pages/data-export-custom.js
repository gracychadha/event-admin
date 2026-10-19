$(document).ready(function() {
    setTimeout(function() {

        // [ HTML5 Export Buttons ]
     $('#basic-btn').DataTable({
            dom: 'Bfrtip',
            buttons: [
                { extend: 'excelHtml5', text: '<i class="feather icon-file-text"></i> Export Excel', className: 'btn btn-secondary btn-sm', titleAttr: 'Export to Excel', exportOptions: { columns: ':not(:last)' } },
                { extend: 'pdfHtml5', text: '<i class="feather icon-file"></i> Export PDF', className: 'btn btn-secondary btn-sm', titleAttr: 'Export to PDF', orientation: 'landscape', pageSize: 'A4', exportOptions: { columns: ':not(:last)' } },
                { extend: 'copyHtml5', text: 'Copy', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } },
                { extend: 'csvHtml5', text: 'CSV', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } },
                { extend: 'print', text: 'Print', className: 'btn btn-secondary btn-sm', exportOptions: { columns: ':not(:last)' } }
            ]
        });
		
	

        // [ Column Selectors ]
        $('#cbtn-selectors').DataTable({
            dom: 'Bfrtip',
            buttons: [{
                extend: 'copyHtml5',
                exportOptions: {
                    columns: [0, ':visible']
                }
            }, {
                extend: 'excelHtml5',
                exportOptions: {
                    columns: ':visible'
                }
            }, {
                extend: 'pdfHtml5',
                exportOptions: {
                    columns: [0, 1, 2, 5]
                }
            }, 'colvis']
        });

        // [ Excel - Cell Background ]
        $('#excel-bg').DataTable({
            dom: 'Bfrtip',
            buttons: [{
                extend: 'excelHtml5',
                customize: function(xlsx) {
                    var sheet = xlsx.xl.worksheets['sheet1.xml'];
                    $('row c[r^="F"]', sheet).each(function() {
                        if ($('is t', this).text().replace(/[^\d]/g, '') * 1 >= 500000) {
                            $(this).attr('s', '20');
                        }
                    });
                }
            }]
        });

        // [ Custom File (JSON) ] 
        $('#pdf-json').DataTable({
            dom: 'Bfrtip',
            buttons: [{
                text: 'JSON',
                action: function(e, dt, button, config) {
                    var data = dt.buttons.exportData();
                    $.fn.dataTable.fileSave(new Blob([JSON.stringify(data)]), 'Export.json');
                }
            }]
        });

    }, 350);
});
