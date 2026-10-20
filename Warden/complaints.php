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

// Auto-verify complaints table exists with all required fields
$create_complaints_sql = "CREATE TABLE IF NOT EXISTS `complaints` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('pending','in_progress','resolved','rejected') NOT NULL DEFAULT 'pending',
    `assigned_to` BIGINT DEFAULT NULL,
    `response` TEXT DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_c_student` (`student_id`),
    INDEX `idx_c_status` (`status`),
    INDEX `idx_c_priority` (`priority`),
    INDEX `idx_c_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_complaints_sql);

// -------------------------------------------------------------
// POST HANDLERS: STATUS UPDATE & RESOLUTION REMARKS
// -------------------------------------------------------------

// 1. Update Complaint Status & Warden Response
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_complaint_status') {
    $complaint_id = intval($_POST['complaint_id'] ?? 0);
    $new_status   = strtolower(trim($_POST['status'] ?? 'pending'));
    $response     = trim($_POST['response'] ?? '');
    
    $allowed_statuses = ['pending', 'in_progress', 'resolved', 'rejected'];
    if (!in_array($new_status, $allowed_statuses)) {
        $new_status = 'pending';
    }

    if ($complaint_id > 0) {
        // Fetch complaint & student info for friendly notification
        $c_info_q = $conn->query("SELECT c.title, u.name as student_name FROM complaints c LEFT JOIN users u ON c.student_id = u.id WHERE c.id = {$complaint_id} LIMIT 1");
        $c_info = ($c_info_q && $c_info_q->num_rows > 0) ? $c_info_q->fetch_assoc() : null;
        $c_title = $c_info['title'] ?? "Complaint #{$complaint_id}";
        $s_name = $c_info['student_name'] ?? 'Resident Student';

        if ($new_status === 'resolved') {
            $stmt = $conn->prepare("UPDATE `complaints` SET `status` = ?, `assigned_to` = ?, `response` = ?, `resolved_at` = NOW(), `updated_at` = NOW() WHERE `id` = ?");
        } else {
            $stmt = $conn->prepare("UPDATE `complaints` SET `status` = ?, `assigned_to` = ?, `response` = ?, `resolved_at` = NULL, `updated_at` = NOW() WHERE `id` = ?");
        }

        if ($stmt) {
            $stmt->bind_param("sisi", $new_status, $warden_id, $response, $complaint_id);
            if ($stmt->execute()) {
                $status_label = ucfirst(str_replace('_', ' ', $new_status));
                $_SESSION['msg'] = "Complaint '{$c_title}' (by {$s_name}) has been updated to {$status_label}!";
            } else {
                $_SESSION['error'] = "Failed to update complaint: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Invalid complaint ID specified.";
    }

    header("Location: complaints.php");
    exit();
}

// 2. Quick 1-Click Status Shortcut (GET)
if (isset($_GET['quick_action']) && isset($_GET['id'])) {
    $c_id = intval($_GET['id']);
    $quick_act = trim($_GET['quick_action']);

    if ($c_id > 0) {
        $c_check = $conn->query("SELECT c.title, u.name as student_name FROM complaints c LEFT JOIN users u ON c.student_id = u.id WHERE c.id = {$c_id} LIMIT 1");
        $c_row = ($c_check && $c_check->num_rows > 0) ? $c_check->fetch_assoc() : null;
        $c_title = $c_row['title'] ?? "Complaint #{$c_id}";

        if ($quick_act === 'start_work') {
            $quick_resp = "Work initiated by Warden " . $warden_name;
            $stmt = $conn->prepare("UPDATE `complaints` SET `status` = 'in_progress', `assigned_to` = ?, `response` = IF(response IS NULL OR response = '', ?, response), `updated_at` = NOW() WHERE `id` = ?");
            if ($stmt) {
                $stmt->bind_param("isi", $warden_id, $quick_resp, $c_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['msg'] = "Complaint '{$c_title}' marked as IN PROGRESS. Maintenance initiated!";
            }
        } elseif ($quick_act === 'resolve') {
            $quick_resp = "Resolved and verified by Warden " . $warden_name;
            $stmt = $conn->prepare("UPDATE `complaints` SET `status` = 'resolved', `assigned_to` = ?, `response` = IF(response IS NULL OR response = '', ?, response), `resolved_at` = NOW(), `updated_at` = NOW() WHERE `id` = ?");
            if ($stmt) {
                $stmt->bind_param("isi", $warden_id, $quick_resp, $c_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['msg'] = "Complaint '{$c_title}' successfully marked as RESOLVED!";
            }
        }
    }
    header("Location: complaints.php");
    exit();
}

// -------------------------------------------------------------
// FILTERING & DATA RETRIEVAL (DATE-WISE & MULTI-CRITERIA)
// -------------------------------------------------------------

$status_filter   = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$priority_filter = isset($_GET['priority']) ? strtolower(trim($_GET['priority'])) : 'all';
$date_filter     = isset($_GET['date']) ? trim($_GET['date']) : '';
$from_date       = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date         = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$block_filter    = isset($_GET['block_id']) ? intval($_GET['block_id']) : 0;
$search_keyword  = isset($_GET['search']) ? trim($_GET['search']) : '';
$preset          = $_GET['preset'] ?? '';

// Handle Date Presets
$active_preset = '';
if ($preset === 'today') {
    $date_filter = date('Y-m-d');
    $active_preset = 'today';
} elseif ($preset === 'yesterday') {
    $date_filter = date('Y-m-d', strtotime('-1 day'));
    $active_preset = 'yesterday';
} elseif ($preset === 'this_week') {
    $from_date = date('Y-m-d', strtotime('monday this week'));
    $to_date = date('Y-m-d');
    $active_preset = 'this_week';
} elseif ($preset === 'this_month') {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
    $active_preset = 'this_month';
} elseif ($preset === 'urgent') {
    $priority_filter = 'urgent';
    $active_preset = 'urgent';
} elseif ($preset === 'pending') {
    $status_filter = 'pending';
    $active_preset = 'pending';
}

// Build WHERE conditions
$where_clauses = ["1=1"];

// 1. Single Date Filter
if (!empty($date_filter)) {
    $safe_d = $conn->real_escape_string($date_filter);
    $where_clauses[] = "DATE(c.created_at) = '{$safe_d}'";
}

// 2. Date Range Filter
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to   = $conn->real_escape_string($to_date);
    $where_clauses[] = "DATE(c.created_at) BETWEEN '{$safe_from}' AND '{$safe_to}'";
} elseif (!empty($from_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $where_clauses[] = "DATE(c.created_at) >= '{$safe_from}'";
} elseif (!empty($to_date)) {
    $safe_to = $conn->real_escape_string($to_date);
    $where_clauses[] = "DATE(c.created_at) <= '{$safe_to}'";
}

// 3. Status Filter
if ($status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "c.status = '{$safe_status}'";
}

// 4. Priority Filter
if ($priority_filter !== 'all') {
    $safe_prio = $conn->real_escape_string($priority_filter);
    $where_clauses[] = "c.priority = '{$safe_prio}'";
}

// 5. Block Filter
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// 6. Search Keyword
if (!empty($search_keyword)) {
    $safe_q = $conn->real_escape_string($search_keyword);
    $where_clauses[] = "(c.title LIKE '%{$safe_q}%' OR c.description LIKE '%{$safe_q}%' OR u.name LIKE '%{$safe_q}%' OR u.phone LIKE '%{$safe_q}%' OR rm.room_number LIKE '%{$safe_q}%' OR c.response LIKE '%{$safe_q}%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch KPI statistics (independent of some filters to give high-level overview)
$kpi_query = $conn->query("
    SELECT 
        COUNT(*) as total_cnt,
        SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN c.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_cnt,
        SUM(CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END) as resolved_cnt,
        SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected_cnt,
        SUM(CASE WHEN c.priority IN ('high', 'urgent') AND c.status != 'resolved' THEN 1 ELSE 0 END) as urgent_open_cnt
    FROM complaints c
");
$kpi_stats = ($kpi_query && $kpi_query->num_rows > 0) ? $kpi_query->fetch_assoc() : [];

$kpi_total       = intval($kpi_stats['total_cnt'] ?? 0);
$kpi_pending     = intval($kpi_stats['pending_cnt'] ?? 0);
$kpi_in_progress = intval($kpi_stats['in_progress_cnt'] ?? 0);
$kpi_resolved    = intval($kpi_stats['resolved_cnt'] ?? 0);
$kpi_rejected    = intval($kpi_stats['rejected_cnt'] ?? 0);
$kpi_urgent_open = intval($kpi_stats['urgent_open_cnt'] ?? 0);

// Fetch all available blocks for block filter dropdown
$blocks_res = $conn->query("SELECT id, name FROM blocks ORDER BY name ASC");
$all_blocks = [];
if ($blocks_res && $blocks_res->num_rows > 0) {
    while ($b = $blocks_res->fetch_assoc()) {
        $all_blocks[] = $b;
    }
}

// Fetch Full Complaints Roster with Resident, Room, and Warden Info
$complaints_sql = "
    SELECT 
        c.*,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        bd.bed_number,
        w.name AS warden_handler_name,
        w.code AS warden_handler_code
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN wardens w ON c.assigned_to = w.id
    WHERE {$where_sql}
    ORDER BY c.created_at DESC, c.id DESC
";

$complaints_res = $conn->query($complaints_sql);
$complaints_list = [];
if ($complaints_res && $complaints_res->num_rows > 0) {
    while ($r = $complaints_res->fetch_assoc()) {
        $complaints_list[] = $r;
    }
}

ob_start();
?>

<!-- =========================================================
     COMPLAINTS MODULE - EMERALD & OBSIDIAN DESIGN SYSTEM
     ========================================================= -->
<style>
  :root {
    --c-primary: #059669;
    --c-primary-dark: #047857;
    --c-accent: #10b981;
    --c-dark: #0f172a;
    --c-card-bg: #ffffff;
    --c-border: #e2e8f0;
  }

  .complaints-header-card {
    background: linear-gradient(135deg, #064e3b 0%, #065f46 60%, #047857 100%);
    border-radius: 18px;
    padding: 1.85rem 2.2rem;
    color: #ffffff;
    box-shadow: 0 10px 25px -5px rgba(5, 150, 105, 0.35);
    position: relative;
    overflow: hidden;
    margin-bottom: 1.75rem;
  }

  .complaints-header-card::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 320px;
    height: 320px;
    background: radial-gradient(circle, rgba(16, 185, 129, 0.22) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
  }

  .c-kpi-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.25rem 1.4rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .c-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(5, 150, 105, 0.12);
    border-color: #a7f3d0;
  }

  .c-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
  }

  .icon-c-total       { background: #ecfdf5; color: #059669; }
  .icon-c-pending     { background: #fef3c7; color: #d97706; }
  .icon-c-progress    { background: #e0f2fe; color: #0284c7; }
  .icon-c-resolved    { background: #d1fae5; color: #059669; }
  .icon-c-urgent      { background: #fee2e2; color: #dc2626; }

  /* Filter Panel */
  .filter-glass-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 1.4rem 1.6rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
    margin-bottom: 1.75rem;
  }

  .custom-form-input {
    border-radius: 10px !important;
    border: 1.5px solid #cbd5e1 !important;
    font-size: 0.88rem !important;
    padding: 0.55rem 0.95rem !important;
    height: auto !important;
    transition: all 0.2s ease !important;
  }

  .custom-form-input:focus {
    border-color: #059669 !important;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.18) !important;
  }

  .preset-pill {
    padding: 0.45rem 1rem;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: 50px;
    border: 1.5px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    white-space: nowrap;
  }

  .preset-pill:hover {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
    transform: translateY(-1px);
  }

  .preset-pill.active {
    background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
    border-color: #059669;
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
  }

  /* Table Design */
  .complaints-table-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    overflow: hidden;
  }

  #complaintsTable thead th {
    background: #f8fafc;
    color: #334155;
    font-weight: 700;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    padding: 1rem 0.95rem;
    border-bottom: 2px solid #e2e8f0;
  }

  #complaintsTable tbody td {
    padding: 1rem 0.95rem;
    vertical-align: middle;
    font-size: 0.87rem;
    border-bottom: 1px solid #f1f5f9;
  }

  #complaintsTable tbody tr:hover {
    background-color: #f8fafc;
  }

  /* Priority Badges */
  .badge-prio {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    padding: 0.35rem 0.75rem;
    border-radius: 50px;
    line-height: 1.2;
  }

  .badge-prio-urgent {
    background: #fef2f2;
    color: #b91c1c;
    border: 1.5px solid #fecaca;
    animation: urgentPulse 2s infinite;
  }

  @keyframes urgentPulse {
    0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
    70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
  }

  .badge-prio-high {
    background: #fff7ed;
    color: #c2410c;
    border: 1.5px solid #ffedd5;
  }

  .badge-prio-medium {
    background: #fefce8;
    color: #a16207;
    border: 1.5px solid #fef08a;
  }

  .badge-prio-low {
    background: #f1f5f9;
    color: #475569;
    border: 1.5px solid #e2e8f0;
  }

  /* Status Badges */
  .badge-c-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.4rem 0.85rem;
    border-radius: 50px;
    line-height: 1.2;
  }

  .badge-c-pending {
    background: #fef3c7;
    color: #b45309;
    border: 1.5px solid #fde68a;
  }

  .badge-c-progress {
    background: #e0f2fe;
    color: #0369a1;
    border: 1.5px solid #bae6fd;
  }

  .badge-c-resolved {
    background: #d1fae5;
    color: #065f46;
    border: 1.5px solid #a7f3d0;
  }

  .badge-c-rejected {
    background: #fee2e2;
    color: #991b1b;
    border: 1.5px solid #fecaca;
  }

  /* Student Avatar Initial */
  .student-initial-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.95rem;
    flex-shrink: 0;
  }

  /* Thumbnail */
  .complaint-thumb-img {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    object-fit: cover;
    border: 1.5px solid #cbd5e1;
    cursor: pointer;
    transition: transform 0.2s ease;
  }

  .complaint-thumb-img:hover {
    transform: scale(1.08);
    border-color: #059669;
  }

  /* Action Buttons */
  .btn-c-action {
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 8px;
    padding: 0.42rem 0.75rem;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    border: none;
    text-decoration: none !important;
  }

  .btn-c-update {
    background: linear-gradient(135deg, #059669 0%, #0d9488 100%);
    color: #ffffff !important;
  }
  .btn-c-update:hover {
    background: linear-gradient(135deg, #047857 0%, #0f766e 100%);
    box-shadow: 0 4px 10px rgba(5, 150, 105, 0.28);
    transform: translateY(-1px);
  }

  .btn-c-view {
    background: #f1f5f9;
    color: #334155 !important;
    border: 1px solid #cbd5e1;
  }
  .btn-c-view:hover {
    background: #e2e8f0;
    color: #0f172a !important;
  }

  .btn-c-quick-resolve {
    background: #ecfdf5;
    color: #065f46 !important;
    border: 1px solid #a7f3d0;
  }
  .btn-c-quick-resolve:hover {
    background: #059669;
    color: #ffffff !important;
  }

  /* Resolution Remarks Preset Pills */
  .remark-chip-pill {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #334155;
    border-radius: 50px;
    padding: 0.35rem 0.75rem;
    font-size: 0.76rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    margin-bottom: 6px;
    margin-right: 6px;
    transition: all 0.2s ease;
  }

  .remark-chip-pill:hover {
    background: #ecfdf5;
    border-color: #059669;
    color: #065f46;
  }

  /* Print Styles */
  @media print {
    .no-print, .filter-glass-card, .complaints-header-card, .sidebar, .navbar, .footer, .c-kpi-card {
      display: none !important;
    }
    .complaints-table-card {
      border: none !important;
      box-shadow: none !important;
    }
    body {
      background: #ffffff !important;
    }
  }
</style>

<div class="container-fluid px-0">

  <!-- =========================================================
       PAGE HEADER WITH ACTIVE FILTER INDICATOR & ACTION BUTTONS
       ========================================================= -->
  <div class="complaints-header-card">
    <div class="row align-items-center">
      <div class="col-lg-8 col-md-7 mb-3 mb-md-0">
        <div class="d-inline-flex align-items-center px-3 py-1 rounded-pill mb-2" style="background: rgba(255,255,255,0.18); font-size: 0.8rem; font-weight: 600;">
          <i class="fa-solid fa-triangle-exclamation mr-1 text-warning"></i> Maintenance & Complaints Helpdesk
        </div>
        <h2 class="font-weight-800 text-white mb-1" style="font-size: 1.85rem; letter-spacing: -0.5px;">
          Student Complaints & Grievance Desk
        </h2>
        <p class="text-white-50 mb-0 font-weight-500" style="font-size: 0.92rem; max-width: 650px;">
          View, monitor, and resolve hostel maintenance requests, electrical, plumbing, carpentry, and facility issues submitted by resident students.
        </p>
      </div>
    </div>
  </div>

  <!-- =========================================================
       FLASH ALERTS
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
       KPI METRIC CARDS
       ========================================================= -->
  <div class="row mb-4">
    <!-- Total Complaints -->
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
      <div class="c-kpi-card">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Total Registered</span>
          <h3 class="font-weight-800 text-dark mb-0"><?= $kpi_total ?></h3>
        </div>
        <div class="c-kpi-icon icon-c-total">
          <i class="fa-solid fa-list-check"></i>
        </div>
      </div>
    </div>

    <!-- Pending Complaints -->
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
      <div class="c-kpi-card">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Pending Action</span>
          <h3 class="font-weight-800 text-warning mb-0" style="color: #d97706 !important;"><?= $kpi_pending ?></h3>
        </div>
        <div class="c-kpi-icon icon-c-pending">
          <i class="fa-solid fa-clock"></i>
        </div>
      </div>
    </div>

    <!-- In Progress -->
    <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
      <div class="c-kpi-card">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">In Progress</span>
          <h3 class="font-weight-800 text-info mb-0" style="color: #0284c7 !important;"><?= $kpi_in_progress ?></h3>
        </div>
        <div class="c-kpi-icon icon-c-progress">
          <i class="fa-solid fa-screwdriver-wrench"></i>
        </div>
      </div>
    </div>

    <!-- Resolved -->
    <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
      <div class="c-kpi-card">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Resolved & Closed</span>
          <h3 class="font-weight-800 text-success mb-0" style="color: #059669 !important;"><?= $kpi_resolved ?></h3>
        </div>
        <div class="c-kpi-icon icon-c-resolved">
          <i class="fa-solid fa-circle-check"></i>
        </div>
      </div>
    </div>

    <!-- Urgent / High Priority Open -->
    <div class="col-xl-3 col-md-8 col-sm-12 mb-3">
      <div class="c-kpi-card" style="border-left: 4px solid #ef4444;">
        <div>
          <span class="text-danger font-weight-700 d-block mb-1" style="font-size: 0.74rem; text-transform: uppercase;">Urgent & High (Open)</span>
          <h3 class="font-weight-800 text-danger mb-0"><?= $kpi_urgent_open ?></h3>
        </div>
        <div class="c-kpi-icon icon-c-urgent">
          <i class="fa-solid fa-fire-flame-curved"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       DATE-WISE FILTERING & CONTROLS PANEL (USER REQUIREMENT)
       ========================================================= -->
  <div class="filter-glass-card no-print">
    
    <!-- Quick Date & Status Presets Row -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom">
      <div class="d-flex align-items-center flex-wrap gap-2">
        <span class="font-weight-700 text-dark mr-1" style="font-size: 0.82rem;">
          <i class="fa-regular fa-calendar-check text-success mr-1"></i> Date Presets:
        </span>
        <a href="complaints.php?preset=all" class="preset-pill <?= ($active_preset === 'all' || empty($active_preset) && empty($date_filter) && empty($from_date)) ? 'active' : '' ?>">
          All Dates
        </a>
        <a href="complaints.php?preset=today" class="preset-pill <?= ($active_preset === 'today') ? 'active' : '' ?>">
          <i class="fa-solid fa-calendar-day"></i> Today
        </a>
        <a href="complaints.php?preset=yesterday" class="preset-pill <?= ($active_preset === 'yesterday') ? 'active' : '' ?>">
          Yesterday
        </a>
        <a href="complaints.php?preset=this_week" class="preset-pill <?= ($active_preset === 'this_week') ? 'active' : '' ?>">
          This Week
        </a>
        <a href="complaints.php?preset=this_month" class="preset-pill <?= ($active_preset === 'this_month') ? 'active' : '' ?>">
          <i class="fa-regular fa-calendar"></i> This Month
        </a>
      </div>

      <div class="d-flex align-items-center flex-wrap gap-2 mt-2 mt-md-0">
        <span class="font-weight-700 text-dark mr-1" style="font-size: 0.82rem;">
          <i class="fa-solid fa-filter text-primary mr-1"></i> Quick Filters:
        </span>
        <a href="complaints.php?preset=pending" class="preset-pill <?= ($active_preset === 'pending' || $status_filter === 'pending') ? 'active' : '' ?>">
          Pending (<?= $kpi_pending ?>)
        </a>
        <a href="complaints.php?preset=urgent" class="preset-pill <?= ($active_preset === 'urgent' || $priority_filter === 'urgent') ? 'active' : '' ?>" style="color: #b91c1c;">
          <i class="fa-solid fa-fire text-danger"></i> Urgent
        </a>
      </div>
    </div>

    <!-- Date Range & Multi-Criteria Filter Form -->
    <form method="GET" action="complaints.php" class="row align-items-end">
      <!-- Specific Date Filter -->
      <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">
          <i class="fa-regular fa-calendar mr-1 text-success"></i> Specific Date
        </label>
        <input type="date" name="date" class="form-control custom-form-input" value="<?= htmlspecialchars($date_filter) ?>">
      </div>

      <!-- Date Range: From Date -->
      <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">
          <i class="fa-solid fa-calendar-days mr-1 text-primary"></i> Date From
        </label>
        <input type="date" name="from_date" class="form-control custom-form-input" value="<?= htmlspecialchars($from_date) ?>">
      </div>

      <!-- Date Range: To Date -->
      <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">
          <i class="fa-solid fa-calendar-days mr-1 text-primary"></i> Date To
        </label>
        <input type="date" name="to_date" class="form-control custom-form-input" value="<?= htmlspecialchars($to_date) ?>">
      </div>

      <!-- Status Filter -->
      <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">Status</label>
        <select name="status" class="form-control custom-form-input">
          <option value="all" <?= ($status_filter === 'all') ? 'selected' : '' ?>>All Statuses</option>
          <option value="pending" <?= ($status_filter === 'pending') ? 'selected' : '' ?>>Pending</option>
          <option value="in_progress" <?= ($status_filter === 'in_progress') ? 'selected' : '' ?>>In Progress</option>
          <option value="resolved" <?= ($status_filter === 'resolved') ? 'selected' : '' ?>>Resolved</option>
          <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
        </select>
      </div>

      <!-- Priority Filter -->
      <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">Priority</label>
        <select name="priority" class="form-control custom-form-input">
          <option value="all" <?= ($priority_filter === 'all') ? 'selected' : '' ?>>All Priorities</option>
          <option value="urgent" <?= ($priority_filter === 'urgent') ? 'selected' : '' ?>>Urgent</option>
          <option value="high" <?= ($priority_filter === 'high') ? 'selected' : '' ?>>High</option>
          <option value="medium" <?= ($priority_filter === 'medium') ? 'selected' : '' ?>>Medium</option>
          <option value="low" <?= ($priority_filter === 'low') ? 'selected' : '' ?>>Low</option>
        </select>
      </div>

      <!-- Block Filter -->
      <div class="col-lg-2 col-md-3 col-sm-6 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">Block</label>
        <select name="block_id" class="form-control custom-form-input">
          <option value="0">All Blocks</option>
          <?php foreach ($all_blocks as $blk): ?>
            <option value="<?= $blk['id'] ?>" <?= ($block_filter == $blk['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($blk['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Live Search Keyword -->
      <div class="col-lg-8 col-md-7 mb-3">
        <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">
          <i class="fa-solid fa-magnifying-glass mr-1 text-muted"></i> Search Keywords
        </label>
        <div class="input-group">
          <input type="text" name="search" class="form-control custom-form-input" placeholder="Search by student name, complaint title, room number, or description..." value="<?= htmlspecialchars($search_keyword) ?>">
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="col-lg-4 col-md-5 mb-3 text-md-right">
        <button type="submit" class="btn btn-success font-weight-700 rounded-10 px-4 py-2 mr-2" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none;">
          <i class="fa-solid fa-filter mr-1"></i> Apply Filters
        </button>
        <a href="complaints.php" class="btn btn-outline-secondary font-weight-600 rounded-10 px-3 py-2">
          <i class="fa-solid fa-rotate-left mr-1"></i> Reset
        </a>
      </div>
    </form>
  </div>

  <!-- =========================================================
       COMPLAINTS ROSTER TABLE
       ========================================================= -->
  <div class="complaints-table-card">
    <div class="p-3 bg-white border-bottom d-flex align-items-center justify-content-between flex-wrap">
      <div>
        <h5 class="font-weight-800 text-dark mb-0" style="font-size: 1.05rem;">
          <i class="fa-solid fa-clipboard-list text-success mr-2"></i> Registered Complaints List
        </h5>
        <small class="text-muted font-weight-600">
          Showing <strong><?= count($complaints_list) ?></strong> complaint record(s) matching current criteria
        </small>
      </div>

      <div class="d-flex align-items-center gap-2 mt-2 mt-sm-0">
        <?php if (!empty($date_filter) || !empty($from_date) || $status_filter !== 'all' || $priority_filter !== 'all' || !empty($search_keyword)): ?>
          <span class="badge badge-light border text-muted px-2 py-1 font-weight-600" style="font-size: 0.76rem;">
            Filtered View Active
          </span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($complaints_list)): ?>
      <div class="text-center py-5 px-3">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 72px; height: 72px; background: #ecfdf5; color: #059669; font-size: 1.8rem;">
          <i class="fa-solid fa-circle-check"></i>
        </div>
        <h5 class="font-weight-800 text-dark mb-1">No Complaints Found</h5>
        <p class="text-muted mb-3" style="max-width: 450px; margin: 0 auto; font-size: 0.88rem;">
          No resident complaints match your selected date or status criteria. Clear the filters or select a different date range.
        </p>
        <a href="complaints.php" class="btn btn-outline-success font-weight-700 rounded-10 px-4 py-2">
          <i class="fa-solid fa-rotate-left mr-1"></i> View All Complaints
        </a>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="complaintsTable" style="width: 100%;">
          <thead>
            <tr>
              <th style="width: 45px;" class="text-center">#</th>
              <th style="min-width: 135px;">Date Filed</th>
              <th style="min-width: 210px;">Resident Student</th>
              <th style="min-width: 260px;">Complaint Details</th>
              <th style="width: 105px;" class="text-center">Priority</th>
              <th style="width: 120px;" class="text-center">Status</th>
              <th style="min-width: 180px;">Warden Resolution</th>
              <th style="width: 160px;" class="text-center no-print">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $sl = 1;
            foreach ($complaints_list as $row):
                $c_id         = $row['id'];
                $c_title      = $row['title'];
                $c_desc       = $row['description'];
                $c_img        = $row['image'];
                $c_prio       = strtolower($row['priority'] ?? 'medium');
                $c_status     = strtolower($row['status'] ?? 'pending');
                $c_response   = $row['response'];
                $c_created    = $row['created_at'];
                $c_date_fmt   = date('d M, Y', strtotime($c_created));
                $c_time_fmt   = date('h:i A', strtotime($c_created));
                $c_resolved_at= !empty($row['resolved_at']) ? date('d M, Y h:i A', strtotime($row['resolved_at'])) : null;

                // Resident student details
                $s_name     = $row['student_name'] ?? 'Resident';
                $s_phone    = $row['student_phone'] ?? 'N/A';
                $s_room     = !empty($row['room_number']) 
                    ? ($row['block_name'] ?? 'Block') . " • Rm " . $row['room_number'] . " (Bed " . ($row['bed_number'] ?? '1') . ")"
                    : 'Unassigned';

                // Check if filed today or yesterday for visual badge
                $is_today     = (date('Y-m-d', strtotime($c_created)) === date('Y-m-d'));
                $is_yesterday = (date('Y-m-d', strtotime($c_created)) === date('Y-m-d', strtotime('-1 day')));
            ?>
              <tr>
                <!-- # -->
                <td class="text-center font-weight-700 text-muted"><?= $sl++ ?></td>

                <!-- Date Filed -->
                <td>
                  <strong class="text-dark d-block font-weight-700" style="font-size: 0.88rem;"><?= $c_date_fmt ?></strong>
                  <div class="text-muted d-flex align-items-center gap-1" style="font-size: 0.76rem;">
                    <i class="fa-regular fa-clock mr-1 text-success"></i><?= $c_time_fmt ?>
                    <?php if ($is_today): ?>
                      <span class="badge badge-success text-white px-2 py-0 ml-1 font-weight-700" style="font-size: 0.65rem; background: #059669;">TODAY</span>
                    <?php elseif ($is_yesterday): ?>
                      <span class="badge badge-secondary px-2 py-0 ml-1 font-weight-700" style="font-size: 0.65rem;">YESTERDAY</span>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Resident Student Info -->
                <td>
                  <div class="d-flex align-items-center">
                    <div class="student-initial-circle mr-2" style="background: #f0fdf4; color: #059669; border: 1.5px solid #d1fae5;">
                      <?= strtoupper(substr($s_name, 0, 1)) ?>
                    </div>
                    <div>
                      <strong class="text-dark d-block" style="font-size: 0.9rem; line-height: 1.25;">
                        <?= htmlspecialchars($s_name) ?>
                      </strong>
                      <small class="text-secondary font-weight-600 d-block" style="font-size: 0.76rem;">
                        <i class="fa-solid fa-bed mr-1 text-primary"></i><?= htmlspecialchars($s_room) ?>
                      </small>
                      <small class="text-muted d-block" style="font-size: 0.74rem;">
                        <i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($s_phone) ?>
                      </small>
                    </div>
                  </div>
                </td>

                <!-- Complaint Details (Title, Description, Image Preview) -->
                <td>
                  <div class="d-flex align-items-start gap-2">
                    <?php if (!empty($c_img) && file_exists("../uploads/complaints/{$c_img}")): ?>
                      <img src="../uploads/complaints/<?= htmlspecialchars($c_img) ?>" 
                           alt="Complaint Photo" 
                           class="complaint-thumb-img mr-2" 
                           onclick="openImageLightbox('../uploads/complaints/<?= htmlspecialchars($c_img) ?>', '<?= htmlspecialchars($c_title, ENT_QUOTES) ?>')"
                           title="Click to zoom image">
                    <?php endif; ?>
                    <div style="max-width: 320px;">
                      <strong class="text-dark d-block font-weight-700" style="font-size: 0.88rem; line-height: 1.3;">
                        <?= htmlspecialchars($c_title) ?>
                      </strong>
                      <p class="text-muted mb-0 font-weight-500 text-truncate-2" style="font-size: 0.78rem; line-height: 1.35; max-height: 2.7em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                        <?= htmlspecialchars($c_desc) ?>
                      </p>
                    </div>
                  </div>
                </td>

                <!-- Priority Badge -->
                <td class="text-center">
                  <?php if ($c_prio === 'urgent'): ?>
                    <span class="badge-prio badge-prio-urgent">
                      <i class="fa-solid fa-fire"></i> Urgent
                    </span>
                  <?php elseif ($c_prio === 'high'): ?>
                    <span class="badge-prio badge-prio-high">
                      <i class="fa-solid fa-triangle-exclamation"></i> High
                    </span>
                  <?php elseif ($c_prio === 'medium'): ?>
                    <span class="badge-prio badge-prio-medium">
                      <i class="fa-solid fa-wrench"></i> Medium
                    </span>
                  <?php else: ?>
                    <span class="badge-prio badge-prio-low">
                      <i class="fa-solid fa-circle-info"></i> Low
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Status Badge -->
                <td class="text-center">
                  <?php if ($c_status === 'pending'): ?>
                    <span class="badge-c-status badge-c-pending">
                      <i class="fa-solid fa-clock"></i> Pending
                    </span>
                  <?php elseif ($c_status === 'in_progress'): ?>
                    <span class="badge-c-status badge-c-progress">
                      <i class="fa-solid fa-screwdriver-wrench"></i> In Progress
                    </span>
                  <?php elseif ($c_status === 'resolved'): ?>
                    <span class="badge-c-status badge-c-resolved">
                      <i class="fa-solid fa-circle-check"></i> Resolved
                    </span>
                  <?php elseif ($c_status === 'rejected'): ?>
                    <span class="badge-c-status badge-c-rejected">
                      <i class="fa-solid fa-circle-xmark"></i> Rejected
                    </span>
                  <?php else: ?>
                    <span class="badge-c-status badge-c-pending">
                      <?= htmlspecialchars(ucfirst($c_status)) ?>
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Warden Resolution Info -->
                <td>
                  <?php if (!empty($row['warden_handler_name'])): ?>
                    <small class="font-weight-700 text-dark d-block" style="font-size: 0.77rem;">
                      <i class="fa-solid fa-user-shield text-success mr-1"></i><?= htmlspecialchars($row['warden_handler_name']) ?>
                    </small>
                  <?php endif; ?>
                  
                  <?php if (!empty($c_response)): ?>
                    <span class="text-secondary d-block font-weight-500" style="font-size: 0.78rem; line-height: 1.35; white-space: normal; word-break: break-word;">
                      <?= htmlspecialchars($c_response) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted font-italic" style="font-size: 0.76rem;">No response recorded yet</span>
                  <?php endif; ?>

                  <?php if (!empty($c_resolved_at)): ?>
                    <small class="text-success font-weight-600 d-block mt-1" style="font-size: 0.72rem;">
                      <i class="fa-solid fa-check-double mr-1"></i>Closed: <?= $c_resolved_at ?>
                    </small>
                  <?php endif; ?>
                </td>

                <!-- Actions -->
                <td class="text-center no-print">
                  <div class="d-flex flex-column align-items-center gap-1">
                    <div class="d-inline-flex gap-1">
                      <!-- Update Status & Response Button -->
                      <button type="button" 
                              class="btn btn-c-action btn-c-update btn-open-update-modal"
                              data-id="<?= $c_id ?>"
                              data-title="<?= htmlspecialchars($c_title, ENT_QUOTES) ?>"
                              data-desc="<?= htmlspecialchars($c_desc, ENT_QUOTES) ?>"
                              data-student="<?= htmlspecialchars($s_name, ENT_QUOTES) ?>"
                              data-room="<?= htmlspecialchars($s_room, ENT_QUOTES) ?>"
                              data-date="<?= htmlspecialchars($c_date_fmt, ENT_QUOTES) ?>"
                              data-priority="<?= htmlspecialchars($c_prio, ENT_QUOTES) ?>"
                              data-status="<?= htmlspecialchars($c_status, ENT_QUOTES) ?>"
                              data-response="<?= htmlspecialchars($c_response ?? '', ENT_QUOTES) ?>"
                              title="Update Status & Response">
                        <i class="fa-solid fa-pen-to-square"></i> Action
                      </button>

                      <!-- Quick Resolve Shortcut for Pending / In-Progress -->
                      <?php if ($c_status === 'pending' || $c_status === 'in_progress'): ?>
                        <a href="complaints.php?quick_action=resolve&id=<?= $c_id ?>" 
                           class="btn btn-c-action btn-c-quick-resolve" 
                           onclick="return confirm('Mark this complaint as RESOLVED now?');"
                           title="Quick Mark as Resolved">
                          <i class="fa-solid fa-check"></i>
                        </a>
                      <?php endif; ?>

                      <!-- Full View Details Modal -->
                      <button type="button" 
                              class="btn btn-c-action btn-c-view btn-open-view-modal"
                              data-id="<?= $c_id ?>"
                              data-title="<?= htmlspecialchars($c_title, ENT_QUOTES) ?>"
                              data-desc="<?= htmlspecialchars($c_desc, ENT_QUOTES) ?>"
                              data-student="<?= htmlspecialchars($s_name, ENT_QUOTES) ?>"
                              data-phone="<?= htmlspecialchars($s_phone, ENT_QUOTES) ?>"
                              data-room="<?= htmlspecialchars($s_room, ENT_QUOTES) ?>"
                              data-date="<?= htmlspecialchars($c_date_fmt . ' • ' . $c_time_fmt, ENT_QUOTES) ?>"
                              data-priority="<?= htmlspecialchars(ucfirst($c_prio), ENT_QUOTES) ?>"
                              data-status="<?= htmlspecialchars(ucfirst(str_replace('_', ' ', $c_status)), ENT_QUOTES) ?>"
                              data-warden="<?= htmlspecialchars($row['warden_handler_name'] ?? 'Not Assigned', ENT_QUOTES) ?>"
                              data-response="<?= htmlspecialchars($c_response ?? 'None', ENT_QUOTES) ?>"
                              data-resolved-at="<?= htmlspecialchars($c_resolved_at ?? 'Not resolved', ENT_QUOTES) ?>"
                              data-image="<?= !empty($c_img) ? '../uploads/complaints/' . htmlspecialchars($c_img, ENT_QUOTES) : '' ?>"
                              title="View Full Complaint">
                        <i class="fa-regular fa-eye"></i>
                      </button>
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
     MODAL 1: UPDATE COMPLAINT STATUS & RESOLUTION REMARKS
     ========================================================= -->
<div class="modal fade" id="updateComplaintModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 540px;">
    <div class="modal-content rounded-16 border-0 shadow-lg overflow-hidden">
      
      <!-- Modal Header -->
      <div class="modal-header text-white" style="background: linear-gradient(135deg, #064e3b 0%, #059669 100%) !important; padding: 1.15rem 1.6rem; border: none;">
        <h5 class="modal-title font-weight-700 text-white mb-0" style="font-size: 1.05rem;">
          <i class="fa-solid fa-pen-to-square mr-2"></i> Update Complaint Status & Response
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9; text-shadow: none;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="complaints.php" id="updateComplaintForm">
        <input type="hidden" name="action" value="update_complaint_status">
        <input type="hidden" name="complaint_id" id="modal_u_comp_id" value="">

        <div class="modal-body p-4" style="background: #f8fafc;">
          <!-- Resident & Complaint Overview Card -->
          <div class="alert alert-light border rounded-12 p-3 mb-3" style="background: #ffffff;">
            <div class="d-flex justify-content-between align-items-start mb-1">
              <strong class="text-dark" id="modal_u_title" style="font-size: 0.94rem;">-</strong>
              <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" id="modal_u_priority" style="font-size: 0.72rem;">-</span>
            </div>
            <div class="d-flex align-items-center text-muted" style="font-size: 0.78rem;">
              <span class="mr-3"><i class="fa-regular fa-user mr-1 text-primary"></i><strong class="text-dark" id="modal_u_student">-</strong></span>
              <span><i class="fa-solid fa-bed mr-1 text-success"></i><span id="modal_u_room">-</span></span>
            </div>
          </div>

          <!-- Select New Status -->
          <div class="form-group mb-3">
            <label class="font-weight-700 text-dark mb-1" style="font-size: 0.85rem;">
              Set Resolution Status <span class="text-danger">*</span>
            </label>
            <select name="status" id="modal_u_status_select" class="form-control custom-form-input" required>
              <option value="pending">Pending - Under Review</option>
              <option value="in_progress">In Progress - Maintenance Assigned / Work Underway</option>
              <option value="resolved">Resolved - Issue Fixed & Closed</option>
              <option value="rejected">Rejected - Invalid / Out of Hostel Scope</option>
            </select>
          </div>

          <!-- Resolution Remarks / Notes -->
          <div class="form-group mb-2">
            <label class="font-weight-700 text-dark mb-1" id="modal_u_remarks_label" style="font-size: 0.85rem;">
              Warden Action Taken / Resolution Remarks <span class="text-danger">*</span>
            </label>
            <textarea name="response" id="modal_u_response" class="form-control custom-form-input" rows="3" placeholder="Describe the action taken (e.g. Electrician sent, replaced fan regulator, scheduled for tomorrow)..." required></textarea>
            <small class="text-muted font-weight-500 mt-1 d-block">
              Recorded by: <strong><?= htmlspecialchars($warden_name) ?> (<?= htmlspecialchars($warden_code) ?>)</strong>
            </small>
          </div>

          <!-- Quick Preset Chips -->
          <div class="mt-2 pt-2 border-top">
            <small class="text-muted font-weight-700 text-uppercase d-block mb-1" style="font-size: 0.72rem;">
              <i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Response Presets:
            </small>
            <div class="d-flex flex-wrap">
              <span class="remark-chip-pill" data-remark="Electrician inspected and replaced the faulty part. Working properly now.">
                Electrician Replaced Part
              </span>
              <span class="remark-chip-pill" data-remark="Plumber fixed the leakage and tested water supply flow.">
                Plumber Fixed Leakage
              </span>
              <span class="remark-chip-pill" data-remark="Carpenter completed repair work for room furniture.">
                Carpentry Completed
              </span>
              <span class="remark-chip-pill" data-remark="Housekeeping team dispatched for deep cleaning.">
                Housekeeping Done
              </span>
              <span class="remark-chip-pill" data-remark="Technician scheduled to visit tomorrow morning at 10:00 AM.">
                Technician Scheduled
              </span>
              <span class="remark-chip-pill" data-remark="Duplicate entry or resolved during previous room round.">
                Duplicate Entry
              </span>
            </div>
          </div>
        </div>

        <div class="modal-footer bg-light p-3" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
          <button type="button" class="btn btn-secondary font-weight-600 rounded-10 px-3 py-2" data-dismiss="modal">Cancel</button>
          <button type="submit" id="btn_save_complaint_status" class="btn btn-success font-weight-700 rounded-10 px-4 py-2" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none;">
            <i class="fa-solid fa-check mr-1"></i> Save Status & Response
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAL 2: VIEW FULL COMPLAINT TICKET
     ========================================================= -->
<div class="modal fade" id="viewComplaintModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 520px;">
    <div class="modal-content rounded-16 border-0 shadow-lg overflow-hidden">
      
      <div class="modal-header text-white" style="background: linear-gradient(135deg, #064e3b 0%, #047857 100%) !important; padding: 1.15rem 1.6rem; border: none;">
        <h5 class="modal-title font-weight-700 text-white mb-0" style="font-size: 1.05rem;">
          <i class="fa-solid fa-ticket mr-2 text-warning"></i> Complaint Ticket Details
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9; text-shadow: none;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body p-4" id="printableComplaintArea">
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
          <div>
            <h5 class="font-weight-800 text-dark mb-1" id="v_c_title">-</h5>
            <small class="text-muted font-weight-600"><i class="fa-regular fa-calendar mr-1"></i>Filed on: <span id="v_c_date">-</span></small>
          </div>
          <div>
            <span class="badge badge-primary font-weight-700 px-3 py-1" id="v_c_priority">-</span>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Resident Student</small>
            <strong class="text-dark font-weight-700" id="v_c_student">-</strong>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Phone</small>
            <span class="text-dark font-weight-600" id="v_c_phone">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Room & Bed</small>
            <span class="text-dark font-weight-600" id="v_c_room">-</span>
          </div>
          <div class="col-6 mb-2">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.72rem;">Current Status</small>
            <span class="badge badge-success font-weight-700 px-2 py-1" id="v_c_status">-</span>
          </div>
        </div>

        <!-- Description Box -->
        <div class="p-3 bg-light rounded-12 border mb-3">
          <small class="text-muted font-weight-700 text-uppercase d-block mb-1" style="font-size: 0.72rem;">Issue Description</small>
          <p class="text-dark mb-0 font-weight-500" id="v_c_desc" style="font-size: 0.88rem; white-space: pre-wrap;">-</p>
        </div>

        <!-- Image attachment preview if any -->
        <div id="v_c_image_container" class="mb-3 d-none">
          <small class="text-muted font-weight-700 text-uppercase d-block mb-1" style="font-size: 0.72rem;">Attached Photo</small>
          <div class="rounded-12 overflow-hidden border" style="max-height: 220px; background: #000; text-align: center;">
            <img id="v_c_image" src="" alt="Complaint Photo" style="max-height: 220px; width: 100%; object-fit: contain; cursor: pointer;" onclick="openImageLightbox(this.src, 'Complaint Photo')">
          </div>
        </div>

        <!-- Warden Response Box -->
        <div class="p-3 rounded-12 border" style="background: #f0fdf4; border-color: #d1fae5 !important;">
          <small class="text-success font-weight-700 text-uppercase d-block mb-1" style="font-size: 0.72rem;">Warden Action & Response</small>
          <p class="text-dark mb-1 font-weight-600" id="v_c_response" style="font-size: 0.88rem;">-</p>
          <div class="d-flex justify-content-between text-muted mt-2 pt-2 border-top" style="font-size: 0.74rem;">
            <span><i class="fa-solid fa-user-shield mr-1"></i>Handler: <strong id="v_c_warden">-</strong></span>
            <span><i class="fa-solid fa-clock mr-1"></i><span id="v_c_resolved_at">-</span></span>
          </div>
        </div>
      </div>

      <div class="modal-footer bg-light p-3" style="border-bottom-left-radius: 16px; border-bottom-right-radius: 16px;">
        <button type="button" class="btn btn-secondary font-weight-600 rounded-10 px-3 py-2" data-dismiss="modal">Close</button>
        <button type="button" class="btn btn-outline-success font-weight-700 rounded-10 px-3 py-2" onclick="printTicketArea()">
          <i class="fa-solid fa-print mr-1"></i> Print Ticket
        </button>
      </div>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAL 3: ENLARGED IMAGE LIGHTBOX
     ========================================================= -->
<div class="modal fade" id="imageLightboxModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content bg-dark border-0 rounded-16 overflow-hidden">
      <div class="modal-header border-0 pb-0">
        <h6 class="text-white mb-0" id="lightboxTitle">Attached Photo</h6>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-4 text-center">
        <img id="lightboxImage" src="" alt="Enlarged Photo" style="max-height: 550px; max-width: 100%; border-radius: 10px; object-fit: contain;">
      </div>
    </div>
  </div>
</div>

<!-- =========================================================
     JAVASCRIPT LOGIC
     ========================================================= -->
<script>
// Open Lightbox Image Preview
function openImageLightbox(src, title) {
  if (!src) return;
  document.getElementById('lightboxImage').src = src;
  document.getElementById('lightboxTitle').innerText = title || 'Attached Photo';
  if (window.jQuery && typeof jQuery.fn.modal === 'function') {
    jQuery('#imageLightboxModal').modal('show');
  }
}

// Export Table to CSV
function exportComplaintsToCSV() {
  const table = document.getElementById('complaintsTable');
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
  const fileName = "warden_complaints_report_" + new Date().toISOString().slice(0, 10) + ".csv";

  downloadLink.download = fileName;
  downloadLink.href = window.URL.createObjectURL(csvFile);
  downloadLink.style.display = "none";
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}

// Print Ticket Area
function printTicketArea() {
  const printContents = document.getElementById('printableComplaintArea').innerHTML;
  const originalContents = document.body.innerHTML;
  document.body.innerHTML = `
    <div style="padding: 2.5rem; max-width: 550px; margin: 0 auto; border: 2px solid #059669; border-radius: 16px; font-family: sans-serif;">
      <h3 style="text-align: center; color: #059669; margin-bottom: 5px;">HOSTEL MATE - MAINTENANCE WORK ORDER</h3>
      <p style="text-align: center; color: #64748b; font-size: 12px; margin-bottom: 25px;">Official Complaint Resolution Ticket</p>
      ${printContents}
    </div>
  `;
  window.print();
  document.body.innerHTML = originalContents;
  window.location.reload();
}

$(document).ready(function() {
  // Initialize DataTables
  if ($.fn.DataTable && $('#complaintsTable').length) {
    $('#complaintsTable').DataTable({
      "order": [[1, "desc"]],
      "pageLength": 25,
      "language": {
        "search": "_INPUT_",
        "searchPlaceholder": "Search complaints, students...",
        "emptyTable": "No complaints match selected criteria"
      }
    });
  }

  // Populate Update Status Modal
  $(document).on('click', '.btn-open-update-modal', function() {
    const id = $(this).data('id');
    const title = $(this).data('title');
    const student = $(this).data('student');
    const room = $(this).data('room');
    const priority = $(this).data('priority');
    const status = $(this).data('status');
    const response = $(this).data('response');

    $('#modal_u_comp_id').val(id);
    $('#modal_u_title').text(title);
    $('#modal_u_student').text(student);
    $('#modal_u_room').text(room);
    $('#modal_u_priority').text(priority.toUpperCase());
    $('#modal_u_status_select').val(status);
    $('#modal_u_response').val(response);

    $('#updateComplaintModal').modal('show');
    setTimeout(function() {
      $('#modal_u_response').focus();
    }, 300);
  });

  // Populate View Details Modal
  $(document).on('click', '.btn-open-view-modal', function() {
    $('#v_c_title').text($(this).data('title'));
    $('#v_c_date').text($(this).data('date'));
    $('#v_c_student').text($(this).data('student'));
    $('#v_c_phone').text($(this).data('phone'));
    $('#v_c_room').text($(this).data('room'));
    $('#v_c_priority').text($(this).data('priority'));
    $('#v_c_status').text($(this).data('status'));
    $('#v_c_desc').text($(this).data('desc'));
    $('#v_c_warden').text($(this).data('warden'));
    $('#v_c_response').text($(this).data('response'));
    $('#v_c_resolved_at').text($(this).data('resolved-at'));

    const imgSrc = $(this).data('image');
    if (imgSrc) {
      $('#v_c_image').attr('src', imgSrc);
      $('#v_c_image_container').removeClass('d-none');
    } else {
      $('#v_c_image_container').addClass('d-none');
    }

    $('#viewComplaintModal').modal('show');
  });

  // Preset chip click for resolution remarks
  $(document).on('click', '.remark-chip-pill', function() {
    const remark = $(this).data('remark');
    const current = $('#modal_u_response').val();
    if (current && current.trim().length > 0) {
      $('#modal_u_response').val(current + ' ' + remark).focus();
    } else {
      $('#modal_u_response').val(remark).focus();
    }
  });

  // Form submit check
  $('#updateComplaintForm').on('submit', function(e) {
    const resp = $.trim($('#modal_u_response').val());
    if (!resp) {
      e.preventDefault();
      alert('Please enter resolution remarks or action taken notes.');
      $('#modal_u_response').focus();
      return false;
    }
  });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
