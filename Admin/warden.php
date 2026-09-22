<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Directory for warden profiles
$target_dir = "../warden_profile/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// Handle Add / Edit Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $gender = $_POST['gender'] ?? 'male';
    $status = $_POST['status'] ?? 'active';
    $block_ids = isset($_POST['block_ids']) && is_array($_POST['block_ids']) ? $_POST['block_ids'] : [];

    // Profile Image Upload Handling
    $profile_image = '';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($file_ext, $allowed_ext)) {
            $new_filename = 'warden_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_dir . $new_filename)) {
                $profile_image = $new_filename;
            }
        }
    }

    if ($_POST['action'] == 'add') {
        $raw_password = $_POST['password'] ?? '';
        $password_hash = !empty($raw_password) ? password_hash($raw_password, PASSWORD_BCRYPT) : '';

        $stmt = $conn->prepare("INSERT INTO wardens (name, code, email, phone, address, gender, profile_image, password, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("sssssssss", $name, $code, $email, $phone, $address, $gender, $profile_image, $password_hash, $status);
        if ($stmt->execute()) {
            $warden_id = $conn->insert_id;

            // Handle multi-block assignment
            if (!empty($block_ids)) {
                $assign_stmt = $conn->prepare("INSERT INTO warden_block_assignments (warden_id, block_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
                foreach ($block_ids as $b_id) {
                    $b_id = intval($b_id);
                    if ($b_id > 0) {
                        $assign_stmt->bind_param("ii", $warden_id, $b_id);
                        $assign_stmt->execute();
                    }
                }
            }

            $_SESSION['msg'] = "Warden added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add warden: " . $conn->error;
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = intval($_POST['id']);

        // Fetch current profile image & password if not provided
        $current_res = $conn->query("SELECT profile_image, password FROM wardens WHERE id=$id");
        $current_data = $current_res ? $current_res->fetch_assoc() : [];

        if (empty($profile_image)) {
            $profile_image = $current_data['profile_image'] ?? '';
        }

        $raw_password = $_POST['password'] ?? '';
        if (!empty($raw_password)) {
            $password_hash = password_hash($raw_password, PASSWORD_BCRYPT);
        } else {
            $password_hash = $current_data['password'] ?? '';
        }

        $stmt = $conn->prepare("UPDATE wardens SET name=?, code=?, email=?, phone=?, address=?, gender=?, profile_image=?, password=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("sssssssssi", $name, $code, $email, $phone, $address, $gender, $profile_image, $password_hash, $status, $id);
        if ($stmt->execute()) {
            // Clear existing block assignments and insert updated ones
            $del_assign = $conn->prepare("DELETE FROM warden_block_assignments WHERE warden_id=?");
            $del_assign->bind_param("i", $id);
            $del_assign->execute();

            if (!empty($block_ids)) {
                $assign_stmt = $conn->prepare("INSERT INTO warden_block_assignments (warden_id, block_id, assigned_at, status) VALUES (?, ?, NOW(), 'active')");
                foreach ($block_ids as $b_id) {
                    $b_id = intval($b_id);
                    if ($b_id > 0) {
                        $assign_stmt->bind_param("ii", $id, $b_id);
                        $assign_stmt->execute();
                    }
                }
            }

            $_SESSION['msg'] = "Warden updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update warden: " . $conn->error;
        }
    }
    header("Location: warden.php");
    exit();
}

// Handle Delete Action
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Delete block assignments first
    $stmt1 = $conn->prepare("DELETE FROM warden_block_assignments WHERE warden_id=?");
    $stmt1->bind_param("i", $id);
    $stmt1->execute();

    // Delete warden profile image if exists
    $img_res = $conn->query("SELECT profile_image FROM wardens WHERE id=$id");
    if ($img_res && $img_row = $img_res->fetch_assoc()) {
        if (!empty($img_row['profile_image']) && file_exists($target_dir . $img_row['profile_image'])) {
            @unlink($target_dir . $img_row['profile_image']);
        }
    }

    // Delete warden
    $stmt2 = $conn->prepare("DELETE FROM wardens WHERE id=?");
    $stmt2->bind_param("i", $id);
    if ($stmt2->execute()) {
        $_SESSION['msg'] = "Warden deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete warden.";
    }
    header("Location: warden.php");
    exit();
}

// Auto-generate next Warden Code (e.g. WDN-01, WDN-02)
$code_query = "SELECT code FROM wardens WHERE code LIKE 'WDN-%'";
$code_res = $conn->query($code_query);
$max_num = 0;
if ($code_res && $code_res->num_rows > 0) {
    while ($c_row = $code_res->fetch_assoc()) {
        $num_part = intval(preg_replace('/[^0-9]/', '', $c_row['code']));
        if ($num_part > $max_num) {
            $max_num = $num_part;
        }
    }
}
$auto_code = 'WDN-' . str_pad($max_num + 1, 2, '0', STR_PAD_LEFT);

// Fetch all wardens with assigned blocks
$query = "
    SELECT w.*, 
           GROUP_CONCAT(DISTINCT CONCAT(b.name, ' (', b.code, ')') SEPARATOR '||') AS assigned_blocks,
           GROUP_CONCAT(DISTINCT b.id SEPARATOR ',') AS assigned_block_ids
    FROM wardens w
    LEFT JOIN warden_block_assignments wba ON w.id = wba.warden_id AND wba.status = 'active'
    LEFT JOIN blocks b ON wba.block_id = b.id
    GROUP BY w.id
    ORDER BY w.id DESC
";
$wardens = $conn->query($query);

// Fetch all active blocks for block assignment dropdown options
$blocks_res = $conn->query("SELECT id, name, code FROM blocks WHERE status='active' OR status='1' ORDER BY name ASC");
$block_options = "";
if ($blocks_res && $blocks_res->num_rows > 0) {
    while ($b = $blocks_res->fetch_assoc()) {
        $block_options .= "<option value='".$b['id']."'>".htmlspecialchars($b['name'])." (".htmlspecialchars($b['code']).")</option>";
    }
}

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Warden Management</h4>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">Add New Warden</button>
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
                                <th>Profile</th>
                                <th>Name</th>
                                <th>Email/Phone</th>
                                <th>Gender</th>
                                <th>Address</th>
                                <th>Assigned Block(s)</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $wardens->fetch_assoc()): ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <?php if (!empty($row['profile_image']) && file_exists('../warden_profile/' . $row['profile_image'])): ?>
                                        <img src="../warden_profile/<?= htmlspecialchars($row['profile_image']) ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0e0e0; color: #666; display: inline-flex; align-items: center; justify-content: center;">
                                            <i class="ti-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($row['name']) ?>(<?= htmlspecialchars($row['code']) ?>)</td>
                                <td><?= htmlspecialchars($row['email']) ?><br><br><?= htmlspecialchars($row['phone']) ?></td>
                                <td>
                                    <?php 
                                        $g = strtolower($row['gender']);
                                        if($g == 'male') {
                                            echo '<span class="badge badge-info">Male</span>';
                                        } elseif($g == 'female') {
                                            echo '<span class="badge badge-warning">Female</span>';
                                        } else {
                                            echo '<span class="badge badge-secondary">Mixed</span>';
                                        }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($row['address']) ?></td>
                                <td>
                                    <?php 
                                    if(!empty($row['assigned_blocks'])) {
                                        $b_list = explode('||', $row['assigned_blocks']);
                                        foreach($b_list as $blk) {
                                            echo '<span class="badge badge-primary mr-1 mb-1">' . htmlspecialchars($blk) . '</span>';
                                            echo '<br>';
                                        }
                                    } else {
                                        echo '<span class="text-muted font-italic"><small>None</small></span>';
                                    }
                                    ?>
                                </td>
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
                                        data-email="<?= htmlspecialchars($row['email']) ?>"
                                        data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                        data-address="<?= htmlspecialchars($row['address']) ?>"
                                        data-gender="<?= htmlspecialchars($row['gender']) ?>"
                                        data-profile_image="<?= htmlspecialchars($row['profile_image']) ?>"
                                        data-status="<?= $row['status'] ?>"
                                        data-blocks="<?= htmlspecialchars($row['assigned_block_ids'] ?? '') ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>
                                    <a href="javascript:void(0);" data-url="warden.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="ti-trash"></i></a>
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
      <form method="POST" action="" id="addWardenForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Add Warden</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Warden Code <span class="text-danger">*</span></label>
                <input type="text" name="code" id="add_code" class="form-control" value="<?= htmlspecialchars($auto_code) ?>" readonly style="background-color: #e9ecef;">
                <small class="text-danger" id="add_code_err"></small>
            </div>
            <div class="form-group">
                <label>Warden Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. John Doe">
                <small class="text-danger" id="add_name_err"></small>
            </div>
            <div class="form-group">
                <label>Email <span class="text-danger">*</span></label>
                <input type="email" name="email" id="add_email" class="form-control" placeholder="e.g. warden@example.com">
                <small class="text-danger" id="add_email_err"></small>
            </div>
            <div class="form-group">
                <label>Phone <span class="text-danger">*</span></label>
                <input type="text" name="phone" id="add_phone" class="form-control" placeholder="e.g. 9876543210">
                <small class="text-danger" id="add_phone_err"></small>
            </div>
            <div class="form-group">
                <label>Password <span class="text-danger">*</span></label>
                <input type="password" name="password" id="add_password" class="form-control" placeholder="Enter warden password">
                <small class="text-danger" id="add_password_err"></small>
            </div>
            <div class="form-group">
                <label>Gender <span class="text-danger">*</span></label>
                <select name="gender" id="add_gender" class="form-control">
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="mixed">Mixed</option>
                </select>
                <small class="text-danger" id="add_gender_err"></small>
            </div>
            <div class="form-group">
                <label>Profile Image <span class="text-danger">*</span></label>
                <input type="file" name="profile_image" id="add_profile_image" class="form-control-file" accept="image/*">
                <small class="form-text text-muted">Allowed formats: JPG, PNG, GIF, WEBP</small>
                <small class="text-danger d-block" id="add_image_err"></small>
            </div>
            <div class="form-group">
                <label>Address <span class="text-danger">*</span></label>
                <textarea name="address" id="add_address" class="form-control" rows="3" placeholder="Enter warden address"></textarea>
                <small class="text-danger" id="add_address_err"></small>
            </div>
            <div class="form-group">
                <label>Assign Block(s) <span class="text-danger">*</span></label>
                <select name="block_ids[]" id="add_block_ids" class="form-control" multiple style="height: 110px;">
                    <?= $block_options ?>
                </select>
                <small class="form-text text-muted">Hold Ctrl / Cmd to select multiple blocks.</small>
                <small class="text-danger d-block" id="add_block_err"></small>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status" id="add_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Warden</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="editWardenForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Edit Warden</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Warden Code <span class="text-danger">*</span></label>
                <input type="text" name="code" id="edit_code" class="form-control" readonly style="background-color: #e9ecef;">
                <small class="text-danger" id="edit_code_err"></small>
            </div>
            <div class="form-group">
                <label>Warden Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="edit_name" class="form-control">
                <small class="text-danger" id="edit_name_err"></small>
            </div>
            <div class="form-group">
                <label>Email <span class="text-danger">*</span></label>
                <input type="email" name="email" id="edit_email" class="form-control">
                <small class="text-danger" id="edit_email_err"></small>
            </div>
            <div class="form-group">
                <label>Phone <span class="text-danger">*</span></label>
                <input type="text" name="phone" id="edit_phone" class="form-control">
                <small class="text-danger" id="edit_phone_err"></small>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="edit_password" class="form-control" placeholder="Leave blank to keep current password">
                <small class="text-muted">Leave blank if you do not wish to change password.</small>
            </div>
            <div class="form-group">
                <label>Gender <span class="text-danger">*</span></label>
                <select name="gender" id="edit_gender" class="form-control">
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="mixed">Mixed</option>
                </select>
                <small class="text-danger" id="edit_gender_err"></small>
            </div>
            <div class="form-group">
                <label>Profile Image <span class="text-danger">*</span></label>
                <input type="file" name="profile_image" id="edit_profile_image" class="form-control-file" accept="image/*">
                <div id="edit_image_preview" class="mt-2"></div>
                <small class="text-danger d-block" id="edit_image_err"></small>
            </div>
            <div class="form-group">
                <label>Address <span class="text-danger">*</span></label>
                <textarea name="address" id="edit_address" class="form-control" rows="3"></textarea>
                <small class="text-danger" id="edit_address_err"></small>
            </div>
            <div class="form-group">
                <label>Assign Block(s) <span class="text-danger">*</span></label>
                <select name="block_ids[]" id="edit_block_ids" class="form-control" multiple style="height: 110px;">
                    <?= $block_options ?>
                </select>
                <small class="form-text text-muted">Hold Ctrl / Cmd to select multiple blocks.</small>
                <small class="text-danger d-block" id="edit_block_err"></small>
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
            <button type="submit" class="btn btn-primary">Save Warden</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var defaultAutoCode = "<?= htmlspecialchars($auto_code) ?>";

    // Populate Edit Modal Data
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var code = $(this).data('code');
        var email = $(this).data('email');
        var phone = $(this).data('phone');
        var address = $(this).data('address');
        var gender = $(this).data('gender');
        var profileImage = $(this).data('profile_image');
        var status = $(this).data('status');
        var blocksStr = $(this).data('blocks') ? $(this).data('blocks').toString() : '';

        $('#edit_id').val(id);
        $('#edit_name').val(name);
        $('#edit_code').val(code);
        $('#edit_email').val(email);
        $('#edit_phone').val(phone);
        $('#edit_password').val('');
        $('#edit_address').val(address);
        $('#edit_gender').val(gender);
        $('#edit_status').val(status);

        // Preview existing profile image
        if (profileImage && profileImage.trim() !== '') {
            $('#edit_image_preview').html('<img src="../warden_profile/' + profileImage + '" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;" alt="Profile Preview" data-has-img="true">');
        } else {
            $('#edit_image_preview').html('<span class="text-muted"><small>No image uploaded</small></span>');
        }

        // Select assigned blocks
        $('#edit_block_ids option').prop('selected', false);
        if (blocksStr && blocksStr.trim() !== '') {
            var blockArray = blocksStr.split(',');
            blockArray.forEach(function(bId) {
                $('#edit_block_ids option[value="' + bId.trim() + '"]').prop('selected', true);
            });
        }

        // Clear previous errors
        $('#edit_name_err').text('');
        $('#edit_code_err').text('');
        $('#edit_email_err').text('');
        $('#edit_phone_err').text('');
        $('#edit_gender_err').text('');
        $('#edit_image_err').text('');
        $('#edit_address_err').text('');
        $('#edit_block_err').text('');
    });

    // Clear add form errors and set auto code on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_name_err').text('');
        $('#add_code_err').text('');
        $('#add_email_err').text('');
        $('#add_phone_err').text('');
        $('#add_password_err').text('');
        $('#add_gender_err').text('');
        $('#add_image_err').text('');
        $('#add_address_err').text('');
        $('#add_block_err').text('');
        $('#addWardenForm')[0].reset();
        $('#add_code').val(defaultAutoCode);
        $('#add_block_ids option').prop('selected', false);
    });

    // Validation for Add Form
    $('#addWardenForm').on('submit', function(e) {
        let valid = true;
        let code = $('#add_code').val().trim();
        let name = $('#add_name').val().trim();
        let email = $('#add_email').val().trim();
        let phone = $('#add_phone').val().trim();
        let password = $('#add_password').val().trim();
        let gender = $('#add_gender').val().trim();
        let image = $('#add_profile_image').val();
        let address = $('#add_address').val().trim();
        let block_ids = $('#add_block_ids').val();

        if (code === '') {
            $('#add_code_err').text('Warden Code is mandatory');
            valid = false;
        } else {
            $('#add_code_err').text('');
        }

        if (name === '') {
            $('#add_name_err').text('Warden Name is mandatory');
            valid = false;
        } else {
            $('#add_name_err').text('');
        }

        if (email === '') {
            $('#add_email_err').text('Email is mandatory');
            valid = false;
        } else {
            $('#add_email_err').text('');
        }

        if (phone === '') {
            $('#add_phone_err').text('Phone number is mandatory');
            valid = false;
        } else {
            $('#add_phone_err').text('');
        }

        if (password === '') {
            $('#add_password_err').text('Password is mandatory');
            valid = false;
        } else {
            $('#add_password_err').text('');
        }

        if (gender === '') {
            $('#add_gender_err').text('Gender selection is mandatory');
            valid = false;
        } else {
            $('#add_gender_err').text('');
        }

        if (!image) {
            $('#add_image_err').text('Profile Image is mandatory');
            valid = false;
        } else {
            $('#add_image_err').text('');
        }

        if (address === '') {
            $('#add_address_err').text('Address is mandatory');
            valid = false;
        } else {
            $('#add_address_err').text('');
        }

        if (!block_ids || block_ids.length === 0) {
            $('#add_block_err').text('Block selection is mandatory');
            valid = false;
        } else {
            $('#add_block_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Validation for Edit Form
    $('#editWardenForm').on('submit', function(e) {
        let valid = true;
        let code = $('#edit_code').val().trim();
        let name = $('#edit_name').val().trim();
        let email = $('#edit_email').val().trim();
        let phone = $('#edit_phone').val().trim();
        let gender = $('#edit_gender').val().trim();
        let image = $('#edit_profile_image').val();
        let hasExistingImg = $('#edit_image_preview').find('img[data-has-img="true"]').length > 0;
        let address = $('#edit_address').val().trim();
        let block_ids = $('#edit_block_ids').val();

        if (code === '') {
            $('#edit_code_err').text('Warden Code is mandatory');
            valid = false;
        } else {
            $('#edit_code_err').text('');
        }

        if (name === '') {
            $('#edit_name_err').text('Warden Name is mandatory');
            valid = false;
        } else {
            $('#edit_name_err').text('');
        }

        if (email === '') {
            $('#edit_email_err').text('Email is mandatory');
            valid = false;
        } else {
            $('#edit_email_err').text('');
        }

        if (phone === '') {
            $('#edit_phone_err').text('Phone number is mandatory');
            valid = false;
        } else {
            $('#edit_phone_err').text('');
        }

        if (gender === '') {
            $('#edit_gender_err').text('Gender selection is mandatory');
            valid = false;
        } else {
            $('#edit_gender_err').text('');
        }

        if (!image && !hasExistingImg) {
            $('#edit_image_err').text('Profile Image is mandatory');
            valid = false;
        } else {
            $('#edit_image_err').text('');
        }

        if (address === '') {
            $('#edit_address_err').text('Address is mandatory');
            valid = false;
        } else {
            $('#edit_address_err').text('');
        }

        if (!block_ids || block_ids.length === 0) {
            $('#edit_block_err').text('Block selection is mandatory');
            valid = false;
        } else {
            $('#edit_block_err').text('');
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