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

if (!abstract_can('Invoice History')) {
    abstract_swal_page('error', 'Access Denied', 'You do not have permission to view invoice history.', 'dashboard.php');
}

$flash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);

$search    = trim($_GET['search'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to'] ?? '');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = 15;
$offset    = ($page - 1) * $perPage;

$where  = [];
$params = [];
$types  = '';

if ($search !== '') {
    $where[] = "(i.document_number LIKE ? OR i.customer_name LIKE ? OR i.customer_email LIKE ? OR i.registration_id LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if (in_array($typeFilter, ['invoice', 'receipt'], true)) {
    $where[] = "i.document_type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "i.document_date >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = "i.document_date <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// Count
$countSql = "SELECT COUNT(*) AS c FROM invoices i" . $whereSql;
$stmt = $db->prepare($countSql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalRows = (int) $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Fetch page
$sql = "SELECT i.*, a.username AS created_by_name
        FROM invoices i
        LEFT JOIN admin a ON a._id = i.created_by" . $whereSql . "
        ORDER BY i.id DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$fetchParams = $params;
$fetchTypes  = $types . 'ii';
$fetchParams[] = $perPage;
$fetchParams[] = $offset;
$stmt->bind_param($fetchTypes, ...$fetchParams);
$stmt->execute();
$rows = $stmt->get_result();
$invoices = [];
while ($r = $rows->fetch_assoc()) {
    $invoices[] = $r;
}
$stmt->close();

$csrf = abstract_csrf_token();
$settings = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM login_settings"));
$favicon = $settings['favicon'] ?? '';

function invoice_query_string($overrides = [])
{
    $q = array_merge($_GET, $overrides);
    return http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Invoice History</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo htmlspecialchars($favicon); ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
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
                                <h5 class="m-b-10">Invoice History</h5>
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

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header">
                            <form method="get" action="" class="row">
                                <div class="col-md-4 mb-2">
                                    <input type="text" name="search" class="form-control" placeholder="Search number / customer / email / registration ID" value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <select name="type" class="form-control">
                                        <option value="">All Types</option>
                                        <option value="invoice" <?php echo $typeFilter === 'invoice' ? 'selected' : ''; ?>>Tax Invoice</option>
                                        <option value="receipt" <?php echo $typeFilter === 'receipt' ? 'selected' : ''; ?>>Payment Receipt</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-2">
                                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>" title="From date">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>" title="To date">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <button type="submit" class="btn btn-secondary"><i class="feather icon-search"></i> Filter</button>
                                    <a href="manage-invoices.php" class="btn btn-light">Reset</a>
                                </div>
                            </form>
                        </div>

                        <div class="card-body">
                            <div class="dt-responsive table-responsive">
                                <table class="table table-striped table-bordered nowrap">
                                    <thead>
                                        <tr>
                                            <th>Document No.</th>
                                            <th>Type</th>
                                            <th>Customer</th>
                                            <th>Event</th>
                                            <th>Registration ID</th>
                                            <th>Date</th>
                                            <th>Total</th>
                                            <th>Payment</th>
                                            <th>Status</th>
                                            <th>Created By</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$invoices): ?>
                                            <tr>
                                                <td colspan="11" class="text-center text-muted py-4">
                                                    No documents found. <?php echo ($search || $typeFilter || $dateFrom || $dateTo) ? 'Try adjusting your filters.' : 'Create your first invoice or receipt from <a href="create-invoice.php">Create Invoice / Receipt</a>.'; ?>
                                                </td>
                                            </tr>
                                        <?php else: foreach ($invoices as $inv):
                                            $pstatus = invoice_payment_status_label($inv['payment_status']);
                                            $istatus = invoice_status_label($inv['status']);
                                            $idEnc = base64_encode($inv['id']);
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($inv['document_number']); ?></strong></td>
                                                <td><?php echo htmlspecialchars(invoice_type_label($inv['document_type'])); ?></td>
                                                <td><?php echo htmlspecialchars($inv['customer_name']); ?><br><small><?php echo htmlspecialchars($inv['customer_email']); ?></small></td>
                                                <td><?php echo htmlspecialchars($inv['event_name'] ?: '-'); ?></td>
                                                <td><?php echo htmlspecialchars($inv['registration_id'] ?: '-'); ?></td>
                                                <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($inv['document_date']))); ?></td>
                                                <td><?php echo htmlspecialchars(number_format((float) $inv['grand_total'], 2)); ?></td>
                                                <td><span class="<?php echo $pstatus['class']; ?>"><?php echo htmlspecialchars($pstatus['label']); ?></span></td>
                                                <td><span class="<?php echo $istatus['class']; ?>"><?php echo htmlspecialchars($istatus['label']); ?></span></td>
                                                <td><?php echo htmlspecialchars($inv['created_by_name'] ?? 'Admin'); ?></td>
                                                <td>
                                                    <a href="view-invoice.php?id=<?php echo $idEnc; ?>" class="btn btn-primary btn-sm" title="View"><i class="feather icon-eye"></i></a>
                                                    <a href="invoice-pdf.php?id=<?php echo $idEnc; ?>" target="_blank" class="btn btn-info btn-sm" title="View PDF"><i class="feather icon-file-text"></i></a>
                                                    <a href="invoice-pdf.php?id=<?php echo $idEnc; ?>&download=1" class="btn btn-success btn-sm" title="Download PDF"><i class="feather icon-download"></i></a>
                                                    <?php if ($inv['status'] !== 'void' && ($isAdmin || abstract_can('Invoice History'))): ?>
                                                        <button type="button" class="btn btn-danger btn-sm" title="Void"
                                                                onclick="voidInvoice('<?php echo $idEnc; ?>', '<?php echo htmlspecialchars($inv['document_number']); ?>');">
                                                            <i class="feather icon-x-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <nav class="mt-3">
                                    <ul class="pagination">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo htmlspecialchars(invoice_query_string(['page' => $page - 1])); ?>">Previous</a>
                                        </li>
                                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo htmlspecialchars(invoice_query_string(['page' => $p])); ?>"><?php echo $p; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?<?php echo htmlspecialchars(invoice_query_string(['page' => $page + 1])); ?>">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <form method="post" action="void-invoice.php" id="voidForm">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="reason" value="">
    </form>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/plugins/pcoded.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <?php echo abstract_swal_js(); ?>
    <script>feather.replace();</script>
    <script>
        function voidInvoice(idEnc, number) {
            Swal.fire({
                icon: 'warning',
                title: 'Void ' + number + '?',
                text: 'Issued documents are never deleted. Voiding keeps an audit trail.',
                input: 'textarea',
                inputPlaceholder: 'Reason for voiding...',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Void',
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return 'A reason is required.';
                    }
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    var f = document.getElementById('voidForm');
                    f.elements['id'].value = idEnc;
                    f.elements['reason'].value = result.value.trim();
                    f.submit();
                }
            });
        }
    </script>
    <?php echo abstract_swal_render(); ?>
</body>

</html>
