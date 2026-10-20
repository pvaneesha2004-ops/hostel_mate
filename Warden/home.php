<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../db.php';

$warden_id = intval($_SESSION['warden_id']);
$warden_name = $_SESSION['warden_name'] ?? 'Hostel Warden';
$warden_code = $_SESSION['warden_code'] ?? 'WDN';

// -------------------------------------------------------------
// 1. WARDEN DETAILS & ASSIGNED BLOCKS
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
// 2. STUDENTS & OCCUPANCY METRICS
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
$unallocated_students = intval($student_stats['unallocated_count'] ?? 0);

// Beds & Occupancy
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

// -------------------------------------------------------------
// 3. TODAY'S ATTENDANCE STATS
// -------------------------------------------------------------
$today_att_q = $conn->query("
    SELECT 
        COUNT(*) as total_marked,
        SUM(CASE WHEN LOWER(status) = 'present' THEN 1 ELSE 0 END) as present_cnt,
        SUM(CASE WHEN LOWER(status) = 'absent' THEN 1 ELSE 0 END) as absent_cnt,
        SUM(CASE WHEN LOWER(status) = 'late' THEN 1 ELSE 0 END) as late_cnt
    FROM attendance
    WHERE attendance_date = CURDATE()
");
$today_att = ($today_att_q && $today_att_q->num_rows > 0) ? $today_att_q->fetch_assoc() : [];
$att_total   = intval($today_att['total_marked'] ?? 0);
$att_present = intval($today_att['present_cnt'] ?? 0);
$att_absent  = intval($today_att['absent_cnt'] ?? 0);
$att_late    = intval($today_att['late_cnt'] ?? 0);
$att_pct     = ($att_total > 0) ? round(($att_present / $att_total) * 100, 1) : 0;

// 7-Day Attendance Trend
$trend_labels = [];
$trend_present = [];
$trend_absent = [];
$trend_late = [];

for ($i = 6; $i >= 0; $i--) {
    $t_date = date('Y-m-d', strtotime("-$i days"));
    $t_label = date('D d M', strtotime("-$i days"));
    $trend_labels[] = $t_label;

    $t_q = $conn->query("
        SELECT 
            SUM(CASE WHEN LOWER(status) = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN LOWER(status) = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN LOWER(status) = 'late' THEN 1 ELSE 0 END) as late
        FROM attendance
        WHERE attendance_date = '{$t_date}'
    ");
    $t_row = ($t_q && $t_q->num_rows > 0) ? $t_q->fetch_assoc() : [];
    $trend_present[] = intval($t_row['present'] ?? 0);
    $trend_absent[]  = intval($t_row['absent'] ?? 0);
    $trend_late[]    = intval($t_row['late'] ?? 0);
}

// -------------------------------------------------------------
// 4. COMPLAINTS METRICS
// -------------------------------------------------------------
$comp_q = $conn->query("
    SELECT 
        COUNT(*) as total_cnt,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_cnt,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_cnt,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_cnt,
        SUM(CASE WHEN priority IN ('urgent', 'high') AND status != 'resolved' THEN 1 ELSE 0 END) as urgent_cnt
    FROM complaints
");
$comp_stats = ($comp_q && $comp_q->num_rows > 0) ? $comp_q->fetch_assoc() : [];
$comp_total       = intval($comp_stats['total_cnt'] ?? 0);
$comp_pending     = intval($comp_stats['pending_cnt'] ?? 0);
$comp_in_progress = intval($comp_stats['in_progress_cnt'] ?? 0);
$comp_resolved    = intval($comp_stats['resolved_cnt'] ?? 0);
$comp_rejected    = intval($comp_stats['rejected_cnt'] ?? 0);
$comp_urgent      = intval($comp_stats['urgent_cnt'] ?? 0);

// Recent Open Complaints (Latest 5 that need warden review)
$recent_complaints_res = $conn->query("
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
    WHERE c.status IN ('pending', 'in_progress')
    ORDER BY FIELD(c.priority, 'urgent', 'high', 'medium', 'low'), c.created_at DESC
    LIMIT 5
");
$recent_complaints = [];
if ($recent_complaints_res && $recent_complaints_res->num_rows > 0) {
    while ($rc = $recent_complaints_res->fetch_assoc()) {
        $recent_complaints[] = $rc;
    }
}

// -------------------------------------------------------------
// 5. VISITORS BOOK TODAY & ACTIVE GATE MOVEMENT
// -------------------------------------------------------------
$vis_q = $conn->query("
    SELECT 
        COUNT(*) as total_today,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_today,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_today
    FROM visitors
    WHERE visit_date = CURDATE()
");
$vis_stats = ($vis_q && $vis_q->num_rows > 0) ? $vis_q->fetch_assoc() : [];
$vis_total_today    = intval($vis_stats['total_today'] ?? 0);
$vis_pending_today  = intval($vis_stats['pending_today'] ?? 0);
$vis_approved_today = intval($vis_stats['approved_today'] ?? 0);

// In Hostel Right Now
$in_hostel_q = $conn->query("
    SELECT COUNT(DISTINCT visitor_id) as in_hostel 
    FROM visitor_logs 
    WHERE check_in_at IS NOT NULL AND check_out_at IS NULL AND DATE(check_in_at) = CURDATE()
");
$in_hostel_now = ($in_hostel_q && $in_hostel_q->num_rows > 0) ? intval($in_hostel_q->fetch_assoc()['in_hostel']) : 0;

// Today's Visitor Requests Roster (Latest 4)
$today_visitors_res = $conn->query("
    SELECT 
        v.*, 
        u.name as student_name, 
        rm.room_number,
        blk.name as block_name
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    WHERE v.visit_date = CURDATE()
    ORDER BY v.created_at DESC
    LIMIT 4
");
$today_visitors = [];
if ($today_visitors_res && $today_visitors_res->num_rows > 0) {
    while ($tv = $today_visitors_res->fetch_assoc()) {
        $today_visitors[] = $tv;
    }
}

// -------------------------------------------------------------
// 6. BLOCK & ROOM OCCUPANCY BREAKDOWN
// -------------------------------------------------------------
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
if ($blocks_occ_res && $blocks_occ_res->num_rows > 0) {
    while ($bo = $blocks_occ_res->fetch_assoc()) {
        $blocks_occupancy[] = $bo;
    }
}

// -------------------------------------------------------------
// 7. LATEST ACTIVE NOTICES
// -------------------------------------------------------------
$notices_res = $conn->query("
    SELECT * 
    FROM notices 
    WHERE status = 'published' AND (publish_at IS NULL OR publish_at <= NOW()) AND (expires_at IS NULL OR expires_at >= NOW()) 
    ORDER BY FIELD(priority, 'urgent', 'important', 'normal'), created_at DESC 
    LIMIT 3
");
$active_notices = [];
if ($notices_res && $notices_res->num_rows > 0) {
    while ($n = $notices_res->fetch_assoc()) {
        $active_notices[] = $n;
    }
}

// -------------------------------------------------------------
// 8. TODAY'S FOOD MENU PREVIEW
// -------------------------------------------------------------
$today_menu_day = date('l'); // e.g. Tuesday
$today_menu_q = $conn->query("SELECT * FROM food_menus WHERE menu_day = '{$today_menu_day}' LIMIT 1");
$today_menu = ($today_menu_q && $today_menu_q->num_rows > 0) ? $today_menu_q->fetch_assoc() : null;

ob_start();
?>

<!-- Chart.js 3+ for High-Performance Graphs -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
  :root {
    --warden-primary: #059669;
    --warden-primary-dark: #047857;
    --warden-accent: #10b981;
    --warden-obsidian: #03201a;
  }

  /* Page Wrapper */
  .warden-dashboard-wrapper {
    width: 100%;
    margin: 0;
    padding: 0;
  }

  /* Welcome Hero Card */
  .warden-hero-card {
    background: linear-gradient(135deg, #03201a 0%, #064e3b 50%, #059669 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem;
    color: #ffffff;
    box-shadow: 0 10px 28px rgba(5, 150, 105, 0.22);
    position: relative;
    overflow: hidden;
  }

  .warden-hero-card::after {
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

  .hero-tag-badge {
    background: rgba(255, 255, 255, 0.16);
    border: 1px solid rgba(255, 255, 255, 0.22);
    color: #ffffff;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    backdrop-filter: blur(4px);
    margin-right: 6px;
    margin-bottom: 6px;
  }

  .hero-quick-actions {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
  }

  .btn-hero-action {
    border-radius: 12px;
    padding: 0.55rem 1.15rem;
    font-size: 0.84rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.22s ease;
    text-decoration: none !important;
  }

  .btn-hero-primary {
    background: #ffffff;
    color: #065f46 !important;
    border: 1px solid rgba(255, 255, 255, 0.9);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
  }
  .btn-hero-primary:hover {
    background: #ecfdf5;
    color: #047857 !important;
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
  }

  .btn-hero-glass {
    background: rgba(255, 255, 255, 0.18);
    color: #ffffff !important;
    border: 1.5px solid rgba(255, 255, 255, 0.35);
    backdrop-filter: blur(4px);
  }
  .btn-hero-glass:hover {
    background: rgba(255, 255, 255, 0.28);
    color: #ffffff !important;
    transform: translateY(-2px);
  }

  /* 5-Column Responsive KPI Grid */
  @media (min-width: 1200px) {
    .col-dash-5 {
      flex: 0 0 20% !important;
      max-width: 20% !important;
    }
  }

  /* Metric KPI Cards */
  .dash-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem 1.3rem;
    height: 100%;
    min-height: 165px;
    box-shadow: 0 3px 14px rgba(15, 23, 42, 0.04);
    transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }

  .dash-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(5, 150, 105, 0.12);
    border-color: #a7f3d0;
  }

  .kpi-urgent-border {
    border-left: 4px solid #f59e0b !important;
  }

  .dash-kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.65rem;
  }

  .dash-kpi-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 0;
  }

  .dash-kpi-icon-box {
    width: 44px;
    height: 44px;
    min-width: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
  }

  .icon-box-emerald { background: #ecfdf5; color: #059669; }
  .icon-box-teal    { background: #f0fdfa; color: #0d9488; }
  .icon-box-amber   { background: #fef3c7; color: #d97706; }
  .icon-box-blue    { background: #e0f2fe; color: #0284c7; }
  .icon-box-violet  { background: #f5f3ff; color: #7c3aed; }

  .dash-kpi-value {
    font-size: 1.85rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
    margin: 0.35rem 0 0.45rem 0;
  }

  .dash-kpi-meta {
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 20px;
    gap: 6px;
  }

  .dash-progress-track {
    height: 6px;
    background: #f1f5f9;
    border-radius: 10px;
    overflow: hidden;
    margin-top: 0.55rem;
  }

  .dash-progress-fill {
    height: 100%;
    border-radius: 10px;
  }

  /* Section Card */
  .dash-section-card {
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

  .dash-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
    padding-bottom: 0.85rem;
    border-bottom: 1px solid #f1f5f9;
  }

  .dash-section-title {
    font-size: 1.02rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 0;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .dash-card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
  }

  /* Table Design */
  .dash-table {
    margin: 0 -1.6rem -1.45rem -1.6rem;
    width: calc(100% + 3.2rem);
    margin-bottom: 0;
  }

  .dash-table thead th {
    background: #f8fafc;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    padding: 0.8rem 0.95rem;
    border-bottom: 1.5px solid #e2e8f0;
    border-top: none;
    white-space: nowrap;
  }

  .dash-table thead th:first-child,
  .dash-table tbody td:first-child {
    padding-left: 1.6rem !important;
  }

  .dash-table thead th:last-child,
  .dash-table tbody td:last-child {
    padding-right: 1.6rem !important;
  }

  .dash-table tbody td {
    padding: 0.85rem 0.95rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.85rem;
  }

  .dash-table tbody tr:last-child td {
    border-bottom: none;
  }

  .dash-table tbody tr:hover {
    background: #fbfdfe;
  }

  /* Visitors List */
  .dash-visitor-item {
    padding: 0.85rem 0;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .dash-visitor-item:first-child {
    padding-top: 0;
  }

  .dash-visitor-item:last-child {
    border-bottom: none !important;
    padding-bottom: 0;
  }

  .dash-visitor-avatar {
    width: 38px;
    height: 38px;
    min-width: 38px;
    border-radius: 50%;
    background: #ecfdf5;
    color: #059669;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 0.9rem;
    border: 1.5px solid #d1fae5;
    flex-shrink: 0;
  }

  /* Auxiliary Cards */
  .block-occ-item {
    padding-bottom: 0.85rem;
    margin-bottom: 0.85rem;
    border-bottom: 1px solid #f1f5f9;
  }

  .block-occ-item:last-child {
    border-bottom: none !important;
    margin-bottom: 0 !important;
    padding-bottom: 0 !important;
  }

  .meal-slot-box {
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    padding: 0.85rem 0.95rem;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s ease;
  }

  .meal-slot-box:hover {
    background: #ffffff;
    border-color: #059669;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.08);
  }

  .notice-dash-item {
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
  }

  .notice-dash-item:first-child {
    padding-top: 0;
  }

  .notice-dash-item:last-child {
    border-bottom: none !important;
    padding-bottom: 0 !important;
  }

  /* Quick Shortcut Button Cards */
  .quick-nav-pill {
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    padding: 0.85rem 1rem;
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none !important;
    transition: all 0.22s ease;
    color: #0f172a;
    height: 100%;
    min-height: 72px;
  }

  .quick-nav-pill:hover {
    transform: translateY(-2px);
    border-color: #059669;
    box-shadow: 0 8px 20px rgba(5, 150, 105, 0.12);
    color: #059669;
  }

  .quick-nav-icon {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
  }

  .quick-nav-text {
    min-width: 0;
    flex: 1;
  }

  .quick-nav-text strong {
    font-size: 0.85rem;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .quick-nav-text small {
    font-size: 0.74rem;
    color: #64748b;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* Priority Badges */
  .badge-prio-pill {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0.28rem 0.6rem;
    border-radius: 50px;
    line-height: 1.2;
    display: inline-block;
  }

  .badge-prio-urgent { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
  .badge-prio-high   { background: #fff7ed; color: #c2410c; border: 1px solid #ffedd5; }
  .badge-prio-medium { background: #fefce8; color: #a16207; border: 1px solid #fef08a; }
  .badge-prio-low    { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

  /* Pulse indicator */
  .live-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
    animation: livePulse 2s infinite;
  }

  @keyframes livePulse {
    0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
    70% { box-shadow: 0 0 0 7px rgba(16, 185, 129, 0); }
    100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
  }

  @media (max-width: 767px) {
    .warden-hero-card {
      padding: 1.5rem 1.35rem;
    }
    .hero-quick-actions {
      justify-content: flex-start;
      margin-top: 1rem;
    }
  }
</style>

<div class="warden-dashboard-wrapper">

  <!-- =========================================================
       HERO WELCOME BANNER (ALIGNED IN ROW)
       ========================================================= -->
  <div class="row mb-4">
    <div class="col-12">
      <div class="warden-hero-card">
        <div class="row align-items-center">
          <div class="col-lg-8 col-md-7 mb-3 mb-md-0">
            <div class="d-flex align-items-center flex-wrap mb-2">
              <span class="hero-tag-badge">
                <span class="live-dot mr-1"></span> WARDEN DESK ACTIVE
              </span>
              <span class="hero-tag-badge">
                <i class="fa-solid fa-building mr-1"></i> Assigned: <?= htmlspecialchars($assigned_blocks_label) ?>
              </span>
              <span class="hero-tag-badge">
                <i class="fa-regular fa-calendar mr-1"></i> <?= date('l, d F Y') ?>
              </span>
            </div>
            <h2 class="font-weight-800 text-white mb-1" style="font-size: 1.85rem; letter-spacing: -0.5px;">
              Welcome back, <?= htmlspecialchars($warden_name) ?>!
            </h2>
            <p class="text-white-50 mb-0 font-weight-500" style="font-size: 0.92rem; max-width: 650px; line-height: 1.5;">
              Hostel Operations Center: Real-time oversight for daily roll call, guest passes, bed occupancy, and facility maintenance.
            </p>
          </div>
          <div class="col-lg-4 col-md-5 text-md-right">
            <div class="hero-quick-actions">
              <a href="attendance.php" class="btn btn-hero-action btn-hero-primary">
                <i class="fa-solid fa-clipboard-user"></i> Roll Call
              </a>
              <a href="visitors.php" class="btn btn-hero-action btn-hero-glass">
                <i class="fa-solid fa-address-book"></i> Gate Pass
              </a>
              <a href="reports.php" class="btn btn-hero-action btn-hero-glass">
                <i class="fa-solid fa-chart-column"></i> Reports
              </a>
              <a href="complaints.php?preset=pending" class="btn btn-hero-action btn-hero-glass">
                <i class="fa-solid fa-triangle-exclamation"></i> Complaints
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       PRIMARY KPI METRIC CARDS (EQUAL 5-COLUMN ROW)
       ========================================================= -->
  <div class="row mb-4">
    <!-- Card 1: Total Students -->
    <div class="col-dash-5 col-lg-4 col-md-6 col-sm-6 mb-3 mb-xl-0">
      <div class="dash-kpi-card">
        <div>
          <div class="dash-kpi-header">
            <h6 class="dash-kpi-title">Residents</h6>
            <div class="dash-kpi-icon-box icon-box-emerald">
              <i class="fa-solid fa-users"></i>
            </div>
          </div>
          <div class="dash-kpi-value"><?= $total_students ?></div>
          <div class="dash-kpi-meta">
            <span><i class="fa-solid fa-bed text-success mr-1"></i><?= $allocated_students ?> Allocated</span>
            <?php if ($unallocated_students > 0): ?>
              <span class="text-warning font-weight-700"><?= $unallocated_students ?> Unset</span>
            <?php else: ?>
              <span class="text-muted">100% Set</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="dash-progress-track">
          <div class="dash-progress-fill" style="width: <?= ($total_students > 0) ? round(($allocated_students / $total_students) * 100) : 0 ?>%; background: #059669;"></div>
        </div>
      </div>
    </div>

    <!-- Card 2: Today's Attendance -->
    <div class="col-dash-5 col-lg-4 col-md-6 col-sm-6 mb-3 mb-xl-0">
      <div class="dash-kpi-card">
        <div>
          <div class="dash-kpi-header">
            <h6 class="dash-kpi-title">Today's Roll Call</h6>
            <div class="dash-kpi-icon-box icon-box-teal">
              <i class="fa-solid fa-clipboard-check"></i>
            </div>
          </div>
          <div class="dash-kpi-value"><?= $att_pct ?>%</div>
          <div class="dash-kpi-meta">
            <span class="text-success"><i class="fa-solid fa-user-check mr-1"></i><?= $att_present ?> Pres</span>
            <span class="text-danger"><i class="fa-solid fa-user-xmark mr-1"></i><?= $att_absent ?> Abs</span>
          </div>
        </div>
        <div class="dash-progress-track">
          <div class="dash-progress-fill" style="width: <?= $att_pct ?>%; background: linear-gradient(90deg, #059669, #0d9488);"></div>
        </div>
      </div>
    </div>

    <!-- Card 3: Pending Complaints -->
    <div class="col-dash-5 col-lg-4 col-md-6 col-sm-6 mb-3 mb-xl-0">
      <div class="dash-kpi-card <?= ($comp_urgent > 0) ? 'kpi-urgent-border' : '' ?>">
        <div>
          <div class="dash-kpi-header">
            <h6 class="dash-kpi-title">Open Tickets</h6>
            <div class="dash-kpi-icon-box icon-box-amber">
              <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
          </div>
          <div class="dash-kpi-value text-warning" style="color: #d97706 !important;">
            <?= $comp_pending + $comp_in_progress ?>
          </div>
          <div class="dash-kpi-meta">
            <span><i class="fa-solid fa-clock text-warning mr-1"></i><?= $comp_pending ?> Pending</span>
            <?php if ($comp_urgent > 0): ?>
              <span class="badge badge-danger text-white px-2 py-0 font-weight-700" style="font-size: 0.68rem; background: #dc2626;">
                <i class="fa-solid fa-fire mr-1"></i><?= $comp_urgent ?> Urgent
              </span>
            <?php else: ?>
              <span class="text-info font-weight-600"><?= $comp_in_progress ?> Active</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="dash-progress-track">
          <div class="dash-progress-fill" style="width: <?= min(100, ($comp_pending + $comp_in_progress) * 20) ?>%; background: linear-gradient(90deg, #f59e0b, #ef4444);"></div>
        </div>
      </div>
    </div>

    <!-- Card 4: Visitors Today -->
    <div class="col-dash-5 col-lg-6 col-md-6 col-sm-6 mb-3 mb-xl-0">
      <div class="dash-kpi-card">
        <div>
          <div class="dash-kpi-header">
            <h6 class="dash-kpi-title">Visitors Today</h6>
            <div class="dash-kpi-icon-box icon-box-blue">
              <i class="fa-solid fa-address-book"></i>
            </div>
          </div>
          <div class="dash-kpi-value text-info" style="color: #0284c7 !important;">
            <?= $vis_total_today ?>
          </div>
          <div class="dash-kpi-meta">
            <span><i class="fa-solid fa-door-open text-primary mr-1"></i><?= $in_hostel_now ?> In Hostel</span>
            <span class="text-warning font-weight-600"><?= $vis_pending_today ?> Pend</span>
          </div>
        </div>
        <div class="dash-progress-track">
          <div class="dash-progress-fill" style="width: <?= min(100, $vis_total_today * 25) ?>%; background: #0284c7;"></div>
        </div>
      </div>
    </div>

    <!-- Card 5: Bed Occupancy -->
    <div class="col-dash-5 col-lg-6 col-md-12 col-sm-12 mb-3 mb-xl-0">
      <div class="dash-kpi-card">
        <div>
          <div class="dash-kpi-header">
            <h6 class="dash-kpi-title">Bed Occupancy</h6>
            <div class="dash-kpi-icon-box icon-box-violet">
              <i class="fa-solid fa-bed"></i>
            </div>
          </div>
          <div class="dash-kpi-value text-purple" style="color: #7c3aed !important;">
            <?= $occupancy_pct ?>%
          </div>
          <div class="dash-kpi-meta">
            <span><strong class="text-dark"><?= $occupied_beds ?></strong> / <?= $total_beds ?> Filled</span>
            <span class="text-success font-weight-700"><?= $available_beds ?> Vacant</span>
          </div>
        </div>
        <div class="dash-progress-track">
          <div class="dash-progress-fill" style="width: <?= $occupancy_pct ?>%; background: linear-gradient(90deg, #7c3aed, #a855f7);"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       ANALYTICS GRAPHS & CHARTS ROW
       ========================================================= -->
  <div class="row mb-4">
    
    <!-- Chart 1: 7-Day Attendance Trend -->
    <div class="col-lg-8 mb-4 mb-lg-0">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-chart-line text-success"></i> 7-Day Attendance Trends
            </h5>
            <small class="text-muted font-weight-600">Daily count of present, absent, and late-marked residents</small>
          </div>
          <div class="d-flex align-items-center">
            <span class="badge badge-light border text-muted px-2 py-1 font-weight-700 mr-2" style="font-size: 0.74rem;">
              Daily Roll Call
            </span>
            <a href="attendance.php" class="btn btn-sm btn-outline-success font-weight-700 rounded-8 py-1 px-2" style="font-size: 0.76rem;">
              View Logs <i class="fa-solid fa-arrow-right ml-1"></i>
            </a>
          </div>
        </div>

        <div class="dash-card-body">
          <div style="height: 290px; position: relative; width: 100%;">
            <canvas id="attendanceTrendChart"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- Chart 2: Complaints Resolution Breakdown -->
    <div class="col-lg-4">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-chart-pie text-warning"></i> Complaints Ratio
            </h5>
            <small class="text-muted font-weight-600">Status resolution ratio</small>
          </div>
          <a href="complaints.php" class="btn btn-sm btn-outline-secondary font-weight-700 rounded-8 py-1 px-2" style="font-size: 0.76rem;">
            Helpdesk <i class="fa-solid fa-arrow-right ml-1"></i>
          </a>
        </div>

        <div class="dash-card-body">
          <div style="height: 220px; position: relative; width: 100%;">
            <canvas id="complaintsDoughnutChart"></canvas>
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
  </div>

  <!-- =========================================================
       OPERATIONAL TABLES & ACTION LISTS
       ========================================================= -->
  <div class="row mb-4">
    
    <!-- Left Column: Pending Complaints Requiring Action -->
    <div class="col-lg-7 mb-4 mb-lg-0">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-screwdriver-wrench text-danger"></i> Urgent & Pending Maintenance
            </h5>
            <small class="text-muted font-weight-600">Complaints awaiting warden review & contractor dispatch</small>
          </div>
          <a href="complaints.php?preset=pending" class="btn btn-sm btn-success font-weight-700 rounded-8 py-1 px-3" style="background: linear-gradient(135deg, #059669 0%, #0d9488 100%); border: none; font-size: 0.78rem;">
            View All (<?= $comp_pending + $comp_in_progress ?>)
          </a>
        </div>

        <div class="dash-card-body">
          <?php if (empty($recent_complaints)): ?>
            <div class="text-center py-5 text-muted my-auto">
              <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px; background: #ecfdf5; color: #059669;">
                <i class="fa-solid fa-check-double font-weight-bold"></i>
              </div>
              <p class="font-weight-600 mb-0" style="font-size: 0.9rem;">All complaints resolved! No pending maintenance requests.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table dash-table table-hover align-middle">
                <thead>
                  <tr>
                    <th>Student & Room</th>
                    <th>Issue Title</th>
                    <th class="text-center">Priority</th>
                    <th class="text-center">Status</th>
                    <th class="text-right">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recent_complaints as $rc): ?>
                    <tr>
                      <td>
                        <strong class="text-dark d-block" style="font-size: 0.88rem;"><?= htmlspecialchars($rc['student_name']) ?></strong>
                        <small class="text-muted">
                          <?= !empty($rc['room_number']) ? (($rc['block_name'] ?? 'Block') . " Rm " . $rc['room_number']) : 'Unassigned' ?>
                        </small>
                      </td>
                      <td style="max-width: 220px;">
                        <span class="text-dark font-weight-600 d-block text-truncate" style="font-size: 0.86rem;"><?= htmlspecialchars($rc['title']) ?></span>
                        <small class="text-muted"><i class="fa-regular fa-clock mr-1"></i><?= date('d M, h:i A', strtotime($rc['created_at'])) ?></small>
                      </td>
                      <td class="text-center">
                        <?php 
                          $prio_class = 'badge-prio-' . strtolower($rc['priority']);
                        ?>
                        <span class="badge-prio-pill <?= $prio_class ?>">
                          <?= htmlspecialchars(ucfirst($rc['priority'])) ?>
                        </span>
                      </td>
                      <td class="text-center">
                        <?php if ($rc['status'] === 'in_progress'): ?>
                          <span class="badge badge-info text-white font-weight-700 px-2 py-1" style="font-size: 0.72rem; border-radius: 50px;">In Progress</span>
                        <?php else: ?>
                          <span class="badge badge-warning text-dark font-weight-700 px-2 py-1" style="font-size: 0.72rem; border-radius: 50px;">Pending</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-right">
                        <a href="complaints.php" class="btn btn-sm btn-outline-success font-weight-700 py-1 px-2 rounded-8" style="font-size: 0.75rem;">
                          Review
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

    <!-- Right Column: Today's Visitors Activity -->
    <div class="col-lg-5">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-address-book text-primary"></i> Today's Visitors
            </h5>
            <small class="text-muted font-weight-600">Gate entries & visiting appointments</small>
          </div>
          <a href="visitors.php?preset=today" class="btn btn-sm btn-outline-primary font-weight-700 rounded-8 py-1 px-3" style="font-size: 0.78rem;">
            Roster (<?= $vis_total_today ?>)
          </a>
        </div>

        <div class="dash-card-body">
          <?php if (empty($today_visitors)): ?>
            <div class="text-center py-5 text-muted my-auto">
              <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px; background: #e0f2fe; color: #0284c7;">
                <i class="fa-solid fa-user-clock font-weight-bold"></i>
              </div>
              <p class="font-weight-600 mb-0" style="font-size: 0.9rem;">No visitor arrivals recorded yet for today.</p>
            </div>
          <?php else: ?>
            <div>
              <?php foreach ($today_visitors as $tv): ?>
                <div class="dash-visitor-item">
                  <div class="d-flex align-items-center">
                    <div class="dash-visitor-avatar">
                      <?= strtoupper(substr($tv['name'], 0, 1)) ?>
                    </div>
                    <div>
                      <strong class="text-dark d-block" style="font-size: 0.88rem;"><?= htmlspecialchars($tv['name']) ?></strong>
                      <small class="text-muted d-block" style="font-size: 0.75rem;">
                        Visiting: <strong><?= htmlspecialchars($tv['student_name']) ?></strong> (<?= htmlspecialchars($tv['relationship']) ?>)
                      </small>
                    </div>
                  </div>

                  <div class="text-right">
                    <?php if ($tv['status'] === 'approved'): ?>
                      <span class="badge badge-success px-2 py-1 font-weight-700" style="font-size: 0.7rem; border-radius: 50px;">Approved</span>
                    <?php elseif ($tv['status'] === 'pending'): ?>
                      <span class="badge badge-warning text-dark px-2 py-1 font-weight-700" style="font-size: 0.7rem; border-radius: 50px;">Pending</span>
                    <?php else: ?>
                      <span class="badge badge-light border text-muted px-2 py-1 font-weight-700" style="font-size: 0.7rem; border-radius: 50px;"><?= ucfirst($tv['status']) ?></span>
                    <?php endif; ?>
                    <small class="text-muted d-block mt-1 font-weight-600" style="font-size: 0.72rem;">
                      <?= !empty($tv['expected_in_time']) ? date('h:i A', strtotime($tv['expected_in_time'])) : '-' ?>
                    </small>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       BOTTOM ROW: BLOCK OCCUPANCY, FOOD MENU, & NOTICES
       ========================================================= -->
  <div class="row mb-4">
    
    <!-- Block Capacity & Occupancy -->
    <div class="col-lg-4 mb-4 mb-lg-0">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-hotel text-success"></i> Block Capacity
            </h5>
            <small class="text-muted font-weight-600">Bed allocation per block</small>
          </div>
          <span class="badge badge-light border text-muted px-2 py-1 font-weight-600" style="font-size: 0.74rem;">
            <?= count($blocks_occupancy) ?> Blocks
          </span>
        </div>

        <div class="dash-card-body">
          <?php if (empty($blocks_occupancy)): ?>
            <p class="text-muted font-weight-600 text-center py-4 my-auto">No block records found.</p>
          <?php else: ?>
            <div>
              <?php foreach ($blocks_occupancy as $bo): 
                  $b_total = intval($bo['total_beds']);
                  $b_occ   = intval($bo['occupied_beds']);
                  $b_pct   = ($b_total > 0) ? round(($b_occ / $b_total) * 100, 1) : 0;
              ?>
                <div class="block-occ-item">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-dark font-weight-700" style="font-size: 0.88rem;"><?= htmlspecialchars($bo['block_name']) ?></strong>
                    <span class="text-muted font-weight-600" style="font-size: 0.8rem;">
                      <strong class="text-dark"><?= $b_occ ?></strong> / <?= $b_total ?> Beds (<?= $b_pct ?>%)
                    </span>
                  </div>
                  <div class="dash-progress-track mt-1" style="height: 6px;">
                    <div class="dash-progress-fill" style="width: <?= $b_pct ?>%; background: linear-gradient(90deg, #059669, #10b981);"></div>
                  </div>
                  <div class="d-flex justify-content-between text-muted mt-1" style="font-size: 0.74rem;">
                    <span>Rooms: <?= $bo['total_rooms'] ?></span>
                    <span class="text-success font-weight-600"><?= intval($bo['available_beds']) ?> Vacant Beds</span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Today's Food Menu Preview -->
    <div class="col-lg-4 mb-4 mb-lg-0">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-utensils text-warning"></i> Today's Food Menu
            </h5>
            <small class="text-muted font-weight-600"><?= $today_menu_day ?> Hostel Mess Schedule</small>
          </div>
          <a href="food_menu.php" class="btn btn-sm btn-outline-warning text-dark font-weight-700 rounded-8 py-1 px-2" style="font-size: 0.75rem;">
            Full Week <i class="fa-solid fa-arrow-right ml-1"></i>
          </a>
        </div>

        <div class="dash-card-body">
          <?php if ($today_menu): ?>
            <div class="row mx-n1 h-100">
              <!-- Breakfast -->
              <div class="col-6 px-1 mb-2">
                <div class="meal-slot-box">
                  <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">Breakfast</small>
                  <strong class="text-dark d-block text-truncate my-1" style="font-size: 0.84rem;"><?= htmlspecialchars($today_menu['break_fast'] ?? 'Standard Meal') ?></strong>
                  <small class="text-success font-weight-600" style="font-size: 0.7rem;"><?= htmlspecialchars($today_menu['breakfast_time'] ?? '7:30 - 9:00 AM') ?></small>
                </div>
              </div>

              <!-- Lunch -->
              <div class="col-6 px-1 mb-2">
                <div class="meal-slot-box">
                  <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">Lunch</small>
                  <strong class="text-dark d-block text-truncate my-1" style="font-size: 0.84rem;"><?= htmlspecialchars($today_menu['lunch'] ?? 'Rice & Curries') ?></strong>
                  <small class="text-success font-weight-600" style="font-size: 0.7rem;"><?= htmlspecialchars($today_menu['lunch_time'] ?? '12:30 - 2:00 PM') ?></small>
                </div>
              </div>

              <!-- Evening Tea -->
              <div class="col-6 px-1 mb-2">
                <div class="meal-slot-box">
                  <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">Evening Tea</small>
                  <strong class="text-dark d-block text-truncate my-1" style="font-size: 0.84rem;"><?= htmlspecialchars($today_menu['evening_tea'] ?? 'Tea & Snacks') ?></strong>
                  <small class="text-success font-weight-600" style="font-size: 0.7rem;"><?= htmlspecialchars($today_menu['evening_tea_time'] ?? '4:30 - 5:30 PM') ?></small>
                </div>
              </div>

              <!-- Dinner -->
              <div class="col-6 px-1 mb-2">
                <div class="meal-slot-box">
                  <small class="text-muted font-weight-700 text-uppercase d-block" style="font-size: 0.68rem;">Dinner</small>
                  <strong class="text-dark d-block text-truncate my-1" style="font-size: 0.84rem;"><?= htmlspecialchars($today_menu['dinner'] ?? 'Chapati & Curry') ?></strong>
                  <small class="text-success font-weight-600" style="font-size: 0.7rem;"><?= htmlspecialchars($today_menu['dinner_time'] ?? '7:30 - 9:00 PM') ?></small>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="text-center py-5 text-muted my-auto">
              <i class="fa-solid fa-bowl-food fa-2x mb-2 text-muted"></i>
              <p class="font-weight-600 mb-0" style="font-size: 0.88rem;">No mess schedule posted for <?= $today_menu_day ?>.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Active Official Notices -->
    <div class="col-lg-4">
      <div class="dash-section-card">
        <div class="dash-section-header">
          <div>
            <h5 class="dash-section-title">
              <i class="fa-solid fa-bullhorn text-danger"></i> Active Notices
            </h5>
            <small class="text-muted font-weight-600">Official bulletins & circulars</small>
          </div>
          <a href="notices.php" class="btn btn-sm btn-outline-danger font-weight-700 rounded-8 py-1 px-2" style="font-size: 0.75rem;">
            Notices <i class="fa-solid fa-arrow-right ml-1"></i>
          </a>
        </div>

        <div class="dash-card-body">
          <?php if (empty($active_notices)): ?>
            <div class="text-center py-5 text-muted my-auto">
              <i class="fa-solid fa-bell-slash fa-2x mb-2 text-muted"></i>
              <p class="font-weight-600 mb-0" style="font-size: 0.88rem;">No active broadcast notices at this time.</p>
            </div>
          <?php else: ?>
            <div>
              <?php foreach ($active_notices as $not): ?>
                <div class="notice-dash-item">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-dark text-truncate mr-2" style="font-size: 0.86rem;"><?= htmlspecialchars($not['title']) ?></strong>
                    <?php if ($not['priority'] === 'urgent'): ?>
                      <span class="badge badge-danger text-white px-2 py-0 font-weight-700" style="font-size: 0.65rem;">URGENT</span>
                    <?php elseif ($not['priority'] === 'important'): ?>
                      <span class="badge badge-warning text-dark px-2 py-0 font-weight-700" style="font-size: 0.65rem;">IMPORTANT</span>
                    <?php else: ?>
                      <span class="badge badge-light border text-muted px-2 py-0 font-weight-600" style="font-size: 0.65rem;">NORMAL</span>
                    <?php endif; ?>
                  </div>
                  <p class="text-muted mb-1 text-truncate" style="font-size: 0.77rem;"><?= htmlspecialchars($not['description']) ?></p>
                  <small class="text-muted font-weight-600" style="font-size: 0.72rem;">
                    <i class="fa-regular fa-clock mr-1"></i><?= date('d M, Y', strtotime($not['created_at'])) ?>
                  </small>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================================================
       QUICK ACCESS OPERATIONS SHORTCUTS (ALIGNED IN ROW)
       ========================================================= -->
  <div class="row">
    <div class="col-12 mb-3">
      <h5 class="font-weight-800 text-dark mb-0" style="font-size: 1.02rem;">
        <i class="fa-solid fa-compass text-success mr-2"></i> Quick Workflow Shortcuts
      </h5>
    </div>

    <!-- Students -->
    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
      <a href="student.php" class="quick-nav-pill">
        <div class="quick-nav-icon icon-box-emerald">
          <i class="fa-solid fa-user-graduate"></i>
        </div>
        <div class="quick-nav-text">
          <strong>Students</strong>
          <small>Roster & Rooms</small>
        </div>
      </a>
    </div>

    <!-- Attendance -->
    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
      <a href="attendance.php" class="quick-nav-pill">
        <div class="quick-nav-icon icon-box-teal">
          <i class="fa-solid fa-clipboard-user"></i>
        </div>
        <div class="quick-nav-text">
          <strong>Attendance</strong>
          <small>Daily Roll Call</small>
        </div>
      </a>
    </div>

    <!-- Complaints -->
    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
      <a href="complaints.php" class="quick-nav-pill">
        <div class="quick-nav-icon icon-box-amber">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="quick-nav-text">
          <strong>Complaints</strong>
          <small>Helpdesk Tickets</small>
        </div>
      </a>
    </div>

    <!-- Visitors -->
    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
      <a href="visitors.php" class="quick-nav-pill">
        <div class="quick-nav-icon icon-box-blue">
          <i class="fa-solid fa-address-book"></i>
        </div>
        <div class="quick-nav-text">
          <strong>Visitors Book</strong>
          <small>Gate Passes</small>
        </div>
      </a>
    </div>

    <!-- Food Menu -->
    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
      <a href="food_menu.php" class="quick-nav-pill">
        <div class="quick-nav-icon icon-box-violet">
          <i class="fa-solid fa-utensils"></i>
        </div>
        <div class="quick-nav-text">
          <strong>Food & Mess</strong>
          <small>Weekly Menu</small>
        </div>
      </a>
    </div>

    <!-- Notices -->
    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
      <a href="notices.php" class="quick-nav-pill">
        <div class="quick-nav-icon icon-box-emerald">
          <i class="fa-solid fa-bullhorn"></i>
        </div>
        <div class="quick-nav-text">
          <strong>Notices</strong>
          <small>Announcements</small>
        </div>
      </a>
    </div>
  </div>

</div>

<!-- =========================================================
     CHART.JS INITIALIZATION SCRIPTS
     ========================================================= -->
<script>
document.addEventListener("DOMContentLoaded", function() {
  
  // 1. Attendance 7-Day Trend Chart
  const attCanvas = document.getElementById('attendanceTrendChart');
  if (attCanvas) {
    const ctx = attCanvas.getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($trend_labels) ?>,
        datasets: [
          {
            label: 'Present',
            data: <?= json_encode($trend_present) ?>,
            backgroundColor: '#059669',
            borderRadius: 6,
            barPercentage: 0.65,
            categoryPercentage: 0.7
          },
          {
            label: 'Absent',
            data: <?= json_encode($trend_absent) ?>,
            backgroundColor: '#ef4444',
            borderRadius: 6,
            barPercentage: 0.65,
            categoryPercentage: 0.7
          },
          {
            label: 'Late',
            data: <?= json_encode($trend_late) ?>,
            backgroundColor: '#f59e0b',
            borderRadius: 6,
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
              font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' },
              color: '#334155',
              padding: 14
            }
          },
          tooltip: {
            backgroundColor: '#0f172a',
            titleFont: { family: 'Plus Jakarta Sans', weight: '700' },
            bodyFont: { family: 'Plus Jakarta Sans' },
            padding: 10,
            cornerRadius: 8
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              stepSize: 1,
              font: { family: 'Plus Jakarta Sans', size: 11 },
              color: '#64748b'
            },
            grid: {
              color: '#f1f5f9'
            }
          },
          x: {
            ticks: {
              font: { family: 'Plus Jakarta Sans', size: 11 },
              color: '#64748b'
            },
            grid: {
              display: false
            }
          }
        }
      }
    });
  }

  // 2. Complaints Breakdown Doughnut Chart
  const compCanvas = document.getElementById('complaintsDoughnutChart');
  if (compCanvas) {
    const ctx = compCanvas.getContext('2d');
    new Chart(ctx, {
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
        cutout: '72%',
        cutoutPercentage: 72,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              boxWidth: 10,
              font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
              color: '#334155',
              padding: 10
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

});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
