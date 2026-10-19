<?php
session_start();

if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit;
}

include("db/config.php");
include("abstract_helpers.php");

$query = "SELECT * FROM abstract_theme ORDER BY id DESC";
$result = mysqli_query($db, $query);

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
    <title>All Abstract Themes</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php echo abstract_swal_css(); ?>
</head>

<body>
    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
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
                                <h5 class="m-b-10">All Abstract Themes</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-header table-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Abstract Themes</h5>
                            <a href="add-abstract-theme.php" class="btn btn-secondary">
                                <i class="feather icon-plus"></i> Add New Theme
                            </a>
                        </div>
                        <div class="card-body">
                            <form id="deleteMForm" action="delete-abstract-theme.php" method="post">
                                <button type="button" id="deleteSelected" class="btn btn-danger">
                                    <i class="feather icon-trash"></i> Delete Selected
                                </button>

                                <div class="dt-responsive table-responsive mt-3">
                                    <?php
                                    if (isset($_GET['status'])) {
                                        $st1 = base64_decode($_GET['status']);
                                        if ($st1 > 0) {
                                            abstract_swal_flash('success', 'Success!', 'Abstract Theme has been updated successfully.');
                                        } else {
                                            abstract_swal_flash('error', 'Error!', 'Operation failed.');
                                        }
                                    }
                                    ?>


                                    <table id="basic-btn" class="table table-striped table-bordered nowrap">
                                        <thead>
                                            <tr>
                                                <th>SELECT</th>
                                                <th>SNO</th>
                                                <th>NAME</th>
                                                <th>STATUS</th>
                                                <th>EDIT</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $count = 1;
                                            while ($row = mysqli_fetch_assoc($result)) {
                                                $encoded_id = base64_encode($row['id']);
                                            ?>
                                                <tr class="record">
                                                    <td><input type="checkbox" name="theme_ids[]" value="<?php echo $encoded_id; ?>"></td>
                                                    <td><?php echo $count; ?></td>
                                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                    <td><?php echo ($row['status'] == 1) ? 'Enable' : 'Disable'; ?></td>
                                                    <td>
                                                        <a href="edit-abstract-theme.php?id=<?php echo $encoded_id; ?>" class="btn btn-warning">
                                                            <i class="feather icon-edit"></i> Edit
                                                        </a>
                                                        <a href="javascript:void(0)" class="btn btn-danger delete-btn" data-id="<?php echo $encoded_id; ?>">
                                                            <i class="feather icon-trash"></i> Delete
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php
                                                $count++;
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Delete</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this Abstract Theme?
                    <input type="hidden" id="deleteId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="confirmDelete">Delete</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete the selected Abstract Themes?
                </div>
                <div class="modal-footer">
                    <button type="button" id="confirmMultiDelete" class="btn btn-danger">Delete</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- No Selection Modal -->
    <div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">No Selection</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    Please select at least one theme to delete.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" data-dismiss="modal">OK</button>
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
    <script>
        $(document).ready(function() {
            $("#gold").delay(5000).slideUp(300);

            $(".delete-btn").on("click", function() {
                document.getElementById('deleteId').value = this.getAttribute('data-id');
                $("#deleteModal").modal('show');
            });

            $("#confirmDelete").on("click", function() {
                const id = document.getElementById('deleteId').value;
                if (id) {
                    window.location.href = 'delete-abstract-theme.php?id=' + encodeURIComponent(id);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'No ID found to delete.', confirmButtonColor: '#dc3545' });
                }
            });

            $("#deleteSelected").on("click", function() {
                var checkboxes = document.querySelectorAll("input[name='theme_ids[]']:checked");
                if (checkboxes.length === 0) {
                    $("#alertModal").modal('show');
                } else {
                    $("#deleteSelectedModal").modal('show');
                }
            });

            $("#confirmMultiDelete").on("click", function() {
                document.getElementById("deleteMForm").submit();
            });
        });
    </script>
    <?php echo abstract_swal_js(); abstract_swal_render(); ?>

</body>

</html>
