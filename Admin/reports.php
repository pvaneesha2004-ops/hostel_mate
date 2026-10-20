<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../db.php';

$admin_id   = intval($_SESSION['admin_id']);
$admin_name = $_SESSION['admin_name'] ?? 'System Administrator';

// -------------------------------------------------------------------
// 1. DATE-WISE FILTERING & PRESETS
// -------------------------------------------------------------------
$preset     = trim($_GET['preset'] ?? '');
$start_date = trim($_GET['start_date'] ?? $_GET['from_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? $_GET['to_date'] ?? '');
$block_id   = intval($_GET['block_id'] ?? 0);

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

// Ensure valid dates sequence
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp = $start_date;
    $start_date = $end_date;
    $end_date = $tmp;
}

$safe_start = $conn->real_escape_string($start_date);
$safe_end   = $conn->real_escape_string($end_date);

$days_diff = max(1, round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
$period_label = date('d M Y', strtotime($start_date)) . " — " . date('d M Y', strtotime($end_date));

// Fetch all available blocks for filter
$all_blocks_res = $conn->query("SELECT id, name FROM blocks ORDER BY name ASC");
$all_blocks = [];
if ($all_blocks_res && $all_blocks_res->num_rows > 0) {
    while ($b = $all_blocks_res->fetch_assoc()) {
        $all_blocks[] = $b;
    }
}

// Block clause builder for joined tables
$block_clause_ra = ($block_id > 0) ? " AND ra.block_id = {$block_id} " : "";

// -------------------------------------------------------------------
// 2. FINANCIAL & PAYMENT HISTORY ANALYTICS (EXPLICIT USER REQUIREMENT)
// -------------------------------------------------------------------
$fee_kpi_q = $conn->query("
    SELECT 
        COUNT(sfh.id) AS total_payments,
        COALESCE(SUM(sfh.price), 0) AS total_revenue,
        COALESCE(AVG(sfh.price), 0) AS avg_payment,
        COUNT(DISTINCT sfh.student_id) AS paying_residents
    FROM student_fee_history sfh
    JOIN users u ON sfh.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
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
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
    GROUP BY sfh.date
    ORDER BY sfh.date ASC
");
$fee_chart_labels = [];
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
    WHERE sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
    ORDER BY sfh.date DESC, sfh.id DESC
");
$fee_audit_list = [];
if ($fee_audit_q && $fee_audit_q->num_rows > 0) {
    while ($row = $fee_audit_q->fetch_assoc()) {
        $fee_audit_list[] = $row;
    }
}

// -------------------------------------------------------------------
// 3. STUDENT ATTENDANCE ANALYTICS
// -------------------------------------------------------------------
$att_kpi_q = $conn->query("
    SELECT 
        COUNT(a.id) AS total_records,
        SUM(CASE WHEN LOWER(a.status) = 'present' THEN 1 ELSE 0 END) AS present_cnt,
        SUM(CASE WHEN LOWER(a.status) = 'absent' THEN 1 ELSE 0 END) AS absent_cnt,
        SUM(CASE WHEN LOWER(a.status) = 'late' THEN 1 ELSE 0 END) AS late_cnt,
        SUM(CASE WHEN LOWER(a.status) IN ('leave', 'outing') THEN 1 ELSE 0 END) AS leave_cnt,
        COUNT(DISTINCT a.student_id) AS unique_students,
        COUNT(DISTINCT a.attendance_date) AS active_days
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE a.attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
");
$att_kpi = ($att_kpi_q && $att_kpi_q->num_rows > 0) ? $att_kpi_q->fetch_assoc() : [];

$att_total       = intval($att_kpi['total_records'] ?? 0);
$att_present     = intval($att_kpi['present_cnt'] ?? 0);
$att_absent      = intval($att_kpi['absent_cnt'] ?? 0);
$att_late        = intval($att_kpi['late_cnt'] ?? 0);
$att_leave       = intval($att_kpi['leave_cnt'] ?? 0);
$att_unique_stu  = intval($att_kpi['unique_students'] ?? 0);
$att_present_pct = ($att_total > 0) ? round(($att_present / $att_total) * 100, 1) : 0;
$att_absent_pct  = ($att_total > 0) ? round(($att_absent / $att_total) * 100, 1) : 0;

// Daily attendance timeline for chart
$att_daily_q = $conn->query("
    SELECT 
        a.attendance_date,
        COUNT(a.id) AS total_marked,
        SUM(CASE WHEN LOWER(a.status) = 'present' THEN 1 ELSE 0 END) AS present_cnt,
        SUM(CASE WHEN LOWER(a.status) = 'absent' THEN 1 ELSE 0 END) AS absent_cnt,
        SUM(CASE WHEN LOWER(a.status) = 'late' THEN 1 ELSE 0 END) AS late_cnt
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE a.attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
    GROUP BY a.attendance_date
    ORDER BY a.attendance_date ASC
");
$att_chart_labels  = [];
$att_chart_present = [];
$att_chart_absent  = [];
$att_chart_late    = [];

if ($att_daily_q && $att_daily_q->num_rows > 0) {
    while ($ad = $att_daily_q->fetch_assoc()) {
        $att_chart_labels[]  = date('d M', strtotime($ad['attendance_date']));
        $att_chart_present[] = intval($ad['present_cnt']);
        $att_chart_absent[]  = intval($ad['absent_cnt']);
        $att_chart_late[]    = intval($ad['late_cnt']);
    }
}

// Attendance Audit List
$att_audit_q = $conn->query("
    SELECT 
        a.id AS attendance_id,
        a.attendance_date,
        a.status,
        a.marked_by,
        a.remarks,
        a.created_at,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        blk.name AS block_name,
        rm.room_number,
        bd.bed_number
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE a.attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
    ORDER BY a.attendance_date DESC, a.id DESC
");
$att_audit_list = [];
if ($att_audit_q && $att_audit_q->num_rows > 0) {
    while ($row = $att_audit_q->fetch_assoc()) {
        $att_audit_list[] = $row;
    }
}

// -------------------------------------------------------------------
// 4. COMPLAINTS & GRIEVANCES ANALYTICS
// -------------------------------------------------------------------
$comp_kpi_q = $conn->query("
    SELECT 
        COUNT(c.id) AS total_complaints,
        SUM(CASE WHEN LOWER(c.status) = 'resolved' THEN 1 ELSE 0 END) AS resolved_cnt,
        SUM(CASE WHEN LOWER(c.status) = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_cnt,
        SUM(CASE WHEN LOWER(c.status) = 'pending' THEN 1 ELSE 0 END) AS pending_cnt,
        SUM(CASE WHEN LOWER(c.status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt,
        SUM(CASE WHEN LOWER(c.priority) = 'urgent' THEN 1 ELSE 0 END) AS urgent_cnt,
        SUM(CASE WHEN LOWER(c.priority) = 'high' THEN 1 ELSE 0 END) AS high_cnt,
        SUM(CASE WHEN LOWER(c.priority) = 'medium' THEN 1 ELSE 0 END) AS medium_cnt,
        SUM(CASE WHEN LOWER(c.priority) = 'low' THEN 1 ELSE 0 END) AS low_cnt
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE DATE(c.created_at) BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
");
$comp_kpi = ($comp_kpi_q && $comp_kpi_q->num_rows > 0) ? $comp_kpi_q->fetch_assoc() : [];

$comp_total       = intval($comp_kpi['total_complaints'] ?? 0);
$comp_resolved    = intval($comp_kpi['resolved_cnt'] ?? 0);
$comp_in_progress = intval($comp_kpi['in_progress_cnt'] ?? 0);
$comp_pending     = intval($comp_kpi['pending_cnt'] ?? 0);
$comp_rejected    = intval($comp_kpi['rejected_cnt'] ?? 0);
$comp_urgent      = intval($comp_kpi['urgent_cnt'] ?? 0);
$comp_high        = intval($comp_kpi['high_cnt'] ?? 0);

$comp_resolve_pct = ($comp_total > 0) ? round(($comp_resolved / $comp_total) * 100, 1) : 0;

// Complaints Audit List
$comp_audit_q = $conn->query("
    SELECT 
        c.*,
        u.name AS student_name,
        u.phone AS student_phone,
        blk.name AS block_name,
        rm.room_number,
        bd.bed_number,
        w.name AS warden_handler_name
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN wardens w ON c.assigned_to = w.id
    WHERE DATE(c.created_at) BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
    ORDER BY c.created_at DESC, c.id DESC
");
$comp_audit_list = [];
if ($comp_audit_q && $comp_audit_q->num_rows > 0) {
    while ($row = $comp_audit_q->fetch_assoc()) {
        $comp_audit_list[] = $row;
    }
}

// -------------------------------------------------------------------
// 5. VISITORS & GATE ACTIVITY ANALYTICS
// -------------------------------------------------------------------
$vis_kpi_q = $conn->query("
    SELECT 
        COUNT(v.id) AS total_visitors,
        SUM(CASE WHEN LOWER(v.status) = 'approved' THEN 1 ELSE 0 END) AS approved_cnt,
        SUM(CASE WHEN LOWER(v.status) = 'completed' THEN 1 ELSE 0 END) AS completed_cnt,
        SUM(CASE WHEN LOWER(v.status) = 'pending' THEN 1 ELSE 0 END) AS pending_cnt,
        SUM(CASE WHEN LOWER(v.status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE v.visit_date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
");
$vis_kpi = ($vis_kpi_q && $vis_kpi_q->num_rows > 0) ? $vis_kpi_q->fetch_assoc() : [];

$vis_total       = intval($vis_kpi['total_visitors'] ?? 0);
$vis_approved    = intval($vis_kpi['approved_cnt'] ?? 0);
$vis_completed   = intval($vis_kpi['completed_cnt'] ?? 0);
$vis_pending     = intval($vis_kpi['pending_cnt'] ?? 0);
$vis_rejected    = intval($vis_kpi['rejected_cnt'] ?? 0);
$vis_approve_pct = ($vis_total > 0) ? round((($vis_approved + $vis_completed) / $vis_total) * 100, 1) : 0;

// Visitors Audit List
$vis_audit_q = $conn->query("
    SELECT 
        v.*,
        u.name AS student_name,
        blk.name AS block_name,
        rm.room_number,
        bd.bed_number,
        w.name AS warden_approver_name,
        vl.check_in_at,
        vl.check_out_at
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN wardens w ON v.approved_by = w.id
    LEFT JOIN visitor_logs vl ON v.id = vl.visitor_id
    WHERE v.visit_date BETWEEN '{$safe_start}' AND '{$safe_end}' {$block_clause_ra}
    ORDER BY v.visit_date DESC, v.id DESC
");
$vis_audit_list = [];
if ($vis_audit_q && $vis_audit_q->num_rows > 0) {
    while ($row = $vis_audit_q->fetch_assoc()) {
        $vis_audit_list[] = $row;
    }
}

// -------------------------------------------------------------------
// 6. HOSTEL BED CAPACITY & OCCUPANCY ANALYTICS
// -------------------------------------------------------------------
$blocks_cap_q = $conn->query("
    SELECT 
        b.id AS block_id,
        b.name AS block_name,
        COUNT(DISTINCT r.id) AS total_rooms,
        COUNT(bd.id) AS total_beds,
        SUM(CASE WHEN LOWER(bd.status) = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds,
        SUM(CASE WHEN LOWER(bd.status) = 'available' THEN 1 ELSE 0 END) AS available_beds
    FROM blocks b
    LEFT JOIN floors f ON b.id = f.block_id
    LEFT JOIN rooms r ON f.id = r.floor_id
    LEFT JOIN beds bd ON r.id = bd.room_id
    GROUP BY b.id, b.name
    ORDER BY b.name ASC
");
$blocks_cap_list   = [];
$block_chart_names = [];
$block_chart_occ   = [];
$block_chart_avail = [];

$total_hostel_beds = 0;
$total_hostel_occ  = 0;
$total_hostel_vac  = 0;

if ($blocks_cap_q && $blocks_cap_q->num_rows > 0) {
    while ($b = $blocks_cap_q->fetch_assoc()) {
        $b_tot   = intval($b['total_beds'] ?? 0);
        $b_occ   = intval($b['occupied_beds'] ?? 0);
        $b_avail = intval($b['available_beds'] ?? 0);

        $total_hostel_beds += $b_tot;
        $total_hostel_occ  += $b_occ;
        $total_hostel_vac  += $b_avail;

        $blocks_cap_list[]   = $b;
        $block_chart_names[] = $b['block_name'];
        $block_chart_occ[]   = $b_occ;
        $block_chart_avail[] = $b_avail;
    }
}

$hostel_occupancy_pct = ($total_hostel_beds > 0) ? round(($total_hostel_occ / $total_hostel_beds) * 100, 1) : 0;

// Start Output Buffering
ob_start();
?>

<!-- Custom CSS for Admin Reports & Analytics (Royal Cobalt Theme) -->
<style>
.reports-filter-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
    margin-bottom: 1.75rem;
}
.reports-filter-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    margin-bottom: 0.35rem;
}
.reports-preset-pill {
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 600;
    color: #475569;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    text-decoration: none !important;
    transition: all 0.2s ease;
    display: inline-block;
}
.reports-preset-pill:hover {
    background: #e2e8f0;
    color: #1e293b;
    transform: translateY(-1px);
}
.reports-preset-pill.active {
    background: #2563eb;
    color: #ffffff !important;
    border-color: #2563eb;
    box-shadow: 0 3px 10px rgba(37, 99, 235, 0.3);
}
.kpi-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem 1.35rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    align-items: center;
    height: 100%;
}
.kpi-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.06);
}
.kpi-stat-icon {
    width: 52px;
    height: 52px;
    min-width: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.45rem;
    margin-right: 1.1rem;
}
.chart-panel-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.5rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
    margin-bottom: 1.75rem;
    height: 100%;
}
.chart-container-box {
    position: relative;
    height: 270px;
    width: 100%;
}
.admin-nav-tabs {
    border-bottom: 2px solid #e2e8f0;
    gap: 8px;
}
.admin-nav-tabs .nav-link {
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
.admin-nav-tabs .nav-link:hover {
    color: #2563eb;
    border-bottom-color: #bfdbfe !important;
}
.admin-nav-tabs .nav-link.active {
    color: #2563eb !important;
    background: transparent !important;
    border-bottom-color: #2563eb !important;
}
.receipt-box {
    background: #ffffff;
    border: 2px dashed #cbd5e1;
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
    color: rgba(37, 99, 235, 0.05);
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
    .navbar, .sidebar, .reports-filter-card, .btn-no-print, .admin-nav-tabs, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info {
        display: none !important;
    }
    .tab-content > .tab-pane {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
        margin-bottom: 2.5rem !important;
        page-break-after: always;
    }
    .print-only-header {
        display: block !important;
    }
    .table-responsive {
        max-height: none !important;
        overflow: visible !important;
        border: none !important;
    }
}
.print-only-header {
    display: none;
}
</style>

<div class="container-fluid px-0">
    <!-- Printable Executive Letterhead -->
    <div class="print-only-header border-bottom pb-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="font-weight-bold text-dark mb-0">HOSTEL MATE — EXECUTIVE AUDIT REPORT</h3>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">Comprehensive Financial, Occupancy, Attendance, and Grievance Intelligence</p>
            </div>
            <div class="text-right">
                <div class="font-weight-bold text-primary">Period: <?= htmlspecialchars($period_label) ?></div>
                <small class="text-muted">Generated by: <?= htmlspecialchars($admin_name) ?> (Administrator)</small><br>
                <small class="text-muted">Date: <?= date('d M Y, h:i A') ?></small>
            </div>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row align-items-center mb-4 btn-no-print">
        <div class="col-md-7 col-12 mb-3 mb-md-0">
            <div class="d-flex align-items-center">
                <div class="stat-icon-shape rounded-14 mr-3" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; width: 48px; height: 48px;">
                    <i class="fa-solid fa-chart-column" style="font-size: 1.4rem;"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.3px;">Executive Analytics & Reports</h3>
                    <p class="text-muted mb-0" style="font-size: 0.88rem;">
                        Real-time institutional metrics, payment collection history, room occupancy, and grievance audit logs.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-5 col-12 text-md-right">
            <span class="badge badge-primary-soft mr-2 py-2 px-3" style="font-size: 0.82rem;">
                <i class="fa-regular fa-calendar-check mr-1"></i> <?= htmlspecialchars($period_label) ?>
            </span>
            <button onclick="window.print()" class="btn btn-primary shadow-sm btn-sm py-2 px-3" style="border-radius: 10px;">
                <i class="fa-solid fa-print mr-1"></i> Print Executive Report
            </button>
        </div>
    </div>

    <!-- Interactive Date Filtering Card -->
    <div class="reports-filter-card p-3 p-md-4 btn-no-print">
        <form method="GET" action="reports.php" id="adminReportsFilterForm">
            <!-- Top Row: Date Presets -->
            <div class="d-flex flex-wrap align-items-center mb-3 pb-3 border-bottom">
                <span class="reports-filter-label mr-3 mb-2 mb-md-0"><i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Presets:</span>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    <a href="reports.php?preset=today<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'today') ? 'active' : '' ?>">Today</a>
                    <a href="reports.php?preset=yesterday<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'yesterday') ? 'active' : '' ?>">Yesterday</a>
                    <a href="reports.php?preset=this_week<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'this_week') ? 'active' : '' ?>">This Week</a>
                    <a href="reports.php?preset=last_7_days<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'last_7_days') ? 'active' : '' ?>">Last 7 Days</a>
                    <a href="reports.php?preset=this_month<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'this_month') ? 'active' : '' ?>">This Month</a>
                    <a href="reports.php?preset=last_30_days<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'last_30_days') ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="reports.php?preset=this_year<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'this_year') ? 'active' : '' ?>">This Year</a>
                    <a href="reports.php?preset=all<?= $block_id > 0 ? '&block_id=' . $block_id : '' ?>" class="reports-preset-pill <?= ($active_preset === 'all') ? 'active' : '' ?>">All Recorded</a>
                </div>
            </div>

            <!-- Second Row: Custom Start Date, End Date, Block, Submit -->
            <div class="row align-items-end">
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="reports-filter-label"><i class="fa-regular fa-calendar-alt text-primary mr-1"></i> Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" style="font-weight: 500;">
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="reports-filter-label"><i class="fa-regular fa-calendar-alt text-primary mr-1"></i> End Date</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" style="font-weight: 500;">
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="reports-filter-label"><i class="fa-solid fa-building text-secondary mr-1"></i> Hostel Block Scope</label>
                    <select name="block_id" class="form-control" style="font-weight: 500;">
                        <option value="0">All Hostel Blocks</option>
                        <?php foreach ($all_blocks as $blk): ?>
                            <option value="<?= $blk['id'] ?>" <?= $block_id == $blk['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($blk['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6 col-12 d-flex">
                    <button type="submit" class="btn btn-primary w-100 mr-2 shadow-sm d-flex align-items-center justify-content-center" style="font-weight: 600;">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i> Generate Report
                    </button>
                    <a href="reports.php" class="btn btn-secondary shadow-sm d-flex align-items-center justify-content-center" title="Reset Filters" style="min-width: 44px; padding: 0.6rem 0.85rem;">
                        <i class="fa-solid fa-rotate-right"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- 5 Executive High-Level KPI Summary Cards -->
    <div class="row mb-4">
        <!-- 1. Fee Revenue KPI -->
        <div class="col-xl col-lg-4 col-md-6 mb-3 mb-xl-0">
            <div class="kpi-stat-card">
                <div class="kpi-stat-icon bg-success-soft text-success">
                    <i class="fa-solid fa-indian-rupee-sign"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase;">Fee Collections</span>
                    <h4 class="font-weight-bold text-dark mb-0">₹ <?= number_format($stat_fee_revenue, 2) ?></h4>
                    <small class="text-success font-weight-bold"><?= $stat_fee_payments ?> payment(s) in period</small>
                </div>
            </div>
        </div>
        <!-- 2. Occupancy Rate KPI -->
        <div class="col-xl col-lg-4 col-md-6 mb-3 mb-xl-0">
            <div class="kpi-stat-card">
                <div class="kpi-stat-icon bg-primary-soft text-primary">
                    <i class="fa-solid fa-bed"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase;">Hostel Occupancy</span>
                    <h4 class="font-weight-bold text-dark mb-0"><?= $hostel_occupancy_pct ?>%</h4>
                    <small class="text-primary font-weight-bold"><?= $total_hostel_occ ?> / <?= $total_hostel_beds ?> beds occupied</small>
                </div>
            </div>
        </div>
        <!-- 3. Attendance Rate KPI -->
        <div class="col-xl col-lg-4 col-md-6 mb-3 mb-xl-0">
            <div class="kpi-stat-card">
                <div class="kpi-stat-icon bg-info-soft text-info">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase;">Attendance Rate</span>
                    <h4 class="font-weight-bold text-dark mb-0"><?= $att_present_pct ?>%</h4>
                    <small class="text-info font-weight-bold"><?= $att_present ?> present &bull; <?= $att_absent ?> absent</small>
                </div>
            </div>
        </div>
        <!-- 4. Complaints Resolution KPI -->
        <div class="col-xl col-lg-6 col-md-6 mb-3 mb-xl-0">
            <div class="kpi-stat-card">
                <div class="kpi-stat-icon bg-warning-soft text-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase;">Issue Resolution</span>
                    <h4 class="font-weight-bold text-dark mb-0"><?= $comp_resolve_pct ?>%</h4>
                    <small class="text-warning font-weight-bold"><?= $comp_resolved ?> / <?= $comp_total ?> resolved</small>
                </div>
            </div>
        </div>
        <!-- 5. Visitors KPI -->
        <div class="col-xl col-lg-6 col-md-6 mb-3 mb-xl-0">
            <div class="kpi-stat-card">
                <div class="kpi-stat-icon bg-danger-soft text-danger">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase;">Visitor Gate Traffic</span>
                    <h4 class="font-weight-bold text-dark mb-0"><?= $vis_total ?> Visits</h4>
                    <small class="text-danger font-weight-bold"><?= $vis_approve_pct ?>% approved</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 4 Interactive Chart.js Graphs -->
    <div class="row mb-4 btn-no-print">
        <!-- Chart 1: Fee Collection / Revenue Timeline -->
        <div class="col-lg-6 col-12 mb-4">
            <div class="chart-panel-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-money-bill-trend-up text-success mr-2"></i> Payment Collection Trend (₹)
                        </h6>
                        <small class="text-muted">Daily fee collections recorded in selected period</small>
                    </div>
                    <span class="badge badge-success-soft font-weight-bold">Revenue Timeline</span>
                </div>
                <div class="chart-container-box">
                    <canvas id="adminFeeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Chart 2: Block Bed Capacity & Occupancy -->
        <div class="col-lg-6 col-12 mb-4">
            <div class="chart-panel-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-building-user text-primary mr-2"></i> Block Bed Capacity & Occupancy
                        </h6>
                        <small class="text-muted">Occupied beds vs available vacant capacity</small>
                    </div>
                    <span class="badge badge-primary-soft font-weight-bold">Hostel Capacity</span>
                </div>
                <div class="chart-container-box">
                    <canvas id="adminBlockChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Chart 3: Complaints Resolution & Status Breakdown -->
        <div class="col-lg-6 col-12 mb-4">
            <div class="chart-panel-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-chart-pie text-warning mr-2"></i> Complaints Resolution Breakdown
                        </h6>
                        <small class="text-muted">Resolved, in-progress, pending, and rejected tickets</small>
                    </div>
                    <span class="badge badge-warning-soft font-weight-bold">Grievance Health</span>
                </div>
                <div class="chart-container-box">
                    <canvas id="adminComplaintChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Chart 4: Daily Attendance Rate Timeline -->
        <div class="col-lg-6 col-12 mb-4">
            <div class="chart-panel-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-clipboard-user text-info mr-2"></i> Daily Roll Call Timeline
                        </h6>
                        <small class="text-muted">Present, absent, and late student counts per date</small>
                    </div>
                    <span class="badge badge-info-soft font-weight-bold">Roll Call Activity</span>
                </div>
                <div class="chart-container-box">
                    <canvas id="adminAttendanceChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabbed Detailed Audit Data Tables -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white pt-4 px-4 pb-0 border-bottom-0">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h5 class="font-weight-bold text-dark mb-2 mb-md-0">
                    <i class="fa-solid fa-table-list text-primary mr-2"></i> Institutional Operational Audit Tables
                </h5>
                <span class="text-muted" style="font-size: 0.85rem;">
                    Auditing data for period: <strong><?= htmlspecialchars($period_label) ?></strong>
                </span>
            </div>

            <!-- Tab Navigation (Hidden on Print) -->
            <ul class="nav nav-tabs admin-nav-tabs btn-no-print" id="reportTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="tab-fees-link" data-toggle="tab" href="#tab-fees" role="tab">
                        <i class="fa-solid fa-receipt text-success"></i> Payment History (<?= count($fee_audit_list) ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-attendance-link" data-toggle="tab" href="#tab-attendance" role="tab">
                        <i class="fa-solid fa-clipboard-user text-info"></i> Attendance Logs (<?= count($att_audit_list) ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-complaints-link" data-toggle="tab" href="#tab-complaints" role="tab">
                        <i class="fa-solid fa-triangle-exclamation text-warning"></i> Complaints (<?= count($comp_audit_list) ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-visitors-link" data-toggle="tab" href="#tab-visitors" role="tab">
                        <i class="fa-solid fa-users text-danger"></i> Visitor Logs (<?= count($vis_audit_list) ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="tab-blocks-link" data-toggle="tab" href="#tab-blocks" role="tab">
                        <i class="fa-solid fa-building text-primary"></i> Block Capacity (<?= count($blocks_cap_list) ?>)
                    </a>
                </li>
            </ul>
        </div>

        <div class="card-body p-3 p-md-4">
            <div class="tab-content" id="reportTabsContent">
                
                <!-- TAB 1: PAYMENT HISTORY AUDIT (PRIMARY USER REQUEST) -->
                <div class="tab-pane fade show active" id="tab-fees" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-receipt text-success mr-1"></i> Student Fee Payment History Audit
                        </h6>
                        <span class="badge badge-success-soft" style="font-size: 0.85rem;">
                            Total Collected: ₹ <?= number_format($stat_fee_revenue, 2) ?>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="reportFeeTable">
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
                                            <div class="font-weight-bold text-success" style="font-size: 0.95rem;">₹ <?= number_format($row['price'], 2) ?></div>
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
                                            <span class="badge badge-success-soft font-weight-bold">
                                                <i class="fa-solid fa-check mr-1"></i> Paid
                                            </span>
                                        </td>
                                        <td class="text-center btn-no-print">
                                            <button type="button" class="btn btn-sm btn-outline-primary view-fee-btn"
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
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-clipboard-user text-info mr-1"></i> Attendance Roll Call Log
                        </h6>
                        <span class="badge badge-info-soft" style="font-size: 0.85rem;">
                            Rate: <?= $att_present_pct ?>% Present
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="reportAttendanceTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Student Resident</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Marked By</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($att_audit_list as $row): 
                                    $st = strtolower($row['status'] ?? 'present');
                                    $badge_class = 'badge-success-soft';
                                    if ($st === 'absent') $badge_class = 'badge-danger-soft';
                                    elseif ($st === 'late') $badge_class = 'badge-warning-soft';
                                ?>
                                    <tr>
                                        <td class="font-weight-bold text-dark"><?= date('d M, Y', strtotime($row['attendance_date'])) ?></td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($row['student_phone'] ?? '') ?></small>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['block_name'] ?? 'Block') ?></div>
                                            <small class="text-muted">Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?> (Bed <?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>)</small>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badge_class ?> font-weight-bold text-uppercase" style="font-size: 0.72rem;">
                                                <?= ucfirst($st) ?>
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($row['marked_by'] ?? 'Hostel Warden') ?></small></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($row['remarks'] ?? '-') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 3: COMPLAINTS AUDIT -->
                <div class="tab-pane fade" id="tab-complaints" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-triangle-exclamation text-warning mr-1"></i> Student Complaints & Grievance Register
                        </h6>
                        <span class="badge badge-warning-soft" style="font-size: 0.85rem;">
                            Resolution Rate: <?= $comp_resolve_pct ?>%
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="reportComplaintsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Resident Student</th>
                                    <th>Issue Title</th>
                                    <th>Priority</th>
                                    <th>Date Filed</th>
                                    <th>Status</th>
                                    <th>Assigned Warden</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comp_audit_list as $row): 
                                    $c_st = strtolower($row['status'] ?? 'pending');
                                    $c_prio = strtolower($row['priority'] ?? 'medium');
                                    $badge_class = 'badge-warning-soft';
                                    if ($c_st === 'resolved') $badge_class = 'badge-success-soft';
                                    elseif ($c_st === 'in_progress') $badge_class = 'badge-primary-soft';
                                    elseif ($c_st === 'rejected') $badge_class = 'badge-danger-soft';
                                ?>
                                    <tr>
                                        <td class="font-weight-bold text-muted"><?= $row['id'] ?></td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($row['block_name'] ?? '') ?> Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?></small>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['title']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars(substr(strip_tags($row['description']), 0, 50)) ?>...</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-secondary py-1 px-2 font-weight-bold text-uppercase" style="font-size: 0.72rem;">
                                                <?= ucfirst($c_prio) ?>
                                            </span>
                                        </td>
                                        <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <span class="badge <?= $badge_class ?> font-weight-bold text-uppercase" style="font-size: 0.72rem;">
                                                <?= ucfirst(str_replace('_', ' ', $c_st)) ?>
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($row['warden_handler_name'] ?? 'Unassigned') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 4: VISITORS AUDIT -->
                <div class="tab-pane fade" id="tab-visitors" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-users text-danger mr-1"></i> Visitor Logs & Security Register
                        </h6>
                        <span class="badge badge-danger-soft" style="font-size: 0.85rem;">
                            Approved: <?= $vis_approved + $vis_completed ?> / <?= $vis_total ?>
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="reportVisitorsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Visitor Name</th>
                                    <th>Visiting Student</th>
                                    <th>Visit Date</th>
                                    <th>Gate Timestamps</th>
                                    <th>Status</th>
                                    <th>Approver</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vis_audit_list as $row): 
                                    $v_st = strtolower($row['status'] ?? 'pending');
                                    $badge_class = 'badge-warning-soft';
                                    if ($v_st === 'approved') $badge_class = 'badge-success-soft';
                                    elseif ($v_st === 'completed') $badge_class = 'badge-primary-soft';
                                    elseif ($v_st === 'rejected') $badge_class = 'badge-danger-soft';
                                ?>
                                    <tr>
                                        <td class="font-weight-bold text-muted"><?= $row['id'] ?></td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($row['relationship']) ?> &bull; <?= htmlspecialchars($row['phone']) ?></small>
                                        </td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($row['block_name'] ?? '') ?> Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?></small>
                                        </td>
                                        <td><?= date('d M Y', strtotime($row['visit_date'])) ?></td>
                                        <td>
                                            <?php if (!empty($row['check_in_at'])): ?>
                                                <small class="text-success font-weight-bold d-block">In: <?= date('h:i A', strtotime($row['check_in_at'])) ?></small>
                                                <?php if (!empty($row['check_out_at'])): ?>
                                                    <small class="text-secondary font-weight-bold">Out: <?= date('h:i A', strtotime($row['check_out_at'])) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <small class="text-muted">Not Checked In</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badge_class ?> font-weight-bold text-uppercase" style="font-size: 0.72rem;">
                                                <?= ucfirst($v_st) ?>
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($row['warden_approver_name'] ?? 'Pending') ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 5: HOSTEL BLOCKS & BED CAPACITY -->
                <div class="tab-pane fade" id="tab-blocks" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fa-solid fa-building text-primary mr-1"></i> Hostel Blocks & Bed Allocation Audit
                        </h6>
                        <span class="badge badge-primary-soft" style="font-size: 0.85rem;">
                            Occupancy: <?= $hostel_occupancy_pct ?>%
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Block Name</th>
                                    <th>Total Rooms</th>
                                    <th>Total Beds</th>
                                    <th>Occupied Beds</th>
                                    <th>Available Beds</th>
                                    <th>Occupancy Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($blocks_cap_list)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">No blocks configured.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($blocks_cap_list as $b): 
                                        $tot = intval($b['total_beds'] ?? 0);
                                        $occ = intval($b['occupied_beds'] ?? 0);
                                        $pct = ($tot > 0) ? round(($occ / $tot) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td class="font-weight-bold text-dark">
                                                <i class="fa-solid fa-building text-primary mr-1"></i> <?= htmlspecialchars($b['block_name']) ?>
                                            </td>
                                            <td><?= intval($b['total_rooms'] ?? 0) ?> Rooms</td>
                                            <td><?= $tot ?> Beds</td>
                                            <td class="text-success font-weight-bold"><?= $occ ?> Occupied</td>
                                            <td class="text-primary font-weight-bold"><?= intval($b['available_beds'] ?? 0) ?> Available</td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="font-weight-bold mr-2"><?= $pct ?>%</span>
                                                    <div class="progress flex-grow-1" style="height: 6px; border-radius: 4px;">
                                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Official Administrator Print Sign-off Box (Visible on Print) -->
    <div class="print-only-header mt-5 pt-4 border-top">
        <div class="row align-items-center">
            <div class="col-6">
                <small class="text-muted d-block">Hostel Administration & Governance</small>
                <small class="text-muted">Hostel Mate Management Information System (MIS)</small>
            </div>
            <div class="col-6 text-right">
                <div style="display: inline-block; text-align: center; min-width: 200px;">
                    <div style="border-bottom: 1.5px solid #000; height: 40px; margin-bottom: 6px;"></div>
                    <span class="font-weight-bold text-dark" style="font-size: 0.88rem;">System Administrator Signature</span><br>
                    <small class="text-muted"><?= htmlspecialchars($admin_name) ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: View Fee Receipt -->
<div class="modal fade btn-no-print" id="feeReceiptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 580px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title font-weight-bold text-dark d-flex align-items-center mb-0">
                    <i class="fa-solid fa-receipt text-primary mr-2"></i> Official Hostel Fee Receipt
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <div class="receipt-box" id="printableReceiptArea">
                    <div class="receipt-watermark">PAID</div>
                    
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <h5 class="font-weight-bold text-primary mb-1">HOSTEL MATE</h5>
                            <small class="text-muted d-block">Hostel Management & Resident Accommodations</small>
                            <small class="text-muted">Official Student Fee Payment Voucher</small>
                        </div>
                        <div class="text-right">
                            <span class="badge badge-success-soft font-weight-bold mb-1" style="font-size: 0.78rem;">Status: PAID</span>
                            <div class="font-weight-bold text-dark" style="font-size: 0.85rem;" id="modalReceiptNo">REC-0000</div>
                        </div>
                    </div>

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

                    <div class="d-flex justify-content-between align-items-center pt-2">
                        <small class="text-muted">
                            <i class="fa-regular fa-clock mr-1"></i> Recorded on: <span id="modalRecordedTime" class="font-weight-bold text-dark">-</span>
                        </small>
                        <small class="text-muted font-italic">Hostel Mate Authorized Copy</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-3 px-4 d-flex justify-content-between" style="background: #f8fafc; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
                <button type="button" class="btn btn-outline-secondary" onclick="printReceiptElement('printableReceiptArea')">
                    <i class="fa-solid fa-print mr-1"></i> Print Receipt
                </button>
                <button type="button" class="btn btn-secondary px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Page Scripts for Chart.js & DataTables -->
<script>
document.addEventListener("DOMContentLoaded", function () {

    // 1. Fee Collection / Revenue Timeline Chart (Line / Bar)
    const feeCtx = document.getElementById('adminFeeChart');
    if (feeCtx) {
        new Chart(feeCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(!empty($fee_chart_labels) ? $fee_chart_labels : [date('d M')]) ?>,
                datasets: [{
                    label: 'Revenue (₹)',
                    data: <?= json_encode(!empty($fee_chart_amounts) ? $fee_chart_amounts : [0]) ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.75)',
                    borderColor: '#10b981',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: true, position: 'top', labels: { boxWidth: 12, fontColor: '#334155' } },
                plugins: {
                    legend: { display: true, position: 'top', labels: { boxWidth: 12, color: '#334155' } }
                },
                scales: {
                    yAxes: [{
                        ticks: { beginAtZero: true, fontColor: '#64748b' },
                        gridLines: { color: '#f1f5f9' }
                    }],
                    xAxes: [{
                        ticks: { fontColor: '#64748b' },
                        gridLines: { display: false }
                    }],
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b' } },
                    x: { grid: { display: false }, ticks: { color: '#64748b' } }
                }
            }
        });
    }

    // 2. Block Bed Capacity Chart (Stacked Bar)
    const blockCtx = document.getElementById('adminBlockChart');
    if (blockCtx) {
        new Chart(blockCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(!empty($block_chart_names) ? $block_chart_names : ['Block A']) ?>,
                datasets: [
                    {
                        label: 'Occupied Beds',
                        data: <?= json_encode(!empty($block_chart_occ) ? $block_chart_occ : [0]) ?>,
                        backgroundColor: '#2563eb',
                        borderRadius: 5
                    },
                    {
                        label: 'Available Vacant Beds',
                        data: <?= json_encode(!empty($block_chart_avail) ? $block_chart_avail : [0]) ?>,
                        backgroundColor: '#bfdbfe',
                        borderRadius: 5
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: true, position: 'top', labels: { boxWidth: 12, fontColor: '#334155' } },
                plugins: {
                    legend: { display: true, position: 'top', labels: { boxWidth: 12, color: '#334155' } }
                },
                scales: {
                    yAxes: [{
                        ticks: { beginAtZero: true, fontColor: '#64748b', stepSize: 1 },
                        gridLines: { color: '#f1f5f9' }
                    }],
                    xAxes: [{
                        ticks: { fontColor: '#64748b' },
                        gridLines: { display: false }
                    }],
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b', stepSize: 1 } },
                    x: { grid: { display: false }, ticks: { color: '#64748b' } }
                }
            }
        });
    }

    // 3. Complaints Resolution Breakdown (Doughnut)
    const compCtx = document.getElementById('adminComplaintChart');
    if (compCtx) {
        new Chart(compCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Resolved', 'In Progress', 'Pending', 'Rejected'],
                datasets: [{
                    data: [<?= $comp_resolved ?>, <?= $comp_in_progress ?>, <?= $comp_pending ?>, <?= $comp_rejected ?>],
                    backgroundColor: ['#10b981', '#0284c7', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutoutPercentage: 70,
                cutout: '70%',
                legend: { display: true, position: 'bottom', labels: { boxWidth: 12, fontColor: '#334155' } },
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 12, color: '#334155' } }
                }
            }
        });
    }

    // 4. Daily Attendance Trend Chart (Grouped Bar)
    const attCtx = document.getElementById('adminAttendanceChart');
    if (attCtx) {
        new Chart(attCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(!empty($att_chart_labels) ? $att_chart_labels : [date('d M')]) ?>,
                datasets: [
                    {
                        label: 'Present',
                        data: <?= json_encode(!empty($att_chart_present) ? $att_chart_present : [0]) ?>,
                        backgroundColor: '#10b981',
                        borderRadius: 4
                    },
                    {
                        label: 'Absent',
                        data: <?= json_encode(!empty($att_chart_absent) ? $att_chart_absent : [0]) ?>,
                        backgroundColor: '#ef4444',
                        borderRadius: 4
                    },
                    {
                        label: 'Late',
                        data: <?= json_encode(!empty($att_chart_late) ? $att_chart_late : [0]) ?>,
                        backgroundColor: '#f59e0b',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { display: true, position: 'top', labels: { boxWidth: 12, fontColor: '#334155' } },
                plugins: {
                    legend: { display: true, position: 'top', labels: { boxWidth: 12, color: '#334155' } }
                },
                scales: {
                    yAxes: [{
                        ticks: { beginAtZero: true, fontColor: '#64748b', stepSize: 1 },
                        gridLines: { color: '#f1f5f9' }
                    }],
                    xAxes: [{
                        ticks: { fontColor: '#64748b' },
                        gridLines: { display: false }
                    }],
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#64748b', stepSize: 1 } },
                    x: { grid: { display: false }, ticks: { color: '#64748b' } }
                }
            }
        });
    }

    // Initialize DataTables for Audit Lists with error suppression and zeroRecords handling
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
                columns: [
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true }
                ],
                language: { 
                    search: "Filter Attendance:",
                    emptyTable: "No attendance logs found in date period",
                    zeroRecords: "No matching attendance records found"
                }
            });
        }

        if (!$.fn.DataTable.isDataTable('#reportComplaintsTable')) {
            $('#reportComplaintsTable').DataTable({
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
                    { orderable: true }
                ],
                language: { 
                    search: "Filter Complaints:",
                    emptyTable: "No complaints filed in this date range",
                    zeroRecords: "No matching complaints found"
                }
            });
        }

        if (!$.fn.DataTable.isDataTable('#reportVisitorsTable')) {
            $('#reportVisitorsTable').DataTable({
                pageLength: 10,
                ordering: true,
                order: [[3, "desc"]],
                columns: [
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true },
                    { orderable: true }
                ],
                language: { 
                    search: "Filter Visitors:",
                    emptyTable: "No visitor entries found in this date range",
                    zeroRecords: "No matching visitors found"
                }
            });
        }

        // Adjust DataTables layout whenever an audit tab is switched
        $('a[data-toggle="tab"]').on('shown.bs.tab', function () {
            $($.fn.dataTable.tables(true)).DataTable().columns.adjust().responsive.recalc();
        });
    }

    // Handle View Receipt Modal
    $('.view-fee-btn').on('click', function () {
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

function printReceiptElement(elementId) {
    var printContent = document.getElementById(elementId).innerHTML;
    var printWindow = window.open('', '', 'height=650,width=750');
    printWindow.document.write('<html><head><title>Hostel Fee Receipt</title>');
    printWindow.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">');
    printWindow.document.write('<style>body{padding:20px;font-family:sans-serif;} .badge-success-soft{background:#dcfce7;color:#15803d;padding:4px 8px;border-radius:6px;} .receipt-box{border:1px solid #ddd;padding:20px;border-radius:10px;}</style>');
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
