<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../db.php';

$warden_id   = intval($_SESSION['warden_id']);
$warden_name = $_SESSION['warden_name'] ?? 'Hostel Warden';
$warden_code = $_SESSION['warden_code'] ?? 'WDN';

// -------------------------------------------------------------
// 1. DATE-WISE FILTERING & PRESETS
// -------------------------------------------------------------
$preset     = trim($_GET['preset'] ?? '');
$start_date = trim($_GET['start_date'] ?? $_GET['from_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? $_GET['to_date'] ?? '');

$active_preset = '';

// Handle quick presets
if ($preset === 'today') {
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d');
    $active_preset = 'today';
} elseif ($preset === 'yesterday') {
    $start_date = date('Y-m-d', strtotime('-1 day'));
    $end_date   = date('Y-m-d', strtotime('-1 day'));
    $active_preset = 'yesterday';
} elseif ($preset === 'this_week') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date   = date('Y-m-d');
    $active_preset = 'this_week';
} elseif ($preset === 'last_7_days') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $end_date   = date('Y-m-d');
    $active_preset = 'last_7_days';
} elseif ($preset === 'this_month') {
    $start_date = date('Y-m-01');
    $end_date   = date('Y-m-d');
    $active_preset = 'this_month';
} elseif ($preset === 'last_30_days') {
    $start_date = date('Y-m-d', strtotime('-29 days'));
    $end_date   = date('Y-m-d');
    $active_preset = 'last_30_days';
} elseif ($preset === 'this_year') {
    $start_date = date('Y-01-01');
    $end_date   = date('Y-m-d');
    $active_preset = 'this_year';
} elseif ($preset === 'all') {
    $start_date = '2023-01-01';
    $end_date   = date('Y-m-d');
    $active_preset = 'all';
}

// Default fallback: Current Month if nothing provided
if (empty($start_date) && empty($end_date)) {
    $start_date = date('Y-m-01');
    $end_date   = date('Y-m-d');
    $active_preset = 'this_month';
} elseif (empty($start_date)) {
    $start_date = date('Y-m-01', strtotime($end_date));
} elseif (empty($end_date)) {
    $end_date = date('Y-m-d');
}

// Ensure valid dates and sequence
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$safe_start = $conn->real_escape_string($start_date);
$safe_end   = $conn->real_escape_string($end_date);

$days_diff = max(1, round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
$period_label = date('d M Y', strtotime($start_date)) . " — " . date('d M Y', strtotime($end_date));

// -------------------------------------------------------------
// 2. WARDEN ASSIGNED BLOCKS
// -------------------------------------------------------------
$assigned_blocks_res = $conn->query("
    SELECT b.id, b.name 
    FROM warden_block_assignments wba 
    JOIN blocks b ON wba.block_id = b.id 
    WHERE wba.warden_id = {$warden_id} AND wba.status = 'active'
    ORDER BY b.name ASC
");
$assigned_blocks = [];
if ($assigned_blocks_res && $assigned_blocks_res->num_rows > 0) {
    while ($b = $assigned_blocks_res->fetch_assoc()) {
        $assigned_blocks[] = $b['name'];
    }
}
$assigned_blocks_label = !empty($assigned_blocks) ? implode(', ', $assigned_blocks) : 'All Hostel Blocks';

// -------------------------------------------------------------
// 2.5 STUDENT FEE & PAYMENT REVENUE ANALYTICS
// -------------------------------------------------------------
$fee_kpi_q = $conn->query("
    SELECT 
        COUNT(sfh.id) AS total_payments,
        COALESCE(SUM(sfh.price), 0) AS total_revenue,
        COALESCE(AVG(sfh.price), 0) AS avg_payment,
        COUNT(DISTINCT sfh.student_id) AS paying_residents
    FROM student_fee_history sfh
    JOIN users u ON sfh.student_id = u.id
    WHERE sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}'
");
$fee_kpi = ($fee_kpi_q && $fee_kpi_q->num_rows > 0) ? $fee_kpi_q->fetch_assoc() : [];

$stat_fee_payments   = intval($fee_kpi['total_payments'] ?? 0);
$stat_fee_revenue    = floatval($fee_kpi['total_revenue'] ?? 0);
$stat_fee_avg        = floatval($fee_kpi['avg_payment'] ?? 0);
$stat_fee_residents  = intval($fee_kpi['paying_residents'] ?? 0);

// Fee Timeline for Chart.js
$fee_timeline_q = $conn->query("
    SELECT 
        sfh.date AS tx_date,
        COUNT(sfh.id) AS tx_count,
        COALESCE(SUM(sfh.price), 0) AS daily_amount
    FROM student_fee_history sfh
    JOIN users u ON sfh.student_id = u.id
    WHERE sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}'
    GROUP BY sfh.date
    ORDER BY sfh.date ASC
");
$fee_chart_labels  = [];
$fee_chart_amounts = [];
$fee_chart_counts  = [];

if ($fee_timeline_q && $fee_timeline_q->num_rows > 0) {
    while ($ft = $fee_timeline_q->fetch_assoc()) {
        $fee_chart_labels[]  = date('d M', strtotime($ft['tx_date']));
        $fee_chart_amounts[] = floatval($ft['daily_amount']);
        $fee_chart_counts[]  = intval($ft['tx_count']);
    }
}

// Full Fee Payment History Audit List
$fee_audit_q = $conn->query("
    SELECT 
        sfh.id AS fee_id,
        sfh.student_id,
        sfh.price,
        sfh.date AS fee_date,
        sfh.created_at,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        st.admission_no,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        rm.room_type,
        bd.bed_number
    FROM student_fee_history sfh
    JOIN users u ON sfh.student_id = u.id
    LEFT JOIN students st ON u.id = st.user_id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}'
    ORDER BY sfh.date DESC, sfh.id DESC
");
$fee_audit_list = [];
if ($fee_audit_q && $fee_audit_q->num_rows > 0) {
    while ($row = $fee_audit_q->fetch_assoc()) {
        $fee_audit_list[] = $row;
    }
}

// -------------------------------------------------------------
// 3. ATTENDANCE ANALYTICS (IN DATE RANGE)
// -------------------------------------------------------------
$att_summary_q = $conn->query("
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN LOWER(status) = 'present' THEN 1 ELSE 0 END) as present_cnt,
        SUM(CASE WHEN LOWER(status) = 'absent' THEN 1 ELSE 0 END) as absent_cnt,
        SUM(CASE WHEN LOWER(status) = 'late' THEN 1 ELSE 0 END) as late_cnt,
        COUNT(DISTINCT student_id) as unique_students,
        COUNT(DISTINCT attendance_date) as active_days
    FROM attendance
    WHERE attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}'
");
$att_stats = ($att_summary_q && $att_summary_q->num_rows > 0) ? $att_summary_q->fetch_assoc() : [];

$att_total   = intval($att_stats['total_records'] ?? 0);
$att_present = intval($att_stats['present_cnt'] ?? 0);
$att_absent  = intval($att_stats['absent_cnt'] ?? 0);
$att_late    = intval($att_stats['late_cnt'] ?? 0);
$att_unique  = intval($att_stats['unique_students'] ?? 0);
$att_days    = intval($att_stats['active_days'] ?? 0);

$att_present_pct = ($att_total > 0) ? round(($att_present / $att_total) * 100, 1) : 0;
$att_absent_pct  = ($att_total > 0) ? round(($att_absent / $att_total) * 100, 1) : 0;
$att_late_pct    = ($att_total > 0) ? round(($att_late / $att_total) * 100, 1) : 0;

// Daily attendance timeline
$att_daily_res = $conn->query("
    SELECT 
        attendance_date,
        COUNT(*) as total_marked,
        SUM(CASE WHEN LOWER(status) = 'present' THEN 1 ELSE 0 END) as present_cnt,
        SUM(CASE WHEN LOWER(status) = 'absent' THEN 1 ELSE 0 END) as absent_cnt,
        SUM(CASE WHEN LOWER(status) = 'late' THEN 1 ELSE 0 END) as late_cnt
    FROM attendance
    WHERE attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}'
    GROUP BY attendance_date
    ORDER BY attendance_date ASC
");
$att_timeline = [];
$att_chart_labels  = [];
$att_chart_present = [];
$att_chart_absent  = [];
$att_chart_late    = [];

if ($att_daily_res && $att_daily_res->num_rows > 0) {
    while ($ar = $att_daily_res->fetch_assoc()) {
        $att_timeline[] = $ar;
        $att_chart_labels[]  = date('d M', strtotime($ar['attendance_date']));
        $att_chart_present[] = intval($ar['present_cnt']);
        $att_chart_absent[]  = intval($ar['absent_cnt']);
        $att_chart_late[]    = intval($ar['late_cnt']);
    }
}

// -------------------------------------------------------------
// 4. COMPLAINTS ANALYTICS (IN DATE RANGE)
// -------------------------------------------------------------
$comp_summary_q = $conn->query("
    SELECT 
        COUNT(*) as total_lodged,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_cnt,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_cnt,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_cnt,
        SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_cnt,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_cnt,
        SUM(CASE WHEN priority = 'medium' THEN 1 ELSE 0 END) as medium_cnt,
        SUM(CASE WHEN priority = 'low' THEN 1 ELSE 0 END) as low_cnt
    FROM complaints
    WHERE DATE(created_at) BETWEEN '{$safe_start}' AND '{$safe_end}'
");
$comp_stats = ($comp_summary_q && $comp_summary_q->num_rows > 0) ? $comp_summary_q->fetch_assoc() : [];

$comp_total       = intval($comp_stats['total_lodged'] ?? 0);
$comp_resolved    = intval($comp_stats['resolved_cnt'] ?? 0);
$comp_in_progress = intval($comp_stats['in_progress_cnt'] ?? 0);
$comp_pending     = intval($comp_stats['pending_cnt'] ?? 0);
$comp_rejected    = intval($comp_stats['rejected_cnt'] ?? 0);

$comp_urgent = intval($comp_stats['urgent_cnt'] ?? 0);
$comp_high   = intval($comp_stats['high_cnt'] ?? 0);
$comp_medium = intval($comp_stats['medium_cnt'] ?? 0);
$comp_low    = intval($comp_stats['low_cnt'] ?? 0);

$comp_resolution_rate = ($comp_total > 0) ? round(($comp_resolved / $comp_total) * 100, 1) : 0;

// Detailed Complaints List in Range
$complaints_res = $conn->query("
    SELECT 
        c.*, 
        u.name as student_name, 
        u.phone as student_phone, 
        rm.room_number, 
        blk.name as block_name
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    WHERE DATE(c.created_at) BETWEEN '{$safe_start}' AND '{$safe_end}'
    ORDER BY c.created_at DESC
");
$complaints_list = [];
if ($complaints_res && $complaints_res->num_rows > 0) {
    while ($c = $complaints_res->fetch_assoc()) {
        $complaints_list[] = $c;
    }
}

// -------------------------------------------------------------
// 5. VISITORS & GATE LOGS ANALYTICS (IN DATE RANGE)
// -------------------------------------------------------------
$vis_summary_q = $conn->query("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_cnt,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_cnt,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_cnt
    FROM visitors
    WHERE visit_date BETWEEN '{$safe_start}' AND '{$safe_end}'
");
$vis_stats = ($vis_summary_q && $vis_summary_q->num_rows > 0) ? $vis_summary_q->fetch_assoc() : [];

$vis_total     = intval($vis_stats['total_requests'] ?? 0);
$vis_approved  = intval($vis_stats['approved_cnt'] ?? 0);
$vis_pending   = intval($vis_stats['pending_cnt'] ?? 0);
$vis_rejected  = intval($vis_stats['rejected_cnt'] ?? 0);
$vis_completed = intval($vis_stats['completed_cnt'] ?? 0);
$vis_approval_rate = ($vis_total > 0) ? round(($vis_approved / $vis_total) * 100, 1) : 0;

// Gate logs from visitor_logs table in period
$vl_summary_q = $conn->query("
    SELECT 
        COUNT(*) as total_checkins,
        SUM(CASE WHEN check_out_at IS NOT NULL THEN 1 ELSE 0 END) as total_checkouts
    FROM visitor_logs
    WHERE DATE(check_in_at) BETWEEN '{$safe_start}' AND '{$safe_end}'
");
$vl_stats = ($vl_summary_q && $vl_summary_q->num_rows > 0) ? $vl_summary_q->fetch_assoc() : [];
$gate_checkins  = intval($vl_stats['total_checkins'] ?? 0);
$gate_checkouts = intval($vl_stats['total_checkouts'] ?? 0);

// Daily visitor requests timeline for chart
$vis_daily_res = $conn->query("
    SELECT 
        visit_date,
        COUNT(*) as requests_cnt,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_cnt
    FROM visitors
    WHERE visit_date BETWEEN '{$safe_start}' AND '{$safe_end}'
    GROUP BY visit_date
    ORDER BY visit_date ASC
");
$vis_chart_labels   = [];
$vis_chart_requests = [];
$vis_chart_approved = [];
if ($vis_daily_res && $vis_daily_res->num_rows > 0) {
    while ($vr = $vis_daily_res->fetch_assoc()) {
        $vis_chart_labels[]   = date('d M', strtotime($vr['visit_date']));
        $vis_chart_requests[] = intval($vr['requests_cnt']);
        $vis_chart_approved[] = intval($vr['approved_cnt']);
    }
}

// Full Visitors List in Range
$visitors_list_res = $conn->query("
    SELECT 
        v.*, 
        u.name as student_name, 
        rm.room_number,
        blk.name as block_name,
        vl.check_in_at,
        vl.check_out_at
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN (
        SELECT visitor_id, MAX(check_in_at) as check_in_at, MAX(check_out_at) as check_out_at
        FROM visitor_logs
        GROUP BY visitor_id
    ) vl ON v.id = vl.visitor_id
    WHERE v.visit_date BETWEEN '{$safe_start}' AND '{$safe_end}'
    ORDER BY v.visit_date DESC, v.created_at DESC
");
$visitors_list = [];
if ($visitors_list_res && $visitors_list_res->num_rows > 0) {
    while ($vl_row = $visitors_list_res->fetch_assoc()) {
        $visitors_list[] = $vl_row;
    }
}

// -------------------------------------------------------------
// 6. ROOM & BED OCCUPANCY SNAPSHOT
// -------------------------------------------------------------
$students_q = $conn->query("
    SELECT 
        COUNT(DISTINCT u.id) as total_students,
        SUM(CASE WHEN ra.id IS NOT NULL THEN 1 ELSE 0 END) as allocated_count,
        SUM(CASE WHEN ra.id IS NULL THEN 1 ELSE 0 END) as unallocated_count
    FROM users u
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE u.role = 'student' AND (u.status = 'active' OR u.status IS NULL)
");
$student_stats = ($students_q && $students_q->num_rows > 0) ? $students_q->fetch_assoc() : [];
$total_students     = intval($student_stats['total_students'] ?? 0);
$allocated_students = intval($student_stats['allocated_count'] ?? 0);

$beds_q = $conn->query("
    SELECT 
        COUNT(*) as total_beds,
        SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied_beds,
        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_beds
    FROM beds
");
$bed_stats = ($beds_q && $beds_q->num_rows > 0) ? $beds_q->fetch_assoc() : [];
$total_beds     = intval($bed_stats['total_beds'] ?? 0);
$occupied_beds  = intval($bed_stats['occupied_beds'] ?? 0);
$available_beds = intval($bed_stats['available_beds'] ?? 0);
$occupancy_pct  = ($total_beds > 0) ? round(($occupied_beds / $total_beds) * 100, 1) : 0;

// New allocations created in selected date range
$new_alloc_q = $conn->query("
    SELECT COUNT(*) as new_alloc_cnt
    FROM room_allocations
    WHERE DATE(allocated_date) BETWEEN '{$safe_start}' AND '{$safe_end}'
");
$new_allocations_cnt = ($new_alloc_q && $new_alloc_q->num_rows > 0) ? intval($new_alloc_q->fetch_assoc()['new_alloc_cnt']) : 0;

// Block-by-Block Occupancy
$blocks_occ_res = $conn->query("
    SELECT 
        blk.id, blk.name as block_name,
        COUNT(DISTINCT rm.id) as total_rooms,
        COUNT(bd.id) as total_beds,
        SUM(CASE WHEN bd.status = 'occupied' THEN 1 ELSE 0 END) as occupied_beds,
        SUM(CASE WHEN bd.status = 'available' THEN 1 ELSE 0 END) as available_beds
    FROM blocks blk
    LEFT JOIN floors flr ON blk.id = flr.block_id
    LEFT JOIN rooms rm ON flr.id = rm.floor_id
    LEFT JOIN beds bd ON rm.id = bd.room_id
    GROUP BY blk.id, blk.name
    ORDER BY blk.name ASC
");
$blocks_occupancy = [];
$block_chart_names = [];
$block_chart_occ   = [];
$block_chart_avail = [];
if ($blocks_occ_res && $blocks_occ_res->num_rows > 0) {
    while ($bo = $blocks_occ_res->fetch_assoc()) {
        $blocks_occupancy[] = $bo;
        $block_chart_names[] = $bo['block_name'];
        $block_chart_occ[]   = intval($bo['occupied_beds']);
        $block_chart_avail[] = intval($bo['available_beds']);
    }
}

ob_start();
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
  :root {
    --warden-primary: #059669;
    --warden-primary-dark: #047857;
    --warden-accent: #10b981;
    --warden-obsidian: #03201a;
  }

  .reports-wrapper {
    width: 100%;
    margin: 0;
    padding: 0;
  }

  /* Header Card */
  .reports-header-card {
    background: linear-gradient(135deg, #03201a 0%, #064e3b 50%, #059669 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem;
    color: #ffffff;
    box-shadow: 0 10px 28px rgba(5, 150, 105, 0.22);
    position: relative;
    overflow: hidden;
  }

  .reports-header-card::after {
    content: '';
    position: absolute;
    top: -40%;
    right: -8%;
    width: 360px;
    height: 360px;
    background: radial-gradient(circle, rgba(16, 185, 129, 0.22) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
  }

  /* Filter Panel */
  .reports-filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.4rem 1.6rem;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
  }

  .preset-pill {
    padding: 0.45rem 0.95rem;
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 50px;
    border: 1.5px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
    white-space: nowrap;
    margin-right: 4px;
    margin-bottom: 6px;
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

  .report-date-input {
    border-radius: 10px !important;
    border: 1.5px solid #cbd5e1 !important;
    font-size: 0.88rem !important;
    padding: 0.52rem 0.85rem !important;
    height: auto !important;
    font-weight: 600 !important;
    color: #1e293b !important;
  }
  .report-date-input:focus {
    border-color: #059669 !important;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.16) !important;
  }

  /* KPI Summary Cards */
  .report-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.35rem 1.45rem;
    height: 100%;
    min-height: 165px;
    box-shadow: 0 3px 14px rgba(15, 23, 42, 0.04);
    transition: all 0.22s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .report-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(5, 150, 105, 0.12);
    border-color: #a7f3d0;
  }

  .report-kpi-icon {
    width: 46px;
    height: 46px;
    min-width: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .icon-emerald { background: #ecfdf5; color: #059669; }
  .icon-teal    { background: #f0fdfa; color: #0d9488; }
  .icon-amber   { background: #fef3c7; color: #d97706; }
  .icon-blue    { background: #e0f2fe; color: #0284c7; }
  .icon-purple  { background: #f5f3ff; color: #7c3aed; }

  /* Section Card */
  .report-section-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.45rem 1.6rem;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
    height: 100%;
    display: flex;
    flex-direction: column;
    margin-bottom: 0;
  }

  .report-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid #f1f5f9;
  }

  .report-section-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 0;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* Nav Tabs */
  .report-nav-tabs {
    border-bottom: 2px solid #e2e8f0;
    gap: 8px;
  }
  .report-nav-tabs .nav-link {
    border: none !important;
    border-bottom: 3px solid transparent !important;
    color: #64748b;
    font-weight: 700;
    font-size: 0.88rem;
    padding: 0.75rem 1.2rem;
    border-radius: 0 !important;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .report-nav-tabs .nav-link:hover {
    color: #059669;
    border-bottom-color: #a7f3d0 !important;
  }
  .report-nav-tabs .nav-link.active {
    color: #059669 !important;
    background: transparent !important;
    border-bottom-color: #059669 !important;
  }

  /* Tables */
  .report-table thead th {
    background: #f8fafc;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    padding: 0.85rem 1rem;
    border-bottom: 2px solid #e2e8f0;
    border-top: none;
    white-space: nowrap;
  }
  .report-table tbody td {
    padding: 0.9rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.86rem;
  }
  .report-table tbody tr:hover {
    background: #fbfdfe;
  }

  /* Badges */
  .badge-stat-pill {
    padding: 0.3rem 0.65rem;
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 50px;
    text-transform: uppercase;
  }
  .badge-p-present { background: #d1fae5; color: #065f46; }
  .badge-p-absent  { background: #fee2e2; color: #991b1b; }
  .badge-p-late    { background: #fef3c7; color: #92400e; }
  .student-avatar {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e2e8f0;
  }
  .student-avatar-placeholder {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #059669, #0d9488);
    color: #fff;
    font-weight: 700;
    font-size: 0.88rem;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .fee-amount-badge {
    font-size: 0.95rem;
    font-weight: 800;
    color: #065f46;
  }
  .receipt-box {
    background: #ffffff;
    border: 2px dashed #a7f3d0;
    border-radius: 16px;
    padding: 1.75rem;
    position: relative;
  }
  .receipt-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-25deg);
    font-size: 4rem;
    font-weight: 900;
    color: rgba(5, 150, 105, 0.06);
    text-transform: uppercase;
    pointer-events: none;
    user-select: none;
    letter-spacing: 5px;
  }

  /* Print Stylesheet */
  @media print {
    body, .content-wrapper, .main-panel {
      background: #ffffff !important;
      padding: 0 !important;
      margin: 0 !important;
    }
    .navbar, .sidebar, .reports-filter-card, .btn-no-print, .nav-tabs, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info {
      display: none !important;
    }
    .reports-header-card {
      background: #064e3b !important;
      color: #ffffff !important;
      padding: 1.5rem !important;
      border-radius: 8px !important;
      margin-bottom: 1.5rem !important;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .report-section-card, .report-kpi-card {
      border: 1px solid #cbd5e1 !important;
      box-shadow: none !important;
      page-break-inside: avoid;
    }
    .tab-content > .tab-pane {
      display: block !important;
      opacity: 1 !important;
      visibility: visible !important;
      margin-bottom: 2rem;
    }
    .print-only-header {
      display: block !important;
    }
  }

  .print-only-header {
    display: none;
  }
</style>

<div class="reports-wrapper">

  <!-- =========================================================
       PAGE HEADER
       ========================================================= -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="reports-header-card">
        <div class="row align-items-center">
          <div class="col-lg-8 col-md-7 mb-3 mb-md-0">
            <div class="d-flex align-items-center flex-wrap mb-2">
              <span class="badge badge-light px-3 py-1 font-weight-700 text-dark mr-2 mb-1" style="border-radius: 50px; font-size: 0.78rem;">
                <i class="fa-solid fa-chart-line text-success mr-1"></i> HOSTEL ANALYTICS & AUDIT DESK
              </span>
              <span class="badge badge-dark px-3 py-1 font-weight-700 text-white mr-2 mb-1" style="background: rgba(255,255,255,0.18); border-radius: 50px; font-size: 0.78rem;">
                <i class="fa-solid fa-building mr-1"></i> <?= htmlspecialchars($assigned_blocks_label) ?>
              </span>
              <span class="badge badge-dark px-3 py-1 font-weight-700 text-white mb-1" style="background: rgba(255,255,255,0.18); border-radius: 50px; font-size: 0.78rem;">
                <i class="fa-regular fa-clock mr-1"></i> <?= date('d M Y, h:i A') ?>
              </span>
            </div>
            <h2 class="font-weight-800 text-white mb-1" style="font-size: 1.85rem; letter-spacing: -0.5px;">
              Operational Analytics & Institutional Reports
            </h2>
            <p class="text-white-50 mb-0 font-weight-500" style="font-size: 0.92rem; max-width: 680px; line-height: 1.5;">
              Consolidated intelligence and auditing for student roll call attendance, facility complaints resolution, visitor gate activity, and hostel bed occupancy.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       DATE RANGE FILTER PANEL (START DATE & END DATE)
       ========================================================= -->
  <div class="row mb-4 btn-no-print">
    <div class="col-12">
      <div class="reports-filter-card">
        <form method="GET" action="reports.php" id="reportsFilterForm">
          
          <!-- Preset Chips -->
          <div class="d-flex align-items-center justify-content-between flex-wrap mb-3 pb-3 border-bottom">
            <div class="d-flex align-items-center flex-wrap mb-2 mb-md-0">
              <span class="text-muted font-weight-700 text-uppercase mr-2" style="font-size: 0.75rem;">
                <i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Presets:
              </span>
              <a href="reports.php?preset=today" class="preset-pill <?= ($active_preset === 'today') ? 'active' : '' ?>">Today</a>
              <a href="reports.php?preset=yesterday" class="preset-pill <?= ($active_preset === 'yesterday') ? 'active' : '' ?>">Yesterday</a>
              <a href="reports.php?preset=this_week" class="preset-pill <?= ($active_preset === 'this_week') ? 'active' : '' ?>">This Week</a>
              <a href="reports.php?preset=last_7_days" class="preset-pill <?= ($active_preset === 'last_7_days') ? 'active' : '' ?>">Last 7 Days</a>
              <a href="reports.php?preset=this_month" class="preset-pill <?= ($active_preset === 'this_month') ? 'active' : '' ?>">This Month</a>
              <a href="reports.php?preset=last_30_days" class="preset-pill <?= ($active_preset === 'last_30_days') ? 'active' : '' ?>">Last 30 Days</a>
              <a href="reports.php?preset=this_year" class="preset-pill <?= ($active_preset === 'this_year') ? 'active' : '' ?>">This Year</a>
              <a href="reports.php?preset=all" class="preset-pill <?= ($active_preset === 'all') ? 'active' : '' ?>">All Time</a>
            </div>

            <div>
              <span class="badge badge-success px-3 py-2 font-weight-700" style="background: #ecfdf5; color: #065f46; border: 1.5px solid #a7f3d0; border-radius: 50px; font-size: 0.78rem;">
                <i class="fa-regular fa-calendar-check mr-1"></i> <?= htmlspecialchars($period_label) ?> (<?= $days_diff ?> <?= ($days_diff == 1) ? 'Day' : 'Days' ?>)
              </span>
            </div>
          </div>

          <!-- Custom Start Date & End Date Inputs -->
          <div class="row align-items-end">
            <!-- Start Date -->
            <div class="col-lg-3 col-md-4 col-sm-6 mb-2 mb-md-0">
              <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">
                <i class="fa-regular fa-calendar-plus text-success mr-1"></i> Start Date (From)
              </label>
              <input type="date" name="start_date" id="start_date" class="form-control report-date-input" value="<?= htmlspecialchars($start_date) ?>" required>
            </div>

            <!-- End Date -->
            <div class="col-lg-3 col-md-4 col-sm-6 mb-2 mb-md-0">
              <label class="font-weight-700 text-dark mb-1" style="font-size: 0.8rem;">
                <i class="fa-regular fa-calendar-minus text-danger mr-1"></i> End Date (To)
              </label>
              <input type="date" name="end_date" id="end_date" class="form-control report-date-input" value="<?= htmlspecialchars($end_date) ?>" required>
            </div>

            <!-- Filter Actions -->
            <div class="col-lg-6 col-md-4 col-sm-12 text-md-right mt-3 mt-md-0">
              <button type="submit" class="btn btn-success font-weight-700 rounded-10 px-4 py-2 mr-2" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none;">
                <i class="fa-solid fa-filter mr-1"></i> Generate Analytic Report
              </button>
              <a href="reports.php?preset=this_month" class="btn btn-light border font-weight-700 rounded-10 px-3 py-2 text-muted">
                <i class="fa-solid fa-rotate-left mr-1"></i> Reset
              </a>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>

  <!-- =========================================================
       PRINT HEADER (VISIBLE ONLY ON PRINT)
       ========================================================= -->
  <div class="print-only-header mb-4 pb-3 border-bottom">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h3 class="font-weight-800 text-dark mb-0">HOSTEL MATE — OFFICIAL WARDEN REPORT</h3>
        <p class="text-muted mb-0">Institutional Operations, Roll Call, Helpdesk, and Gate Logs Audit</p>
      </div>
      <div class="text-right">
        <p class="mb-0"><strong>Period:</strong> <?= htmlspecialchars($period_label) ?></p>
        <p class="mb-0 text-muted"><strong>Auditor:</strong> <?= htmlspecialchars($warden_name) ?> (<?= htmlspecialchars($warden_code) ?>)</p>
        <p class="mb-0 text-muted"><strong>Generated:</strong> <?= date('d M Y, h:i A') ?></p>
      </div>
    </div>
  </div>

  <!-- =========================================================
       PRIMARY KPI METRIC CARDS (5 DOMAINS INCL. FEE COLLECTIONS)
       ========================================================= -->
  <div class="row mb-4">
    
    <!-- Fee Collections KPI Card -->
    <div class="col-xl col-lg-4 col-md-6 mb-3">
      <div class="report-kpi-card" style="border-left: 4px solid #059669 !important;">
        <div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted font-weight-700 text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px;">Fee Collections</span>
            <div class="report-kpi-icon icon-emerald">
              <i class="fa-solid fa-receipt"></i>
            </div>
          </div>
          <div class="font-weight-800 text-dark" style="font-size: 1.85rem; line-height: 1;">
            ₹ <?= number_format($stat_fee_revenue) ?>
          </div>
          <div class="d-flex justify-content-between mt-2 font-weight-600" style="font-size: 0.78rem;">
            <span class="text-success"><i class="fa-solid fa-check mr-1"></i><?= $stat_fee_payments ?> Paid</span>
            <span class="text-muted"><?= $stat_fee_residents ?> Residents</span>
          </div>
        </div>
        <div class="pt-2 border-top mt-2">
          <small class="text-muted font-weight-600" style="font-size: 0.72rem;">
            Avg Payment: <strong>₹ <?= number_format($stat_fee_avg, 2) ?></strong>
          </small>
        </div>
      </div>
    </div>

    <!-- Attendance KPI Card -->
    <div class="col-xl col-lg-4 col-md-6 mb-3">
      <div class="report-kpi-card">
        <div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted font-weight-700 text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px;">Attendance Rate</span>
            <div class="report-kpi-icon icon-teal">
              <i class="fa-solid fa-clipboard-check"></i>
            </div>
          </div>
          <div class="font-weight-800 text-dark" style="font-size: 1.85rem; line-height: 1;">
            <?= $att_present_pct ?>%
          </div>
          <div class="d-flex justify-content-between mt-2 font-weight-600" style="font-size: 0.78rem;">
            <span class="text-success"><i class="fa-solid fa-check mr-1"></i><?= $att_present ?> Pres</span>
            <span class="text-danger"><i class="fa-solid fa-xmark mr-1"></i><?= $att_absent ?> Abs</span>
            <span class="text-warning"><i class="fa-solid fa-clock mr-1"></i><?= $att_late ?> Late</span>
          </div>
        </div>
        <div class="pt-2 border-top mt-2">
          <small class="text-muted font-weight-600" style="font-size: 0.72rem;">
            Total Calls: <strong><?= number_format($att_total) ?></strong> over <?= $att_days ?>d
          </small>
        </div>
      </div>
    </div>

    <!-- Complaints KPI Card -->
    <div class="col-xl col-lg-4 col-md-6 mb-3">
      <div class="report-kpi-card" style="<?= ($comp_urgent > 0) ? 'border-left: 4px solid #ef4444 !important;' : '' ?>">
        <div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted font-weight-700 text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px;">Resolution Rate</span>
            <div class="report-kpi-icon icon-amber">
              <i class="fa-solid fa-screwdriver-wrench"></i>
            </div>
          </div>
          <div class="font-weight-800 text-dark" style="font-size: 1.85rem; line-height: 1;">
            <?= $comp_resolution_rate ?>%
          </div>
          <div class="d-flex justify-content-between mt-2 font-weight-600" style="font-size: 0.78rem;">
            <span class="text-success"><i class="fa-solid fa-circle-check mr-1"></i><?= $comp_resolved ?> Done</span>
            <span class="text-info"><?= $comp_in_progress ?> Active</span>
            <span class="text-warning"><?= $comp_pending ?> Pend</span>
          </div>
        </div>
        <div class="pt-2 border-top mt-2 d-flex justify-content-between align-items-center">
          <small class="text-muted font-weight-600" style="font-size: 0.72rem;">
            Total: <strong><?= $comp_total ?></strong>
          </small>
          <?php if ($comp_urgent > 0): ?>
            <span class="badge badge-danger text-white px-2 py-0 font-weight-700" style="font-size: 0.65rem;">
              <?= $comp_urgent ?> Urgent
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Visitors KPI Card -->
    <div class="col-xl col-lg-6 col-md-6 mb-3">
      <div class="report-kpi-card">
        <div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted font-weight-700 text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px;">Visitor Approval</span>
            <div class="report-kpi-icon icon-blue">
              <i class="fa-solid fa-address-book"></i>
            </div>
          </div>
          <div class="font-weight-800 text-dark" style="font-size: 1.85rem; line-height: 1;">
            <?= $vis_approval_rate ?>%
          </div>
          <div class="d-flex justify-content-between mt-2 font-weight-600" style="font-size: 0.78rem;">
            <span class="text-success"><i class="fa-solid fa-user-check mr-1"></i><?= $vis_approved ?> Appr</span>
            <span class="text-danger"><i class="fa-solid fa-user-xmark mr-1"></i><?= $vis_rejected ?> Rej</span>
            <span class="text-warning"><?= $vis_pending ?> Pend</span>
          </div>
        </div>
        <div class="pt-2 border-top mt-2">
          <small class="text-muted font-weight-600" style="font-size: 0.72rem;">
            Gate Logs: <strong><?= $gate_checkins ?></strong> in
          </small>
        </div>
      </div>
    </div>

    <!-- Bed Occupancy Snapshot -->
    <div class="col-xl col-lg-6 col-md-6 mb-3">
      <div class="report-kpi-card">
        <div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="text-muted font-weight-700 text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px;">Bed Occupancy</span>
            <div class="report-kpi-icon icon-purple">
              <i class="fa-solid fa-bed"></i>
            </div>
          </div>
          <div class="font-weight-800 text-dark" style="font-size: 1.85rem; line-height: 1;">
            <?= $occupancy_pct ?>%
          </div>
          <div class="d-flex justify-content-between mt-2 font-weight-600" style="font-size: 0.78rem;">
            <span class="text-dark"><strong class="text-success"><?= $occupied_beds ?></strong> / <?= $total_beds ?></span>
            <span class="text-success font-weight-700"><?= $available_beds ?> Free</span>
          </div>
        </div>
        <div class="pt-2 border-top mt-2">
          <small class="text-muted font-weight-600" style="font-size: 0.72rem;">
            New Allocs: <strong><?= $new_allocations_cnt ?></strong>
          </small>
        </div>
      </div>
    </div>

  </div>

  <!-- =========================================================
       FINANCIAL & REVENUE ANALYTICS CHART
       ========================================================= -->
  <div class="row mb-4">
    <div class="col-lg-8 mb-4 mb-lg-0">
      <div class="report-section-card">
        <div class="report-section-header">
          <div>
            <h5 class="report-section-title">
              <i class="fa-solid fa-money-bill-trend-up text-success"></i> Payment Collections & Daily Revenue Timeline
            </h5>
            <small class="text-muted font-weight-600">Daily student fee collections in Indian Rupees (₹) within selected date range</small>
          </div>
          <span class="badge badge-light border text-success px-2 py-1 font-weight-700" style="font-size: 0.78rem;">
            Total: ₹ <?= number_format($stat_fee_revenue, 2) ?>
          </span>
        </div>

        <div style="height: 280px; position: relative; width: 100%;">
          <?php if (empty($fee_chart_labels)): ?>
            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
              <i class="fa-solid fa-receipt fa-3x mb-2 text-muted" style="opacity: 0.4;"></i>
              <p class="font-weight-600 mb-0">No fee payment collections recorded in this date range.</p>
            </div>
          <?php else: ?>
            <canvas id="reportFeeChart"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="report-section-card">
        <div class="report-section-header">
          <div>
            <h5 class="report-section-title">
              <i class="fa-solid fa-wallet text-success"></i> Revenue Highlights
            </h5>
            <small class="text-muted font-weight-600">Summary of fee collections</small>
          </div>
          <span class="badge badge-success px-2 py-1 font-weight-700" style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; font-size:0.75rem;">
            <?= $stat_fee_payments ?> Vouchers
          </span>
        </div>

        <div class="d-flex flex-column justify-content-around h-100 py-2">
          <div class="p-3 rounded-12 mb-2" style="background: #f8fafc; border: 1px solid #f1f5f9;">
            <div class="text-muted font-weight-700 text-uppercase" style="font-size: 0.7rem;">Total Revenue Collected</div>
            <div class="font-weight-800 text-success" style="font-size: 1.5rem;">₹ <?= number_format($stat_fee_revenue, 2) ?></div>
            <small class="text-muted">Direct hostel fees received</small>
          </div>

          <div class="row no-gutters mb-2">
            <div class="col-6 pr-1">
              <div class="p-2 rounded-10 bg-light border text-center">
                <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.65rem;">Average Fee</small>
                <strong class="text-dark font-weight-800" style="font-size: 0.95rem;">₹ <?= number_format($stat_fee_avg) ?></strong>
              </div>
            </div>
            <div class="col-6 pl-1">
              <div class="p-2 rounded-10 bg-light border text-center">
                <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.65rem;">Residents Paid</small>
                <strong class="text-dark font-weight-800" style="font-size: 0.95rem;"><?= $stat_fee_residents ?> Students</strong>
              </div>
            </div>
          </div>

          <div class="pt-2 border-top text-center">
            <a href="fee_history.php" class="btn btn-sm btn-outline-success btn-block font-weight-700 py-2 rounded-8" style="font-size: 0.8rem;">
              <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Open Full Fee History Register
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       INTERACTIVE ANALYTICS CHARTS (ROW OF 2)
       ========================================================= -->
  <div class="row mb-4">
    
    <!-- Chart 1: Attendance Trends Timeline -->
    <div class="col-lg-8 mb-4 mb-lg-0">
      <div class="report-section-card">
        <div class="report-section-header">
          <div>
            <h5 class="report-section-title">
              <i class="fa-solid fa-chart-line text-success"></i> Attendance Trend Over Period
            </h5>
            <small class="text-muted font-weight-600">Daily trajectory of students present, absent, and latecomers</small>
          </div>
          <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" style="font-size: 0.75rem;">
            <?= count($att_timeline) ?> Data Points
          </span>
        </div>

        <div style="height: 300px; position: relative; width: 100%;">
          <?php if (empty($att_timeline)): ?>
            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
              <i class="fa-solid fa-chart-bar fa-3x mb-2 text-muted"></i>
              <p class="font-weight-600 mb-0">No attendance data marked for this date range.</p>
            </div>
          <?php else: ?>
            <canvas id="reportAttendanceChart"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Chart 2: Complaints Status & Resolution Ratio -->
    <div class="col-lg-4">
      <div class="report-section-card">
        <div class="report-section-header">
          <div>
            <h5 class="report-section-title">
              <i class="fa-solid fa-chart-pie text-warning"></i> Complaints Resolution
            </h5>
            <small class="text-muted font-weight-600">Breakdown of tickets filed in period</small>
          </div>
          <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" style="font-size: 0.75rem;">
            <?= $comp_total ?> Total
          </span>
        </div>

        <div style="height: 220px; position: relative; width: 100%;">
          <?php if ($comp_total === 0): ?>
            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
              <i class="fa-solid fa-circle-check fa-3x mb-2 text-success"></i>
              <p class="font-weight-600 mb-0">Zero complaints filed in this period.</p>
            </div>
          <?php else: ?>
            <canvas id="reportComplaintsChart"></canvas>
          <?php endif; ?>
        </div>

        <div class="d-flex align-items-center justify-content-around text-center pt-3 border-top mt-auto" style="height: 70px;">
          <div>
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">Resolved</small>
            <strong class="text-success font-weight-800" style="font-size: 1.15rem;"><?= $comp_resolved ?></strong>
          </div>
          <div class="border-left pl-3">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">In Progress</small>
            <strong class="text-info font-weight-800" style="font-size: 1.15rem;"><?= $comp_in_progress ?></strong>
          </div>
          <div class="border-left pl-3">
            <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">Pending</small>
            <strong class="text-warning font-weight-800" style="font-size: 1.15rem;"><?= $comp_pending ?></strong>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- =========================================================
       SECONDARY CHARTS ROW: VISITOR TRAFFIC & BLOCK OCCUPANCY
       ========================================================= -->
  <div class="row mb-4">
    
    <!-- Visitor Flow Timeline -->
    <div class="col-lg-6 mb-4 mb-lg-0">
      <div class="report-section-card">
        <div class="report-section-header">
          <div>
            <h5 class="report-section-title">
              <i class="fa-solid fa-users-viewfinder text-primary"></i> Visitor Traffic Timeline
            </h5>
            <small class="text-muted font-weight-600">Daily requested passes vs approved entries</small>
          </div>
          <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" style="font-size: 0.75rem;">
            <?= $vis_total ?> Requests
          </span>
        </div>

        <div style="height: 250px; position: relative; width: 100%;">
          <?php if (empty($vis_chart_labels)): ?>
            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
              <i class="fa-solid fa-user-clock fa-3x mb-2 text-muted"></i>
              <p class="font-weight-600 mb-0">No visitor records found for this date range.</p>
            </div>
          <?php else: ?>
            <canvas id="reportVisitorChart"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Block Occupancy Capacity Bar Chart -->
    <div class="col-lg-6">
      <div class="report-section-card">
        <div class="report-section-header">
          <div>
            <h5 class="report-section-title">
              <i class="fa-solid fa-hotel text-success"></i> Block-Wise Bed Capacity
            </h5>
            <small class="text-muted font-weight-600">Occupied beds compared with available capacity</small>
          </div>
          <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" style="font-size: 0.75rem;">
            <?= count($blocks_occupancy) ?> Blocks
          </span>
        </div>

        <div style="height: 250px; position: relative; width: 100%;">
          <?php if (empty($block_chart_names)): ?>
            <div class="h-100 d-flex flex-column align-items-center justify-content-center text-muted">
              <i class="fa-solid fa-bed fa-3x mb-2 text-muted"></i>
              <p class="font-weight-600 mb-0">No block records configured.</p>
            </div>
          <?php else: ?>
            <canvas id="reportBlockChart"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- =========================================================
       COMPREHENSIVE AUDIT LOGS & TABBED DRILLDOWNS
       ========================================================= -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="report-section-card">
        
        <!-- Tab Navigation -->
        <ul class="nav report-nav-tabs mb-4 btn-no-print" id="reportTabs" role="tablist">
          <li class="nav-item">
            <a class="nav-link active" id="fees-tab" data-toggle="tab" href="#tab-fees" role="tab">
              <i class="fa-solid fa-receipt text-success"></i> Payment History Audit (<?= count($fee_audit_list) ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" id="att-tab" data-toggle="tab" href="#tab-attendance" role="tab">
              <i class="fa-solid fa-clipboard-user"></i> Daily Attendance Summary (<?= count($att_timeline) ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" id="comp-tab" data-toggle="tab" href="#tab-complaints" role="tab">
              <i class="fa-solid fa-triangle-exclamation"></i> Complaints Log (<?= count($complaints_list) ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" id="vis-tab" data-toggle="tab" href="#tab-visitors" role="tab">
              <i class="fa-solid fa-address-book"></i> Visitors Roster (<?= count($visitors_list) ?>)
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" id="occ-tab" data-toggle="tab" href="#tab-occupancy" role="tab">
              <i class="fa-solid fa-hotel"></i> Hostel Blocks Audit (<?= count($blocks_occupancy) ?>)
            </a>
          </li>
        </ul>

        <div class="tab-content" id="reportTabsContent">
          
          <!-- TAB 1: PAYMENT HISTORY AUDIT -->
          <div class="tab-pane fade show active" id="tab-fees" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="font-weight-800 text-dark mb-0">
                <i class="fa-solid fa-receipt text-success mr-2"></i> Student Fee Payment History Audit
              </h6>
              <span class="badge badge-success px-3 py-1 font-weight-700" style="background: #ecfdf5; color: #065f46; border: 1.5px solid #a7f3d0; border-radius: 50px; font-size: 0.8rem;">
                Total Collected: ₹ <?= number_format($stat_fee_revenue, 2) ?>
              </span>
            </div>

            <div class="table-responsive">
              <table class="table report-table table-hover align-middle mb-0" id="reportFeeTable">
                <thead>
                  <tr>
                    <th style="width: 50px;">#</th>
                    <th>Student Resident</th>
                    <th>Room & Bed Allocation</th>
                    <th>Fee Amount</th>
                    <th>Billing Date</th>
                    <th>Logged At</th>
                    <th>Status</th>
                    <th class="text-center btn-no-print" style="width: 90px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fee_audit_list as $row): 
                      $first_letter = !empty($row['student_name']) ? strtoupper(substr($row['student_name'], 0, 1)) : 'S';
                      $receipt_no = "REC-" . date('Y', strtotime($row['fee_date'])) . "-" . str_pad($row['fee_id'], 4, '0', STR_PAD_LEFT);
                  ?>
                    <tr>
                      <td class="font-weight-bold text-muted"><?= $row['fee_id'] ?></td>
                      <td>
                        <div class="d-flex align-items-center">
                          <?php if (!empty($row['student_profile_image']) && file_exists("../student_profile/" . $row['student_profile_image'])): ?>
                            <img src="../student_profile/<?= htmlspecialchars($row['student_profile_image']) ?>" class="student-avatar mr-2" alt="Student">
                          <?php else: ?>
                            <div class="student-avatar-placeholder mr-2" style="width:34px; height:34px;"><?= $first_letter ?></div>
                          <?php endif; ?>
                          <div>
                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?></div>
                            <small class="text-muted"><?= htmlspecialchars($row['student_phone'] ?? '') ?></small>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['block_name'] ?? 'Block') ?></div>
                        <small class="text-muted">Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?><?= !empty($row['bed_number']) ? ' (Bed ' . htmlspecialchars($row['bed_number']) . ')' : '' ?></small>
                      </td>
                      <td>
                        <div class="fee-amount-badge">₹ <?= number_format($row['price'], 2) ?></div>
                        <small class="text-muted">Hostel Accommodation</small>
                      </td>
                      <td>
                        <div class="font-weight-bold text-dark"><?= date('d M, Y', strtotime($row['fee_date'])) ?></div>
                        <small class="text-muted"><?= date('l', strtotime($row['fee_date'])) ?></small>
                      </td>
                      <td>
                        <div class="text-dark font-weight-medium"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                        <small class="text-muted"><?= date('h:i A', strtotime($row['created_at'])) ?></small>
                      </td>
                      <td>
                        <span class="badge-stat-pill badge-p-present font-weight-bold">
                          <i class="fa-solid fa-check mr-1"></i> Paid
                        </span>
                      </td>
                      <td class="text-center btn-no-print">
                        <button type="button" class="btn btn-sm btn-outline-success view-fee-btn rounded-8"
                          data-id="<?= $row['fee_id'] ?>"
                          data-receipt="<?= $receipt_no ?>"
                          data-name="<?= htmlspecialchars($row['student_name'] ?? '') ?>"
                          data-adm="<?= htmlspecialchars($row['admission_no'] ?? 'N/A') ?>"
                          data-email="<?= htmlspecialchars($row['student_email'] ?? '') ?>"
                          data-phone="<?= htmlspecialchars($row['student_phone'] ?? '') ?>"
                          data-block="<?= htmlspecialchars($row['block_name'] ?? 'Not Allocated') ?>"
                          data-floor="<?= htmlspecialchars($row['floor_name'] ?? 'N/A') ?>"
                          data-room="<?= htmlspecialchars($row['room_number'] ?? 'N/A') ?>"
                          data-bed="<?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>"
                          data-price="<?= number_format($row['price'], 2) ?>"
                          data-date="<?= date('d F, Y', strtotime($row['fee_date'])) ?>"
                          data-time="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>">
                          <i class="fa-solid fa-file-invoice"></i> Receipt
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- TAB 2: ATTENDANCE AUDIT -->
          <div class="tab-pane fade" id="tab-attendance" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="font-weight-800 text-dark mb-0">
                <i class="fa-solid fa-calendar-day text-success mr-2"></i> Attendance Record Breakdown
              </h6>
              <span class="text-muted font-weight-600" style="font-size: 0.8rem;">
                Total Marked: <strong><?= number_format($att_total) ?></strong>
              </span>
            </div>

            <?php if (empty($att_timeline)): ?>
              <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-calendar-xmark fa-2x mb-2 text-muted"></i>
                <p class="font-weight-600 mb-0">No roll call records found in this date range.</p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table report-table table-hover align-middle mb-0" id="reportAttendanceTable">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th class="text-center">Total Marked</th>
                      <th class="text-center">Present Count</th>
                      <th class="text-center">Absent Count</th>
                      <th class="text-center">Late Count</th>
                      <th class="text-center">Attendance %</th>
                      <th class="text-right btn-no-print">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($att_timeline as $at): 
                        $t_marked = intval($at['total_marked']);
                        $t_pres   = intval($at['present_cnt']);
                        $t_abs    = intval($at['absent_cnt']);
                        $t_late   = intval($at['late_cnt']);
                        $t_pct    = ($t_marked > 0) ? round(($t_pres / $t_marked) * 100, 1) : 0;
                    ?>
                      <tr>
                        <td>
                          <strong class="text-dark"><?= date('D, d M Y', strtotime($at['attendance_date'])) ?></strong>
                        </td>
                        <td class="text-center font-weight-700"><?= $t_marked ?></td>
                        <td class="text-center">
                          <span class="badge-stat-pill badge-p-present"><?= $t_pres ?> Present</span>
                        </td>
                        <td class="text-center">
                          <span class="badge-stat-pill badge-p-absent"><?= $t_abs ?> Absent</span>
                        </td>
                        <td class="text-center">
                          <span class="badge-stat-pill badge-p-late"><?= $t_late ?> Late</span>
                        </td>
                        <td class="text-center">
                          <div class="d-inline-flex align-items-center">
                            <strong class="text-dark mr-2"><?= $t_pct ?>%</strong>
                            <div class="progress" style="width: 70px; height: 6px; border-radius: 10px;">
                              <div class="progress-bar bg-success" style="width: <?= $t_pct ?>%;"></div>
                            </div>
                          </div>
                        </td>
                        <td class="text-right btn-no-print">
                          <a href="attendance.php?filter_date=<?= urlencode($at['attendance_date']) ?>" class="btn btn-sm btn-outline-success font-weight-700 py-1 px-2 rounded-8" style="font-size: 0.75rem;">
                            View Day Logs
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- TAB 2: COMPLAINTS AUDIT -->
          <div class="tab-pane fade" id="tab-complaints" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="font-weight-800 text-dark mb-0">
                <i class="fa-solid fa-list-check text-warning mr-2"></i> Complaints Lodged in Selected Period
              </h6>
              <span class="text-muted font-weight-600" style="font-size: 0.8rem;">
                Total: <strong><?= count($complaints_list) ?></strong> tickets
              </span>
            </div>

            <?php if (empty($complaints_list)): ?>
              <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-check-double fa-2x mb-2 text-success"></i>
                <p class="font-weight-600 mb-0">No complaints registered in this date range.</p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table report-table table-hover align-middle mb-0" id="reportComplaintsTable">
                  <thead>
                    <tr>
                      <th>Ticket ID</th>
                      <th>Resident & Room</th>
                      <th>Issue Title</th>
                      <th class="text-center">Priority</th>
                      <th class="text-center">Status</th>
                      <th>Lodged Date</th>
                      <th>Resolution Info</th>
                      <th class="text-right btn-no-print">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($complaints_list as $cl): ?>
                      <tr>
                        <td><span class="badge badge-light border text-muted font-weight-700">#<?= $cl['id'] ?></span></td>
                        <td>
                          <strong class="text-dark d-block"><?= htmlspecialchars($cl['student_name']) ?></strong>
                          <small class="text-muted">
                            <?= !empty($cl['room_number']) ? (($cl['block_name'] ?? 'Block') . " Rm " . $cl['room_number']) : 'Unassigned' ?>
                          </small>
                        </td>
                        <td style="max-width: 200px;">
                          <strong class="text-dark d-block text-truncate"><?= htmlspecialchars($cl['title']) ?></strong>
                          <small class="text-muted d-block text-truncate"><?= htmlspecialchars($cl['description']) ?></small>
                        </td>
                        <td class="text-center">
                          <?php 
                            $p_class = 'badge-prio-' . strtolower($cl['priority']);
                          ?>
                          <span class="badge-stat-pill <?= $p_class ?>">
                            <?= ucfirst($cl['priority']) ?>
                          </span>
                        </td>
                        <td class="text-center">
                          <?php if ($cl['status'] === 'resolved'): ?>
                            <span class="badge badge-success px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Resolved</span>
                          <?php elseif ($cl['status'] === 'in_progress'): ?>
                            <span class="badge badge-info text-white px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">In Progress</span>
                          <?php elseif ($cl['status'] === 'rejected'): ?>
                            <span class="badge badge-danger px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Rejected</span>
                          <?php else: ?>
                            <span class="badge badge-warning text-dark px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Pending</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <small class="text-muted font-weight-600">
                            <?= date('d M Y, h:i A', strtotime($cl['created_at'])) ?>
                          </small>
                        </td>
                        <td style="max-width: 180px;">
                          <?php if (!empty($cl['resolution_remarks'])): ?>
                            <small class="text-dark d-block text-truncate font-weight-600"><?= htmlspecialchars($cl['resolution_remarks']) ?></small>
                            <?php if (!empty($cl['resolved_at'])): ?>
                              <small class="text-success"><i class="fa-regular fa-clock mr-1"></i><?= date('d M, h:i A', strtotime($cl['resolved_at'])) ?></small>
                            <?php endif; ?>
                          <?php else: ?>
                            <small class="text-muted italic">No remarks recorded</small>
                          <?php endif; ?>
                        </td>
                        <td class="text-right btn-no-print">
                          <a href="complaints.php" class="btn btn-sm btn-outline-success font-weight-700 py-1 px-2 rounded-8" style="font-size: 0.75rem;">
                            View
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- TAB 3: VISITORS AUDIT -->
          <div class="tab-pane fade" id="tab-visitors" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="font-weight-800 text-dark mb-0">
                <i class="fa-solid fa-address-book text-primary mr-2"></i> Visitor Requests & Gate Logs
              </h6>
              <span class="text-muted font-weight-600" style="font-size: 0.8rem;">
                Total: <strong><?= count($visitors_list) ?></strong> visitors
              </span>
            </div>

            <?php if (empty($visitors_list)): ?>
              <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-user-clock fa-2x mb-2 text-muted"></i>
                <p class="font-weight-600 mb-0">No visitors logged in this date range.</p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table report-table table-hover align-middle mb-0" id="reportVisitorsTable">
                  <thead>
                    <tr>
                      <th>Visitor Name</th>
                      <th>Resident Visited</th>
                      <th>Visit Date</th>
                      <th>Expected Timing</th>
                      <th>Actual Gate Logs</th>
                      <th class="text-center">Status</th>
                      <th class="text-right btn-no-print">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($visitors_list as $vl): ?>
                      <tr>
                        <td>
                          <strong class="text-dark d-block"><?= htmlspecialchars($vl['name']) ?></strong>
                          <small class="text-muted">
                            <i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($vl['phone'] ?? '-') ?> 
                            (<?= htmlspecialchars($vl['relationship'] ?? 'Guest') ?>)
                          </small>
                        </td>
                        <td>
                          <strong class="text-dark d-block"><?= htmlspecialchars($vl['student_name']) ?></strong>
                          <small class="text-muted">
                            <?= !empty($vl['room_number']) ? (($vl['block_name'] ?? 'Block') . " Rm " . $vl['room_number']) : 'Unassigned' ?>
                          </small>
                        </td>
                        <td>
                          <strong class="text-dark"><?= date('D, d M Y', strtotime($vl['visit_date'])) ?></strong>
                        </td>
                        <td>
                          <small class="text-dark d-block font-weight-600">
                            <?= !empty($vl['expected_in_time']) ? date('h:i A', strtotime($vl['expected_in_time'])) : '-' ?>
                            — <?= !empty($vl['expected_out_time']) ? date('h:i A', strtotime($vl['expected_out_time'])) : '-' ?>
                          </small>
                        </td>
                        <td>
                          <?php if (!empty($vl['check_in_at'])): ?>
                            <small class="text-success d-block font-weight-700">
                              <i class="fa-solid fa-arrow-right-to-bracket mr-1"></i>In: <?= date('d M, h:i A', strtotime($vl['check_in_at'])) ?>
                            </small>
                            <?php if (!empty($vl['check_out_at'])): ?>
                              <small class="text-danger d-block font-weight-700">
                                <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i>Out: <?= date('d M, h:i A', strtotime($vl['check_out_at'])) ?>
                              </small>
                            <?php else: ?>
                              <span class="badge badge-warning text-dark font-weight-700" style="font-size: 0.68rem;">Inside Hostel</span>
                            <?php endif; ?>
                          <?php else: ?>
                            <small class="text-muted italic">No gate entry recorded</small>
                          <?php endif; ?>
                        </td>
                        <td class="text-center">
                          <?php if ($vl['status'] === 'approved'): ?>
                            <span class="badge badge-success px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Approved</span>
                          <?php elseif ($vl['status'] === 'rejected'): ?>
                            <span class="badge badge-danger px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Rejected</span>
                          <?php elseif ($vl['status'] === 'completed'): ?>
                            <span class="badge badge-primary px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Completed</span>
                          <?php else: ?>
                            <span class="badge badge-warning text-dark px-2 py-1 font-weight-700" style="font-size: 0.72rem; border-radius: 50px;">Pending</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-right btn-no-print">
                          <a href="visitors.php" class="btn btn-sm btn-outline-primary font-weight-700 py-1 px-2 rounded-8" style="font-size: 0.75rem;">
                            View
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

          <!-- TAB 4: OCCUPANCY AUDIT -->
          <div class="tab-pane fade" id="tab-occupancy" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="font-weight-800 text-dark mb-0">
                <i class="fa-solid fa-hotel text-success mr-2"></i> Hostel Blocks & Beds Audit
              </h6>
              <span class="text-muted font-weight-600" style="font-size: 0.8rem;">
                Total Hostel Beds: <strong><?= $total_beds ?></strong> | Occupied: <strong><?= $occupied_beds ?></strong> (<?= $occupancy_pct ?>%)
              </span>
            </div>

            <?php if (empty($blocks_occupancy)): ?>
              <p class="text-muted text-center py-5 font-weight-600">No block records configured.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table report-table table-hover align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Block Name</th>
                      <th class="text-center">Total Rooms</th>
                      <th class="text-center">Total Bed Capacity</th>
                      <th class="text-center">Occupied Beds</th>
                      <th class="text-center">Vacant Beds</th>
                      <th class="text-center">Occupancy Rate</th>
                      <th class="text-right btn-no-print">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($blocks_occupancy as $bocc): 
                        $b_tot  = intval($bocc['total_beds']);
                        $b_oc   = intval($bocc['occupied_beds']);
                        $b_av   = intval($bocc['available_beds']);
                        $b_rate = ($b_tot > 0) ? round(($b_oc / $b_tot) * 100, 1) : 0;
                    ?>
                      <tr>
                        <td>
                          <strong class="text-dark font-weight-800" style="font-size: 0.95rem;">
                            <i class="fa-solid fa-building text-success mr-2"></i><?= htmlspecialchars($bocc['block_name']) ?>
                          </strong>
                        </td>
                        <td class="text-center font-weight-700"><?= intval($bocc['total_rooms']) ?></td>
                        <td class="text-center font-weight-700"><?= $b_tot ?></td>
                        <td class="text-center">
                          <span class="badge badge-success px-2 py-1 font-weight-700" style="font-size: 0.78rem; border-radius: 50px;">
                            <?= $b_oc ?> Occupied
                          </span>
                        </td>
                        <td class="text-center">
                          <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" style="font-size: 0.78rem; border-radius: 50px;">
                            <?= $b_av ?> Available
                          </span>
                        </td>
                        <td class="text-center">
                          <div class="d-inline-flex align-items-center">
                            <strong class="text-dark mr-2"><?= $b_rate ?>%</strong>
                            <div class="progress" style="width: 80px; height: 6px; border-radius: 10px;">
                              <div class="progress-bar bg-success" style="width: <?= $b_rate ?>%;"></div>
                            </div>
                          </div>
                        </td>
                        <td class="text-right btn-no-print">
                          <a href="student.php" class="btn btn-sm btn-outline-success font-weight-700 py-1 px-2 rounded-8" style="font-size: 0.75rem;">
                            View Roster
                          </a>
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
  </div>

  <!-- =========================================================
       OFFICIAL AUDIT SIGN-OFF FOOTER (PRINT READY)
       ========================================================= -->
  <div class="row pt-4 mt-2 print-only-header border-top">
    <div class="col-6">
      <p class="font-weight-700 text-dark mb-1">Generated by Warden:</p>
      <p class="text-dark mb-0 font-weight-600"><?= htmlspecialchars($warden_name) ?> (<?= htmlspecialchars($warden_code) ?>)</p>
      <small class="text-muted">Hostel Operations Center, Hostel Mate System</small>
    </div>
    <div class="col-6 text-right">
      <div style="height: 40px;"></div>
      <p class="font-weight-700 text-dark mb-0 border-top d-inline-block pt-1" style="min-width: 200px;">
        Authorized Institutional Sign & Seal
      </p>
    </div>
  </div>

</div>

<!-- Modal: View Fee Receipt -->
<div class="modal fade" id="feeReceiptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 580px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title font-weight-bold text-dark d-flex align-items-center mb-0">
                    <i class="fa-solid fa-receipt text-success mr-2"></i> Official Hostel Fee Receipt
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <div class="receipt-box" id="wardenPrintableReceiptArea">
                    <div class="receipt-watermark">PAID</div>
                    
                    <!-- Institutional Header -->
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <h5 class="font-weight-bold text-success mb-1">HOSTEL MATE</h5>
                            <small class="text-muted d-block">Hostel Administration & Warden Desk</small>
                            <small class="text-muted">Official Student Fee Payment Voucher</small>
                        </div>
                        <div class="text-right">
                            <span class="badge badge-success px-2 py-1 font-weight-bold mb-1" style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; font-size: 0.78rem;">Status: PAID</span>
                            <div class="font-weight-bold text-dark" style="font-size: 0.85rem;" id="modalReceiptNo">REC-0000</div>
                        </div>
                    </div>

                    <!-- Resident Details -->
                    <div class="row mb-3">
                        <div class="col-6">
                            <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.7rem;">Student Resident</small>
                            <h6 class="font-weight-bold text-dark mb-0" id="modalStudentName">-</h6>
                            <small class="text-muted d-block" id="modalStudentAdm">Adm No: -</small>
                            <small class="text-muted" id="modalStudentPhone">Phone: -</small>
                        </div>
                        <div class="col-6 text-right">
                            <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.7rem;">Room Allotment</small>
                            <h6 class="font-weight-bold text-dark mb-0" id="modalStudentRoom">-</h6>
                            <small class="text-muted d-block" id="modalStudentBlock">Block -</small>
                            <small class="text-muted" id="modalStudentBed">Bed -</small>
                        </div>
                    </div>

                    <!-- Line Item Breakdown -->
                    <div class="table-responsive mb-3" style="border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
                        <table class="table table-sm mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="py-2 px-3 text-uppercase" style="font-size: 0.72rem;">Description</th>
                                    <th class="py-2 px-3 text-right text-uppercase" style="font-size: 0.72rem;">Billing Date</th>
                                    <th class="py-2 px-3 text-right text-uppercase" style="font-size: 0.72rem;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="py-2 px-3">
                                        <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">Hostel Room & Bed Allocation</div>
                                        <small class="text-muted">Standard accommodation and hostel facilities fee</small>
                                    </td>
                                    <td class="py-2 px-3 text-right font-weight-medium text-dark" style="font-size: 0.86rem;" id="modalFeeDate">-</td>
                                    <td class="py-2 px-3 text-right font-weight-bold text-dark" style="font-size: 0.92rem;">
                                        ₹ <span id="modalFeeAmount">0.00</span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <th colspan="2" class="py-2 px-3 text-right font-weight-bold text-dark" style="font-size: 0.88rem;">Total Amount Paid:</th>
                                    <th class="py-2 px-3 text-right font-weight-bold text-success" style="font-size: 1.05rem;">
                                        ₹ <span id="modalFeeTotal">0.00</span>
                                    </th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Footer Notes & Timestamp -->
                    <div class="d-flex justify-content-between align-items-center pt-2">
                        <small class="text-muted">
                            <i class="fa-regular fa-clock mr-1"></i> Recorded on: <span id="modalRecordedTime" class="font-weight-bold text-dark">-</span>
                        </small>
                        <small class="text-muted font-italic">Hostel Mate Authorized Copy</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-3 px-4 d-flex justify-content-between" style="background: #f8fafc; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
                <button type="button" class="btn btn-outline-secondary" onclick="printWardenReceiptElement('wardenPrintableReceiptArea')">
                    <i class="fa-solid fa-print mr-1"></i> Print Receipt
                </button>
                <button type="button" class="btn btn-secondary px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     CHART.JS SCRIPTS & DATATABLES INITIALIZATION
     ========================================================= -->
<script>
document.addEventListener("DOMContentLoaded", function() {

  // 0. Fee Collections & Daily Revenue Chart
  const feeCtx = document.getElementById('reportFeeChart');
  if (feeCtx) {
    new Chart(feeCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode(!empty($fee_chart_labels) ? $fee_chart_labels : [date('d M')]) ?>,
        datasets: [
          {
            label: 'Collected Amount (₹)',
            data: <?= json_encode(!empty($fee_chart_amounts) ? $fee_chart_amounts : [0]) ?>,
            backgroundColor: '#059669',
            borderRadius: 5,
            barPercentage: 0.65
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top',
            labels: {
              boxWidth: 12,
              font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
              color: '#334155'
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              font: { family: 'Plus Jakarta Sans', size: 10 },
              color: '#64748b',
              callback: function(value) { return '₹ ' + value.toLocaleString(); }
            },
            grid: { color: '#f1f5f9' }
          },
          x: {
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b' },
            grid: { display: false }
          }
        }
      }
    });
  }

  // 1. Attendance Timeline Chart
  const attCtx = document.getElementById('reportAttendanceChart');
  if (attCtx) {
    new Chart(attCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($att_chart_labels) ?>,
        datasets: [
          {
            label: 'Present Students',
            data: <?= json_encode($att_chart_present) ?>,
            backgroundColor: '#059669',
            borderRadius: 5,
            barPercentage: 0.65,
            categoryPercentage: 0.7
          },
          {
            label: 'Absent',
            data: <?= json_encode($att_chart_absent) ?>,
            backgroundColor: '#ef4444',
            borderRadius: 5,
            barPercentage: 0.65,
            categoryPercentage: 0.7
          },
          {
            label: 'Late',
            data: <?= json_encode($att_chart_late) ?>,
            backgroundColor: '#f59e0b',
            borderRadius: 5,
            barPercentage: 0.65,
            categoryPercentage: 0.7
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top',
            labels: {
              boxWidth: 12,
              font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
              color: '#334155',
              padding: 12
            }
          },
          tooltip: {
            backgroundColor: '#0f172a',
            padding: 10,
            cornerRadius: 8
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b', stepSize: 1 },
            grid: { color: '#f1f5f9' }
          },
          x: {
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b' },
            grid: { display: false }
          }
        }
      }
    });
  }

  // 2. Complaints Resolution Doughnut Chart
  const compCtx = document.getElementById('reportComplaintsChart');
  if (compCtx) {
    new Chart(compCtx.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: ['Resolved', 'In Progress', 'Pending', 'Rejected'],
        datasets: [{
          data: [
            <?= $comp_resolved ?>,
            <?= $comp_in_progress ?>,
            <?= $comp_pending ?>,
            <?= $comp_rejected ?>
          ],
          backgroundColor: [
            '#059669', // Emerald
            '#0284c7', // Sky Blue
            '#f59e0b', // Amber
            '#ef4444'  // Rose Red
          ],
          borderWidth: 3,
          borderColor: '#ffffff',
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        cutoutPercentage: 70,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              boxWidth: 10,
              font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
              color: '#334155',
              padding: 8
            }
          },
          tooltip: {
            backgroundColor: '#0f172a',
            padding: 10,
            cornerRadius: 8
          }
        }
      }
    });
  }

  // 3. Visitor Traffic Chart
  const visCtx = document.getElementById('reportVisitorChart');
  if (visCtx) {
    new Chart(visCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($vis_chart_labels) ?>,
        datasets: [
          {
            label: 'Total Requests',
            data: <?= json_encode($vis_chart_requests) ?>,
            backgroundColor: '#0284c7',
            borderRadius: 5,
            barPercentage: 0.6
          },
          {
            label: 'Approved Visits',
            data: <?= json_encode($vis_chart_approved) ?>,
            backgroundColor: '#10b981',
            borderRadius: 5,
            barPercentage: 0.6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top',
            labels: {
              boxWidth: 12,
              font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
              color: '#334155'
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b', stepSize: 1 },
            grid: { color: '#f1f5f9' }
          },
          x: {
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b' },
            grid: { display: false }
          }
        }
      }
    });
  }

  // 4. Block Bed Capacity Chart
  const blockCtx = document.getElementById('reportBlockChart');
  if (blockCtx) {
    new Chart(blockCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($block_chart_names) ?>,
        datasets: [
          {
            label: 'Occupied Beds',
            data: <?= json_encode($block_chart_occ) ?>,
            backgroundColor: '#059669',
            borderRadius: 5,
            barPercentage: 0.6
          },
          {
            label: 'Available Vacant Beds',
            data: <?= json_encode($block_chart_avail) ?>,
            backgroundColor: '#a7f3d0',
            borderRadius: 5,
            barPercentage: 0.6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'top',
            labels: {
              boxWidth: 12,
              font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
              color: '#334155'
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b', stepSize: 1 },
            grid: { color: '#f1f5f9' }
          },
          x: {
            ticks: { font: { family: 'Plus Jakarta Sans', size: 10 }, color: '#64748b' },
            grid: { display: false }
          }
        }
      }
    });
  }

  // Initialize DataTables for searchable audit lists if available
  if (window.jQuery && $.fn.DataTable) {
    // Prevent browser alert() popups for DataTables warnings
    $.fn.dataTable.ext.errMode = 'none';

    if (!$.fn.DataTable.isDataTable('#reportFeeTable')) {
      $('#reportFeeTable').DataTable({
        pageLength: 10,
        ordering: true,
        order: [[4, "desc"]],
        columns: [
          { orderable: true },
          { orderable: true },
          { orderable: true },
          { orderable: true },
          { orderable: true },
          { orderable: true },
          { orderable: true },
          { orderable: false }
        ],
        language: {
          search: "Filter Payments:",
          emptyTable: "No fee payment records found within this date period",
          zeroRecords: "No matching payment records found"
        }
      });
    }

    if (!$.fn.DataTable.isDataTable('#reportAttendanceTable')) {
      $('#reportAttendanceTable').DataTable({
        pageLength: 10,
        ordering: true,
        order: [[0, "desc"]],
        language: { search: "Filter Days:" }
      });
    }

    if (!$.fn.DataTable.isDataTable('#reportComplaintsTable')) {
      $('#reportComplaintsTable').DataTable({
        pageLength: 10,
        ordering: true,
        order: [[5, "desc"]],
        language: { search: "Filter Tickets:" }
      });
    }

    if (!$.fn.DataTable.isDataTable('#reportVisitorsTable')) {
      $('#reportVisitorsTable').DataTable({
        pageLength: 10,
        ordering: true,
        order: [[2, "desc"]],
        language: { search: "Filter Visitors:" }
      });
    }

    $('a[data-toggle="tab"]').on('shown.bs.tab', function () {
      $($.fn.dataTable.tables(true)).DataTable().columns.adjust().responsive.recalc();
    });
  }

  // Handle View Receipt Modal in Warden
  $(document).on('click', '.view-fee-btn', function () {
    var btn = $(this);
    $('#modalReceiptNo').text(btn.data('receipt'));
    $('#modalStudentName').text(btn.data('name'));
    $('#modalStudentAdm').text('Adm No: ' + btn.data('adm'));
    $('#modalStudentPhone').text('Phone: ' + btn.data('phone'));
    $('#modalStudentRoom').text('Room ' + btn.data('room'));
    $('#modalStudentBlock').text(btn.data('block') + ' (' + btn.data('floor') + ')');
    $('#modalStudentBed').text('Bed: ' + btn.data('bed'));

    $('#modalFeeDate').text(btn.data('date'));
    $('#modalFeeAmount').text(btn.data('price'));
    $('#modalFeeTotal').text(btn.data('price'));
    $('#modalRecordedTime').text(btn.data('time'));

    $('#feeReceiptModal').modal('show');
  });

});

function printWardenReceiptElement(elementId) {
  var printContent = document.getElementById(elementId).innerHTML;
  var printWindow = window.open('', '', 'height=650,width=750');
  printWindow.document.write('<html><head><title>Hostel Fee Receipt</title>');
  printWindow.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">');
  printWindow.document.write('<style>body{padding:20px;font-family:sans-serif;} .badge-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:4px 8px;border-radius:6px;} .receipt-box{border:1px solid #ddd;padding:20px;border-radius:10px;} .receipt-watermark{position:absolute;top:50%;left:50%;transform:translate(-50%, -50%) rotate(-25deg);font-size:4rem;font-weight:900;color:rgba(5, 150, 105, 0.06);text-transform:uppercase;pointer-events:none;user-select:none;letter-spacing:5px;}</style>');
  printWindow.document.write('</head><body>');
  printWindow.document.write(printContent);
  printWindow.document.write('</body></html>');
  printWindow.document.close();
  printWindow.focus();
  setTimeout(function() {
    printWindow.print();
    printWindow.close();
  }, 500);
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/main_master.php';
?>
