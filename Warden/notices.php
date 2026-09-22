<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

// Directory for notice attachments
$target_dir = "../notice_attachments/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$warden_id = $_SESSION['warden_id'] ?? 1;

// Handle Add / Edit Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $status = $_POST['status'] ?? 'published';
    
    // Format dates for MySQL
    $publish_at_raw = $_POST['publish_at'] ?? '';
    $expires_at_raw = $_POST['expires_at'] ?? '';
    
    $publish_at = !empty($publish_at_raw) ? date('Y-m-d 00:00:00', strtotime($publish_at_raw)) : date('Y-m-d 00:00:00');
    $expires_at = !empty($expires_at_raw) ? date('Y-m-d 23:59:59', strtotime($expires_at_raw)) : date('Y-m-d 23:59:59', strtotime('+30 days'));

    // Attachment Upload Handling
    $attachment = '';
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'];
        if (in_array($file_ext, $allowed_ext)) {
            $new_filename = 'notice_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_dir . $new_filename)) {
                $attachment = $new_filename;
            }
        }
    }

    if ($_POST['action'] == 'add') {
        $stmt = $conn->prepare("INSERT INTO notices (created_by, title, description, attachment, priority, publish_at, expires_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("isssssss", $warden_id, $title, $description, $attachment, $priority, $publish_at, $expires_at, $status);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Notice added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add notice: " . $conn->error;
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = intval($_POST['id']);

        // Fetch current attachment if not updating
        $current_res = $conn->query("SELECT attachment FROM notices WHERE id=$id");
        $current_data = $current_res ? $current_res->fetch_assoc() : [];

        if (empty($attachment)) {
            $attachment = $current_data['attachment'] ?? '';
        }

        $stmt = $conn->prepare("UPDATE notices SET title=?, description=?, attachment=?, priority=?, publish_at=?, expires_at=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("sssssssi", $title, $description, $attachment, $priority, $publish_at, $expires_at, $status, $id);
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Notice updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update notice: " . $conn->error;
        }
    }
    header("Location: notices.php");
    exit();
}

// Handle Delete Action
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Delete attachment file if exists
    $img_res = $conn->query("SELECT attachment FROM notices WHERE id=$id");
    if ($img_res && $img_row = $img_res->fetch_assoc()) {
        if (!empty($img_row['attachment']) && file_exists($target_dir . $img_row['attachment'])) {
            @unlink($target_dir . $img_row['attachment']);
        }
    }

    // Delete notice record
    $stmt = $conn->prepare("DELETE FROM notices WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Notice deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete notice.";
    }
    header("Location: notices.php");
    exit();
}

// Fetch all notices
$notices = $conn->query("SELECT * FROM notices ORDER BY id DESC");

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Notice Management</h4>
                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal"><i class="ti-plus mr-1"></i>Add New Notice</button>
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
                                <th>Title</th>
                                <th>Priority</th>
                                <th>Publish Date</th>
                                <th>Expire Date</th>
                                <th>Attachment</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $notices->fetch_assoc()): ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <span class="font-weight-bold d-block"><?= htmlspecialchars($row['title']) ?></span>
                                    <small class="text-muted d-block" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($row['description']) ?></small>
                                </td>
                                <td>
                                    <?php 
                                        $p = strtolower($row['priority']);
                                        if($p == 'urgent') {
                                            echo '<span class="badge badge-danger">Urgent</span>';
                                        } elseif($p == 'important') {
                                            echo '<span class="badge badge-warning">Important</span>';
                                        } else {
                                            echo '<span class="badge badge-info">Normal</span>';
                                        }
                                    ?>
                                </td>
                                <td><small><?= date('M d, Y', strtotime($row['publish_at'])) ?></small></td>
                                <td><small><?= date('M d, Y', strtotime($row['expires_at'])) ?></small></td>
                                <td>
                                    <?php if (!empty($row['attachment']) && file_exists('../notice_attachments/' . $row['attachment'])): ?>
                                        <a href="../notice_attachments/<?= htmlspecialchars($row['attachment']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-1 px-2" title="View Attachment">
                                            <i class="ti-file mr-1"></i> View File
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted font-italic"><small>None</small></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $st = strtolower($row['status']);
                                        if($st == 'published') {
                                            echo '<span class="badge badge-success">Published</span>';
                                        } elseif($st == 'draft') {
                                            echo '<span class="badge badge-secondary">Draft</span>';
                                        } else {
                                            echo '<span class="badge badge-danger">Expired</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <!-- View Notice Button -->
                                    <button type="button" class="btn btn-warning btn-sm view-notice-btn mr-1 shadow-sm" title="View Details"
                                        data-id="<?= $row['id'] ?>"
                                        data-title="<?= htmlspecialchars($row['title']) ?>"
                                        data-description="<?= htmlspecialchars($row['description']) ?>"
                                        data-priority="<?= htmlspecialchars($row['priority']) ?>"
                                        data-publish_at="<?= date('d M Y', strtotime($row['publish_at'])) ?>"
                                        data-expires_at="<?= date('d M Y', strtotime($row['expires_at'])) ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                        data-attachment="<?= htmlspecialchars($row['attachment'] ?? '') ?>"
                                        data-created_at="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>"
                                        data-toggle="modal" data-target="#viewNoticeModal">
                                        <i class="ti-eye"></i>
                                    </button>
                                    <button class="btn btn-primary btn-sm edit-btn mr-1 shadow-sm" title="Edit" 
                                        data-id="<?= $row['id'] ?>"
                                        data-title="<?= htmlspecialchars($row['title']) ?>"
                                        data-description="<?= htmlspecialchars($row['description']) ?>"
                                        data-priority="<?= htmlspecialchars($row['priority']) ?>"
                                        data-publish_at="<?= date('Y-m-d', strtotime($row['publish_at'])) ?>"
                                        data-expires_at="<?= date('Y-m-d', strtotime($row['expires_at'])) ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                        data-attachment="<?= htmlspecialchars($row['attachment']) ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>
                                    <a href="javascript:void(0);" data-url="notices.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn shadow-sm" title="Delete"><i class="ti-trash"></i></a>
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

<!-- View Notice Modal -->
<div class="modal fade" id="viewNoticeModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document" style="max-width: 700px;">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
        <div class="modal-header bg-light">
          <h5 class="modal-title font-weight-bold text-dark">
              <i class="ti-announcement mr-2 text-primary"></i>Notice Details
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body p-4" style="background: #f8fafc;">
            <!-- Header Banner -->
            <div class="p-3 mb-3 bg-white rounded-12 shadow-sm border">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h4 class="font-weight-bold text-dark mb-0" id="view_notice_title"></h4>
                    <span id="view_notice_priority_badge"></span>
                </div>
                <div class="d-flex flex-wrap text-muted small mt-2">
                    <span class="mr-3"><i class="ti-calendar mr-1 text-primary"></i>Published: <strong id="view_notice_publish_date" class="text-dark"></strong></span>
                    <span class="mr-3"><i class="ti-timer mr-1 text-danger"></i>Expires: <strong id="view_notice_expire_date" class="text-dark"></strong></span>
                    <span id="view_notice_status_badge"></span>
                </div>
            </div>

            <!-- Description Box -->
            <div class="card border mb-3 rounded-12 shadow-sm bg-white">
                <div class="card-header bg-white font-weight-bold border-bottom text-dark">
                    <i class="ti-align-left mr-1 text-primary"></i> Notice Content / Description
                </div>
                <div class="card-body">
                    <p class="mb-0 text-dark" id="view_notice_description" style="white-space: pre-line; line-height: 1.6; font-size: 0.95rem;"></p>
                </div>
            </div>

            <!-- Attachment File -->
            <div class="card border mb-0 rounded-12 shadow-sm bg-white">
                <div class="card-header bg-white font-weight-bold border-bottom text-dark">
                    <i class="ti-clip mr-1 text-primary"></i> Attached Document / Circular
                </div>
                <div class="card-body" id="view_notice_attachment_wrapper">
                </div>
            </div>

            <div class="mt-3 text-right">
                <small class="text-muted"><i class="ti-time mr-1"></i>Created: <span id="view_notice_created_at"></span></small>
            </div>
        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn btn-secondary px-4 py-2" data-dismiss="modal">Close</button>
        </div>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="addNoticeForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Add Notice</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Notice Title <span class="text-danger">*</span></label>
                <input type="text" name="title" id="add_title" class="form-control" placeholder="e.g. Hostel Maintenance Notice">
                <small class="text-danger" id="add_title_err"></small>
            </div>
            <div class="form-group">
                <label>Priority <span class="text-danger">*</span></label>
                <select name="priority" id="add_priority" class="form-control">
                    <option value="">Select Priority</option>
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                </select>
                <small class="text-danger" id="add_priority_err"></small>
            </div>
            <div class="form-group">
                <label>Status <span class="text-danger">*</span></label>
                <select name="status" id="add_status" class="form-control">
                    <option value="">Select Status</option>
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                    <option value="expired">Expired</option>
                </select>
                <small class="text-danger" id="add_status_err"></small>
            </div>
            <div class="form-group">
                <label>Publish Date <span class="text-danger">*</span></label>
                <input type="date" name="publish_at" id="add_publish_at" class="form-control">
                <small class="text-danger" id="add_publish_at_err"></small>
            </div>
            <div class="form-group">
                <label>Expire Date <span class="text-danger">*</span></label>
                <input type="date" name="expires_at" id="add_expires_at" class="form-control">
                <small class="text-danger" id="add_expires_at_err"></small>
            </div>
            <div class="form-group">
                <label>Description <span class="text-danger">*</span></label>
                <textarea name="description" id="add_description" class="form-control" rows="3" placeholder="Enter notice description"></textarea>
                <small class="text-danger" id="add_description_err"></small>
            </div>
            <div class="form-group">
                <label>Attachment File <span class="text-danger">*</span></label>
                <input type="file" name="attachment" id="add_attachment" class="form-control-file">
                <small class="form-text text-muted">Allowed formats: PDF, DOC, DOCX, JPG, PNG, GIF, WEBP, TXT</small>
                <small class="text-danger d-block" id="add_attachment_err"></small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Notice</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="editNoticeForm" enctype="multipart/form-data">
          <div class="modal-header">
            <h5 class="modal-title">Edit Notice</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Notice Title <span class="text-danger">*</span></label>
                <input type="text" name="title" id="edit_title" class="form-control">
                <small class="text-danger" id="edit_title_err"></small>
            </div>
            <div class="form-group">
                <label>Priority <span class="text-danger">*</span></label>
                <select name="priority" id="edit_priority" class="form-control">
                    <option value="">Select Priority</option>
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                </select>
                <small class="text-danger" id="edit_priority_err"></small>
            </div>
            <div class="form-group">
                <label>Status <span class="text-danger">*</span></label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="">Select Status</option>
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                    <option value="expired">Expired</option>
                </select>
                <small class="text-danger" id="edit_status_err"></small>
            </div>
            <div class="form-group">
                <label>Publish Date <span class="text-danger">*</span></label>
                <input type="date" name="publish_at" id="edit_publish_at" class="form-control">
                <small class="text-danger" id="edit_publish_at_err"></small>
            </div>
            <div class="form-group">
                <label>Expire Date <span class="text-danger">*</span></label>
                <input type="date" name="expires_at" id="edit_expires_at" class="form-control">
                <small class="text-danger" id="edit_expires_at_err"></small>
            </div>
            <div class="form-group">
                <label>Description <span class="text-danger">*</span></label>
                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                <small class="text-danger" id="edit_description_err"></small>
            </div>
            <div class="form-group">
                <label>Attachment File <span class="text-danger">*</span></label>
                <input type="file" name="attachment" id="edit_attachment" class="form-control-file">
                <div id="edit_file_preview" class="mt-2"></div>
                <small class="text-danger d-block" id="edit_attachment_err"></small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Notice</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // View Modal Event Handler
    $(document).on('click', '.view-notice-btn', function() {
        var title = $(this).data('title');
        var description = $(this).data('description');
        var priority = $(this).data('priority');
        var publishAt = $(this).data('publish_at');
        var expiresAt = $(this).data('expires_at');
        var status = $(this).data('status');
        var attachment = $(this).data('attachment');
        var createdAt = $(this).data('created_at');

        $('#view_notice_title').text(title);
        $('#view_notice_description').text(description);
        $('#view_notice_publish_date').text(publishAt);
        $('#view_notice_expire_date').text(expiresAt);
        $('#view_notice_created_at').text(createdAt);

        var p = (priority || 'normal').toLowerCase();
        if (p === 'urgent') {
            $('#view_notice_priority_badge').html('<span class="badge badge-danger px-3 py-1"><i class="ti-alert mr-1"></i>Urgent Priority</span>');
        } else if (p === 'important') {
            $('#view_notice_priority_badge').html('<span class="badge badge-warning text-dark px-3 py-1"><i class="ti-star mr-1"></i>Important</span>');
        } else {
            $('#view_notice_priority_badge').html('<span class="badge badge-info px-3 py-1"><i class="ti-info mr-1"></i>Normal</span>');
        }

        var s = (status || 'published').toLowerCase();
        if (s === 'published') {
            $('#view_notice_status_badge').html('<span class="badge badge-success px-2 py-1">Published</span>');
        } else if (s === 'draft') {
            $('#view_notice_status_badge').html('<span class="badge badge-secondary px-2 py-1">Draft</span>');
        } else {
            $('#view_notice_status_badge').html('<span class="badge badge-danger px-2 py-1">Expired</span>');
        }

        if (attachment && attachment.trim() !== '') {
            var ext = attachment.split('.').pop().toLowerCase();
            var iconClass = (ext === 'pdf') ? 'ti-file text-danger' : 'ti-clip text-primary';
            $('#view_notice_attachment_wrapper').html(
                '<a href="../notice_attachments/' + attachment + '" target="_blank" class="btn btn-outline-primary btn-sm rounded-8 py-2 px-3">' +
                    '<i class="' + iconClass + ' mr-1"></i> View / Download Attachment (' + attachment + ')' +
                '</a>'
            );
        } else {
            $('#view_notice_attachment_wrapper').html('<span class="text-muted font-italic"><i class="ti-info-alt mr-1"></i> No attachment file uploaded with this notice.</span>');
        }
    });

    // Populate Edit Modal Data
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var title = $(this).data('title');
        var description = $(this).data('description');
        var priority = $(this).data('priority');
        var publishAt = $(this).data('publish_at');
        var expiresAt = $(this).data('expires_at');
        var status = $(this).data('status');
        var attachment = $(this).data('attachment');

        $('#edit_id').val(id);
        $('#edit_title').val(title);
        $('#edit_description').val(description);
        $('#edit_priority').val(priority);
        $('#edit_publish_at').val(publishAt);
        $('#edit_expires_at').val(expiresAt);
        $('#edit_status').val(status);

        if (attachment && attachment.trim() !== '') {
            $('#edit_file_preview').html('<a href="../notice_attachments/' + attachment + '" target="_blank" class="btn btn-sm btn-outline-info" data-has-file="true"><i class="ti-file mr-1"></i> Current Attachment (' + attachment + ')</a>');
        } else {
            $('#edit_file_preview').html('<span class="text-muted"><small>No attachment uploaded</small></span>');
        }

        // Clear previous errors
        $('#edit_title_err').text('');
        $('#edit_priority_err').text('');
        $('#edit_status_err').text('');
        $('#edit_publish_at_err').text('');
        $('#edit_expires_at_err').text('');
        $('#edit_description_err').text('');
        $('#edit_attachment_err').text('');
    });

    // Clear add form errors on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_title_err').text('');
        $('#add_priority_err').text('');
        $('#add_status_err').text('');
        $('#add_publish_at_err').text('');
        $('#add_expires_at_err').text('');
        $('#add_description_err').text('');
        $('#add_attachment_err').text('');
        $('#addNoticeForm')[0].reset();
    });

    // Validation for Add Form
    $('#addNoticeForm').on('submit', function(e) {
        let valid = true;
        let title = $('#add_title').val().trim();
        let priority = $('#add_priority').val().trim();
        let status = $('#add_status').val().trim();
        let publish_at = $('#add_publish_at').val().trim();
        let expires_at = $('#add_expires_at').val().trim();
        let description = $('#add_description').val().trim();
        let attachment = $('#add_attachment').val();

        if (title === '') {
            $('#add_title_err').text('Notice Title is mandatory');
            valid = false;
        } else {
            $('#add_title_err').text('');
        }

        if (priority === '') {
            $('#add_priority_err').text('Priority selection is mandatory');
            valid = false;
        } else {
            $('#add_priority_err').text('');
        }

        if (status === '') {
            $('#add_status_err').text('Status selection is mandatory');
            valid = false;
        } else {
            $('#add_status_err').text('');
        }

        if (publish_at === '') {
            $('#add_publish_at_err').text('Publish Date is mandatory');
            valid = false;
        } else {
            $('#add_publish_at_err').text('');
        }

        if (expires_at === '') {
            $('#add_expires_at_err').text('Expiry Date is mandatory');
            valid = false;
        } else {
            $('#add_expires_at_err').text('');
        }

        if (description === '') {
            $('#add_description_err').text('Description is mandatory');
            valid = false;
        } else {
            $('#add_description_err').text('');
        }

        if (!attachment) {
            $('#add_attachment_err').text('Attachment File is mandatory');
            valid = false;
        } else {
            $('#add_attachment_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Validation for Edit Form
    $('#editNoticeForm').on('submit', function(e) {
        let valid = true;
        let title = $('#edit_title').val().trim();
        let priority = $('#edit_priority').val().trim();
        let status = $('#edit_status').val().trim();
        let publish_at = $('#edit_publish_at').val().trim();
        let expires_at = $('#edit_expires_at').val().trim();
        let description = $('#edit_description').val().trim();
        let attachment = $('#edit_attachment').val();
        let hasExistingFile = $('#edit_file_preview').find('a[data-has-file="true"]').length > 0;

        if (title === '') {
            $('#edit_title_err').text('Notice Title is mandatory');
            valid = false;
        } else {
            $('#edit_title_err').text('');
        }

        if (priority === '') {
            $('#edit_priority_err').text('Priority selection is mandatory');
            valid = false;
        } else {
            $('#edit_priority_err').text('');
        }

        if (status === '') {
            $('#edit_status_err').text('Status selection is mandatory');
            valid = false;
        } else {
            $('#edit_status_err').text('');
        }

        if (publish_at === '') {
            $('#edit_publish_at_err').text('Publish Date is mandatory');
            valid = false;
        } else {
            $('#edit_publish_at_err').text('');
        }

        if (expires_at === '') {
            $('#edit_expires_at_err').text('Expiry Date is mandatory');
            valid = false;
        } else {
            $('#edit_expires_at_err').text('');
        }

        if (description === '') {
            $('#edit_description_err').text('Description is mandatory');
            valid = false;
        } else {
            $('#edit_description_err').text('');
        }

        if (!attachment && !hasExistingFile) {
            $('#edit_attachment_err').text('Attachment File is mandatory');
            valid = false;
        } else {
            $('#edit_attachment_err').text('');
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
