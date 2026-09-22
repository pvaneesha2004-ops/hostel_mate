<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Directory for room images
$target_dir = "../room_images/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $floor_id = $_POST['floor_id'];
    $room_number = $_POST['room_number'];
    $room_type = $_POST['room_type'];
    $capacity = $_POST['capacity'];
    $price = $_POST['price'];
    $status = $_POST['status'];

    // Handle Image Upload
    $room_image = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $allowed_ext)) {
            $new_filename = 'room_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $new_filename)) {
                $room_image = $new_filename;
            }
        }
    }

    if ($_POST['action'] == 'add') {
        if (empty($room_image)) {
            $_SESSION['error'] = "Room image is required.";
            header("Location: room.php");
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO rooms (floor_id, room_number, room_type, capacity, price, image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("issidss", $floor_id, $room_number, $room_type, $capacity, $price, $room_image, $status);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Room added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add room: " . $conn->error;
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = intval($_POST['id']);

        // Fetch current room image
        $current_res = $conn->query("SELECT image FROM rooms WHERE id=$id");
        $current_data = $current_res ? $current_res->fetch_assoc() : [];
        $old_image = $current_data['image'] ?? '';

        if (empty($room_image)) {
            $room_image = $old_image;
        } else {
            if (!empty($old_image) && file_exists($target_dir . $old_image)) {
                @unlink($target_dir . $old_image);
            }
        }

        if (empty($room_image)) {
            $_SESSION['error'] = "Room image is required.";
            header("Location: room.php");
            exit();
        }

        $stmt = $conn->prepare("UPDATE rooms SET floor_id=?, room_number=?, room_type=?, capacity=?, price=?, image=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("issidssi", $floor_id, $room_number, $room_type, $capacity, $price, $room_image, $status, $id);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Room updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update room: " . $conn->error;
        }
    }
    header("Location: room.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Delete room image file if exists
    $img_res = $conn->query("SELECT image FROM rooms WHERE id=$id");
    if ($img_res && $img_row = $img_res->fetch_assoc()) {
        if (!empty($img_row['image']) && file_exists($target_dir . $img_row['image'])) {
            @unlink($target_dir . $img_row['image']);
        }
    }

    $stmt = $conn->prepare("DELETE FROM rooms WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Room deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete room.";
    }
    header("Location: room.php");
    exit();
}

// Fetch all rooms
$rooms = $conn->query("SELECT r.*, f.name as floor_name, b.name as block_name FROM rooms r LEFT JOIN floors f ON r.floor_id = f.id LEFT JOIN blocks b ON f.block_id = b.id ORDER BY r.id DESC");

// Fetch floors for dropdown
$floors = $conn->query("SELECT f.id, f.name as floor_name, b.name as block_name FROM floors f LEFT JOIN blocks b ON f.block_id = b.id");
$floor_options = "";
if($floors && $floors->num_rows > 0) {
    while($f = $floors->fetch_assoc()) {
        $displayName = htmlspecialchars($f['floor_name']);
        if (!empty($f['block_name'])) {
            $displayName .= " (" . htmlspecialchars($f['block_name']) . ")";
        }
        $floor_options .= "<option value='".$f['id']."'>".$displayName."</option>";
    }
}

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Room Management</h4>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">Add New Room</button>
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
                                <th>Image</th>
                                <th>Block & Floor</th>
                                <th>Room Number</th>
                                <th>Type</th>
                                <th>Capacity</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $rooms->fetch_assoc()): ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <?php if (!empty($row['image']) && file_exists('../room_images/' . $row['image'])): ?>
                                        <img src="../room_images/<?= htmlspecialchars($row['image']) ?>" alt="Room Image" style="width: 48px; height: 48px; border-radius: 6px; object-fit: cover; border: 1px solid #ddd;">
                                    <?php else: ?>
                                        <div style="width: 48px; height: 48px; border-radius: 6px; background: #f0f0f0; color: #888; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #ddd;">
                                            <i class="ti-image" style="font-size: 18px;"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        echo htmlspecialchars($row['floor_name'] ?? 'N/A');
                                        if(!empty($row['block_name'])) echo " (" . htmlspecialchars($row['block_name']) . ")";
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($row['room_number']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($row['room_type'])) ?></td>
                                <td><?= $row['capacity'] ?></td>
                                <td><?= isset($row['price']) && $row['price'] !== '' ? '₹' . number_format((float)$row['price'], 2) : '₹0.00' ?></td>
                                <td>
                                    <?php if($row['status'] == 'available'): ?>
                                        <label class="badge badge-success">Available</label>
                                    <?php elseif($row['status'] == 'full'): ?>
                                        <label class="badge badge-warning">Full</label>
                                    <?php elseif($row['status'] == 'maintenance'): ?>
                                        <label class="badge badge-info">Maintenance</label>
                                    <?php else: ?>
                                        <label class="badge badge-danger">Inactive</label>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm edit-btn" title="Edit" 
                                        data-id="<?= $row['id'] ?>"
                                        data-floor_id="<?= $row['floor_id'] ?>"
                                        data-room_number="<?= htmlspecialchars($row['room_number']) ?>"
                                        data-room_type="<?= htmlspecialchars($row['room_type']) ?>"
                                        data-capacity="<?= $row['capacity'] ?>"
                                        data-price="<?= htmlspecialchars($row['price'] ?? '0') ?>"
                                        data-image="<?= htmlspecialchars($row['image'] ?? '') ?>"
                                        data-status="<?= $row['status'] ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>
                                    <a href="javascript:void(0);" data-url="room.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="ti-trash"></i></a>
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
      <form method="POST" action="" id="addRoomForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Add Room</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Floor <span class="text-danger">*</span></label>
                <select name="floor_id" id="add_floor_id" class="form-control">
                    <option value="">Select Floor</option>
                    <?= $floor_options ?>
                </select>
                <small class="text-danger" id="add_floor_err"></small>
            </div>
            <div class="form-group">
                <label>Room Number <span class="text-danger">*</span></label>
                <input type="text" name="room_number" id="add_room_number" class="form-control" placeholder="e.g. 101">
                <small class="text-danger" id="add_room_number_err"></small>
            </div>
            <div class="form-group">
                <label>Room Type <span class="text-danger">*</span></label>
                <select name="room_type" id="add_room_type" class="form-control">
                    <option value="">Select Room Type</option>
                    <option value="single">Single</option>
                    <option value="double">Double</option>
                    <option value="triple">Triple</option>
                    <option value="dormitory">Dormitory</option>
                </select>
                <small class="text-danger" id="add_room_type_err"></small>
            </div>
            <div class="form-group">
                <label>Capacity <span class="text-danger">*</span></label>
                <input type="number" name="capacity" id="add_capacity" class="form-control" placeholder="e.g. 2">
                <small class="text-danger" id="add_capacity_err"></small>
            </div>
            <div class="form-group">
                <label>Price (₹) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" name="price" id="add_price" class="form-control" placeholder="e.g. 5000.00">
                <small class="text-danger" id="add_price_err"></small>
            </div>
            <div class="form-group">
                <label>Room Image <span class="text-danger">*</span></label>
                <input type="file" name="image" id="add_image" class="form-control-file" accept="image/*">
                <div id="add_image_preview" class="mt-2"></div>
                <small class="text-danger d-block" id="add_image_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="form-control">
                    <option value="available">Available</option>
                    <option value="full">Full</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Room</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="editRoomForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Edit Room</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Floor <span class="text-danger">*</span></label>
                <select name="floor_id" id="edit_floor_id" class="form-control">
                    <option value="">Select Floor</option>
                    <?= $floor_options ?>
                </select>
                <small class="text-danger" id="edit_floor_err"></small>
            </div>
            <div class="form-group">
                <label>Room Number <span class="text-danger">*</span></label>
                <input type="text" name="room_number" id="edit_room_number" class="form-control">
                <small class="text-danger" id="edit_room_number_err"></small>
            </div>
            <div class="form-group">
                <label>Room Type <span class="text-danger">*</span></label>
                <select name="room_type" id="edit_room_type" class="form-control">
                    <option value="">Select Room Type</option>
                    <option value="single">Single</option>
                    <option value="double">Double</option>
                    <option value="triple">Triple</option>
                    <option value="dormitory">Dormitory</option>
                </select>
                <small class="text-danger" id="edit_room_type_err"></small>
            </div>
            <div class="form-group">
                <label>Capacity <span class="text-danger">*</span></label>
                <input type="number" name="capacity" id="edit_capacity" class="form-control">
                <small class="text-danger" id="edit_capacity_err"></small>
            </div>
            <div class="form-group">
                <label>Price (₹) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0" name="price" id="edit_price" class="form-control" placeholder="e.g. 5000.00">
                <small class="text-danger" id="edit_price_err"></small>
            </div>
            <div class="form-group">
                <label>Room Image <span class="text-danger">*</span></label>
                <input type="file" name="image" id="edit_image" class="form-control-file" accept="image/*">
                <div id="edit_image_preview" class="mt-2"></div>
                <small class="text-danger d-block" id="edit_image_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="available">Available</option>
                    <option value="full">Full</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Update Room</button>
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
        var floor_id = $(this).data('floor_id');
        var room_number = $(this).data('room_number');
        var room_type = $(this).data('room_type');
        var capacity = $(this).data('capacity');
        var price = $(this).data('price');
        var image = $(this).data('image');
        var status = $(this).data('status');
        
        $('#edit_id').val(id);
        $('#edit_floor_id').val(floor_id);
        $('#edit_room_number').val(room_number);
        $('#edit_room_type').val(room_type);
        $('#edit_capacity').val(capacity);
        $('#edit_price').val(price);
        $('#edit_status').val(status);
        
        // Preview existing room image
        if (image && image.toString().trim() !== '') {
            $('#edit_image_preview').html('<img src="../room_images/' + image + '" style="width: 60px; height: 60px; border-radius: 6px; object-fit: cover; border: 1px solid #ddd;" alt="Current Image" data-has-img="true">');
        } else {
            $('#edit_image_preview').html('<span class="text-muted"><small>No image uploaded</small></span>');
        }
        $('#edit_image').val('');

        // Clear previous errors
        $('#edit_floor_err').text('');
        $('#edit_room_number_err').text('');
        $('#edit_room_type_err').text('');
        $('#edit_capacity_err').text('');
        $('#edit_price_err').text('');
        $('#edit_image_err').text('');
    });

    // Clear add form errors on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_floor_err').text('');
        $('#add_room_number_err').text('');
        $('#add_room_type_err').text('');
        $('#add_capacity_err').text('');
        $('#add_price_err').text('');
        $('#add_image_err').text('');
        $('#add_image_preview').html('');
        $('#addRoomForm')[0].reset();
    });

    // Real-time image preview for Add Form
    $('#add_image').on('change', function() {
        const file = this.files[0];
        if (file) {
            const allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
            if (!allowed.includes(file.type)) {
                $('#add_image_err').text('Only JPG, JPEG, PNG, WEBP and GIF files are allowed.');
                $('#add_image').val('');
                $('#add_image_preview').html('');
                return;
            }
            $('#add_image_err').text('');
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#add_image_preview').html('<img src="' + e.target.result + '" style="width: 60px; height: 60px; border-radius: 6px; object-fit: cover; border: 1px solid #ddd;" alt="Preview">');
            };
            reader.readAsDataURL(file);
        } else {
            $('#add_image_preview').html('');
        }
    });

    // Real-time image preview for Edit Form
    $('#edit_image').on('change', function() {
        const file = this.files[0];
        if (file) {
            const allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
            if (!allowed.includes(file.type)) {
                $('#edit_image_err').text('Only JPG, JPEG, PNG, WEBP and GIF files are allowed.');
                $('#edit_image').val('');
                return;
            }
            $('#edit_image_err').text('');
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#edit_image_preview').html('<img src="' + e.target.result + '" style="width: 60px; height: 60px; border-radius: 6px; object-fit: cover; border: 1px solid #ddd;" alt="Preview">');
            };
            reader.readAsDataURL(file);
        }
    });

    // Validation for Add Form
    $('#addRoomForm').on('submit', function(e) {
        let valid = true;
        let floor_id = $('#add_floor_id').val().trim();
        let room_number = $('#add_room_number').val().trim();
        let room_type = $('#add_room_type').val().trim();
        let capacity = $('#add_capacity').val().trim();
        let price = $('#add_price').val().trim();
        let image = $('#add_image').val();

        if (floor_id === '') {
            $('#add_floor_err').text('Floor selection is mandatory');
            valid = false;
        } else {
            $('#add_floor_err').text('');
        }

        if (room_number === '') {
            $('#add_room_number_err').text('Room Number is mandatory');
            valid = false;
        } else {
            $('#add_room_number_err').text('');
        }

        if (room_type === '') {
            $('#add_room_type_err').text('Room Type is mandatory');
            valid = false;
        } else {
            $('#add_room_type_err').text('');
        }

        if (capacity === '') {
            $('#add_capacity_err').text('Capacity is mandatory');
            valid = false;
        } else {
            $('#add_capacity_err').text('');
        }

        if (price === '' || isNaN(price) || parseFloat(price) < 0) {
            $('#add_price_err').text('Valid price is mandatory');
            valid = false;
        } else {
            $('#add_price_err').text('');
        }

        if (!image) {
            $('#add_image_err').text('Room Image is mandatory');
            valid = false;
        } else {
            $('#add_image_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Validation for Edit Form
    $('#editRoomForm').on('submit', function(e) {
        let valid = true;
        let floor_id = $('#edit_floor_id').val().trim();
        let room_number = $('#edit_room_number').val().trim();
        let room_type = $('#edit_room_type').val().trim();
        let capacity = $('#edit_capacity').val().trim();
        let price = $('#edit_price').val().trim();
        let image = $('#edit_image').val();
        let hasExistingImg = $('#edit_image_preview').find('img[data-has-img="true"]').length > 0;

        if (floor_id === '') {
            $('#edit_floor_err').text('Floor selection is mandatory');
            valid = false;
        } else {
            $('#edit_floor_err').text('');
        }

        if (room_number === '') {
            $('#edit_room_number_err').text('Room Number is mandatory');
            valid = false;
        } else {
            $('#edit_room_number_err').text('');
        }

        if (room_type === '') {
            $('#edit_room_type_err').text('Room Type is mandatory');
            valid = false;
        } else {
            $('#edit_room_type_err').text('');
        }

        if (capacity === '') {
            $('#edit_capacity_err').text('Capacity is mandatory');
            valid = false;
        } else {
            $('#edit_capacity_err').text('');
        }

        if (price === '' || isNaN(price) || parseFloat(price) < 0) {
            $('#edit_price_err').text('Valid price is mandatory');
            valid = false;
        } else {
            $('#edit_price_err').text('');
        }

        if (!image && !hasExistingImg) {
            $('#edit_image_err').text('Room Image is mandatory');
            valid = false;
        } else {
            $('#edit_image_err').text('');
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