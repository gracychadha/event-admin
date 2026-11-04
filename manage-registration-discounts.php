<?php
session_start();
error_reporting(E_ALL);

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
}
$name = $_SESSION['login_user'];

include("db/config.php");
include("registration_pricing_helpers.php");

// Activate / deactivate toggle
if (isset($_GET['toggle_id'])) {
    $toggleId = base64_decode($_GET['toggle_id']);
    if (is_numeric($toggleId)) {
        $stmt = mysqli_prepare($db, "UPDATE registration_discounts SET status = 1 - status WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $toggleId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $_SESSION['msg'] = "<div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;'>
            <strong><i class='feather icon-check'></i> Updated!</strong> Discount status changed successfully.
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
    }
    header("Location: manage-registration-discounts.php");
    exit();
}

// List discounts with mapped category names
$stmt = mysqli_prepare($db, "SELECT d.*,
    (SELECT GROUP_CONCAT(c.category_name SEPARATOR ', ')
       FROM registration_discount_categories dc
       INNER JOIN registration_categories c ON c.id = dc.category_id
       WHERE dc.discount_id = d.id) AS category_names
    FROM registration_discounts d
    ORDER BY d.start_date DESC, d.id DESC");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'];
$helpdeskNumber = $settings['helpdesk_no'];
$favicon = $settings['favicon'];
?>

<!DOCTYPE html>
<html lang="en">

<meta http-equiv="content-type" content="text/html;charset=UTF-8" />

<head>
    <title>Special Discounts</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="">

    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <!-- Header -->
    <?php include("header.php"); ?>
    <!-- /Header -->

    <!-- navbar -->
    <?php include("navbar.php"); ?>
    <!-- /navbar -->

    <section class="pcoded-main-container">
        <div class="pcoded-content">

            <div class="page-header">
                <div class="page-block">
                    <div class="row align-items-center">
                        <div class="col-md-12">
                            <div class="page-header-title">
                                <h5 class="m-b-10">Special Discounts</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header">
                            <a href="add-registration-discount.php" class="btn btn-primary">
                                <i class="feather icon-plus"></i> Add Special Discount
                            </a>
                        </div>
                        <div class="card-body">

                            <form id="deleteMForm" action="delete-registration-discount.php" method="post">
                                <button type="button" id="deleteSelected" class="btn btn-danger">
                                    <i class='feather icon-trash'></i> Delete Selected
                                </button>
                                <div class="dt-responsive table-responsive mt-4">
                                    <?php
                                    if (isset($_SESSION['msg'])) {
                                        echo $_SESSION['msg'];
                                        unset($_SESSION['msg']);
                                    }
                                    ?>
                                    <br />

                                    <?php
                                    echo '<table id="basic-btn" class="table table-striped table-bordered nowrap">';
                                    echo "<thead>";
                                    echo "<tr>";
                                    echo "<th>SELECT</th>";
                                    echo "<th>SNO</th>";
                                    echo "<th>DISCOUNT NAME</th>";
                                    echo "<th>APPLICABLE CATEGORIES</th>";
                                    echo "<th>PERIOD</th>";
                                    echo "<th>TYPE / VALUE</th>";
                                    echo "<th>STATUS</th>";
                                    echo "<th>ACTIONS</th>";
                                    echo "</tr>";
                                    echo "</thead>";
                                    echo "<tbody>";

                                    $count = 1;
                                    while ($row = mysqli_fetch_assoc($result)) {
                                        $encoded_id = base64_encode($row['id']);
                                        echo "<tr class='record'>";

                                        echo "<td><input type='checkbox' name='discount_ids[]' value='$encoded_id'></td>";
                                        echo "<td>" . $count++ . "</td>";
                                        echo "<td>" . htmlspecialchars($row['discount_name']) . "</td>";
                                        echo "<td>" . ($row['category_names'] ? htmlspecialchars($row['category_names']) : '<span class="text-muted">None</span>') . "</td>";
                                        echo "<td>" . date('d M Y', strtotime($row['start_date'])) . " - " . date('d M Y', strtotime($row['end_date'])) . "</td>";
                                        echo "<td>" . ($row['discount_type'] === 'percentage'
                                            ? rtrim(rtrim($row['discount_value'], '0'), '.') . '%'
                                            : rp_money($row['discount_value'])) . "</td>";

                                        echo "<td>";
                                        if ($row['status'] == 1) {
                                            echo "<a href='manage-registration-discounts.php?toggle_id=$encoded_id' class='badge badge-success p-1'>Active</a>";
                                        } else {
                                            echo "<a href='manage-registration-discounts.php?toggle_id=$encoded_id' class='badge badge-danger p-1'>Inactive</a>";
                                        }
                                        echo "</td>";

                                        echo "<td>
                                            <a href='edit-registration-discount.php?id=$encoded_id' class='btn btn-warning btn-sm'>
                                                <i class='feather icon-edit'></i> Edit
                                            </a>
                                            <a href='javascript:void(0)' class='btn btn-danger btn-sm delete-btn' data-id='$encoded_id' data-bs-toggle='modal' data-bs-target='#deleteModal'>
                                                <i class='feather icon-trash'></i> Delete
                                            </a>
                                        </td>";

                                        echo "</tr>";
                                    }
                                    echo "</tbody>";
                                    echo "</table>";
                                    ?>

                                </div>
                                <br />
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete Special Discount</h5>
                    <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this Special Discount? Historical registrations keep their stored discount name and amount.
                    <input type="hidden" id="deleteId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Modal -->
    <div class="modal fade" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="alertModalLabel">No Selection</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">X</button>
                </div>
                <div class="modal-body">
                    Please select at least one Special Discount to delete.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Multi Delete Confirmation Modal -->
    <div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal">X</button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete the selected special discounts?
                </div>
                <div class="modal-footer">
                    <button type="button" id="confirmMultiDelete" class="btn btn-danger">Delete</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <script src="assets/js/plugins/jquery.dataTables.min.js"></script>
    <script src="assets/js/plugins/dataTables.bootstrap4.min.js"></script>
    <script src="assets/js/plugins/buttons.colVis.min.js"></script>
    <script src="assets/js/plugins/buttons.print.min.js"></script>
    <script src="assets/js/plugins/pdfmake.min.js"></script>
    <script src="assets/js/plugins/vfs_fonts.js"></script>
    <script src="assets/js/plugins/jszip.min.js"></script>
    <script src="assets/js/plugins/dataTables.buttons.min.js"></script>
    <script src="assets/js/plugins/buttons.html5.min.js"></script>
    <script src="assets/js/plugins/buttons.bootstrap4.min.js"></script>
    <script src="assets/js/pages/data-export-custom.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $("#gold").delay(5000).slideUp(300);
        });

        document.querySelectorAll('.delete-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                document.getElementById('deleteId').value = id;
            });
        });

        document.getElementById('confirmDelete').addEventListener('click', function() {
            const id = document.getElementById('deleteId').value;
            if (id) {
                window.location.href = 'delete-registration-discount.php?id=' + encodeURIComponent(id);
            } else {
                alert('No ID found to delete.');
            }
        });

        document.getElementById("deleteSelected").addEventListener("click", function() {
            var form = document.getElementById("deleteMForm");
            var checkboxes = form.querySelectorAll("input[name='discount_ids[]']:checked");

            if (checkboxes.length === 0) {
                var alertModal = new bootstrap.Modal(document.getElementById('alertModal'));
                alertModal.show();
            } else {
                var deleteSelectedModal = new bootstrap.Modal(document.getElementById('deleteSelectedModal'));
                deleteSelectedModal.show();
            }
        });

        document.getElementById("confirmMultiDelete").addEventListener("click", function() {
            document.getElementById("deleteMForm").submit();
        });
    </script>

</body>

</html>
