<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $room_id = intval($_POST['room_id'] ?? 0);
    $bed_number = trim($_POST['bed_number'] ?? '');
    $status = $_POST['status'] ?? 'available';

    if ($_POST['action'] == 'add') {
        $stmt = $conn->prepare("INSERT INTO beds (room_id, bed_number, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("iss", $room_id, $bed_number, $status);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Bed added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add bed: " . $conn->error;
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE beds SET room_id=?, bed_number=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("issi", $room_id, $bed_number, $status, $id);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Bed updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update bed: " . $conn->error;
        }
    }
    header("Location: bed.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM beds WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Bed deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete bed.";
    }
    header("Location: bed.php");
    exit();
}

// Fetch all beds with Room, Floor, and Block details
$beds = $conn->query("
    SELECT b.*, 
           r.room_number, 
           r.room_type,
           f.name as floor_name, 
           blk.name as block_name 
    FROM beds b 
    LEFT JOIN rooms r ON b.room_id = r.id 
    LEFT JOIN floors f ON r.floor_id = f.id 
    LEFT JOIN blocks blk ON f.block_id = blk.id 
    ORDER BY b.id DESC
");

// Fetch rooms for dropdown
$rooms = $conn->query("
    SELECT r.id, r.room_number, r.room_type, f.name as floor_name, blk.name as block_name 
    FROM rooms r 
    LEFT JOIN floors f ON r.floor_id = f.id 
    LEFT JOIN blocks blk ON f.block_id = blk.id 
    ORDER BY r.room_number ASC
");
$room_options = "";
if($rooms && $rooms->num_rows > 0) {
    while($r = $rooms->fetch_assoc()) {
        $label = "Room " . htmlspecialchars($r['room_number']);
        if (!empty($r['floor_name']) || !empty($r['block_name'])) {
            $label .= " (";
            if (!empty($r['block_name'])) $label .= htmlspecialchars($r['block_name']) . " - ";
            $label .= htmlspecialchars($r['floor_name'] ?? '') . ")";
        }
        if (!empty($r['room_type'])) {
            $label .= " - " . ucfirst(htmlspecialchars($r['room_type']));
        }
        $room_options .= "<option value='".$r['id']."'>".$label."</option>";
    }
}

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Bed Management</h4>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">Add New Bed</button>
                </div>
                
                <?php if(isset($_SESSION['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['msg']) ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['msg']); endif; ?>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['error']) ?>
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
                                <th>Block & Floor</th>
                                <th>Room Number</th>
                                <th>Room Type</th>
                                <th>Bed Number</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $beds->fetch_assoc()): ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <?php 
                                        echo htmlspecialchars($row['floor_name'] ?? 'N/A');
                                        if(!empty($row['block_name'])) echo " (" . htmlspecialchars($row['block_name']) . ")";
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($row['room_number'] ?? 'N/A') ?></td>
                                <td><?= !empty($row['room_type']) ? htmlspecialchars(ucfirst($row['room_type'])) : 'N/A' ?></td>
                                <td><span class="font-weight-bold"><?= htmlspecialchars($row['bed_number']) ?></span></td>
                                <td>
                                    <?php if($row['status'] == 'available'): ?>
                                        <label class="badge badge-success">Available</label>
                                    <?php elseif($row['status'] == 'occupied'): ?>
                                        <label class="badge badge-warning">Occupied</label>
                                    <?php elseif($row['status'] == 'maintenance'): ?>
                                        <label class="badge badge-danger">Maintenance</label>
                                    <?php else: ?>
                                        <label class="badge badge-secondary"><?= htmlspecialchars(ucfirst($row['status'])) ?></label>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm edit-btn" title="Edit" 
                                        data-id="<?= $row['id'] ?>"
                                        data-room_id="<?= $row['room_id'] ?>"
                                        data-bed_number="<?= htmlspecialchars($row['bed_number']) ?>"
                                        data-status="<?= $row['status'] ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>
                                    <a href="javascript:void(0);" data-url="bed.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="ti-trash"></i></a>
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
      <form method="POST" action="" id="addBedForm">
          <div class="modal-header">
            <h5 class="modal-title">Add Bed</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Room <span class="text-danger">*</span></label>
                <select name="room_id" id="add_room_id" class="form-control">
                    <option value="">Select Room</option>
                    <?= $room_options ?>
                </select>
                <small class="text-danger" id="add_room_err"></small>
            </div>
            <div class="form-group">
                <label>Bed Number / Identifier <span class="text-danger">*</span></label>
                <input type="text" name="bed_number" id="add_bed_number" class="form-control" placeholder="e.g. Bed 1 / B-101-A">
                <small class="text-danger" id="add_bed_number_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="add_status" class="form-control">
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Bed</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="editBedForm">
          <div class="modal-header">
            <h5 class="modal-title">Edit Bed</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Room <span class="text-danger">*</span></label>
                <select name="room_id" id="edit_room_id" class="form-control">
                    <option value="">Select Room</option>
                    <?= $room_options ?>
                </select>
                <small class="text-danger" id="edit_room_err"></small>
            </div>
            <div class="form-group">
                <label>Bed Number / Identifier <span class="text-danger">*</span></label>
                <input type="text" name="bed_number" id="edit_bed_number" class="form-control">
                <small class="text-danger" id="edit_bed_number_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="available">Available</option>
                    <option value="occupied">Occupied</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Bed</button>
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
        var room_id = $(this).data('room_id');
        var bed_number = $(this).data('bed_number');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_room_id').val(room_id);
        $('#edit_bed_number').val(bed_number);
        $('#edit_status').val(status);
        
        // Clear previous errors
        $('#edit_room_err').text('');
        $('#edit_bed_number_err').text('');
    });

    // Clear add form errors on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_room_err').text('');
        $('#add_bed_number_err').text('');
        $('#addBedForm')[0].reset();
    });

    // Validation for Add Form
    $('#addBedForm').on('submit', function(e) {
        let valid = true;
        let room_id = $('#add_room_id').val().trim();
        let bed_number = $('#add_bed_number').val().trim();

        if (room_id === '') {
            $('#add_room_err').text('Room selection is mandatory');
            valid = false;
        } else {
            $('#add_room_err').text('');
        }

        if (bed_number === '') {
            $('#add_bed_number_err').text('Bed Number is mandatory');
            valid = false;
        } else {
            $('#add_bed_number_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Validation for Edit Form
    $('#editBedForm').on('submit', function(e) {
        let valid = true;
        let room_id = $('#edit_room_id').val().trim();
        let bed_number = $('#edit_bed_number').val().trim();

        if (room_id === '') {
            $('#edit_room_err').text('Room selection is mandatory');
            valid = false;
        } else {
            $('#edit_room_err').text('');
        }

        if (bed_number === '') {
            $('#edit_bed_number_err').text('Bed Number is mandatory');
            valid = false;
        } else {
            $('#edit_bed_number_err').text('');
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