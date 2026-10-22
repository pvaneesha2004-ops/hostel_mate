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



// Handle Change Notice Status Action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    $id = intval($_POST['id'] ?? 0);
    $new_status = strtolower(trim($_POST['status'] ?? ''));
    $allowed_statuses = ['published', 'draft', 'expired'];

    if ($id > 0 && in_array($new_status, $allowed_statuses)) {
        $new_expires_at = !empty($_POST['expires_at']) ? date('Y-m-d 23:59:59', strtotime($_POST['expires_at'])) : null;

        if ($new_expires_at && $new_status === 'published') {
            $stmt = $conn->prepare("UPDATE notices SET status=?, expires_at=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("ssi", $new_status, $new_expires_at, $id);
        } else {
            $stmt = $conn->prepare("UPDATE notices SET status=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("si", $new_status, $id);
        }

        if ($stmt && $stmt->execute()) {
            $status_label = ucfirst($new_status);
            $_SESSION['msg'] = "Notice status successfully updated to {$status_label}.";
        } else {
            $_SESSION['error'] = "Failed to update notice status: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Invalid status update request.";
    }

    $current_filter = isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '';
    header("Location: notices.php" . $current_filter);
    exit();
}

// Handle Add / Edit Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
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
        $stmt = $conn->prepare("INSERT INTO notices (created_by, title, description, attachment, publish_at, expires_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param("issssss", $warden_id, $title, $description, $attachment, $publish_at, $expires_at, $status);
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

        $stmt = $conn->prepare("UPDATE notices SET title=?, description=?, attachment=?, publish_at=?, expires_at=?, status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("ssssssi", $title, $description, $attachment, $publish_at, $expires_at, $status, $id);
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

// Determine active status filter
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
if (!in_array($status_filter, ['all', 'published', 'draft', 'expired'])) {
    $status_filter = 'all';
}

// Live Status Counts for Navigation Tabs
$count_all = 0;
$count_published = 0;
$count_draft = 0;
$count_expired = 0;

$cnt_res = $conn->query("SELECT status, COUNT(*) as cnt FROM notices GROUP BY status");
if ($cnt_res) {
    while ($cr = $cnt_res->fetch_assoc()) {
        $st = strtolower($cr['status'] ?? '');
        if ($st === 'published') $count_published = intval($cr['cnt']);
        elseif ($st === 'draft') $count_draft = intval($cr['cnt']);
        elseif ($st === 'expired') $count_expired = intval($cr['cnt']);
    }
}
$count_all = $count_published + $count_draft + $count_expired;

// Fetch notices based on filter
$where_sql = "";
if ($status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_sql = "WHERE status = '$safe_status'";
}
$notices = $conn->query("SELECT * FROM notices $where_sql ORDER BY id DESC");

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-1">Notice Management</h4>
                        <p class="text-muted small mb-0">View, manage, and easily change status across all hostel circulars and notices.</p>
                    </div>
                    <button class="btn btn-primary btn-sm shadow-sm" data-toggle="modal" data-target="#addModal"><i class="ti-plus mr-1"></i>Add New Notice</button>
                </div>

                <!-- Status Filter Navigation Pills -->
                <div class="status-filter-wrapper mb-3 p-2 bg-light rounded d-flex flex-wrap align-items-center justify-content-between">
                    <div class="status-filter-pills d-flex flex-wrap align-items-center">
                        <a href="notices.php?status=all" class="filter-pill <?= ($status_filter === 'all') ? 'active all-active' : '' ?>" title="View all status notices">
                            <i class="ti-layers mr-1"></i> All Notices
                            <span class="pill-count"><?= $count_all ?></span>
                        </a>
                        <a href="notices.php?status=published" class="filter-pill <?= ($status_filter === 'published') ? 'active pub-active' : '' ?>" title="View live published notices">
                            <span class="status-dot dot-published"></span> Published
                            <span class="pill-count"><?= $count_published ?></span>
                        </a>
                        <a href="notices.php?status=draft" class="filter-pill <?= ($status_filter === 'draft') ? 'active draft-active' : '' ?>" title="View draft notices">
                            <span class="status-dot dot-draft"></span> Draft
                            <span class="pill-count"><?= $count_draft ?></span>
                        </a>
                        <a href="notices.php?status=expired" class="filter-pill <?= ($status_filter === 'expired') ? 'active exp-active' : '' ?>" title="View expired / archived notices">
                            <span class="status-dot dot-expired"></span> Expired
                            <span class="pill-count"><?= $count_expired ?></span>
                        </a>
                    </div>
                    <div class="text-muted small mt-2 mt-md-0 d-none d-md-block">
                        <i class="ti-info-alt mr-1 text-primary"></i> Click any <strong>Status badge</strong> or <strong>Status button</strong> to quick-change status.
                    </div>
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
                                        $st = strtolower($row['status'] ?? 'published');
                                    ?>
                                    <div class="dropdown d-inline-block">
                                        <button class="btn btn-xs dropdown-toggle status-dropdown-btn font-weight-bold" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Click to Change Status">
                                            <?php if($st == 'published'): ?>
                                                <span class="badge badge-success py-1 px-2"><i class="ti-check mr-1"></i>Published <i class="ti-angle-down ml-1 small"></i></span>
                                            <?php elseif($st == 'draft'): ?>
                                                <span class="badge badge-secondary py-1 px-2"><i class="ti-pencil-alt mr-1"></i>Draft <i class="ti-angle-down ml-1 small"></i></span>
                                            <?php else: ?>
                                                <span class="badge badge-danger py-1 px-2"><i class="ti-time mr-1"></i>Expired <i class="ti-angle-down ml-1 small"></i></span>
                                            <?php endif; ?>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right shadow border-0 py-2" style="border-radius: 12px; min-width: 210px;">
                                            <div class="dropdown-header text-uppercase font-weight-bold text-muted small" style="letter-spacing: 0.5px;">Change Status</div>
                                            <a class="dropdown-item quick-status-change font-weight-medium py-2 <?= ($st == 'published') ? 'active' : '' ?>" href="javascript:void(0);" data-id="<?= $row['id'] ?>" data-status="published" data-title="<?= htmlspecialchars($row['title']) ?>">
                                                <span class="status-dot dot-published"></span> Mark as Published
                                            </a>
                                            <a class="dropdown-item quick-status-change font-weight-medium py-2 <?= ($st == 'draft') ? 'active' : '' ?>" href="javascript:void(0);" data-id="<?= $row['id'] ?>" data-status="draft" data-title="<?= htmlspecialchars($row['title']) ?>">
                                                <span class="status-dot dot-draft"></span> Mark as Draft
                                            </a>
                                            <a class="dropdown-item quick-status-change font-weight-medium py-2 <?= ($st == 'expired') ? 'active' : '' ?>" href="javascript:void(0);" data-id="<?= $row['id'] ?>" data-status="expired" data-title="<?= htmlspecialchars($row['title']) ?>">
                                                <span class="status-dot dot-expired"></span> Mark as Expired
                                            </a>
                                            <div class="dropdown-divider my-1"></div>
                                            <a class="dropdown-item open-status-modal font-weight-medium text-primary py-2" href="javascript:void(0);" 
                                                data-id="<?= $row['id'] ?>" 
                                                data-status="<?= $st ?>" 
                                                data-title="<?= htmlspecialchars($row['title']) ?>" 
                                                data-expires_at="<?= date('Y-m-d', strtotime($row['expires_at'])) ?>">
                                                <i class="ti-settings mr-2"></i> Status Options...
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <!-- View Notice Button -->
                                    <button type="button" class="btn btn-warning btn-sm view-notice-btn mr-1 shadow-sm" title="View Details"
                                        data-id="<?= $row['id'] ?>"
                                        data-title="<?= htmlspecialchars($row['title']) ?>"
                                        data-description="<?= htmlspecialchars($row['description']) ?>"
                                        data-publish_at="<?= date('d M Y', strtotime($row['publish_at'])) ?>"
                                        data-expires_at="<?= date('d M Y', strtotime($row['expires_at'])) ?>"
                                        data-expires_at_raw="<?= date('Y-m-d', strtotime($row['expires_at'])) ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                        data-attachment="<?= htmlspecialchars($row['attachment'] ?? '') ?>"
                                        data-created_at="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>"
                                        data-toggle="modal" data-target="#viewNoticeModal">
                                        <i class="ti-eye"></i>
                                    </button>

                                    <!-- Change Status Button -->
                                    <button type="button" class="btn btn-info btn-sm open-status-modal mr-1 shadow-sm" title="Change Status"
                                        data-id="<?= $row['id'] ?>"
                                        data-status="<?= strtolower($row['status']) ?>"
                                        data-title="<?= htmlspecialchars($row['title']) ?>"
                                        data-expires_at="<?= date('Y-m-d', strtotime($row['expires_at'])) ?>">
                                        <i class="ti-exchange-vertical"></i>
                                    </button>

                                    <!-- Edit Button -->
                                    <button class="btn btn-primary btn-sm edit-btn mr-1 shadow-sm" title="Edit" 
                                        data-id="<?= $row['id'] ?>"
                                        data-title="<?= htmlspecialchars($row['title']) ?>"
                                        data-description="<?= htmlspecialchars($row['description']) ?>"
                                        data-publish_at="<?= date('Y-m-d', strtotime($row['publish_at'])) ?>"
                                        data-expires_at="<?= date('Y-m-d', strtotime($row['expires_at'])) ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                        data-attachment="<?= htmlspecialchars($row['attachment']) ?>"
                                        data-toggle="modal" data-target="#editModal"><i class="ti-pencil"></i></button>

                                    <!-- Delete Button -->
                                    <a href="javascript:void(0);" data-url="notices.php?delete=<?= $row['id'] ?><?= ($status_filter !== 'all') ? '&status=' . urlencode($status_filter) : '' ?>" class="btn btn-danger btn-sm delete-btn shadow-sm" title="Delete"><i class="ti-trash"></i></a>
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
        <div class="modal-footer bg-white border-top d-flex justify-content-between">
          <button type="button" class="btn btn-outline-info btn-sm px-3" id="view_modal_status_btn">
            <i class="ti-exchange-vertical mr-1"></i> Change Status
          </button>
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
                <label>Status <span class="text-danger">*</span></label>
                <select name="status" id="add_status" class="form-control">
                    <option value="">Select Status</option>
                    <option value="published">Published</option>
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
                <label>Status <span class="text-danger">*</span></label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="">Select Status</option>
                    <option value="published">Published</option>
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

<!-- Change Notice Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 480px;">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      <form method="POST" action="" id="statusChangeForm">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="id" id="status_notice_id">
        <div class="modal-header bg-light py-3">
          <h5 class="modal-title font-weight-bold text-dark">
            <i class="ti-exchange-vertical mr-2 text-primary"></i>Change Notice Status
          </h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body p-4">
          <div class="mb-3 p-3 bg-light rounded-12 border">
            <small class="text-muted d-block text-uppercase font-weight-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Selected Notice</small>
            <h6 class="font-weight-bold text-dark mb-1 mt-1" id="status_notice_title"></h6>
            <div class="small text-muted">Current status: <span id="status_current_badge"></span></div>
          </div>

          <label class="font-weight-bold text-dark mb-2">Select New Status <span class="text-danger">*</span></label>
          <div class="status-option-cards mb-3">
            <!-- Published Option -->
            <label class="status-radio-card d-flex align-items-center p-3 mb-2 rounded border" style="cursor: pointer;">
              <input type="radio" name="status" value="published" id="status_radio_published" class="mr-3">
              <div class="flex-grow-1">
                <div class="d-flex align-items-center">
                  <span class="badge badge-success mr-2 py-1 px-2"><i class="ti-check mr-1"></i>Published</span>
                  <small class="font-weight-bold text-dark">Live on Student Portal</small>
                </div>
                <small class="text-muted d-block mt-1">Visible to all hostel students immediately.</small>
              </div>
            </label>

            <!-- Draft Option -->
            <label class="status-radio-card d-flex align-items-center p-3 mb-2 rounded border" style="cursor: pointer;">
              <input type="radio" name="status" value="draft" id="status_radio_draft" class="mr-3">
              <div class="flex-grow-1">
                <div class="d-flex align-items-center">
                  <span class="badge badge-secondary mr-2 py-1 px-2"><i class="ti-pencil-alt mr-1"></i>Draft</span>
                  <small class="font-weight-bold text-dark">Saved as Draft</small>
                </div>
                <small class="text-muted d-block mt-1">Hidden from students. Can be published anytime.</small>
              </div>
            </label>

            <!-- Expired Option -->
            <label class="status-radio-card d-flex align-items-center p-3 rounded border" style="cursor: pointer;">
              <input type="radio" name="status" value="expired" id="status_radio_expired" class="mr-3">
              <div class="flex-grow-1">
                <div class="d-flex align-items-center">
                  <span class="badge badge-danger mr-2 py-1 px-2"><i class="ti-time mr-1"></i>Expired</span>
                  <small class="font-weight-bold text-dark">Expired / Archived</small>
                </div>
                <small class="text-muted d-block mt-1">Archived notice. Students will no longer see this notice.</small>
              </div>
            </label>
          </div>

          <div class="form-group mb-0" id="status_expiry_group">
            <label class="small font-weight-bold text-muted mb-1">Expiry Date</label>
            <input type="date" name="expires_at" id="status_expires_at" class="form-control form-control-sm">
            <small class="text-muted font-italic">Extend or adjust expiry date when republishing.</small>
          </div>
        </div>
        <div class="modal-footer bg-light py-2">
          <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4"><i class="ti-check mr-1"></i>Save Status</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Hidden Quick Status Form for 1-Click Dropdown Actions -->
<form method="POST" action="" id="quickStatusForm" style="display: none;">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="id" id="quick_status_id">
    <input type="hidden" name="status" id="quick_status_val">
</form>

<style>
.status-filter-pills {
    display: flex;
    gap: 8px;
}
.filter-pill {
    display: inline-flex;
    align-items: center;
    padding: 6px 14px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    text-decoration: none !important;
    transition: all 0.2s ease;
}
.filter-pill:hover {
    background: #f1f5f9;
    color: #0f172a;
    transform: translateY(-1px);
}
.filter-pill.active.all-active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
}
.filter-pill.active.pub-active {
    background: #059669;
    color: #ffffff;
    border-color: #059669;
    box-shadow: 0 4px 10px rgba(5, 150, 105, 0.25);
}
.filter-pill.active.draft-active {
    background: #475569;
    color: #ffffff;
    border-color: #475569;
    box-shadow: 0 4px 10px rgba(71, 85, 105, 0.25);
}
.filter-pill.active.exp-active {
    background: #dc2626;
    color: #ffffff;
    border-color: #dc2626;
    box-shadow: 0 4px 10px rgba(220, 38, 38, 0.25);
}
.filter-pill .pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #e2e8f0;
    color: #334155;
    font-size: 0.75rem;
    padding: 1px 7px;
    border-radius: 12px;
    margin-left: 8px;
    min-width: 22px;
}
.filter-pill.active .pill-count {
    background: rgba(255, 255, 255, 0.28);
    color: #ffffff;
}
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
}
.dot-published { background: #10b981; }
.dot-draft { background: #94a3b8; }
.dot-expired { background: #ef4444; }

.status-dropdown-btn {
    border: none !important;
    background: transparent !important;
    padding: 0 !important;
    cursor: pointer;
    box-shadow: none !important;
}
.status-dropdown-btn:hover .badge {
    opacity: 0.9;
    transform: scale(1.03);
}
.status-dropdown-btn::after {
    display: none !important;
}
.custom-swal-popup {
    border-radius: 16px !important;
    padding: 2rem !important;
}
.status-radio-card {
    transition: all 0.2s ease;
}
.status-radio-card:hover {
    background-color: #f8fafc;
    border-color: #cbd5e1;
}
.status-radio-card.active-card {
    border-color: #2563eb !important;
    background-color: #eff6ff !important;
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [[ 0, "asc" ]],
            "pageLength": 15
        });
    }
    // View Modal Event Handler
    $(document).on('click', '.view-notice-btn', function() {
        var title = $(this).data('title');
        var description = $(this).data('description');
        var publishAt = $(this).data('publish_at');
        var expiresAt = $(this).data('expires_at');
        var expiresAtRaw = $(this).data('expires_at_raw');
        var status = $(this).data('status');
        var attachment = $(this).data('attachment');
        var createdAt = $(this).data('created_at');
        var id = $(this).data('id');

        $('#view_notice_title').text(title);
        $('#view_notice_description').text(description);
        $('#view_notice_publish_date').text(publishAt);
        $('#view_notice_expire_date').text(expiresAt);
        $('#view_notice_created_at').text(createdAt);

        // Bind data to Change Status button in View Modal
        $('#view_modal_status_btn').data('id', id);
        $('#view_modal_status_btn').data('title', title);
        $('#view_modal_status_btn').data('status', status);
        $('#view_modal_status_btn').data('expires_at', expiresAtRaw);

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
        var publishAt = $(this).data('publish_at');
        var expiresAt = $(this).data('expires_at');
        var status = $(this).data('status');
        var attachment = $(this).data('attachment');

        $('#edit_id').val(id);
        $('#edit_title').val(title);
        $('#edit_description').val(description);
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
        $('#edit_status_err').text('');
        $('#edit_publish_at_err').text('');
        $('#edit_expires_at_err').text('');
        $('#edit_description_err').text('');
        $('#edit_attachment_err').text('');
    });

    // Clear add form errors on modal open
    $('#addModal').on('show.bs.modal', function () {
        $('#add_title_err').text('');
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

    // Open Change Status Modal
    $(document).on('click', '.open-status-modal', function() {
        var id = $(this).data('id');
        var status = ($(this).data('status') || 'published').toLowerCase();
        var title = $(this).data('title');
        var expiresAt = $(this).data('expires_at');

        $('#status_notice_id').val(id);
        $('#status_notice_title').text(title);
        $('#status_expires_at').val(expiresAt);

        if (status === 'published') {
            $('#status_current_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Published</span>');
            $('#status_radio_published').prop('checked', true);
        } else if (status === 'draft') {
            $('#status_current_badge').html('<span class="badge badge-secondary"><i class="ti-pencil-alt mr-1"></i>Draft</span>');
            $('#status_radio_draft').prop('checked', true);
        } else {
            $('#status_current_badge').html('<span class="badge badge-danger"><i class="ti-time mr-1"></i>Expired</span>');
            $('#status_radio_expired').prop('checked', true);
        }

        updateRadioCardStyles();
        $('#statusModal').modal('show');
    });

    // Change status from inside View Notice Modal
    $(document).on('click', '#view_modal_status_btn', function() {
        var id = $(this).data('id');
        var title = $(this).data('title');
        var status = $(this).data('status');
        var expiresAt = $(this).data('expires_at');

        $('#viewNoticeModal').modal('hide');
        setTimeout(function() {
            $('#status_notice_id').val(id);
            $('#status_notice_title').text(title);
            $('#status_expires_at').val(expiresAt);

            var s = (status || 'published').toLowerCase();
            if (s === 'published') {
                $('#status_current_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Published</span>');
                $('#status_radio_published').prop('checked', true);
            } else if (s === 'draft') {
                $('#status_current_badge').html('<span class="badge badge-secondary"><i class="ti-pencil-alt mr-1"></i>Draft</span>');
                $('#status_radio_draft').prop('checked', true);
            } else {
                $('#status_current_badge').html('<span class="badge badge-danger"><i class="ti-time mr-1"></i>Expired</span>');
                $('#status_radio_expired').prop('checked', true);
            }

            updateRadioCardStyles();
            $('#statusModal').modal('show');
        }, 400);
    });

    // Update style on status radio selection
    $(document).on('change', 'input[name="status"]', function() {
        updateRadioCardStyles();
        var selected = $(this).val();
        if (selected === 'published') {
            var expVal = $('#status_expires_at').val();
            // If date is empty or in the past, default to today + 30 days
            var today = new Date().toISOString().split('T')[0];
            if (!expVal || expVal < today) {
                var futureDate = new Date();
                futureDate.setDate(futureDate.getDate() + 30);
                $('#status_expires_at').val(futureDate.toISOString().split('T')[0]);
            }
        }
    });

    function updateRadioCardStyles() {
        $('.status-radio-card').removeClass('active-card');
        var checked = $('input[name="status"]:checked');
        if (checked.length) {
            checked.closest('.status-radio-card').addClass('active-card');
        }
    }

    // 1-Click Quick Status Change from Dropdown
    $(document).on('click', '.quick-status-change', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var newStatus = $(this).data('status');
        var title = $(this).data('title');
        var statusLabel = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);

        var confirmText = "Notice will be marked as " + statusLabel + ".";
        if (newStatus === 'published') {
            confirmText = "This notice will become live and visible to all students immediately.";
        } else if (newStatus === 'draft') {
            confirmText = "This notice will be hidden from students as a draft.";
        } else if (newStatus === 'expired') {
            confirmText = "This notice will be archived as expired and hidden from students.";
        }

        Swal.fire({
            title: 'Set status to ' + statusLabel + '?',
            text: confirmText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: newStatus === 'published' ? '#059669' : (newStatus === 'expired' ? '#dc2626' : '#475569'),
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, change to ' + statusLabel,
            customClass: {
                popup: 'custom-swal-popup'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $('#quick_status_id').val(id);
                $('#quick_status_val').val(newStatus);
                $('#quickStatusForm').submit();
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
