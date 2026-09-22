<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $block_id = $_POST['block_id'];
    $floor_number = $_POST['floor_number'];
    $name = $_POST['name'];

    if ($_POST['action'] == 'add') {
        $stmt = $conn->prepare("INSERT INTO floors (block_id, floor_number, name, created, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("iss", $block_id, $floor_number, $name);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Floor added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add floor.";
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = $_POST['id'];
        $stmt = $conn->prepare("UPDATE floors SET block_id=?, floor_number=?, name=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("issi", $block_id, $floor_number, $name, $id);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Floor updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update floor.";
        }
    }
    header("Location: floor.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM floors WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Floor deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete floor.";
    }
    header("Location: floor.php");
    exit();
}

// Fetch all floors
$floors = $conn->query("SELECT f.*, b.name as block_name FROM floors f LEFT JOIN blocks b ON f.block_id = b.id ORDER BY f.id DESC");

// Fetch active blocks for dropdown
$blocks = $conn->query("SELECT id, name FROM blocks WHERE status='active' OR status='1'");
$block_options = "";
if($blocks && $blocks->num_rows > 0) {
    while($b = $blocks->fetch_assoc()) {
        $block_options .= "<option value='".$b['id']."'>".htmlspecialchars($b['name'])."</option>";
    }
}

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Floor Management</h4>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">Add New Floor</button>
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
                                <th>Block Name</th>
                                <th>Floor Number</th>
                                <th>Floor Name</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $floors->fetch_assoc()): ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td><?= htmlspecialchars($row['block_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['floor_number']) ?></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td>
                                    <button class="btn btn-primary btn-sm edit-btn" title="Edit" 
                                        data-id="<?= $row['id'] ?>"
                                        data-block_id="<?= $row['block_id'] ?>"
                                        data-floor_number="<?= htmlspecialchars($row['floor_number']) ?>"
                                        data-name="<?= htmlspecialchars($row['name']) ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>
                                    <a href="javascript:void(0);" data-url="floor.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="ti-trash"></i></a>
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
      <form method="POST" action="" id="addFloorForm">
          <div class="modal-header">
            <h5 class="modal-title">Add Floor</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Block <span class="text-danger">*</span></label>
                <select name="block_id" id="add_block_id" class="form-control">
                    <option value="">Select Block</option>
                    <?= $block_options ?>
                </select>
                <small class="text-danger" id="add_block_err"></small>
            </div>
            <div class="form-group">
                <label>Floor Number <span class="text-danger">*</span></label>
                <input type="text" name="floor_number" id="add_floor_number" class="form-control" placeholder="e.g. 1">
                <small class="text-danger" id="add_floor_number_err"></small>
            </div>
            <div class="form-group">
                <label>Floor Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. First Floor">
                <small class="text-danger" id="add_name_err"></small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Floor</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="editFloorForm">
          <div class="modal-header">
            <h5 class="modal-title">Edit Floor</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Block <span class="text-danger">*</span></label>
                <select name="block_id" id="edit_block_id" class="form-control">
                    <option value="">Select Block</option>
                    <?= $block_options ?>
                </select>
                <small class="text-danger" id="edit_block_err"></small>
            </div>
            <div class="form-group">
                <label>Floor Number <span class="text-danger">*</span></label>
                <input type="text" name="floor_number" id="edit_floor_number" class="form-control">
                <small class="text-danger" id="edit_floor_number_err"></small>
            </div>
            <div class="form-group">
                <label>Floor Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="edit_name" class="form-control">
                <small class="text-danger" id="edit_name_err"></small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Floor</button>
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
        var block_id = $(this).data('block_id');
        var floor_number = $(this).data('floor_number');
        var name = $(this).data('name');
        
        $('#edit_id').val(id);
        $('#edit_block_id').val(block_id);
        $('#edit_floor_number').val(floor_number);
        $('#edit_name').val(name);
        
        // Clear previous errors
        $('#edit_block_err').text('');
        $('#edit_floor_number_err').text('');
        $('#edit_name_err').text('');
    });

    // Clear add form errors on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_block_err').text('');
        $('#add_floor_number_err').text('');
        $('#add_name_err').text('');
        $('#addFloorForm')[0].reset();
    });

    // Validation for Add Form
    $('#addFloorForm').on('submit', function(e) {
        let valid = true;
        let block_id = $('#add_block_id').val().trim();
        let floor_number = $('#add_floor_number').val().trim();
        let name = $('#add_name').val().trim();

        if (block_id === '') {
            $('#add_block_err').text('Block selection is mandatory');
            valid = false;
        } else {
            $('#add_block_err').text('');
        }

        if (floor_number === '') {
            $('#add_floor_number_err').text('Floor Number is mandatory');
            valid = false;
        } else {
            $('#add_floor_number_err').text('');
        }

        if (name === '') {
            $('#add_name_err').text('Floor Name is mandatory');
            valid = false;
        } else {
            $('#add_name_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Validation for Edit Form
    $('#editFloorForm').on('submit', function(e) {
        let valid = true;
        let block_id = $('#edit_block_id').val().trim();
        let floor_number = $('#edit_floor_number').val().trim();
        let name = $('#edit_name').val().trim();

        if (block_id === '') {
            $('#edit_block_err').text('Block selection is mandatory');
            valid = false;
        } else {
            $('#edit_block_err').text('');
        }

        if (floor_number === '') {
            $('#edit_floor_number_err').text('Floor Number is mandatory');
            valid = false;
        } else {
            $('#edit_floor_number_err').text('');
        }

        if (name === '') {
            $('#edit_name_err').text('Floor Name is mandatory');
            valid = false;
        } else {
            $('#edit_name_err').text('');
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