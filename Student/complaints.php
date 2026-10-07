<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$student_id = intval($_SESSION['student_id']);

// Auto-create complaints table if it doesn't exist yet
$create_table_sql = "CREATE TABLE IF NOT EXISTS `complaints` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `priority` VARCHAR(50) NOT NULL DEFAULT 'medium',
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `assigned_to` VARCHAR(100) DEFAULT NULL,
    `response` TEXT DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_student` (`student_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($create_table_sql);
@$conn->query("ALTER TABLE `complaints` MODIFY COLUMN `image` VARCHAR(255) NULL DEFAULT NULL;");

// Handle File Upload Helper
function handleComplaintImageUpload($file_input_name) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES[$file_input_name]['tmp_name'];
        $file_name = $_FILES[$file_input_name]['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array($file_ext, $allowed_exts)) {
            $upload_dir = '../uploads/complaints/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $new_file_name = 'complaint_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            $destination = $upload_dir . $new_file_name;
            if (move_uploaded_file($file_tmp, $destination)) {
                return $new_file_name;
            }
        }
    }
    return null;
}

// Handle Add Complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_complaint'])) {
    $title       = isset($_POST['title']) ? trim($_POST['title']) : '';
    $priority    = isset($_POST['priority']) ? trim($_POST['priority']) : 'medium';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $allowed_priorities)) {
        $priority = 'medium';
    }

    if (empty($title) || empty($description)) {
        $_SESSION['error'] = "Please fill in all required fields marked with *.";
    } else {
        $uploaded_image = handleComplaintImageUpload('image');
        $image_name = ($uploaded_image !== null) ? $uploaded_image : '';

        $stmt = $conn->prepare("INSERT INTO `complaints` (`student_id`, `title`, `description`, `image`, `priority`, `status`) VALUES (?, ?, ?, ?, ?, 'pending')");
        if ($stmt) {
            $stmt->bind_param("issss", $student_id, $title, $description, $image_name, $priority);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Complaint '{$title}' registered successfully with " . strtoupper($priority) . " priority!";
            } else {
                $_SESSION['error'] = "Failed to register complaint: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    }
    header("Location: complaints.php");
    exit();
}

// Handle Edit Complaint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_complaint'])) {
    $complaint_id = isset($_POST['complaint_id']) ? intval($_POST['complaint_id']) : 0;
    $title        = isset($_POST['title']) ? trim($_POST['title']) : '';
    $priority     = isset($_POST['priority']) ? trim($_POST['priority']) : 'medium';
    $description  = isset($_POST['description']) ? trim($_POST['description']) : '';

    $allowed_priorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $allowed_priorities)) {
        $priority = 'medium';
    }

    if ($complaint_id <= 0 || empty($title) || empty($description)) {
        $_SESSION['error'] = "Please fill in all required fields marked with *.";
    } else {
        // Verify complaint belongs to student and status is pending
        $check_stmt = $conn->prepare("SELECT `status`, `image` FROM `complaints` WHERE `id` = ? AND `student_id` = ? LIMIT 1");
        $check_stmt->bind_param("ii", $complaint_id, $student_id);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();

        if ($check_res && $check_res->num_rows > 0) {
            $existing_row = $check_res->fetch_assoc();
            if ($existing_row['status'] !== 'pending') {
                $_SESSION['error'] = "Only Pending complaints can be modified.";
            } else {
                $new_image = handleComplaintImageUpload('image');
                $final_image = ($new_image !== null) ? $new_image : ($existing_row['image'] ?? '');

                $stmt = $conn->prepare("UPDATE `complaints` SET `title` = ?, `description` = ?, `priority` = ?, `image` = ? WHERE `id` = ? AND `student_id` = ?");
                if ($stmt) {
                    $stmt->bind_param("ssssii", $title, $description, $priority, $final_image, $complaint_id, $student_id);
                    if ($stmt->execute()) {
                        $_SESSION['msg'] = "Complaint updated successfully!";
                    } else {
                        $_SESSION['error'] = "Failed to update complaint: " . $conn->error;
                    }
                    $stmt->close();
                }
            }
        } else {
            $_SESSION['error'] = "Complaint record not found.";
        }
        $check_stmt->close();
    }
    header("Location: complaints.php");
    exit();
}

// Handle Filters
$selected_month    = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$selected_priority = isset($_GET['priority']) ? trim($_GET['priority']) : 'all';
$selected_status   = isset($_GET['status']) ? trim($_GET['status']) : 'all';

$where_clauses = ["`student_id` = {$student_id}"];

if (!empty($selected_month)) {
    $safe_month = $conn->real_escape_string($selected_month);
    $where_clauses[] = "DATE_FORMAT(`created_at`, '%Y-%m') = '{$safe_month}'";
}

if (!empty($selected_priority) && $selected_priority !== 'all') {
    $safe_priority = $conn->real_escape_string($selected_priority);
    $where_clauses[] = "`priority` = '{$safe_priority}'";
}

if (!empty($selected_status) && $selected_status !== 'all') {
    $safe_status = $conn->real_escape_string($selected_status);
    $where_clauses[] = "`status` = '{$safe_status}'";
}

$where_sql = implode(' AND ', $where_clauses);
$complaints_query = $conn->query("SELECT * FROM `complaints` WHERE {$where_sql} ORDER BY `created_at` DESC");

$complaints_records = [];
if ($complaints_query && $complaints_query->num_rows > 0) {
    while ($row = $complaints_query->fetch_assoc()) {
        $complaints_records[] = $row;
    }
}

// Calculate Filtered Statistics for Logged-In Student
$stats_query = $conn->query("SELECT 
    COUNT(*) as total_cnt,
    SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
    SUM(CASE WHEN `priority` IN ('high', 'urgent') THEN 1 ELSE 0 END) as urgent_cnt,
    SUM(CASE WHEN `status` IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved_cnt
    FROM `complaints` WHERE {$where_sql}");

$stats = $stats_query ? $stats_query->fetch_assoc() : [
    'total_cnt' => 0,
    'pending_cnt' => 0,
    'urgent_cnt' => 0,
    'resolved_cnt' => 0
];

$total_cnt    = intval($stats['total_cnt']);
$pending_cnt  = intval($stats['pending_cnt']);
$urgent_cnt   = intval($stats['urgent_cnt']);
$resolved_cnt = intval($stats['resolved_cnt']);

ob_start();
?>

<style>
  /* Complaints Aesthetics (Indigo / Violet Theme) */
  .complaints-hero-card {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    border-radius: 18px;
    color: #ffffff;
    padding: 2rem 2.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 28px rgba(99, 102, 241, 0.22);
    margin-bottom: 2rem;
  }

  .complaints-hero-card::after {
    content: "\f071";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 25px;
    bottom: -20px;
    font-size: 140px;
    color: rgba(255, 255, 255, 0.08);
    pointer-events: none;
  }

  .stat-card-widget {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem;
    height: 100%;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
  }

  .stat-card-widget:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.3);
  }

  .stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    margin-bottom: 0.85rem;
  }

  .stat-icon-total { background: #e0e7ff; color: #4338ca; }
  .stat-icon-pending { background: #fef3c7; color: #d97706; }
  .stat-icon-urgent { background: #fee2e2; color: #dc2626; }
  .stat-icon-resolved { background: #d1fae5; color: #059669; }

  /* Priority & Status Badges */
  .badge-custom {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0.32rem 0.75rem;
    border-radius: 50px;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: capitalize;
  }

  /* Priorities */
  .badge-priority-low { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
  .badge-priority-medium { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
  .badge-priority-high { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
  .badge-priority-urgent { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

  /* Statuses */
  .badge-status-pending { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .badge-status-inprogress { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
  .badge-status-resolved { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
  .badge-status-closed { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

  .filter-control-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1.1rem 1.25rem;
    margin-bottom: 1.75rem;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
  }

  .custom-form-control {
    height: 42px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    color: #1e293b;
    padding: 0.4rem 0.85rem;
    transition: all 0.2s ease;
  }

  .custom-form-control:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
  }

  .btn-student-primary {
    background: #6366f1;
    border-color: #6366f1;
    color: #ffffff;
    font-weight: 600;
    border-radius: 8px;
    height: 42px;
    padding: 0 1.25rem;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }

  .btn-student-primary:hover {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
  }

  #complaintsTable {
    width: 100% !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
  }

  #complaintsTable th, #complaintsTable td {
    padding: 1rem 1.15rem !important;
    vertical-align: middle !important;
  }

  #complaintsTable thead th {
    background-color: #eef2ff !important;
    border-bottom: 2px solid #c7d2fe !important;
    color: #3730a3 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.6px;
    white-space: nowrap !important;
  }

  .thumb-preview {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.08);
  }
</style>

<!-- Top Hero Header Card -->
<div class="complaints-hero-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
    <div>
      <div class="d-flex align-items-center mb-2">
        <span class="badge badge-pill badge-light text-primary font-weight-bold px-3 py-2 mr-2" style="color: #4338ca !important; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
          <i class="fa-solid fa-triangle-exclamation mr-1"></i> Student Portal
        </span>
        <h3 class="mb-0 font-weight-bold text-white">Complaints & Helpdesk</h3>
      </div>
      <p class="text-white-50 mb-0" style="font-size: 0.92rem; max-width: 680px;">
        Raise maintenance, room, electrical, or hostel issues and track real-time resolution status.
      </p>
    </div>
    
    <div class="mt-3 mt-md-0 d-flex align-items-center gap-3">
      <button type="button" class="btn btn-light font-weight-bold shadow-sm rounded-12 px-3 py-2 text-primary" data-toggle="modal" data-target="#addComplaintModal" style="color: #4338ca !important; border: 1px solid #e0e7ff;">
        <i class="fa-solid fa-plus-circle mr-1"></i> Register New Complaint
      </button>
    </div>
  </div>
</div>

<!-- Notification Alerts with 3-Second Auto Dismiss -->
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4 auto-dismiss-alert" role="alert" style="background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7 !important;">
    <i class="fa-solid fa-circle-check mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['msg']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4 auto-dismiss-alert" role="alert" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5 !important;">
    <i class="fa-solid fa-circle-exclamation mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- 4 Key Stat Metrics Cards -->
<div class="row mb-4">
  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Total Complaints</span>
          <h3 class="font-weight-bold text-dark mb-0"><?= $total_cnt ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-total">
          <i class="fa-solid fa-clipboard-list"></i>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Pending Action</span>
          <h3 class="font-weight-bold text-warning mb-0"><?= $pending_cnt ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-pending">
          <i class="fa-solid fa-hourglass-half"></i>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Urgent & High</span>
          <h3 class="font-weight-bold text-danger mb-0"><?= $urgent_cnt ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-urgent">
          <i class="fa-solid fa-fire"></i>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-3 col-sm-6">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Resolved Issues</span>
          <h3 class="font-weight-bold text-success mb-0"><?= $resolved_cnt ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-resolved">
          <i class="fa-solid fa-circle-check"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Controls Card -->
<div class="filter-control-card">
  <form method="GET" action="complaints.php" class="form-row align-items-end">
    <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Select Month</label>
      <input type="month" name="month" class="form-control custom-form-control" value="<?= htmlspecialchars($selected_month) ?>">
    </div>

    <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Priority</label>
      <select name="priority" class="form-control custom-form-control">
        <option value="all" <?= ($selected_priority == 'all') ? 'selected' : '' ?>>All Priorities</option>
        <option value="low" <?= ($selected_priority == 'low') ? 'selected' : '' ?>>Low</option>
        <option value="medium" <?= ($selected_priority == 'medium') ? 'selected' : '' ?>>Medium</option>
        <option value="high" <?= ($selected_priority == 'high') ? 'selected' : '' ?>>High</option>
        <option value="urgent" <?= ($selected_priority == 'urgent') ? 'selected' : '' ?>>Urgent</option>
      </select>
    </div>

    <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Status</label>
      <select name="status" class="form-control custom-form-control">
        <option value="all" <?= ($selected_status == 'all') ? 'selected' : '' ?>>All Statuses</option>
        <option value="pending" <?= ($selected_status == 'pending') ? 'selected' : '' ?>>Pending</option>
        <option value="in_progress" <?= ($selected_status == 'in_progress') ? 'selected' : '' ?>>In Progress</option>
        <option value="resolved" <?= ($selected_status == 'resolved') ? 'selected' : '' ?>>Resolved</option>
        <option value="closed" <?= ($selected_status == 'closed') ? 'selected' : '' ?>>Closed</option>
      </select>
    </div>

    <div class="col-md-3 col-sm-12 d-flex gap-2">
      <button type="submit" class="btn btn-student-primary flex-grow-1">
        <i class="fa-solid fa-filter"></i> Apply Filter
      </button>
      <a href="complaints.php" class="btn btn-light border font-weight-600 ml-2" style="height: 42px; display: inline-flex; align-items: center;">
        Reset
      </a>
    </div>
  </form>
</div>

<!-- Complaints Data Table Card -->
<div class="row">
  <div class="col-12">
    <div class="card shadow-sm border-0 rounded-16">
      <div class="card-body p-4">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
          <div>
            <h4 class="card-title font-weight-bold mb-1" style="font-size: 1.2rem; color: #0f172a;">
              <i class="fa-solid fa-list-check text-primary mr-2" style="color: var(--student-primary) !important;"></i>My Complaints Log
            </h4>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
              Track all registered maintenance requests, priorities, and warden responses.
            </p>
          </div>
          
          <button type="button" class="btn btn-student-primary font-weight-bold shadow-sm mt-2 mt-sm-0" data-toggle="modal" data-target="#addComplaintModal">
            <i class="fa-solid fa-plus-circle mr-1"></i> Add Complaint
          </button>
        </div>

        <?php if (empty($complaints_records)): ?>
          <div class="text-center py-5">
            <div class="mb-3">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 75px; height: 75px; background: rgba(99, 102, 241, 0.08); color: #6366f1; font-size: 2rem;">
                <i class="fa-solid fa-clipboard-check"></i>
              </span>
            </div>
            <h5 class="font-weight-bold text-dark mb-1">No Complaints Found</h5>
            <p class="text-muted mb-0" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
              No complaints match your selected month, priority, or status filter.
            </p>
            <button type="button" class="btn btn-student-primary font-weight-bold mt-3" data-toggle="modal" data-target="#addComplaintModal">
              <i class="fa-solid fa-plus-circle mr-1"></i> Register New Complaint
            </button>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle datatable" id="complaintsTable">
              <thead class="bg-light text-uppercase font-weight-bold">
                <tr>
                  <th style="width: 50px;" class="text-center">#</th>
                  <th style="width: 60px;" class="text-center">Image</th>
                  <th style="min-width: 180px;">Title</th>
                  <th style="min-width: 110px;">Priority</th>
                  <th style="min-width: 120px;">Status</th>
                  <th style="min-width: 140px;">Assigned To</th>
                  <th style="min-width: 130px;">Date Raised</th>
                  <th style="width: 100px;" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sl = 1;
                foreach ($complaints_records as $row): 
                  $created_date_fmt = date('d M, Y', strtotime($row['created_at']));
                  $created_time_fmt = date('h:i A', strtotime($row['created_at']));
                  $prio_val         = strtolower($row['priority']);
                  $status_val       = strtolower($row['status']);
                ?>
                  <tr>
                    <td class="font-weight-600 text-muted text-center"><?= $sl++ ?></td>
                    
                    <td class="text-center">
                      <?php if (!empty($row['image']) && file_exists('../uploads/complaints/' . $row['image'])): ?>
                        <a href="../uploads/complaints/<?= htmlspecialchars($row['image']) ?>" target="_blank">
                          <img src="../uploads/complaints/<?= htmlspecialchars($row['image']) ?>" alt="Proof" class="thumb-preview">
                        </a>
                      <?php else: ?>
                        <span class="text-muted font-italic" style="font-size: 0.78rem;">No img</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <span class="font-weight-700 text-dark d-block"><?= htmlspecialchars($row['title']) ?></span>
                      <small class="text-muted d-block text-truncate" style="max-width: 260px;"><?= htmlspecialchars($row['description']) ?></small>
                    </td>

                    <td>
                      <?php if ($prio_val === 'urgent'): ?>
                        <span class="badge-custom badge-priority-urgent">
                          <i class="fa-solid fa-fire"></i> Urgent
                        </span>
                      <?php elseif ($prio_val === 'high'): ?>
                        <span class="badge-custom badge-priority-high">
                          <i class="fa-solid fa-angles-up"></i> High
                        </span>
                      <?php elseif ($prio_val === 'low'): ?>
                        <span class="badge-custom badge-priority-low">
                          <i class="fa-solid fa-angle-down"></i> Low
                        </span>
                      <?php else: ?>
                        <span class="badge-custom badge-priority-medium">
                          <i class="fa-solid fa-minus"></i> Medium
                        </span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ($status_val === 'pending'): ?>
                        <span class="badge-custom badge-status-pending">
                          <i class="fa-solid fa-hourglass-half"></i> Pending
                        </span>
                      <?php elseif ($status_val === 'in_progress'): ?>
                        <span class="badge-custom badge-status-inprogress">
                          <i class="fa-solid fa-gears"></i> In Progress
                        </span>
                      <?php elseif ($status_val === 'resolved'): ?>
                        <span class="badge-custom badge-status-resolved">
                          <i class="fa-solid fa-circle-check"></i> Resolved
                        </span>
                      <?php else: ?>
                        <span class="badge-custom badge-status-closed">
                          <i class="fa-solid fa-lock"></i> Closed
                        </span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <span class="font-weight-600 text-dark" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-user-gear text-muted mr-1"></i>
                        <?= htmlspecialchars($row['assigned_to'] ?? 'Unassigned') ?>
                      </span>
                    </td>

                    <td>
                      <span class="font-weight-600 text-dark d-block"><?= $created_date_fmt ?></span>
                      <small class="text-muted"><?= $created_time_fmt ?></small>
                    </td>

                    <td class="text-center">
                      <?php if ($status_val === 'pending'): ?>
                        <button type="button" class="btn btn-sm btn-light border text-warning" onclick='editComplaint(<?= json_encode($row) ?>)' title="Edit Complaint">
                          <i class="fa-solid fa-pen-to-square"></i> Edit
                        </button>
                      <?php else: ?>
                        <span class="text-muted font-italic" style="font-size: 0.8rem;">-</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- ADD COMPLAINT MODAL                        -->
<!-- ========================================== -->
<div class="modal fade" id="addComplaintModal" tabindex="-1" role="dialog" aria-labelledby="addComplaintModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      
      <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
        <div class="d-flex align-items-center">
          <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
            <i class="fa-solid fa-plus"></i>
          </div>
          <div>
            <h5 class="modal-title font-weight-bold mb-0 text-white" id="addComplaintModalLabel">
              Register New Complaint
            </h5>
            <small class="text-white-50">Submit maintenance or hostel issues to warden</small>
          </div>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="complaints.php" enctype="multipart/form-data" id="addComplaintForm" onsubmit="return validateAddComplaintForm()">
        <div class="modal-body p-4" style="background: #f8fafc;">
          <div class="row">
            
            <!-- Complaint Title -->
            <div class="col-md-8 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Complaint Subject / Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="add_title" class="form-control custom-form-control" placeholder="e.g. Fan not working in Room 204">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_title_err"></small>
            </div>

            <!-- Priority -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Priority Level <span class="text-danger">*</span></label>
              <select name="priority" id="add_priority" class="form-control custom-form-control">
                <option value="">Select Priority</option>
                <option value="low">Low Priority</option>
                <option value="medium" selected>Medium Priority</option>
                <option value="high">High Priority</option>
                <option value="urgent">Urgent</option>
              </select>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_priority_err"></small>
            </div>

            <!-- Description -->
            <div class="col-12 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Issue Description <span class="text-danger">*</span></label>
              <textarea name="description" id="add_description" class="form-control" rows="4" style="border-radius: 8px; font-size: 0.88rem;" placeholder="Detailed description of the issue..."></textarea>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_description_err"></small>
            </div>

            <!-- Image Upload (Optional) -->
            <div class="col-12 form-group mb-0">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Attach Photo / Proof (Optional)</label>
              <div class="custom-file">
                <input type="file" name="image" class="custom-file-input" id="addComplaintImage" accept="image/*">
                <label class="custom-file-label" for="addComplaintImage">Choose image file...</label>
              </div>
              <small class="text-muted">Allowed formats: JPG, PNG, WEBP, GIF (Max 5MB)</small>
            </div>

          </div>
        </div>

        <div class="modal-footer bg-white border-top py-3 px-4">
          <button type="button" class="btn btn-light border font-weight-500 rounded-10 px-4" data-dismiss="modal">Cancel</button>
          <button type="submit" name="add_complaint" class="btn btn-student-primary font-weight-600 rounded-10 px-4">
            <i class="fa-solid fa-paper-plane mr-1"></i> Submit Complaint
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- EDIT COMPLAINT MODAL                       -->
<!-- ========================================== -->
<div class="modal fade" id="editComplaintModal" tabindex="-1" role="dialog" aria-labelledby="editComplaintModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      
      <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
        <div class="d-flex align-items-center">
          <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
            <i class="fa-solid fa-pen-to-square"></i>
          </div>
          <div>
            <h5 class="modal-title font-weight-bold mb-0 text-white" id="editComplaintModalLabel">
              Edit Complaint
            </h5>
            <small class="text-white-50">Modify pending complaint details</small>
          </div>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="complaints.php" enctype="multipart/form-data" id="editComplaintForm" onsubmit="return validateEditComplaintForm()">
        <input type="hidden" name="complaint_id" id="edit_complaint_id">

        <div class="modal-body p-4" style="background: #f8fafc;">
          <div class="row">
            
            <!-- Complaint Title -->
            <div class="col-md-8 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Complaint Subject / Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="edit_title" class="form-control custom-form-control">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_title_err"></small>
            </div>

            <!-- Priority -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Priority Level <span class="text-danger">*</span></label>
              <select name="priority" id="edit_priority" class="form-control custom-form-control">
                <option value="">Select Priority</option>
                <option value="low">Low Priority</option>
                <option value="medium">Medium Priority</option>
                <option value="high">High Priority</option>
                <option value="urgent">Urgent</option>
              </select>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_priority_err"></small>
            </div>

            <!-- Description -->
            <div class="col-12 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Issue Description <span class="text-danger">*</span></label>
              <textarea name="description" id="edit_description" class="form-control" rows="4" style="border-radius: 8px; font-size: 0.88rem;"></textarea>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_description_err"></small>
            </div>

            <!-- Image Upload (Optional) -->
            <div class="col-12 form-group mb-0">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Change Photo / Proof (Optional)</label>
              <div class="custom-file">
                <input type="file" name="image" class="custom-file-input" id="editComplaintImage" accept="image/*">
                <label class="custom-file-label" for="editComplaintImage">Choose new image file...</label>
              </div>
              <small class="text-muted">Leave blank to keep existing image</small>
            </div>

          </div>
        </div>

        <div class="modal-footer bg-white border-top py-3 px-4">
          <button type="button" class="btn btn-light border font-weight-500 rounded-10 px-4" data-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_complaint" class="btn btn-student-primary font-weight-600 rounded-10 px-4">
            <i class="fa-solid fa-floppy-disk mr-1"></i> Update Complaint
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<script>
function validateAddComplaintForm() {
    let isValid = true;

    document.getElementById('add_title_err').innerText = '';
    document.getElementById('add_priority_err').innerText = '';
    document.getElementById('add_description_err').innerText = '';

    const title = document.getElementById('add_title').value.trim();
    const priority = document.getElementById('add_priority').value.trim();
    const description = document.getElementById('add_description').value.trim();

    if (title === '') {
        document.getElementById('add_title_err').innerText = 'Complaint subject/title is required.';
        isValid = false;
    }

    if (priority === '') {
        document.getElementById('add_priority_err').innerText = 'Please select a priority level.';
        isValid = false;
    }

    if (description === '') {
        document.getElementById('add_description_err').innerText = 'Issue description is required.';
        isValid = false;
    }

    return isValid;
}

function validateEditComplaintForm() {
    let isValid = true;

    document.getElementById('edit_title_err').innerText = '';
    document.getElementById('edit_priority_err').innerText = '';
    document.getElementById('edit_description_err').innerText = '';

    const title = document.getElementById('edit_title').value.trim();
    const priority = document.getElementById('edit_priority').value.trim();
    const description = document.getElementById('edit_description').value.trim();

    if (title === '') {
        document.getElementById('edit_title_err').innerText = 'Complaint subject/title is required.';
        isValid = false;
    }

    if (priority === '') {
        document.getElementById('edit_priority_err').innerText = 'Please select a priority level.';
        isValid = false;
    }

    if (description === '') {
        document.getElementById('edit_description_err').innerText = 'Issue description is required.';
        isValid = false;
    }

    return isValid;
}

function editComplaint(data) {
    document.getElementById('edit_title_err').innerText = '';
    document.getElementById('edit_priority_err').innerText = '';
    document.getElementById('edit_description_err').innerText = '';

    document.getElementById('edit_complaint_id').value = data.id;
    document.getElementById('edit_title').value = data.title;
    document.getElementById('edit_priority').value = data.priority.toLowerCase();
    document.getElementById('edit_description').value = data.description;

    $('#editComplaintModal').modal('show');
}

// Clear real-time errors as user types/changes fields
document.addEventListener("input", function(e) {
    if (e.target.id === 'add_title') document.getElementById('add_title_err').innerText = '';
    if (e.target.id === 'add_description') document.getElementById('add_description_err').innerText = '';
    if (e.target.id === 'edit_title') document.getElementById('edit_title_err').innerText = '';
    if (e.target.id === 'edit_description') document.getElementById('edit_description_err').innerText = '';
});

document.addEventListener("change", function(e) {
    if (e.target.id === 'add_priority') document.getElementById('add_priority_err').innerText = '';
    if (e.target.id === 'edit_priority') document.getElementById('edit_priority_err').innerText = '';

    if (e.target && e.target.classList.contains('custom-file-input')) {
        var fileName = e.target.files[0] ? e.target.files[0].name : "Choose file...";
        var label = e.target.nextElementSibling;
        if (label && label.classList.contains('custom-file-label')) {
            label.innerText = fileName;
        }
    }
});

document.addEventListener("DOMContentLoaded", function() {
    // Auto-dismiss notification alerts after 3 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.auto-dismiss-alert');
        alerts.forEach(function(alertEl) {
            if (typeof $ !== 'undefined' && $.fn.fadeOut) {
                $(alertEl).fadeOut(500, function() {
                    $(this).remove();
                });
            } else {
                alertEl.style.transition = "opacity 0.5s ease";
                alertEl.style.opacity = "0";
                setTimeout(function() { alertEl.remove(); }, 500);
            }
        });
    }, 3000);

    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [],
            "paging": false,
            "info": false,
            "searching": false,
            "columnDefs": [
                { "orderable": false, "targets": [1, 7] }
            ]
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
