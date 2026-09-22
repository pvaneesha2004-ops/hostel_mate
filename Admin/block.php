<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $name = $_POST['name'];
    $code = $_POST['code'];
    $total_capacity = $_POST['total_capacity'];
    $status = $_POST['status'];

    if ($_POST['action'] == 'add') {
        $stmt = $conn->prepare("INSERT INTO blocks (name, code, total_capacity, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("ssis", $name, $code, $total_capacity, $status);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Block added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add block.";
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = $_POST['id'];
        $stmt = $conn->prepare("UPDATE blocks SET name=?, code=?, total_capacity=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ssisi", $name, $code, $total_capacity, $status, $id);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Block updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update block.";
        }
    }
    header("Location: block.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM blocks WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Block deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete block.";
    }
    header("Location: block.php");
    exit();
}

// Fetch all blocks
$blocks = $conn->query("SELECT * FROM blocks ORDER BY id DESC");

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Block Management</h4>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">Add New Block</button>
                </div>
                
                <?php if(isset($_SESSION['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $_SESSION['msg'] ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['msg']); endif; ?>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $_SESSION['error'] ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>SlNo</th>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $blocks->fetch_assoc()): ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['code']) ?></td>
                                <td><?= $row['total_capacity'] ?></td>
                                <td>
                                    <?php if($row['status'] == 'active' || $row['status'] == '1'): ?>
                                        <label class="badge badge-success">Active</label>
                                    <?php else: ?>
                                        <label class="badge badge-danger">Inactive</label>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm edit-btn" title="Edit" 
                                        data-id="<?= $row['id'] ?>"
                                        data-name="<?= htmlspecialchars($row['name']) ?>"
                                        data-code="<?= htmlspecialchars($row['code']) ?>"
                                        data-capacity="<?= $row['total_capacity'] ?>"
                                        data-status="<?= $row['status'] ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>
                                    <a href="javascript:void(0);" data-url="block.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="ti-trash"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="addBlockForm">
          <div class="modal-header">
            <h5 class="modal-title">Add Block</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Block Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. Block A">
                <small class="text-danger" id="add_name_err"></small>
            </div>
            <div class="form-group">
                <label>Block Code <span class="text-danger">*</span></label>
                <input type="text" name="code" id="add_code" class="form-control" placeholder="e.g. BLK-A">
                <small class="text-danger" id="add_code_err"></small>
            </div>
            <div class="form-group">
                <label>Total Capacity <span class="text-danger">*</span></label>
                <input type="number" name="total_capacity" id="add_capacity" class="form-control" placeholder="e.g. 100">
                <small class="text-danger" id="add_capacity_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Block</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="editBlockForm">
          <div class="modal-header">
            <h5 class="modal-title">Edit Block</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Block Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="edit_name" class="form-control">
                <small class="text-danger" id="edit_name_err"></small>
            </div>
            <div class="form-group">
                <label>Block Code <span class="text-danger">*</span></label>
                <input type="text" name="code" id="edit_code" class="form-control">
                <small class="text-danger" id="edit_code_err"></small>
            </div>
            <div class="form-group">
                <label>Total Capacity <span class="text-danger">*</span></label>
                <input type="number" name="total_capacity" id="edit_capacity" class="form-control">
                <small class="text-danger" id="edit_capacity_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Block</button>
          </div>
      </form>
    </div>
  </div>
</div>

<style>
    .custom-swal-popup {
        padding: 35px 30px !important;
        border-radius: 12px !important;
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [[ 0, "asc" ]]
        });
    }
    
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var code = $(this).data('code');
        var capacity = $(this).data('capacity');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_code').val(code);
        $('#edit_capacity').val(capacity);
        $('#edit_status').val(status);
        
        // Clear previous errors
        $('#edit_name_err').text('');
        $('#edit_code_err').text('');
        $('#edit_capacity_err').text('');
    });

    // Clear add form errors on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_name_err').text('');
        $('#add_code_err').text('');
        $('#add_capacity_err').text('');
        $('#addBlockForm')[0].reset();
    });

    // Validation for Add Form
    $('#addBlockForm').on('submit', function(e) {
        let valid = true;
        let name = $('#add_name').val().trim();
        let code = $('#add_code').val().trim();
        let capacity = $('#add_capacity').val().trim();

        if (name === '') {
            $('#add_name_err').text('Block Name is mandatory');
            valid = false;
        } else {
            $('#add_name_err').text('');
        }

        if (code === '') {
            $('#add_code_err').text('Block Code is mandatory');
            valid = false;
        } else {
            $('#add_code_err').text('');
        }

        if (capacity === '') {
            $('#add_capacity_err').text('Total Capacity is mandatory');
            valid = false;
        } else {
            $('#add_capacity_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Validation for Edit Form
    $('#editBlockForm').on('submit', function(e) {
        let valid = true;
        let name = $('#edit_name').val().trim();
        let code = $('#edit_code').val().trim();
        let capacity = $('#edit_capacity').val().trim();

        if (name === '') {
            $('#edit_name_err').text('Block Name is mandatory');
            valid = false;
        } else {
            $('#edit_name_err').text('');
        }

        if (code === '') {
            $('#edit_code_err').text('Block Code is mandatory');
            valid = false;
        } else {
            $('#edit_code_err').text('');
        }

        if (capacity === '') {
            $('#edit_capacity_err').text('Total Capacity is mandatory');
            valid = false;
        } else {
            $('#edit_capacity_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Delete Confirmation with SweetAlert
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');

        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            customClass: {
                popup: 'custom-swal-popup'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>