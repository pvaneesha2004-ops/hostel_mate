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

// Auto-create visitors table if it doesn't exist yet
$create_table_sql = "CREATE TABLE IF NOT EXISTS `visitors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `relationship` VARCHAR(50) NOT NULL,
    `id_proof_type` VARCHAR(50) DEFAULT NULL,
    `id_proof_number` VARCHAR(50) DEFAULT NULL,
    `visit_date` DATE NOT NULL,
    `expected_in_time` TIME NOT NULL,
    `expected_out_time` TIME DEFAULT NULL,
    `purpose` TEXT NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
    `approved_by` VARCHAR(100) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_student` (`student_id`),
    INDEX `idx_visit_date` (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($create_table_sql);

// Handle Add Visitor Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_visitor'])) {
    $name              = isset($_POST['name']) ? trim($_POST['name']) : '';
    $phone             = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $relationship      = isset($_POST['relationship']) ? trim($_POST['relationship']) : '';
    $id_proof_type     = isset($_POST['id_proof_type']) ? trim($_POST['id_proof_type']) : '';
    $id_proof_number   = isset($_POST['id_proof_number']) ? trim($_POST['id_proof_number']) : '';
    $visit_date        = isset($_POST['visit_date']) ? trim($_POST['visit_date']) : '';
    $expected_in_time  = isset($_POST['expected_in_time']) ? trim($_POST['expected_in_time']) : '';
    $expected_out_time = isset($_POST['expected_out_time']) ? trim($_POST['expected_out_time']) : '';
    $purpose           = isset($_POST['purpose']) ? trim($_POST['purpose']) : '';

    if (empty($name) || empty($phone) || empty($relationship) || empty($visit_date) || empty($expected_in_time) || empty($purpose)) {
        $_SESSION['error'] = "Please fill in all required fields marked with *.";
    } else {
        $stmt = $conn->prepare("INSERT INTO `visitors` (`student_id`, `name`, `phone`, `relationship`, `id_proof_type`, `id_proof_number`, `visit_date`, `expected_in_time`, `expected_out_time`, `purpose`, `status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
        if ($stmt) {
            $stmt->bind_param("isssssssss", $student_id, $name, $phone, $relationship, $id_proof_type, $id_proof_number, $visit_date, $expected_in_time, $expected_out_time, $purpose);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Visitor request for '{$name}' submitted successfully and pending approval!";
            } else {
                $_SESSION['error'] = "Failed to submit visitor request: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    }
    header("Location: visitors.php");
    exit();
}

// Handle Edit Visitor Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_visitor'])) {
    $visitor_id        = isset($_POST['visitor_id']) ? intval($_POST['visitor_id']) : 0;
    $name              = isset($_POST['name']) ? trim($_POST['name']) : '';
    $phone             = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $relationship      = isset($_POST['relationship']) ? trim($_POST['relationship']) : '';
    $id_proof_type     = isset($_POST['id_proof_type']) ? trim($_POST['id_proof_type']) : '';
    $id_proof_number   = isset($_POST['id_proof_number']) ? trim($_POST['id_proof_number']) : '';
    $visit_date        = isset($_POST['visit_date']) ? trim($_POST['visit_date']) : '';
    $expected_in_time  = isset($_POST['expected_in_time']) ? trim($_POST['expected_in_time']) : '';
    $expected_out_time = isset($_POST['expected_out_time']) ? trim($_POST['expected_out_time']) : '';
    $purpose           = isset($_POST['purpose']) ? trim($_POST['purpose']) : '';

    if ($visitor_id <= 0 || empty($name) || empty($phone) || empty($relationship) || empty($visit_date) || empty($expected_in_time) || empty($purpose)) {
        $_SESSION['error'] = "Please fill in all required fields marked with *.";
    } else {
        // Verify visitor record belongs to logged in student and status is Pending
        $check_stmt = $conn->prepare("SELECT `status` FROM `visitors` WHERE `id` = ? AND `student_id` = ? LIMIT 1");
        $check_stmt->bind_param("ii", $visitor_id, $student_id);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();

        if ($check_res && $check_res->num_rows > 0) {
            $row_check = $check_res->fetch_assoc();
            if ($row_check['status'] !== 'Pending') {
                $_SESSION['error'] = "Only Pending visitor requests can be modified.";
            } else {
                $stmt = $conn->prepare("UPDATE `visitors` SET `name` = ?, `phone` = ?, `relationship` = ?, `id_proof_type` = ?, `id_proof_number` = ?, `visit_date` = ?, `expected_in_time` = ?, `expected_out_time` = ?, `purpose` = ? WHERE `id` = ? AND `student_id` = ?");
                if ($stmt) {
                    $stmt->bind_param("sssssssssii", $name, $phone, $relationship, $id_proof_type, $id_proof_number, $visit_date, $expected_in_time, $expected_out_time, $purpose, $visitor_id, $student_id);
                    if ($stmt->execute()) {
                        $_SESSION['msg'] = "Visitor request for '{$name}' updated successfully!";
                    } else {
                        $_SESSION['error'] = "Failed to update visitor request: " . $conn->error;
                    }
                    $stmt->close();
                }
            }
        } else {
            $_SESSION['error'] = "Visitor record not found.";
        }
        $check_stmt->close();
    }
    header("Location: visitors.php");
    exit();
}

// Handle Status Filter & Month Filter
$selected_month  = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$selected_status = isset($_GET['status']) ? trim($_GET['status']) : 'all';

$where_clauses = ["`student_id` = {$student_id}"];

if (!empty($selected_month)) {
    $safe_month = $conn->real_escape_string($selected_month);
    $where_clauses[] = "DATE_FORMAT(`visit_date`, '%Y-%m') = '{$safe_month}'";
}

if (!empty($selected_status) && $selected_status !== 'all') {
    $safe_status = $conn->real_escape_string($selected_status);
    $where_clauses[] = "`status` = '{$safe_status}'";
}

$where_sql = implode(' AND ', $where_clauses);
$visitors_query = $conn->query("SELECT * FROM `visitors` WHERE {$where_sql} ORDER BY `visit_date` DESC, `created_at` DESC");

$visitors_records = [];
if ($visitors_query && $visitors_query->num_rows > 0) {
    while ($row = $visitors_query->fetch_assoc()) {
        $visitors_records[] = $row;
    }
}

// Calculate Filtered Statistics for Logged-In Student
$stats_query = $conn->query("SELECT 
    COUNT(*) as total_requests,
    SUM(CASE WHEN `status` = 'Pending' THEN 1 ELSE 0 END) as pending_cnt,
    SUM(CASE WHEN `status` = 'Approved' THEN 1 ELSE 0 END) as approved_cnt,
    SUM(CASE WHEN `status` = 'Rejected' THEN 1 ELSE 0 END) as rejected_cnt,
    SUM(CASE WHEN `status` = 'Completed' THEN 1 ELSE 0 END) as completed_cnt
    FROM `visitors` WHERE {$where_sql}");

$stats = $stats_query ? $stats_query->fetch_assoc() : [
    'total_requests' => 0,
    'pending_cnt' => 0,
    'approved_cnt' => 0,
    'rejected_cnt' => 0,
    'completed_cnt' => 0
];

$total_requests = intval($stats['total_requests']);
$pending_cnt    = intval($stats['pending_cnt']);
$approved_cnt   = intval($stats['approved_cnt']);
$rejected_cnt   = intval($stats['rejected_cnt']);
$completed_cnt  = intval($stats['completed_cnt']);

ob_start();
?>

<style>
  /* Visitors Aesthetics (Indigo / Violet Theme) */
  .visitors-hero-card {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    border-radius: 18px;
    color: #ffffff;
    padding: 2rem 2.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 28px rgba(99, 102, 241, 0.22);
    margin-bottom: 2rem;
  }

  .visitors-hero-card::after {
    content: "\f500";
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
  .stat-icon-approved { background: #d1fae5; color: #059669; }
  .stat-icon-rejected { background: #fee2e2; color: #dc2626; }

  /* Badges */
  .badge-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.35rem 0.8rem;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.3px;
  }

  .badge-status-pending { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .badge-status-approved { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
  .badge-status-rejected { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  .badge-status-completed { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }

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

  #visitorsTable {
    width: 100% !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
  }

  #visitorsTable th, #visitorsTable td {
    padding: 1rem 1.15rem !important;
    vertical-align: middle !important;
  }

  #visitorsTable thead th {
    background-color: #eef2ff !important;
    border-bottom: 2px solid #c7d2fe !important;
    color: #3730a3 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.6px;
    white-space: nowrap !important;
  }
</style>

<!-- Top Hero Header Card -->
<div class="visitors-hero-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
    <div>
      <div class="d-flex align-items-center mb-2">
        <span class="badge badge-pill badge-light text-primary font-weight-bold px-3 py-2 mr-2" style="color: #4338ca !important; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
          <i class="fa-solid fa-users mr-1"></i> Visitor Management
        </span>
        <h3 class="mb-0 font-weight-bold text-white">Guest & Visitor Pass Log</h3>
      </div>
      <p class="text-white-50 mb-0" style="font-size: 0.92rem; max-width: 680px;">
        Register family or guest visits, check warden approval status, and manage entry passes.
      </p>
    </div>
    
    <div class="mt-3 mt-md-0 d-flex align-items-center gap-3">
      <button type="button" class="btn btn-light font-weight-bold shadow-sm rounded-12 px-3 py-2 text-primary" data-toggle="modal" data-target="#addVisitorModal" style="color: #4338ca !important; border: 1px solid #e0e7ff;">
        <i class="fa-solid fa-user-plus mr-1"></i> Request Visitor Entry
      </button>
    </div>
  </div>
</div>

<!-- Alert Notifications with 3-Second Auto Dismiss -->
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
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Total Requests</span>
          <h3 class="font-weight-bold text-dark mb-0"><?= $total_requests ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-total">
          <i class="fa-solid fa-users-rectangle"></i>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Pending Approval</span>
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
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Approved Passes</span>
          <h3 class="font-weight-bold text-success mb-0"><?= $approved_cnt ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-approved">
          <i class="fa-solid fa-circle-check"></i>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-3 col-sm-6">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Rejected / Completed</span>
          <h3 class="font-weight-bold text-secondary mb-0"><?= ($rejected_cnt + $completed_cnt) ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-rejected">
          <i class="fa-solid fa-user-xmark"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Controls Card -->
<div class="filter-control-card">
  <form method="GET" action="visitors.php" class="form-row align-items-end">
    <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Select Month</label>
      <input type="month" name="month" class="form-control custom-form-control" value="<?= htmlspecialchars($selected_month) ?>">
    </div>

    <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Approval Status</label>
      <select name="status" class="form-control custom-form-control">
        <option value="all" <?= ($selected_status == 'all') ? 'selected' : '' ?>>All Statuses</option>
        <option value="Pending" <?= ($selected_status == 'Pending') ? 'selected' : '' ?>>Pending</option>
        <option value="Approved" <?= ($selected_status == 'Approved') ? 'selected' : '' ?>>Approved</option>
        <option value="Rejected" <?= ($selected_status == 'Rejected') ? 'selected' : '' ?>>Rejected</option>
        <option value="Completed" <?= ($selected_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
      </select>
    </div>

    <div class="col-md-4 col-sm-12 d-flex gap-2">
      <button type="submit" class="btn btn-student-primary flex-grow-1">
        <i class="fa-solid fa-filter"></i> Apply Filter
      </button>
      <a href="visitors.php" class="btn btn-light border font-weight-600 ml-2" style="height: 42px; display: inline-flex; align-items: center;">
        Reset
      </a>
    </div>
  </form>
</div>

<!-- Visitors Data Table Card -->
<div class="row">
  <div class="col-12">
    <div class="card shadow-sm border-0 rounded-16">
      <div class="card-body p-4">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
          <div>
            <h4 class="card-title font-weight-bold mb-1" style="font-size: 1.2rem; color: #0f172a;">
              <i class="fa-solid fa-list-check text-primary mr-2" style="color: var(--student-primary) !important;"></i>My Visitor Requests
            </h4>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
              List of all guest entry passes requested for hostel approval.
            </p>
          </div>
          
          <button type="button" class="btn btn-student-primary font-weight-bold shadow-sm mt-2 mt-sm-0" data-toggle="modal" data-target="#addVisitorModal">
            <i class="fa-solid fa-user-plus mr-1"></i> Add Visitor Request
          </button>
        </div>

        <?php if (empty($visitors_records)): ?>
          <div class="text-center py-5">
            <div class="mb-3">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 75px; height: 75px; background: rgba(99, 102, 241, 0.08); color: #6366f1; font-size: 2rem;">
                <i class="fa-solid fa-users-slash"></i>
              </span>
            </div>
            <h5 class="font-weight-bold text-dark mb-1">No Visitor Records Found</h5>
            <p class="text-muted mb-0" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
              No visitor requests match your selected month or status filter.
            </p>
            <button type="button" class="btn btn-student-primary font-weight-bold mt-3" data-toggle="modal" data-target="#addVisitorModal">
              <i class="fa-solid fa-user-plus mr-1"></i> Add Visitor Request
            </button>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle datatable" id="visitorsTable">
              <thead class="bg-light text-uppercase font-weight-bold">
                <tr>
                  <th style="width: 50px;" class="text-center">#</th>
                  <th style="min-width: 160px;">Visitor Name</th>
                  <th style="min-width: 120px;">Phone</th>
                  <th style="min-width: 120px;">Relationship</th>
                  <th style="min-width: 140px;">Visit Date</th>
                  <th style="min-width: 140px;">Expected Time</th>
                  <th style="min-width: 120px;">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sl = 1;
                foreach ($visitors_records as $row): 
                  $visit_date_fmt = date('d M, Y', strtotime($row['visit_date']));
                  $in_time_fmt    = date('h:i A', strtotime($row['expected_in_time']));
                  $out_time_fmt   = !empty($row['expected_out_time']) ? date('h:i A', strtotime($row['expected_out_time'])) : '-';
                  $status_val     = $row['status'];
                ?>
                  <tr>
                    <td class="font-weight-600 text-muted text-center"><?= $sl++ ?></td>
                    <td>
                      <span class="font-weight-700 text-dark d-block"><?= htmlspecialchars($row['name']) ?></span>
                      <?php if (!empty($row['id_proof_type'])): ?>
                        <small class="text-muted"><?= htmlspecialchars($row['id_proof_type']) ?>: <?= htmlspecialchars($row['id_proof_number'] ?? '-') ?></small>
                      <?php endif; ?>
                    </td>
                    <td><span class="font-weight-500 text-dark"><i class="fa-solid fa-phone text-muted mr-1"></i><?= htmlspecialchars($row['phone']) ?></span></td>
                    <td><span class="badge badge-light border px-2 py-1"><?= htmlspecialchars($row['relationship']) ?></span></td>
                    <td class="font-weight-600 text-dark"><?= $visit_date_fmt ?></td>
                    <td>
                      <small class="text-dark font-weight-600 d-block"><i class="fa-regular fa-clock text-primary mr-1"></i>In: <?= $in_time_fmt ?></small>
                      <small class="text-muted d-block">Out: <?= $out_time_fmt ?></small>
                    </td>
                    
                    <td>
                      <?php if (strcasecmp($status_val, 'Pending') === 0): ?>
                        <span class="badge-status badge-status-pending">
                          <i class="fa-solid fa-hourglass-half"></i> Pending
                        </span>
                      <?php elseif (strcasecmp($status_val, 'Approved') === 0): ?>
                        <span class="badge-status badge-status-approved">
                          <i class="fa-solid fa-circle-check"></i> Approved
                        </span>
                      <?php elseif (strcasecmp($status_val, 'Rejected') === 0): ?>
                        <span class="badge-status badge-status-rejected">
                          <i class="fa-solid fa-circle-xmark"></i> Rejected
                        </span>
                      <?php else: ?>
                        <span class="badge-status badge-status-completed">
                          <i class="fa-solid fa-flag-checkered"></i> Completed
                        </span>
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
<!-- ADD VISITOR MODAL                          -->
<!-- ========================================== -->
<div class="modal fade" id="addVisitorModal" tabindex="-1" role="dialog" aria-labelledby="addVisitorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      
      <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
        <div class="d-flex align-items-center">
          <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
            <i class="fa-solid fa-user-plus"></i>
          </div>
          <div>
            <h5 class="modal-title font-weight-bold mb-0 text-white" id="addVisitorModalLabel">
              Request Visitor Entry
            </h5>
            <small class="text-white-50">Fill in guest details for warden approval</small>
          </div>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="visitors.php" id="addVisitorForm" onsubmit="return validateAddVisitorForm()">
        <div class="modal-body p-4" style="background: #f8fafc;">
          <div class="row">
            
            <!-- Visitor Name -->
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Visitor Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="add_name" class="form-control custom-form-control" placeholder="Full name of guest">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_name_err"></small>
            </div>

            <!-- Phone Number -->
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Phone Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" id="add_phone" class="form-control custom-form-control" placeholder="10-digit mobile number">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_phone_err"></small>
            </div>

            <!-- Relationship -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Relationship <span class="text-danger">*</span></label>
              <select name="relationship" id="add_relationship" class="form-control custom-form-control">
                <option value="">Select Relationship</option>
                <option value="Parent">Parent</option>
                <option value="Father">Father</option>
                <option value="Mother">Mother</option>
                <option value="Sibling">Sibling</option>
                <option value="Relative">Relative</option>
                <option value="Guardian">Guardian</option>
                <option value="Friend">Friend</option>
              </select>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_relationship_err"></small>
            </div>

            <!-- ID Proof Type -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">ID Proof Type</label>
              <select name="id_proof_type" id="add_id_proof_type" class="form-control custom-form-control">
                <option value="">Select ID Type</option>
                <option value="Aadhaar Card">Aadhaar Card</option>
                <option value="PAN Card">PAN Card</option>
                <option value="Driving License">Driving License</option>
                <option value="Voter ID">Voter ID</option>
                <option value="Passport">Passport</option>
              </select>
            </div>

            <!-- ID Proof Number -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">ID Proof Number</label>
              <input type="text" name="id_proof_number" id="add_id_proof_number" class="form-control custom-form-control" placeholder="ID Number">
            </div>

            <!-- Visit Date -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Visit Date <span class="text-danger">*</span></label>
              <input type="date" name="visit_date" id="add_visit_date" class="form-control custom-form-control" value="<?= date('Y-m-d') ?>">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_visit_date_err"></small>
            </div>

            <!-- Expected In Time -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Expected In Time <span class="text-danger">*</span></label>
              <input type="time" name="expected_in_time" id="add_expected_in_time" class="form-control custom-form-control">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_expected_in_time_err"></small>
            </div>

            <!-- Expected Out Time -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Expected Out Time</label>
              <input type="time" name="expected_out_time" id="add_expected_out_time" class="form-control custom-form-control">
            </div>

            <!-- Purpose of Visit -->
            <div class="col-12 form-group mb-0">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Purpose of Visit <span class="text-danger">*</span></label>
              <textarea name="purpose" id="add_purpose" class="form-control" rows="3" style="border-radius: 8px; font-size: 0.88rem;" placeholder="State reason for visit..."></textarea>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="add_purpose_err"></small>
            </div>

          </div>
        </div>

        <div class="modal-footer bg-white border-top py-3 px-4">
          <button type="button" class="btn btn-light border font-weight-500 rounded-10 px-4" data-dismiss="modal">Cancel</button>
          <button type="submit" name="add_visitor" class="btn btn-student-primary font-weight-600 rounded-10 px-4">
            <i class="fa-solid fa-paper-plane mr-1"></i> Submit Request
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- EDIT VISITOR MODAL                         -->
<!-- ========================================== -->
<div class="modal fade" id="editVisitorModal" tabindex="-1" role="dialog" aria-labelledby="editVisitorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      
      <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
        <div class="d-flex align-items-center">
          <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
            <i class="fa-solid fa-pen-to-square"></i>
          </div>
          <div>
            <h5 class="modal-title font-weight-bold mb-0 text-white" id="editVisitorModalLabel">
              Edit Visitor Request
            </h5>
            <small class="text-white-50">Modify pending guest visit details</small>
          </div>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="visitors.php" id="editVisitorForm" onsubmit="return validateEditVisitorForm()">
        <input type="hidden" name="visitor_id" id="edit_visitor_id">

        <div class="modal-body p-4" style="background: #f8fafc;">
          <div class="row">
            
            <!-- Visitor Name -->
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Visitor Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="edit_name" class="form-control custom-form-control">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_name_err"></small>
            </div>

            <!-- Phone Number -->
            <div class="col-md-6 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Phone Number <span class="text-danger">*</span></label>
              <input type="text" name="phone" id="edit_phone" class="form-control custom-form-control">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_phone_err"></small>
            </div>

            <!-- Relationship -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Relationship <span class="text-danger">*</span></label>
              <select name="relationship" id="edit_relationship" class="form-control custom-form-control">
                <option value="">Select Relationship</option>
                <option value="Parent">Parent</option>
                <option value="Father">Father</option>
                <option value="Mother">Mother</option>
                <option value="Sibling">Sibling</option>
                <option value="Relative">Relative</option>
                <option value="Guardian">Guardian</option>
                <option value="Friend">Friend</option>
              </select>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_relationship_err"></small>
            </div>

            <!-- ID Proof Type -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">ID Proof Type</label>
              <select name="id_proof_type" id="edit_id_proof_type" class="form-control custom-form-control">
                <option value="">Select ID Type</option>
                <option value="Aadhaar Card">Aadhaar Card</option>
                <option value="PAN Card">PAN Card</option>
                <option value="Driving License">Driving License</option>
                <option value="Voter ID">Voter ID</option>
                <option value="Passport">Passport</option>
              </select>
            </div>

            <!-- ID Proof Number -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">ID Proof Number</label>
              <input type="text" name="id_proof_number" id="edit_id_proof_number" class="form-control custom-form-control">
            </div>

            <!-- Visit Date -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Visit Date <span class="text-danger">*</span></label>
              <input type="date" name="visit_date" id="edit_visit_date" class="form-control custom-form-control">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_visit_date_err"></small>
            </div>

            <!-- Expected In Time -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Expected In Time <span class="text-danger">*</span></label>
              <input type="time" name="expected_in_time" id="edit_expected_in_time" class="form-control custom-form-control">
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_expected_in_time_err"></small>
            </div>

            <!-- Expected Out Time -->
            <div class="col-md-4 form-group mb-3">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Expected Out Time</label>
              <input type="time" name="expected_out_time" id="edit_expected_out_time" class="form-control custom-form-control">
            </div>

            <!-- Purpose of Visit -->
            <div class="col-12 form-group mb-0">
              <label class="font-weight-600 text-dark" style="font-size: 0.88rem;">Purpose of Visit <span class="text-danger">*</span></label>
              <textarea name="purpose" id="edit_purpose" class="form-control" rows="3" style="border-radius: 8px; font-size: 0.88rem;"></textarea>
              <small class="text-danger error-text font-weight-600 d-block mt-1" id="edit_purpose_err"></small>
            </div>

          </div>
        </div>

        <div class="modal-footer bg-white border-top py-3 px-4">
          <button type="button" class="btn btn-light border font-weight-500 rounded-10 px-4" data-dismiss="modal">Cancel</button>
          <button type="submit" name="edit_visitor" class="btn btn-student-primary font-weight-600 rounded-10 px-4">
            <i class="fa-solid fa-floppy-disk mr-1"></i> Update Changes
          </button>
        </div>
      </form>

    </div>
  </div>
</div>

<!-- ========================================== -->
<!-- VIEW VISITOR DETAILS MODAL                  -->
<!-- ========================================== -->
<div class="modal fade" id="viewVisitorModal" tabindex="-1" role="dialog" aria-labelledby="viewVisitorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      
      <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
        <div class="d-flex align-items-center">
          <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
            <i class="fa-solid fa-address-card"></i>
          </div>
          <div>
            <h5 class="modal-title font-weight-bold mb-0 text-white" id="viewVisitorModalLabel">
              Visitor Pass Details
            </h5>
            <small class="text-white-50">Complete visitor request information</small>
          </div>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body p-4" style="background: #ffffff;">
        <div class="table-responsive">
          <table class="table table-bordered mb-0" style="font-size: 0.9rem;">
            <tr>
              <th style="width: 35%; background: #f8fafc;">Visitor Name</th>
              <td id="view_name" class="font-weight-700 text-dark"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Phone Number</th>
              <td id="view_phone"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Relationship</th>
              <td id="view_relationship"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">ID Proof</th>
              <td id="view_id_proof"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Visit Date</th>
              <td id="view_visit_date" class="font-weight-600"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Timing</th>
              <td id="view_timing"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Purpose</th>
              <td id="view_purpose"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Status</th>
              <td id="view_status"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Approved By</th>
              <td id="view_approved_by"></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Warden Remarks</th>
              <td id="view_remarks"></td>
            </tr>
          </table>
        </div>
      </div>

      <div class="modal-footer bg-light border-top py-2 px-4">
        <button type="button" class="btn btn-secondary font-weight-500 rounded-10 px-4" data-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>

<script>
function validateAddVisitorForm() {
    let isValid = true;

    document.getElementById('add_name_err').innerText = '';
    document.getElementById('add_phone_err').innerText = '';
    document.getElementById('add_relationship_err').innerText = '';
    document.getElementById('add_visit_date_err').innerText = '';
    document.getElementById('add_expected_in_time_err').innerText = '';
    document.getElementById('add_purpose_err').innerText = '';

    const name = document.getElementById('add_name').value.trim();
    const phone = document.getElementById('add_phone').value.trim();
    const relationship = document.getElementById('add_relationship').value.trim();
    const visitDate = document.getElementById('add_visit_date').value.trim();
    const expectedInTime = document.getElementById('add_expected_in_time').value.trim();
    const purpose = document.getElementById('add_purpose').value.trim();

    if (name === '') {
        document.getElementById('add_name_err').innerText = 'Visitor name is required.';
        isValid = false;
    }

    if (phone === '') {
        document.getElementById('add_phone_err').innerText = 'Phone number is required.';
        isValid = false;
    } else if (!/^[0-9]{10}$/.test(phone)) {
        document.getElementById('add_phone_err').innerText = 'Please enter a valid 10-digit phone number.';
        isValid = false;
    }

    if (relationship === '') {
        document.getElementById('add_relationship_err').innerText = 'Please select relationship.';
        isValid = false;
    }

    if (visitDate === '') {
        document.getElementById('add_visit_date_err').innerText = 'Visit date is required.';
        isValid = false;
    }

    if (expectedInTime === '') {
        document.getElementById('add_expected_in_time_err').innerText = 'Expected in time is required.';
        isValid = false;
    }

    if (purpose === '') {
        document.getElementById('add_purpose_err').innerText = 'Purpose of visit is required.';
        isValid = false;
    }

    return isValid;
}

function validateEditVisitorForm() {
    let isValid = true;

    document.getElementById('edit_name_err').innerText = '';
    document.getElementById('edit_phone_err').innerText = '';
    document.getElementById('edit_relationship_err').innerText = '';
    document.getElementById('edit_visit_date_err').innerText = '';
    document.getElementById('edit_expected_in_time_err').innerText = '';
    document.getElementById('edit_purpose_err').innerText = '';

    const name = document.getElementById('edit_name').value.trim();
    const phone = document.getElementById('edit_phone').value.trim();
    const relationship = document.getElementById('edit_relationship').value.trim();
    const visitDate = document.getElementById('edit_visit_date').value.trim();
    const expectedInTime = document.getElementById('edit_expected_in_time').value.trim();
    const purpose = document.getElementById('edit_purpose').value.trim();

    if (name === '') {
        document.getElementById('edit_name_err').innerText = 'Visitor name is required.';
        isValid = false;
    }

    if (phone === '') {
        document.getElementById('edit_phone_err').innerText = 'Phone number is required.';
        isValid = false;
    } else if (!/^[0-9]{10}$/.test(phone)) {
        document.getElementById('edit_phone_err').innerText = 'Please enter a valid 10-digit phone number.';
        isValid = false;
    }

    if (relationship === '') {
        document.getElementById('edit_relationship_err').innerText = 'Please select relationship.';
        isValid = false;
    }

    if (visitDate === '') {
        document.getElementById('edit_visit_date_err').innerText = 'Visit date is required.';
        isValid = false;
    }

    if (expectedInTime === '') {
        document.getElementById('edit_expected_in_time_err').innerText = 'Expected in time is required.';
        isValid = false;
    }

    if (purpose === '') {
        document.getElementById('edit_purpose_err').innerText = 'Purpose of visit is required.';
        isValid = false;
    }

    return isValid;
}

function editVisitor(data) {
    document.getElementById('edit_name_err').innerText = '';
    document.getElementById('edit_phone_err').innerText = '';
    document.getElementById('edit_relationship_err').innerText = '';
    document.getElementById('edit_visit_date_err').innerText = '';
    document.getElementById('edit_expected_in_time_err').innerText = '';
    document.getElementById('edit_purpose_err').innerText = '';

    document.getElementById('edit_visitor_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_phone').value = data.phone;
    document.getElementById('edit_relationship').value = data.relationship;
    document.getElementById('edit_id_proof_type').value = data.id_proof_type || '';
    document.getElementById('edit_id_proof_number').value = data.id_proof_number || '';
    document.getElementById('edit_visit_date').value = data.visit_date;
    document.getElementById('edit_expected_in_time').value = data.expected_in_time;
    document.getElementById('edit_expected_out_time').value = data.expected_out_time || '';
    document.getElementById('edit_purpose').value = data.purpose;

    $('#editVisitorModal').modal('show');
}

function viewVisitor(data) {
    document.getElementById('view_name').innerText = data.name;
    document.getElementById('view_phone').innerText = data.phone;
    document.getElementById('view_relationship').innerText = data.relationship;
    
    let idProofStr = (data.id_proof_type) ? (data.id_proof_type + ': ' + (data.id_proof_number || '-')) : 'None';
    document.getElementById('view_id_proof').innerText = idProofStr;
    
    document.getElementById('view_visit_date').innerText = data.visit_date;
    document.getElementById('view_timing').innerText = 'In: ' + data.expected_in_time + ' | Out: ' + (data.expected_out_time || '-');
    document.getElementById('view_purpose').innerText = data.purpose;
    document.getElementById('view_status').innerText = data.status;
    document.getElementById('view_approved_by').innerText = data.approved_by || 'Pending Warden Review';
    document.getElementById('view_remarks').innerText = data.remarks || '-';

    $('#viewVisitorModal').modal('show');
}

// Clear real-time errors as user types/changes fields
document.addEventListener("input", function(e) {
    if (e.target.id === 'add_name') document.getElementById('add_name_err').innerText = '';
    if (e.target.id === 'add_phone') document.getElementById('add_phone_err').innerText = '';
    if (e.target.id === 'add_purpose') document.getElementById('add_purpose_err').innerText = '';
    if (e.target.id === 'edit_name') document.getElementById('edit_name_err').innerText = '';
    if (e.target.id === 'edit_phone') document.getElementById('edit_phone_err').innerText = '';
    if (e.target.id === 'edit_purpose') document.getElementById('edit_purpose_err').innerText = '';
});

document.addEventListener("change", function(e) {
    if (e.target.id === 'add_relationship') document.getElementById('add_relationship_err').innerText = '';
    if (e.target.id === 'add_visit_date') document.getElementById('add_visit_date_err').innerText = '';
    if (e.target.id === 'add_expected_in_time') document.getElementById('add_expected_in_time_err').innerText = '';
    if (e.target.id === 'edit_relationship') document.getElementById('edit_relationship_err').innerText = '';
    if (e.target.id === 'edit_visit_date') document.getElementById('edit_visit_date_err').innerText = '';
    if (e.target.id === 'edit_expected_in_time') document.getElementById('edit_expected_in_time_err').innerText = '';
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
            "searching": false
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
