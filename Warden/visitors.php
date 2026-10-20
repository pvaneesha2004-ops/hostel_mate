<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$warden_id = intval($_SESSION['warden_id']);
$warden_name = $_SESSION['warden_name'] ?? 'Hostel Warden';
$warden_code = $_SESSION['warden_code'] ?? 'WDN';

// Ensure visitors table exists
$create_visitors_sql = "CREATE TABLE IF NOT EXISTS `visitors` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `relationship` VARCHAR(100) NOT NULL,
    `id_proof_type` VARCHAR(100) DEFAULT NULL,
    `id_proof_number` VARCHAR(100) DEFAULT NULL,
    `visit_date` DATE NOT NULL,
    `expected_in_time` TIME NOT NULL,
    `expected_out_time` TIME DEFAULT NULL,
    `purpose` TEXT NOT NULL,
    `status` ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    `approved_by` BIGINT DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_v_student` (`student_id`),
    INDEX `idx_v_date` (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_visitors_sql);

// Ensure visitor_logs table exists
$create_logs_sql = "CREATE TABLE IF NOT EXISTS `visitor_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `visitor_id` BIGINT NOT NULL,
    `check_in_at` DATETIME DEFAULT NULL,
    `check_out_at` DATETIME DEFAULT NULL,
    `recorded_by` BIGINT DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_log_visitor` (`visitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_logs_sql);

// -------------------------------------------------------------
// POST HANDLERS: CHANGE STATUS & GATE LOGS
// -------------------------------------------------------------

// 1. Change Visitor Request Status
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $visitor_id = intval($_POST['visitor_id'] ?? 0);
    $new_status = strtolower(trim($_POST['status'] ?? 'pending'));
    $allowed_statuses = ['pending', 'approved', 'rejected', 'completed'];
    if (!in_array($new_status, $allowed_statuses)) {
        $new_status = 'pending';
    }
    $remarks = trim($_POST['remarks'] ?? '');

    if ($visitor_id > 0) {
        // Fetch visitor name for toast message
        $v_name_res = $conn->query("SELECT name FROM visitors WHERE id = {$visitor_id} LIMIT 1");
        $v_name = ($v_name_res && $v_name_res->num_rows > 0) ? $v_name_res->fetch_assoc()['name'] : 'Visitor';

        $stmt = $conn->prepare("UPDATE `visitors` SET `status` = ?, `approved_by` = ?, `remarks` = ?, `updated_at` = NOW() WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param("sisi", $new_status, $warden_id, $remarks, $visitor_id);
            if ($stmt->execute()) {
                $status_label = ucfirst($new_status);
                $_SESSION['msg'] = "Visitor request for '{$v_name}' has been successfully marked as {$status_label}.";
            } else {
                $_SESSION['error'] = "Failed to update visitor status: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        $_SESSION['error'] = "Invalid visitor request ID.";
    }
    header("Location: visitors.php");
    exit();
}

// 2. Reject Visitor Request with Remark (Required)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_visitor') {
    $visitor_id = intval($_POST['visitor_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    if (empty($remarks)) {
        $remarks = "Declined by Warden Desk";
    }

    if ($visitor_id > 0) {
        $v_name_res = $conn->query("SELECT name FROM visitors WHERE id = {$visitor_id} LIMIT 1");
        $v_name = ($v_name_res && $v_name_res->num_rows > 0) ? $v_name_res->fetch_assoc()['name'] : 'Visitor';

        $stmt = $conn->prepare("UPDATE `visitors` SET `status` = 'rejected', `approved_by` = ?, `remarks` = ?, `updated_at` = NOW() WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param("isi", $warden_id, $remarks, $visitor_id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Visitor request for '{$v_name}' has been REJECTED. Remark: \"{$remarks}\"";
            } else {
                $_SESSION['error'] = "Failed to update visitor status: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Invalid visitor request ID.";
    }
    header("Location: visitors.php");
    exit();
}

// 2b. Quick 1-Click Approve / Reject
if (isset($_GET['quick_action']) && isset($_GET['id'])) {
    $v_id = intval($_GET['id']);
    $quick_act = $_GET['quick_action'];

    if ($v_id > 0) {
        $v_check = $conn->query("SELECT name FROM visitors WHERE id = {$v_id} LIMIT 1");
        $v_data = ($v_check && $v_check->num_rows > 0) ? $v_check->fetch_assoc() : null;
        $v_name = $v_data['name'] ?? 'Visitor';

        if ($quick_act === 'approve') {
            $default_rmk = "Approved by Warden " . $warden_name;
            $stmt = $conn->prepare("UPDATE `visitors` SET `status` = 'approved', `approved_by` = ?, `remarks` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $stmt->bind_param("isi", $warden_id, $default_rmk, $v_id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Visitor request for '{$v_name}' APPROVED successfully!";
            }
            $stmt->close();
        } elseif ($quick_act === 'reject') {
            $custom_rmk = !empty($_GET['remark']) ? trim($_GET['remark']) : "Declined by Warden Desk";
            $stmt = $conn->prepare("UPDATE `visitors` SET `status` = 'rejected', `approved_by` = ?, `remarks` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $stmt->bind_param("isi", $warden_id, $custom_rmk, $v_id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Visitor request for '{$v_name}' REJECTED. Remark: \"{$custom_rmk}\"";
            }
            $stmt->close();
        }
    }
    header("Location: visitors.php");
    exit();
}

// 3. Record Gate Check-In
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_checkin') {
    $visitor_id = intval($_POST['visitor_id'] ?? 0);
    $gate_remarks = trim($_POST['gate_remarks'] ?? 'Checked in at hostel gate');

    if ($visitor_id > 0) {
        // Insert log
        $stmt = $conn->prepare("INSERT INTO `visitor_logs` (`visitor_id`, `check_in_at`, `recorded_by`, `remarks`, `created_at`) VALUES (?, NOW(), ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param("iis", $visitor_id, $warden_id, $gate_remarks);
            $stmt->execute();
            $stmt->close();
        }
        // Update visitor status to approved if not already
        $conn->query("UPDATE `visitors` SET `status` = 'approved', `updated_at` = NOW() WHERE `id` = {$visitor_id} AND `status` = 'pending'");
        $_SESSION['msg'] = "Visitor gate entry logged successfully at " . date('h:i A') . "!";
    }
    header("Location: visitors.php");
    exit();
}

// 4. Record Gate Check-Out
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_checkout') {
    $visitor_id = intval($_POST['visitor_id'] ?? 0);
    $checkout_remarks = trim($_POST['gate_remarks'] ?? 'Checked out from hostel gate');

    if ($visitor_id > 0) {
        // Update open log or insert complete log
        $open_log_res = $conn->query("SELECT id FROM `visitor_logs` WHERE `visitor_id` = {$visitor_id} AND `check_out_at` IS NULL ORDER BY id DESC LIMIT 1");
        if ($open_log_res && $open_log_res->num_rows > 0) {
            $log_id = $open_log_res->fetch_assoc()['id'];
            $conn->query("UPDATE `visitor_logs` SET `check_out_at` = NOW(), `remarks` = CONCAT(IFNULL(remarks,''), ' | ', '{$conn->real_escape_string($checkout_remarks)}') WHERE `id` = {$log_id}");
        } else {
            $conn->query("INSERT INTO `visitor_logs` (`visitor_id`, `check_in_at`, `check_out_at`, `recorded_by`, `remarks`, `created_at`) VALUES ({$visitor_id}, NOW(), NOW(), {$warden_id}, '{$conn->real_escape_string($checkout_remarks)}', NOW())");
        }
        // Mark visitor request as completed
        $conn->query("UPDATE `visitors` SET `status` = 'completed', `updated_at` = NOW() WHERE `id` = {$visitor_id}");
        $_SESSION['msg'] = "Visitor departure recorded and visit marked as COMPLETED!";
    }
    header("Location: visitors.php");
    exit();
}

// 5. Add Direct Walk-in Visitor (Warden Desk Entry)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_walkin_visitor') {
    $student_id        = intval($_POST['student_id'] ?? 0);
    $name              = trim($_POST['name'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $relationship      = trim($_POST['relationship'] ?? 'Guardian');
    $id_proof_type     = trim($_POST['id_proof_type'] ?? 'Aadhaar Card');
    $id_proof_number   = trim($_POST['id_proof_number'] ?? '');
    $visit_date        = !empty($_POST['visit_date']) ? trim($_POST['visit_date']) : date('Y-m-d');
    $expected_in_time  = !empty($_POST['expected_in_time']) ? trim($_POST['expected_in_time']) : date('H:i');
    $expected_out_time = !empty($_POST['expected_out_time']) ? trim($_POST['expected_out_time']) : date('H:i', strtotime('+2 hours'));
    $purpose           = trim($_POST['purpose'] ?? 'Personal Visit');
    $immediate_checkin = isset($_POST['immediate_checkin']);

    if ($student_id <= 0 || empty($name) || empty($phone) || empty($purpose)) {
        $_SESSION['error'] = "Please provide all required visitor details.";
    } else {
        $auto_status = $immediate_checkin ? 'approved' : 'approved';
        $warden_rmk  = "Walk-in entry authorized by Warden " . $warden_name;

        $stmt = $conn->prepare("INSERT INTO `visitors` (`student_id`, `name`, `phone`, `relationship`, `id_proof_type`, `id_proof_number`, `visit_date`, `expected_in_time`, `expected_out_time`, `purpose`, `status`, `approved_by`, `remarks`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        if ($stmt) {
            $stmt->bind_param("issssssssssis", $student_id, $name, $phone, $relationship, $id_proof_type, $id_proof_number, $visit_date, $expected_in_time, $expected_out_time, $purpose, $auto_status, $warden_id, $warden_rmk);
            if ($stmt->execute()) {
                $new_vis_id = $stmt->insert_id;
                if ($immediate_checkin && $new_vis_id > 0) {
                    $conn->query("INSERT INTO `visitor_logs` (`visitor_id`, `check_in_at`, `recorded_by`, `remarks`, `created_at`) VALUES ({$new_vis_id}, NOW(), {$warden_id}, 'Gate Entry checked in immediately', NOW())");
                }
                $_SESSION['msg'] = "Walk-in visitor '{$name}' registered and approved successfully!";
            } else {
                $_SESSION['error'] = "Failed to record visitor: " . $conn->error;
            }
            $stmt->close();
        }
    }
    header("Location: visitors.php");
    exit();
}

// -------------------------------------------------------------
// FILTERING & DATA RETRIEVAL
// -------------------------------------------------------------
$status_filter   = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$date_filter     = isset($_GET['date']) ? trim($_GET['date']) : '';
$from_date       = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date         = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$block_filter    = isset($_GET['block_id']) ? intval($_GET['block_id']) : 0;
$search_keyword  = isset($_GET['search']) ? trim($_GET['search']) : '';
$preset          = $_GET['preset'] ?? '';

// Handle Presets
$active_preset = '';
if ($preset === 'today') {
    $date_filter = date('Y-m-d');
    $active_preset = 'today';
} elseif ($preset === 'pending') {
    $status_filter = 'pending';
    $active_preset = 'pending';
} elseif ($preset === 'approved') {
    $status_filter = 'approved';
    $active_preset = 'approved';
} elseif ($preset === 'completed') {
    $status_filter = 'completed';
    $active_preset = 'completed';
} elseif ($preset === 'this_month') {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
    $active_preset = 'this_month';
} elseif ($preset === 'all') {
    $status_filter = 'all';
    $date_filter = '';
    $from_date = '';
    $to_date = '';
    $active_preset = 'all';
}

$where_clauses = ["1=1"];

// Status
if (!empty($status_filter) && $status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "LOWER(v.status) = '{$safe_status}'";
}

// Date Range vs Exact Date
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to   = $conn->real_escape_string($to_date);
    $where_clauses[] = "(v.visit_date BETWEEN '{$safe_from}' AND '{$safe_to}')";
    $date_label = date('d M Y', strtotime($from_date)) . " - " . date('d M Y', strtotime($to_date));
} elseif (!empty($date_filter)) {
    $safe_date = $conn->real_escape_string($date_filter);
    $where_clauses[] = "v.visit_date = '{$safe_date}'";
    $date_label = date('l, d M Y', strtotime($date_filter));
} else {
    $date_label = "All Dates";
}

// Block
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// Search
if (!empty($search_keyword)) {
    $safe_q = $conn->real_escape_string($search_keyword);
    $where_clauses[] = "(v.name LIKE '%{$safe_q}%' OR v.phone LIKE '%{$safe_q}%' OR v.id_proof_number LIKE '%{$safe_q}%' OR u.name LIKE '%{$safe_q}%' OR rm.room_number LIKE '%{$safe_q}%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Query all visitors with resident student and room info
$visitors_sql = "
    SELECT 
        v.*,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        bd.bed_number,
        w.name AS warden_approver_name,
        w.code AS warden_approver_code,
        vl.check_in_at,
        vl.check_out_at,
        vl.id AS log_id
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN wardens w ON v.approved_by = w.id
    LEFT JOIN (
        SELECT visitor_id, MAX(check_in_at) AS check_in_at, MAX(check_out_at) AS check_out_at, MAX(id) AS id 
        FROM visitor_logs 
        GROUP BY visitor_id
    ) vl ON v.id = vl.visitor_id
    WHERE {$where_sql}
    ORDER BY v.visit_date DESC, v.created_at DESC, v.id DESC
";

$visitors_res = $conn->query($visitors_sql);
$visitors_list = [];
if ($visitors_res && $visitors_res->num_rows > 0) {
    while ($r = $visitors_res->fetch_assoc()) {
        $visitors_list[] = $r;
    }
}

// Global KPI Metrics across all records
$kpi_query = $conn->query("
    SELECT 
        COUNT(id) as total_cnt,
        SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) as approved_cnt,
        SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) as rejected_cnt,
        SUM(CASE WHEN LOWER(status) = 'completed' THEN 1 ELSE 0 END) as completed_cnt
    FROM visitors
");
$kpi_stats = ($kpi_query && $kpi_query->num_rows > 0) ? $kpi_query->fetch_assoc() : [];
$kpi_total     = intval($kpi_stats['total_cnt'] ?? 0);
$kpi_pending   = intval($kpi_stats['pending_cnt'] ?? 0);
$kpi_approved  = intval($kpi_stats['approved_cnt'] ?? 0);
$kpi_rejected  = intval($kpi_stats['rejected_cnt'] ?? 0);
$kpi_completed = intval($kpi_stats['completed_cnt'] ?? 0);

// Active resident students list for the walk-in modal dropdown
$active_students_res = $conn->query("
    SELECT u.id, u.name, u.phone, blk.name as block_name, rm.room_number, bd.bed_number 
    FROM users u 
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE u.role = 'student' AND (u.status = 'active' OR u.status = '1')
    ORDER BY u.name ASC
");
$active_students = [];
if ($active_students_res && $active_students_res->num_rows > 0) {
    while ($s = $active_students_res->fetch_assoc()) {
        $active_students[] = $s;
    }
}

// Blocks for filter
$all_blocks = [];
$b_res = $conn->query("SELECT id, name FROM blocks WHERE status = 'active' OR status = '1' ORDER BY name ASC");
if ($b_res && $b_res->num_rows > 0) {
    while ($b = $b_res->fetch_assoc()) {
        $all_blocks[] = $b;
    }
}

ob_start();
?>

<style>
  /* =========================================================
     WARDEN VISITORS BOOK - EMERALD OBSIDIAN DESIGN SYSTEM
     ========================================================= */
  .visitor-hero-banner {
    background: linear-gradient(135deg, #064e3b 0%, #059669 50%, #0d9488 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem;
    color: #ffffff;
    box-shadow: 0 10px 30px -5px rgba(5, 150, 105, 0.35);
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
  }

  .visitor-hero-banner::after {
    content: '';
    position: absolute;
    right: -60px;
    bottom: -60px;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.14) 0%, rgba(255,255,255,0) 70%);
    pointer-events: none;
  }

  .visitor-badge-tag {
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.35);
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .filter-box-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 1.5rem 1.75rem;
    border: 1px solid #e6f4ea;
    box-shadow: 0 4px 20px rgba(5, 150, 105, 0.05);
    margin-bottom: 2rem;
  }

  .v-preset-pill {
    padding: 0.45rem 1rem;
    border-radius: 50px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1.5px solid #d1fae5;
    background: #f0fdf4;
    color: #047857;
    text-decoration: none !important;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-right: 0.4rem;
    margin-bottom: 0.4rem;
  }

  .v-preset-pill:hover {
    background: #10b981;
    color: #ffffff !important;
    border-color: #10b981;
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(16, 185, 129, 0.25);
  }

  .v-preset-pill.active {
    background: linear-gradient(135deg, #059669 0%, #0d9488 100%) !important;
    color: #ffffff !important;
    border-color: #059669 !important;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
  }

  .v-kpi-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.25rem 1.4rem;
    border: 1px solid #ecfdf5;
    box-shadow: 0 4px 18px rgba(5, 150, 105, 0.04);
    transition: all 0.25s ease;
    height: 100%;
  }

  .v-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.1);
  }

  .v-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .icon-v-total     { background: #e0f2fe; color: #0284c7; }
  .icon-v-pending   { background: #fef3c7; color: #d97706; }
  .icon-v-approved  { background: #d1fae5; color: #059669; }
  .icon-v-completed { background: #ede9fe; color: #6d28d9; }
  .icon-v-rejected  { background: #fee2e2; color: #dc2626; }

  /* Status Badges */
  .badge-v-status {
    padding: 0.45rem 0.85rem;
    border-radius: 50px;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0.3px;
  }

  .badge-v-pending {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
  }

  .badge-v-approved {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
  }

  .badge-v-rejected {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
  }

  .badge-v-completed {
    background: #ede9fe;
    color: #5b21b6;
    border: 1px solid #ddd6fe;
  }

  #visitorsBookTable th {
    background: #f8fafc !important;
    color: #334155 !important;
    font-weight: 700;
    font-size: 0.76rem !important;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border-bottom: 2px solid #e2e8f0 !important;
    padding: 1rem 1.1rem !important;
    white-space: nowrap;
  }

  #visitorsBookTable td {
    padding: 1rem 1.1rem !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
  }

  .btn-v-action {
    padding: 0.35rem 0.75rem;
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
  }
  .btn-v-action * {
    pointer-events: none;
  }

  .btn-v-approve {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
  }
  .btn-v-approve:hover {
    background: #059669;
    color: #ffffff;
  }

  .btn-v-reject {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
  }
  .btn-v-reject:hover {
    background: #dc2626;
    color: #ffffff;
  }

  .btn-v-status-change {
    background: #f0fdf4;
    color: #047857;
    border: 1.5px solid #d1fae5;
  }
  .btn-v-status-change:hover {
    background: #059669;
    color: #ffffff;
    border-color: #059669;
  }

  .pulse-badge {
    animation: pulseWarning 2s infinite;
  }
  @keyframes pulseWarning {
    0% { transform: scale(1); }
    50% { transform: scale(1.06); }
    100% { transform: scale(1); }
  }

  .student-initial-circle {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0369a1;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.88rem;
  }

  .id-proof-chip {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 0.74rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
    display: inline-block;
  }

  .custom-form-input {
    border-radius: 10px !important;
    border: 1.5px solid #d1fae5 !important;
    padding: 0.6rem 0.9rem !important;
    font-size: 0.88rem !important;
  }
  .custom-form-input:focus {
    border-color: #059669 !important;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15) !important;
  }

  /* =========================================================
     MODAL REFINEMENTS: REJECT VISITOR & STATUS CHANGE
     ========================================================= */
  .modal-reject-card {
    border-radius: 18px !important;
    border: none !important;
    overflow: hidden !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22) !important;
  }

  .modal-reject-header {
    background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%) !important;
    padding: 1.25rem 1.75rem !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    border-bottom: none !important;
  }

  .modal-reject-header .modal-title {
    color: #ffffff !important;
    font-size: 1.1rem !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    margin: 0 !important;
  }

  .modal-reject-header .close {
    color: #ffffff !important;
    opacity: 0.85 !important;
    text-shadow: none !important;
    font-size: 1.45rem !important;
    font-weight: 300 !important;
    padding: 0 !important;
    margin: 0 !important;
    line-height: 1 !important;
    transition: opacity 0.2s ease !important;
    outline: none !important;
  }
  .modal-reject-header .close:hover {
    opacity: 1 !important;
  }

  .modal-reject-body {
    padding: 1.6rem 1.75rem !important;
    background: #ffffff !important;
  }

  .reject-visitor-strip {
    background: #fef2f2 !important;
    border: 1.5px solid #fecaca !important;
    border-radius: 12px !important;
    padding: 0.85rem 1.15rem !important;
    margin-bottom: 1.25rem !important;
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
  }

  .reject-visitor-strip-item {
    display: flex !important;
    flex-direction: column !important;
  }

  .reject-visitor-strip-item small {
    font-size: 0.68rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    color: #991b1b !important;
    margin-bottom: 2px !important;
  }

  .reject-visitor-strip-item strong {
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    color: #1e293b !important;
  }

  .reject-textarea-input {
    border-radius: 12px !important;
    border: 1.5px solid #cbd5e1 !important;
    padding: 0.75rem 1rem !important;
    font-size: 0.9rem !important;
    color: #1e293b !important;
    background: #ffffff !important;
    width: 100% !important;
    min-height: 90px !important;
    resize: vertical !important;
    line-height: 1.5 !important;
    transition: all 0.2s ease !important;
  }

  .reject-textarea-input:focus {
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 3.5px rgba(239, 68, 68, 0.14) !important;
    outline: none !important;
  }

  .reject-presets-container {
    margin-top: 1.2rem !important;
    padding-top: 1rem !important;
    border-top: 1px solid #f1f5f9 !important;
  }

  .reject-presets-title {
    font-size: 0.74rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    color: #475569 !important;
    margin-bottom: 0.65rem !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
  }

  .quick-reject-chips-grid {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
  }

  .quick-reject-chip-pill {
    background: #fef2f2 !important;
    border: 1.5px solid #fecaca !important;
    color: #b91c1c !important;
    border-radius: 50px !important;
    padding: 0.45rem 0.95rem !important;
    font-size: 0.78rem !important;
    font-weight: 600 !important;
    line-height: 1.3 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
    white-space: nowrap !important;
  }

  .quick-reject-chip-pill:hover {
    background: #fee2e2 !important;
    border-color: #ef4444 !important;
    color: #991b1b !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 3px 8px rgba(239, 68, 68, 0.18) !important;
  }

  .quick-reject-chip-pill:active {
    transform: translateY(0) !important;
    background: #fecaca !important;
  }

  .modal-reject-footer {
    background: #f8fafc !important;
    border-top: 1px solid #f1f5f9 !important;
    padding: 1.15rem 1.75rem !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 12px !important;
  }

  .btn-reject-cancel {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    color: #475569 !important;
    font-size: 0.86rem !important;
    font-weight: 600 !important;
    border-radius: 10px !important;
    padding: 0.55rem 1.4rem !important;
    transition: all 0.2s ease !important;
  }
  .btn-reject-cancel:hover {
    background: #f1f5f9 !important;
    color: #1e293b !important;
    border-color: #94a3b8 !important;
  }

  .btn-reject-confirm {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%) !important;
    border: none !important;
    color: #ffffff !important;
    font-size: 0.86rem !important;
    font-weight: 700 !important;
    border-radius: 10px !important;
    padding: 0.55rem 1.6rem !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.28) !important;
    transition: all 0.2s ease !important;
  }
  .btn-reject-confirm:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%) !important;
    box-shadow: 0 6px 18px rgba(220, 38, 38, 0.38) !important;
    transform: translateY(-1px) !important;
    color: #ffffff !important;
  }

  @media print {
    .navbar, .sidebar, .visitor-hero-banner, .filter-box-card, .btn-v-action, .no-print, .btn, .dataTables_filter, .dataTables_length, .dataTables_paginate {
      display: none !important;
    }
    .main-panel, .content-wrapper {
      width: 100% !important;
      max-width: 100% !important;
      padding: 0 !important;
    }
  }
</style>

<!-- =========================================================
     HERO BANNER
     ========================================================= -->
<div class="visitor-hero-banner">
  <div class="d-flex flex-wrap justify-content-between align-items-center">
    <div class="mb-3 mb-lg-0">
      <div class="d-flex align-items-center mb-2">
        <span class="visitor-badge-tag mr-2">
          <i class="fa-solid fa-address-book"></i> Hostel Warden Desk
        </span>
        <span class="visitor-badge-tag">
          <i class="fa-solid fa-calendar-check"></i> <?= htmlspecialchars($date_label) ?>
        </span>
      </div>
      <h2 class="font-weight-800 text-white mb-2" style="letter-spacing: -0.5px;">Hostel Visitors Book</h2>
      <p class="text-white-50 mb-0" style="max-width: 680px; font-size: 0.94rem; line-height: 1.5;">
        Review student visitor requests, approve or decline visiting permissions, authorize walk-ins, and track gate check-in & check-out times.
      </p>
    </div>
  </div>
</div>

<!-- =========================================================
     ALERTS
     ========================================================= -->
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4" role="alert" style="background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7 !important;">
    <i class="fa-solid fa-circle-check mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['msg']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4" role="alert" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5 !important;">
    <i class="fa-solid fa-circle-exclamation mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- =========================================================
     LIVE KPI SUMMARY CARDS
     ========================================================= -->
<div class="row mb-4">
  <!-- Total Requests -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="v-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Total Requests</span>
          <h3 class="font-weight-800 text-dark mb-0"><?= $kpi_total ?></h3>
        </div>
        <div class="v-kpi-icon icon-v-total">
          <i class="fa-solid fa-book-open"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Pending Approvals -->
  <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
    <div class="v-kpi-card" style="<?= ($kpi_pending > 0) ? 'border: 2px solid #f59e0b !important;' : '' ?>">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Pending Approvals</span>
          <h3 class="font-weight-800 text-warning mb-0" style="color: #d97706 !important;"><?= $kpi_pending ?></h3>
          <?php if ($kpi_pending > 0): ?>
            <small class="badge badge-warning text-dark font-weight-700 mt-1 pulse-badge" style="font-size: 0.7rem; background: #fef3c7;">
              Action Required
            </small>
          <?php else: ?>
            <small class="text-muted font-weight-600" style="font-size: 0.72rem;">All reviewed</small>
          <?php endif; ?>
        </div>
        <div class="v-kpi-icon icon-v-pending">
          <i class="fa-solid fa-clock"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Approved -->
  <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
    <div class="v-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Approved</span>
          <h3 class="font-weight-800 text-success mb-0"><?= $kpi_approved ?></h3>
          <small class="text-success font-weight-600" style="font-size: 0.72rem;">Authorized passes</small>
        </div>
        <div class="v-kpi-icon icon-v-approved">
          <i class="fa-solid fa-circle-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Completed Visits -->
  <div class="col-xl-2 col-md-6 col-sm-6 mb-3">
    <div class="v-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Completed</span>
          <h3 class="font-weight-800 text-purple mb-0" style="color: #6d28d9 !important;"><?= $kpi_completed ?></h3>
          <small class="text-muted font-weight-600" style="font-size: 0.72rem;">Departed & closed</small>
        </div>
        <div class="v-kpi-icon icon-v-completed">
          <i class="fa-solid fa-flag-checkered"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Rejected -->
  <div class="col-xl-3 col-md-6 col-sm-12 mb-3">
    <div class="v-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Rejected</span>
          <h3 class="font-weight-800 text-danger mb-0"><?= $kpi_rejected ?></h3>
          <small class="text-danger font-weight-600" style="font-size: 0.72rem;">Permissions denied</small>
        </div>
        <div class="v-kpi-icon icon-v-rejected">
          <i class="fa-solid fa-circle-xmark"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- =========================================================
     FILTER & SEARCH CARD
     ========================================================= -->
<div class="filter-box-card">
  <!-- Quick Preset Pills -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-3 border-bottom">
    <div class="d-flex flex-wrap align-items-center">
      <span class="font-weight-700 text-dark mr-3 mb-1" style="font-size: 0.86rem;">
        <i class="fa-solid fa-filter text-success mr-1"></i> Quick Filters:
      </span>
      <a href="visitors.php?preset=all" class="v-preset-pill <?= ($active_preset === 'all' || empty($active_preset) && $status_filter === 'all' && empty($date_filter)) ? 'active' : '' ?>">
        <i class="fa-solid fa-layer-group"></i> All (<?= $kpi_total ?>)
      </a>
      <a href="visitors.php?preset=pending" class="v-preset-pill <?= ($active_preset === 'pending' || $status_filter === 'pending') ? 'active' : '' ?>" style="<?= ($kpi_pending > 0) ? 'font-weight: 800; border-color: #f59e0b;' : '' ?>">
        <i class="fa-solid fa-clock text-warning"></i> Pending Review (<?= $kpi_pending ?>)
      </a>
      <a href="visitors.php?preset=approved" class="v-preset-pill <?= ($active_preset === 'approved' || $status_filter === 'approved') ? 'active' : '' ?>">
        <i class="fa-solid fa-circle-check text-success"></i> Approved (<?= $kpi_approved ?>)
      </a>
      <a href="visitors.php?preset=today" class="v-preset-pill <?= ($active_preset === 'today' || $date_filter === date('Y-m-d')) ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-day"></i> Today's Visits
      </a>
      <a href="visitors.php?preset=this_month" class="v-preset-pill <?= ($active_preset === 'this_month') ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-week"></i> This Month
      </a>
      <a href="visitors.php?preset=completed" class="v-preset-pill <?= ($active_preset === 'completed' || $status_filter === 'completed') ? 'active' : '' ?>">
        <i class="fa-solid fa-flag-checkered"></i> Completed
      </a>
    </div>

    <div>
      <span class="text-muted font-weight-600" style="font-size: 0.82rem;">
        Showing: <strong class="text-success"><?= count($visitors_list) ?></strong> records
      </span>
    </div>
  </div>

  <!-- Detailed Filter Form -->
  <form method="GET" action="visitors.php" class="row align-items-end">
    <!-- Visit Date -->
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-regular fa-calendar-check text-success mr-1"></i> Visit Date
      </label>
      <input type="date" name="date" class="form-control custom-form-input" value="<?= htmlspecialchars($date_filter) ?>">
    </div>

    <!-- Status Dropdown -->
    <div class="col-lg-2 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-solid fa-flag text-success mr-1"></i> Request Status
      </label>
      <select name="status" class="form-control custom-form-input">
        <option value="all" <?= ($status_filter === 'all') ? 'selected' : '' ?>>All Statuses</option>
        <option value="pending" <?= ($status_filter === 'pending') ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= ($status_filter === 'approved') ? 'selected' : '' ?>>Approved</option>
        <option value="completed" <?= ($status_filter === 'completed') ? 'selected' : '' ?>>Completed</option>
        <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
      </select>
    </div>

    <!-- Block Dropdown -->
    <div class="col-lg-2 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-solid fa-building text-success mr-1"></i> Hostel Block
      </label>
      <select name="block_id" class="form-control custom-form-input">
        <option value="0">All Blocks</option>
        <?php foreach ($all_blocks as $blk): ?>
          <option value="<?= $blk['id'] ?>" <?= ($block_filter == $blk['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($blk['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Search keyword -->
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-solid fa-magnifying-glass text-success mr-1"></i> Search Visitor / Student
      </label>
      <input type="text" name="search" class="form-control custom-form-input" placeholder="Visitor name, phone, student, room..." value="<?= htmlspecialchars($search_keyword) ?>">
    </div>

    <!-- Submit & Reset -->
    <div class="col-lg-2 col-md-12 d-flex align-items-center mt-3 mt-lg-0">
      <button type="submit" class="btn btn-success font-weight-700 rounded-10 flex-grow-1 shadow-sm" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none; height: 42px;">
        <i class="fa-solid fa-filter mr-1"></i> Filter
      </button>
      <a href="visitors.php?preset=all" class="btn btn-light border font-weight-600 rounded-10 ml-2" title="Reset Filters" style="height: 42px; display: inline-flex; align-items: center; justify-content: center;">
        <i class="fa-solid fa-arrows-rotate"></i>
      </a>
    </div>
  </form>
</div>

<!-- =========================================================
     VISITORS DATA TABLE CARD
     ========================================================= -->
<div class="card shadow-sm border-0 rounded-16">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom">
      <div>
        <h4 class="font-weight-800 text-dark mb-1" style="font-size: 1.18rem;">
          <i class="fa-solid fa-address-book text-success mr-2"></i> Visitor Requests & Entry Logs
        </h4>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">
          Displaying records matching your filter. Click <strong>Change Status</strong> or quick buttons to approve/reject visiting passes.
        </p>
      </div>

      <div class="mt-2 mt-sm-0">
        <button type="button" class="btn btn-sm btn-outline-success font-weight-700 rounded-8 mr-2" data-toggle="modal" data-target="#addWalkinModal">
          <i class="fa-solid fa-plus mr-1"></i> Add Walk-in
        </button>
      </div>
    </div>

    <?php if (empty($visitors_list)): ?>
      <div class="text-center py-5">
        <div class="mb-3">
          <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 80px; height: 80px; background: #ecfdf5; color: #059669; font-size: 2rem;">
            <i class="fa-solid fa-users-slash"></i>
          </span>
        </div>
        <h5 class="font-weight-700 text-dark mb-1">No Visitor Requests Found</h5>
        <p class="text-muted mb-3" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
          No visitor entries match your selected filter. You can view all records or register a new walk-in visitor.
        </p>
        <div class="d-flex justify-content-center gap-2">
          <a href="visitors.php?preset=all" class="btn btn-outline-success font-weight-600 rounded-10 px-3 py-2 mr-2">
            <i class="fa-solid fa-layer-group mr-1"></i> View All Records
          </a>
          <button type="button" class="btn btn-success font-weight-700 rounded-10 px-3 py-2" data-toggle="modal" data-target="#addWalkinModal" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none;">
            <i class="fa-solid fa-user-plus mr-1"></i> Register Walk-in
          </button>
        </div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="visitorsBookTable" style="width: 100%;">
          <thead>
            <tr>
              <th style="width: 40px;" class="text-center">#</th>
              <th style="min-width: 140px;">Visit Date & Time</th>
              <th style="min-width: 220px;">Visitor Information</th>
              <th style="min-width: 200px;">Resident Student</th>
              <th style="min-width: 170px;">Purpose & ID Proof</th>
              <th style="width: 120px;" class="text-center">Status</th>
              <th style="min-width: 160px;">Approval / Review</th>
              <th style="width: 180px;" class="text-center no-print">Change Status / Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $sl = 1;
            foreach ($visitors_list as $row): 
                $v_id        = $row['id'];
                $visit_date_fmt = date('d M, Y', strtotime($row['visit_date']));
                $visit_day_fmt  = date('D', strtotime($row['visit_date']));
                $in_time_fmt    = !empty($row['expected_in_time']) ? date('h:i A', strtotime($row['expected_in_time'])) : 'N/A';
                $out_time_fmt   = !empty($row['expected_out_time']) ? date('h:i A', strtotime($row['expected_out_time'])) : 'N/A';
                $status_clean   = strtolower($row['status'] ?? 'pending');

                // Student avatar initial
                $s_initial = strtoupper(substr($row['student_name'] ?? 'S', 0, 1));
                $room_label = !empty($row['room_number']) 
                    ? ($row['block_name'] ?? 'Block') . " • Rm " . $row['room_number'] . " (Bed " . ($row['bed_number'] ?? '1') . ")"
                    : 'Unassigned';

                // Check-in / Out gate logs
                $has_checked_in = !empty($row['check_in_at']);
                $has_checked_out = !empty($row['check_out_at']);
            ?>
              <tr>
                <td class="text-center font-weight-600 text-muted"><?= $sl++ ?></td>

                <!-- Visit Date & Time -->
                <td>
                  <strong class="text-dark d-block" style="font-size: 0.88rem;"><?= $visit_date_fmt ?></strong>
                  <small class="badge badge-light border text-muted px-2 py-0 mb-1" style="font-size: 0.72rem;"><?= $visit_day_fmt ?></small>
                  <div class="text-muted" style="font-size: 0.76rem;">
                    <i class="fa-regular fa-clock mr-1 text-success"></i><?= $in_time_fmt ?> - <?= $out_time_fmt ?>
                  </div>
                </td>

                <!-- Visitor Information -->
                <td>
                  <div class="d-flex align-items-center">
                    <div class="student-initial-circle mr-2" style="background: #f0fdf4; color: #059669; border: 1.5px solid #d1fae5;">
                      <?= strtoupper(substr($row['name'], 0, 1)) ?>
                    </div>
                    <div>
                      <strong class="text-dark d-block" style="font-size: 0.9rem; line-height: 1.25;">
                        <?= htmlspecialchars($row['name']) ?>
                      </strong>
                      <small class="text-muted d-block" style="font-size: 0.77rem;">
                        <i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($row['phone']) ?>
                        <span class="badge badge-light border ml-1"><?= htmlspecialchars($row['relationship']) ?></span>
                      </small>
                    </div>
                  </div>
                </td>

                <!-- Student Information -->
                <td>
                  <div>
                    <span class="font-weight-700 text-dark d-block" style="font-size: 0.88rem;">
                      <?= htmlspecialchars($row['student_name']) ?>
                    </span>
                    <small class="text-secondary font-weight-600 d-block" style="font-size: 0.76rem;">
                      <i class="fa-solid fa-bed mr-1 text-primary"></i><?= htmlspecialchars($room_label) ?>
                    </small>
                    <small class="text-muted d-block" style="font-size: 0.74rem;">
                      <i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($row['student_phone'] ?? 'N/A') ?>
                    </small>
                  </div>
                </td>

                <!-- Purpose & ID Proof -->
                <td>
                  <span class="d-block text-dark font-weight-500 mb-1" style="font-size: 0.84rem; max-width: 220px; white-space: normal;">
                    <?= htmlspecialchars($row['purpose']) ?>
                  </span>
                  <?php if (!empty($row['id_proof_type']) || !empty($row['id_proof_number'])): ?>
                    <span class="id-proof-chip">
                      <i class="fa-regular fa-id-card mr-1 text-muted"></i>
                      <?= htmlspecialchars($row['id_proof_type'] ?? 'ID') ?>: <?= htmlspecialchars($row['id_proof_number'] ?? 'N/A') ?>
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Status Badge -->
                <td class="text-center">
                  <?php if ($status_clean === 'approved'): ?>
                    <span class="badge-v-status badge-v-approved">
                      <i class="fa-solid fa-circle-check"></i> Approved
                    </span>
                  <?php elseif ($status_clean === 'pending'): ?>
                    <span class="badge-v-status badge-v-pending">
                      <i class="fa-solid fa-clock"></i> Pending
                    </span>
                  <?php elseif ($status_clean === 'completed'): ?>
                    <span class="badge-v-status badge-v-completed">
                      <i class="fa-solid fa-flag-checkered"></i> Completed
                    </span>
                  <?php elseif ($status_clean === 'rejected'): ?>
                    <span class="badge-v-status badge-v-rejected">
                      <i class="fa-solid fa-circle-xmark"></i> Rejected
                    </span>
                  <?php else: ?>
                    <span class="badge-v-status badge-v-pending">
                      <?= htmlspecialchars(ucfirst($status_clean)) ?>
                    </span>
                  <?php endif; ?>

                  <!-- Gate Log status if available -->
                  <?php if ($has_checked_in && !$has_checked_out): ?>
                    <small class="badge badge-success text-white d-block mt-1 font-weight-bold" style="font-size: 0.68rem; background: #059669;">
                      <i class="fa-solid fa-door-open mr-1"></i>In Hostel
                    </small>
                  <?php elseif ($has_checked_out): ?>
                    <small class="badge badge-secondary d-block mt-1 font-weight-bold" style="font-size: 0.68rem;">
                      <i class="fa-solid fa-person-walking-arrow-right mr-1"></i>Departed
                    </small>
                  <?php endif; ?>
                </td>

                <!-- Approval / Remarks -->
                <td>
                  <?php if (!empty($row['warden_approver_name'])): ?>
                    <small class="font-weight-700 text-dark d-block" style="font-size: 0.78rem;">
                      <i class="fa-solid fa-user-shield text-success mr-1"></i>Warden: <?= htmlspecialchars($row['warden_approver_name']) ?>
                    </small>
                  <?php endif; ?>
                  <span class="text-muted d-block" style="font-size: 0.78rem; white-space: normal; word-break: break-word;">
                    <?= !empty($row['remarks']) ? htmlspecialchars($row['remarks']) : '<span class="text-muted font-italic">-</span>' ?>
                  </span>
                </td>

                <!-- Actions: Change Status & Gate Controls -->
                <td class="text-center no-print">
                  <div class="d-flex flex-column align-items-center gap-1">
                    <!-- Quick Action Shortcuts -->
                    <div class="d-inline-flex gap-1 align-items-center">
                      <?php if ($status_clean === 'pending'): ?>
                        <a href="visitors.php?quick_action=approve&id=<?= $v_id ?>" class="btn btn-v-action btn-v-approve" title="Quick Approve">
                          <i class="fa-solid fa-check"></i> Approve
                        </a>
                        <button type="button" class="btn btn-v-action btn-v-reject"
                                onclick="openRejectModal(<?= $v_id ?>)"
                                title="Reject Visitor Request with Remark">
                          <i class="fa-solid fa-xmark"></i> Reject
                        </button>
                      <?php elseif ($status_clean === 'approved'): ?>
                        <?php if (!$has_checked_in): ?>
                          <button type="button" class="btn btn-sm btn-outline-info font-weight-700 py-1 px-2 btn-gate-modal"
                                  data-id="<?= $v_id ?>"
                                  data-name="<?= htmlspecialchars($row['name']) ?>"
                                  data-action-type="checkin"
                                  title="Log Check-in">
                            <i class="fa-solid fa-door-open mr-1"></i> Check-in
                          </button>
                          <button type="button" class="btn btn-v-action btn-v-reject"
                                  onclick="openRejectModal(<?= $v_id ?>)"
                                  title="Revoke & Reject Visitor Request">
                            <i class="fa-solid fa-xmark"></i> Reject
                          </button>
                        <?php elseif ($has_checked_in && !$has_checked_out): ?>
                          <button type="button" class="btn btn-sm btn-outline-dark font-weight-700 py-1 px-2 btn-gate-modal"
                                  data-id="<?= $v_id ?>"
                                  data-name="<?= htmlspecialchars($row['name']) ?>"
                                  data-action-type="checkout"
                                  title="Log Check-out">
                            <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> Check-out
                          </button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- =========================================================
     MODAL 1: CHANGE STATUS & WARDEN REMARKS (KEY REQUIREMENT)
     ========================================================= -->
<div class="modal fade" id="changeStatusModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content rounded-16 border-0 shadow-lg">
      <div class="modal-header bg-success text-white" style="background: linear-gradient(135deg, #064e3b 0%, #059669 100%) !important; border-top-left-radius: 16px; border-top-right-radius: 16px;">
        <h5 class="modal-title font-weight-700 text-white">
          <i class="fa-solid fa-pen-to-square mr-2"></i> Update Visitor Request Status
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="visitors.php">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="visitor_id" id="modal_status_v_id" value="">

        <div class="modal-body p-4">
          <!-- Visitor & Student Info Box -->
          <div class="alert alert-light border rounded-12 p-3 mb-3" style="background: #f8fafc;">
            <div class="row">
              <div class="col-6 mb-1">
                <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Visitor</small>
                <strong class="text-dark" id="modal_status_v_name">-</strong>
              </div>
              <div class="col-6 mb-1">
                <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Visiting Student</small>
                <strong class="text-dark" id="modal_status_s_name">-</strong>
              </div>
              <div class="col-12 mt-1">
                <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Scheduled Date</small>
                <span class="text-success font-weight-600" id="modal_status_v_date">-</span>
              </div>
            </div>
          </div>

          <!-- Rejection Warning Alert (Appears when Rejected is selected) -->
          <div id="status_rejection_alert" class="alert alert-danger rounded-12 p-3 mb-3 d-none border-0" style="background: #fef2f2; color: #991b1b;">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            <strong>Rejection Reason Required:</strong> Please provide a clear explanation for declining this visitor request so it is logged in the system.
          </div>

          <!-- Select New Status -->
          <div class="form-group mb-3">
            <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">
              Set New Status <span class="text-danger">*</span>
            </label>
            <select name="status" id="modal_status_select" class="form-control custom-form-input" required>
              <option value="approved">Approved - Authorize Entry Pass</option>
              <option value="pending">Pending - Keep Under Review</option>
              <option value="rejected">Rejected - Deny Visiting Permission</option>
              <option value="completed">Completed - Visit Concluded & Closed</option>
            </select>
          </div>

          <!-- Remarks / Reason -->
          <div class="form-group mb-2">
            <label class="font-weight-700 text-dark" id="modal_status_remarks_label" style="font-size: 0.85rem;">
              Warden Remarks / Decision Justification
            </label>
            <textarea name="remarks" id="modal_status_remarks" class="form-control custom-form-input" rows="3" placeholder="Enter reason for approval, rejection, or gate entry notes..."></textarea>
            <small class="text-muted font-weight-500">Reviewed by: <strong><?= htmlspecialchars($warden_name) ?> (<?= htmlspecialchars($warden_code) ?>)</strong></small>
          </div>

          <!-- Quick Rejection Preset Chips for Status Modal -->
          <div id="status_modal_reject_chips" class="reject-presets-container d-none">
            <div class="reject-presets-title">
              <i class="fa-solid fa-bolt text-warning"></i> Quick Reason Presets:
            </div>
            <div class="quick-reject-chips-grid">
              <button type="button" class="quick-reject-chip-pill status-reject-chip" data-reason="Outside visiting hours (Visiting hours: 4:00 PM - 7:00 PM)">
                Visiting Hours Closed
              </button>
              <button type="button" class="quick-reject-chip-pill status-reject-chip" data-reason="Invalid or unverified ID proof document">
                Invalid ID Proof
              </button>
              <button type="button" class="quick-reject-chip-pill status-reject-chip" data-reason="Student unavailable / study & exam preparation period">
                Exam Period
              </button>
              <button type="button" class="quick-reject-chip-pill status-reject-chip" data-reason="Unauthorized guest per hostel safety rules">
                Policy Restriction
              </button>
              <button type="button" class="quick-reject-chip-pill status-reject-chip" data-reason="Parent / guardian verbal consent missing">
                Guardian Consent Missing
              </button>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light p-3" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
          <button type="button" class="btn btn-secondary font-weight-600 rounded-10" data-dismiss="modal">Cancel</button>
          <button type="submit" id="btn_save_status_change" class="btn btn-success font-weight-700 rounded-10 px-4" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none;">
            <i class="fa-solid fa-check mr-1"></i> Save Status Change
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAL 1B: DEDICATED REJECT VISITOR MODAL WITH REQUIRED REMARK
     ========================================================= -->
<div class="modal fade" id="rejectVisitorModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 530px;">
    <div class="modal-content modal-reject-card">
      <div class="modal-header modal-reject-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-circle-xmark mr-1"></i> Reject Visitor Request
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="visitors.php" id="rejectVisitorForm">
        <input type="hidden" name="action" value="reject_visitor">
        <input type="hidden" name="visitor_id" id="reject_modal_v_id" value="">

        <div class="modal-body modal-reject-body">
          <div class="form-group mb-0">
            <label class="font-weight-700 text-dark mb-2" style="font-size: 0.88rem;">
              Rejection Reason / Remark <span class="text-danger">*</span>
            </label>
            <textarea name="remarks" id="reject_modal_remarks" class="form-control reject-textarea-input" rows="4" placeholder="Enter reason for rejection (required)..." required></textarea>
          </div>
        </div>

        <div class="modal-footer modal-reject-footer">
          <button type="button" class="btn btn-reject-cancel" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-reject-confirm" id="btn_confirm_reject">
            <i class="fa-solid fa-ban"></i> Confirm Rejection
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAL 2: GATE CHECK-IN & CHECK-OUT MODAL
     ========================================================= -->
<div class="modal fade" id="gateLogModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content rounded-16 border-0 shadow-lg">
      <div class="modal-header bg-dark text-white" style="background: #0f172a !important; border-top-left-radius: 16px; border-top-right-radius: 16px;">
        <h5 class="modal-title font-weight-700 text-white" id="gateModalTitle">
          <i class="fa-solid fa-door-open mr-2 text-warning"></i> Log Gate Movement
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="visitors.php" id="gateLogForm">
        <input type="hidden" name="action" id="gate_action_input" value="log_checkin">
        <input type="hidden" name="visitor_id" id="gate_visitor_id" value="">

        <div class="modal-body p-4">
          <p class="text-muted mb-3" style="font-size: 0.9rem;">
            Record physical hostel gate entry or departure timestamp for: <strong class="text-dark" id="gate_visitor_name">-</strong>
          </p>

          <div class="alert alert-info rounded-12 p-3 mb-3 border-0" style="background: #e0f2fe; color: #0369a1;">
            <i class="fa-solid fa-clock mr-1"></i> Current Server Time: <strong><?= date('d M Y, h:i A') ?></strong>
          </div>

          <div class="form-group mb-0">
            <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">Gate Security Notes (Optional)</label>
            <input type="text" name="gate_remarks" class="form-control custom-form-input" placeholder="e.g. Identity verified at Main Gate A">
          </div>
        </div>

        <div class="modal-footer bg-light p-3" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
          <button type="button" class="btn btn-secondary font-weight-600 rounded-10" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary font-weight-700 rounded-10 px-4" id="gateSubmitBtn">
            Confirm Log
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAL 3: VIEW FULL VISITOR PASS DETAILS
     ========================================================= -->
<div class="modal fade" id="viewVisitorModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content rounded-16 border-0 shadow-lg">
      <div class="modal-header bg-dark text-white" style="background: #064e3b !important; border-top-left-radius: 16px; border-top-right-radius: 16px;">
        <h5 class="modal-title font-weight-700 text-white">
          <i class="fa-solid fa-id-badge mr-2 text-success"></i> Visitor Pass Details
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body p-4" id="printPassArea">
        <div class="text-center pb-3 border-bottom mb-3">
          <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2" style="width: 56px; height: 56px; background: #d1fae5; color: #059669; font-size: 1.4rem;">
            <i class="fa-solid fa-user-check"></i>
          </div>
          <h5 class="font-weight-800 text-dark mb-0" id="v_pass_name">-</h5>
          <span class="text-muted font-weight-600" style="font-size: 0.85rem;" id="v_pass_phone">-</span>
        </div>

        <div class="row">
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Relationship</small>
            <span class="font-weight-600 text-dark" id="v_pass_rel">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">ID Proof Verified</small>
            <span class="font-weight-600 text-dark" id="v_pass_id_proof">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Visiting Student</small>
            <span class="font-weight-700 text-primary" id="v_pass_student">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Room & Bed</small>
            <span class="font-weight-600 text-dark" id="v_pass_room">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Visit Date</small>
            <span class="font-weight-700 text-success" id="v_pass_date">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Expected Window</small>
            <span class="font-weight-600 text-dark" id="v_pass_timing">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Current Status</small>
            <span class="badge badge-success font-weight-700 px-3 py-1" id="v_pass_status">-</span>
          </div>
        </div>

        <div class="mt-2 pt-2 border-top">
          <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Purpose of Visit</small>
          <p class="text-dark mb-2 font-weight-500" style="font-size: 0.88rem;" id="v_pass_purpose">-</p>
        </div>

        <div class="p-3 bg-light rounded-12 border mt-2">
          <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Warden Approval & Remarks</small>
          <span class="text-secondary font-weight-500" style="font-size: 0.84rem;" id="v_pass_remarks">-</span>
        </div>
      </div>

      <div class="modal-footer bg-light p-3" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
        <button type="button" class="btn btn-secondary font-weight-600 rounded-10" data-dismiss="modal">Close</button>
        <button type="button" class="btn btn-outline-success font-weight-700 rounded-10" onclick="printVisitorPassArea()">
          <i class="fa-solid fa-print mr-1"></i> Print Pass
        </button>
      </div>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAL 4: ADD DIRECT WALK-IN VISITOR
     ========================================================= -->
<div class="modal fade" id="addWalkinModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content rounded-16 border-0 shadow-lg">
      <div class="modal-header bg-success text-white" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%) !important; border-top-left-radius: 16px; border-top-right-radius: 16px;">
        <h5 class="modal-title font-weight-700 text-white">
          <i class="fa-solid fa-user-plus mr-2"></i> Register Walk-in Visitor at Gate / Desk
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="visitors.php">
        <input type="hidden" name="action" value="add_walkin_visitor">

        <div class="modal-body p-4">
          <div class="row">
            <!-- Resident Student -->
            <div class="col-md-12 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">
                Select Resident Student being Visited <span class="text-danger">*</span>
              </label>
              <select name="student_id" class="form-control custom-form-input" required>
                <option value="">-- Choose Resident Student --</option>
                <?php foreach ($active_students as $st): ?>
                  <?php 
                  $r_text = !empty($st['room_number']) ? (" [" . ($st['block_name'] ?? 'Block') . " Rm " . $st['room_number'] . " Bed " . ($st['bed_number'] ?? '1') . "]") : " [No Room]";
                  ?>
                  <option value="<?= $st['id'] ?>">
                    <?= htmlspecialchars($st['name']) ?><?= $r_text ?> - <?= htmlspecialchars($st['phone']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Visitor Name -->
            <div class="col-md-6 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">
                Visitor Full Name <span class="text-danger">*</span>
              </label>
              <input type="text" name="name" class="form-control custom-form-input" placeholder="e.g. Ramesh Kumar" required>
            </div>

            <!-- Visitor Phone -->
            <div class="col-md-6 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">
                Visitor Phone Number <span class="text-danger">*</span>
              </label>
              <input type="tel" name="phone" class="form-control custom-form-input" placeholder="10-digit mobile number" required>
            </div>

            <!-- Relationship -->
            <div class="col-md-4 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">Relationship</label>
              <select name="relationship" class="form-control custom-form-input">
                <option value="Parent">Parent</option>
                <option value="Guardian">Guardian</option>
                <option value="Sibling">Sibling</option>
                <option value="Relative">Relative</option>
                <option value="Friend">Friend</option>
                <option value="Official">Official / Courier</option>
              </select>
            </div>

            <!-- ID Proof Type -->
            <div class="col-md-4 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">ID Proof Type</label>
              <select name="id_proof_type" class="form-control custom-form-input">
                <option value="Aadhaar Card">Aadhaar Card</option>
                <option value="Driving License">Driving License</option>
                <option value="Voter ID">Voter ID</option>
                <option value="Passport">Passport</option>
                <option value="College/Company ID">College/Company ID</option>
              </select>
            </div>

            <!-- ID Proof Number -->
            <div class="col-md-4 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">ID Number / Digits</label>
              <input type="text" name="id_proof_number" class="form-control custom-form-input" placeholder="e.g. 1234-5678-9012">
            </div>

            <!-- Visit Date -->
            <div class="col-md-4 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">Visit Date <span class="text-danger">*</span></label>
              <input type="date" name="visit_date" class="form-control custom-form-input" value="<?= date('Y-m-d') ?>" required>
            </div>

            <!-- Expected In Time -->
            <div class="col-md-4 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">Time In <span class="text-danger">*</span></label>
              <input type="time" name="expected_in_time" class="form-control custom-form-input" value="<?= date('H:i') ?>" required>
            </div>

            <!-- Expected Out Time -->
            <div class="col-md-4 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">Expected Time Out</label>
              <input type="time" name="expected_out_time" class="form-control custom-form-input" value="<?= date('H:i', strtotime('+2 hours')) ?>">
            </div>

            <!-- Purpose -->
            <div class="col-md-12 mb-3">
              <label class="font-weight-700 text-dark" style="font-size: 0.85rem;">Purpose of Visit <span class="text-danger">*</span></label>
              <textarea name="purpose" class="form-control custom-form-input" rows="2" placeholder="e.g. Delivering luggage, parent meeting" required></textarea>
            </div>

            <!-- Immediate Check-in Checkbox -->
            <div class="col-md-12">
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="checkinNow" name="immediate_checkin" value="1" checked>
                <label class="custom-control-label font-weight-700 text-success" for="checkinNow" style="cursor: pointer;">
                  <i class="fa-solid fa-door-open mr-1"></i> Log physical gate entry immediately right now
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light p-3" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
          <button type="button" class="btn btn-secondary font-weight-600 rounded-10" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success font-weight-700 rounded-10 px-4" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none;">
            <i class="fa-solid fa-check mr-1"></i> Save & Authorize Walk-in
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================================================
     JAVASCRIPT LOGIC
     ========================================================= -->
<script>
// Export Table Data to CSV
function exportVisitorsToCSV() {
  const table = document.getElementById('visitorsBookTable');
  if (!table) {
    alert("No records to export.");
    return;
  }

  let csv = [];
  const rows = table.querySelectorAll('tr');

  for (let i = 0; i < rows.length; i++) {
    let row = [], cols = rows[i].querySelectorAll('td, th');
    // omit the last column (Actions)
    for (let j = 0; j < cols.length - 1; j++) {
      let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
      row.push('"' + data + '"');
    }
    csv.push(row.join(','));
  }

  const csvFile = new Blob([csv.join('\n')], { type: "text/csv" });
  const downloadLink = document.createElement("a");
  const fileName = "warden_visitors_book_" + new Date().toISOString().slice(0, 10) + ".csv";

  downloadLink.download = fileName;
  downloadLink.href = window.URL.createObjectURL(csvFile);
  downloadLink.style.display = "none";
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}

// Print Pass Area
function printVisitorPassArea() {
  const printContents = document.getElementById('printPassArea').innerHTML;
  const originalContents = document.body.innerHTML;
  document.body.innerHTML = `
    <div style="padding: 2rem; max-width: 500px; margin: 0 auto; border: 2px solid #059669; border-radius: 16px; font-family: sans-serif;">
      <h3 style="text-align: center; color: #059669; margin-bottom: 5px;">HOSTEL MATE - OFFICIAL VISITOR PASS</h3>
      <p style="text-align: center; color: #64748b; font-size: 12px; margin-bottom: 20px;">Gate Authorization Document</p>
      ${printContents}
    </div>
  `;
  window.print();
  document.body.innerHTML = originalContents;
  window.location.reload();
}

// Global openRejectModal function directly callable from inline onclick
function openRejectModal(id) {
  var numId = parseInt(id, 10) || 0;
  if (!numId) {
    alert("Please select a valid visitor request.");
    return false;
  }

  // Set visitor ID directly on hidden form input
  var input = document.getElementById('reject_modal_v_id');
  if (input) {
    input.value = numId;
  }

  // Reset textarea
  var textarea = document.getElementById('reject_modal_remarks');
  if (textarea) {
    textarea.value = '';
  }

  // Reset confirm button
  var submitBtn = document.getElementById('btn_confirm_reject');
  if (submitBtn) {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-ban mr-1"></i> Confirm Rejection';
  }

  // Open modal cleanly using Bootstrap modal
  if (window.jQuery && typeof jQuery.fn.modal === 'function') {
    jQuery('#rejectVisitorModal').modal('show');
    setTimeout(function() {
      if (textarea) textarea.focus();
    }, 350);
  } else {
    var modalEl = document.getElementById('rejectVisitorModal');
    if (modalEl) {
      modalEl.classList.add('show');
      modalEl.style.display = 'block';
    }
  }
}
window.openRejectModal = openRejectModal;

$(document).ready(function() {
  // Initialize DataTables
  if ($.fn.DataTable && $('#visitorsBookTable').length) {
    $('#visitorsBookTable').DataTable({
      "order": [[1, "desc"]],
      "pageLength": 25,
      "language": {
        "search": "_INPUT_",
        "searchPlaceholder": "Search visitor, student...",
        "emptyTable": "No visitor entries found matching criteria"
      }
    });
  }

  // Reject form validation on submission
  $('#rejectVisitorForm').on('submit', function(e) {
    var vId = parseInt($('#reject_modal_v_id').val(), 10) || 0;
    var remarks = $.trim($('#reject_modal_remarks').val());
    if (vId <= 0) {
      e.preventDefault();
      alert('Error: Missing visitor request ID. Please click Reject again.');
      return false;
    }
    if (!remarks) {
      e.preventDefault();
      alert('Please enter a rejection reason.');
      $('#reject_modal_remarks').focus();
      return false;
    }
    $('#btn_confirm_reject').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin mr-1"></i> Rejecting...');
    return true;
  });

  // Populate Change Status Modal
  $(document).on('click', '.btn-change-status-modal', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const student = $(this).data('student');
    const date = $(this).data('date');
    const status = $(this).data('status');
    const remarks = $(this).data('remarks');

    $('#modal_status_v_id').val(id);
    $('#modal_status_v_name').text(name);
    $('#modal_status_s_name').text(student);
    $('#modal_status_v_date').text(date);
    $('#modal_status_select').val(status);
    $('#modal_status_remarks').val(remarks);

    // Trigger status change UI update
    updateStatusModalUI(status);

    $('#changeStatusModal').modal('show');
  });

  // Dynamic UI update function for changeStatusModal
  function updateStatusModalUI(statusVal) {
    if (statusVal === 'rejected') {
      $('#status_rejection_alert').removeClass('d-none');
      $('#status_modal_reject_chips').removeClass('d-none');
      $('#modal_status_remarks_label').html('Rejection Reason / Justification <span class="text-danger">*</span>');
      $('#modal_status_remarks').attr('required', true).attr('placeholder', 'Please provide a clear reason for rejecting this visitor request...');
      $('#btn_save_status_change').removeClass('btn-success').addClass('btn-danger').html('<i class="fa-solid fa-ban mr-1"></i> Save as Rejected');
    } else {
      $('#status_rejection_alert').addClass('d-none');
      $('#status_modal_reject_chips').addClass('d-none');
      $('#modal_status_remarks_label').html('Warden Remarks / Decision Justification');
      $('#modal_status_remarks').removeAttr('required').attr('placeholder', 'Enter reason for approval, rejection, or gate entry notes...');
      $('#btn_save_status_change').removeClass('btn-danger').addClass('btn-success').html('<i class="fa-solid fa-check mr-1"></i> Save Status Change');
    }
  }

  // Handle status dropdown change
  $('#modal_status_select').on('change', function() {
    updateStatusModalUI($(this).val());
  });

  // Status modal quick chip click
  $(document).on('click', '.status-reject-chip', function() {
    const reason = $(this).data('reason');
    $('#modal_status_remarks').val(reason).focus();
  });

  // Populate Gate Checkin/Checkout Modal
  $(document).on('click', '.btn-gate-modal', function() {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const actType = $(this).data('action-type');

    $('#gate_visitor_id').val(id);
    $('#gate_visitor_name').text(name);

    if (actType === 'checkin') {
      $('#gate_action_input').val('log_checkin');
      $('#gateModalTitle').html('<i class="fa-solid fa-door-open mr-2 text-success"></i> Log Gate Check-In');
      $('#gateSubmitBtn').removeClass('btn-dark').addClass('btn-success').text('Confirm Check-in');
    } else {
      $('#gate_action_input').val('log_checkout');
      $('#gateModalTitle').html('<i class="fa-solid fa-arrow-right-from-bracket mr-2 text-danger"></i> Log Gate Check-Out');
      $('#gateSubmitBtn').removeClass('btn-success').addClass('btn-dark').text('Confirm Check-out & Close');
    }

    $('#gateLogModal').modal('show');
  });

  // Populate View Modal
  $(document).on('click', '.btn-view-visitor', function() {
    $('#v_pass_name').text($(this).data('name'));
    $('#v_pass_phone').text($(this).data('phone'));
    $('#v_pass_rel').text($(this).data('rel'));
    $('#v_pass_id_proof').text($(this).data('id-type') + ' (' + $(this).data('id-num') + ')');
    $('#v_pass_student').text($(this).data('student'));
    $('#v_pass_room').text($(this).data('room'));
    $('#v_pass_date').text($(this).data('date'));
    $('#v_pass_timing').text($(this).data('timing'));
    $('#v_pass_purpose').text($(this).data('purpose'));
    $('#v_pass_status').text($(this).data('status'));
    $('#v_pass_remarks').text($(this).data('remarks'));

    $('#viewVisitorModal').modal('show');
  });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
