<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

// -------------------------------------------------------------------
// FILTER PARAMETERS & DATE RANGE LOGIC
// -------------------------------------------------------------------
$start_date   = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date     = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$status_filter= isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$block_filter = isset($_GET['block_id']) ? intval($_GET['block_id']) : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$preset       = isset($_GET['preset']) ? trim($_GET['preset']) : '';

// Apply Presets if selected
if (!empty($preset)) {
    switch ($preset) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date   = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date   = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date   = date('Y-m-d');
            break;
        case 'last_7_days':
            $start_date = date('Y-m-d', strtotime('-6 days'));
            $end_date   = date('Y-m-d');
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date   = date('Y-m-t');
            break;
        case 'last_30_days':
            $start_date = date('Y-m-d', strtotime('-29 days'));
            $end_date   = date('Y-m-d');
            break;
        case 'all':
            $start_date = '';
            $end_date   = '';
            break;
    }
}

// Build SQL WHERE Conditions
$where_clauses = ["u.role = 'student'"];

// Date Filtering
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $where_clauses[] = "(a.attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}')";
    $date_scope_text = date('d M Y', strtotime($start_date)) . " to " . date('d M Y', strtotime($end_date));
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $where_clauses[] = "a.attendance_date >= '{$safe_start}'";
    $date_scope_text = "From " . date('d M Y', strtotime($start_date));
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $where_clauses[] = "a.attendance_date <= '{$safe_end}'";
    $date_scope_text = "Until " . date('d M Y', strtotime($end_date));
} else {
    $date_scope_text = "All Recorded Dates";
}

// Status Filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "LOWER(a.status) = '{$safe_status}'";
}

// Block Filter
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// Text Search
if (!empty($search_query)) {
    $safe_search = $conn->real_escape_string($search_query);
    $where_clauses[] = "(u.name LIKE '%{$safe_search}%' OR u.email LIKE '%{$safe_search}%' OR u.phone LIKE '%{$safe_search}%' OR st.admission_no LIKE '%{$safe_search}%' OR rm.room_number LIKE '%{$safe_search}%' OR a.marked_by LIKE '%{$safe_search}%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch Available Blocks for Filter Dropdown
$blocks_res = $conn->query("SELECT id, name FROM blocks ORDER BY name ASC");
$all_blocks = [];
if ($blocks_res && $blocks_res->num_rows > 0) {
    while ($b = $blocks_res->fetch_assoc()) {
        $all_blocks[] = $b;
    }
}

// Fetch KPI Metrics Based on Current Date Scope (ignoring status/search for overview)
$kpi_where = ["u.role = 'student'"];
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $kpi_where[] = "(a.attendance_date BETWEEN '{$safe_start}' AND '{$safe_end}')";
} elseif (!empty($start_date)) {
    $kpi_where[] = "a.attendance_date >= '{$safe_start}'";
} elseif (!empty($end_date)) {
    $kpi_where[] = "a.attendance_date <= '{$safe_end}'";
}
if ($block_filter > 0) {
    $kpi_where[] = "ra.block_id = {$block_filter}";
}
$kpi_where_sql = implode(' AND ', $kpi_where);

$kpi_sql = "
    SELECT 
        COUNT(a.id) AS total_records,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_count,
        SUM(CASE WHEN a.status IN ('leave', 'outing') THEN 1 ELSE 0 END) AS leave_outing_count
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE {$kpi_where_sql}
";
$kpi_res = $conn->query($kpi_sql);
$kpi = ($kpi_res && $kpi_res->num_rows > 0) ? $kpi_res->fetch_assoc() : [];

$stat_total   = intval($kpi['total_records'] ?? 0);
$stat_present = intval($kpi['present_count'] ?? 0);
$stat_absent  = intval($kpi['absent_count'] ?? 0);
$stat_late    = intval($kpi['late_count'] ?? 0);
$stat_leave   = intval($kpi['leave_outing_count'] ?? 0);
$present_pct  = $stat_total > 0 ? round(($stat_present / $stat_total) * 100, 1) : 0;

// Fetch Attendance Rows with Student, Room, and Block Information
$attendance_sql = "
    SELECT 
        a.id AS attendance_id,
        a.student_id,
        a.attendance_date,
        a.status,
        a.marked_by,
        a.remarks,
        a.created_at AS record_created_at,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        st.admission_no,
        st.course,
        st.department,
        st.year,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        bd.bed_number
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN students st ON u.id = st.user_id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE {$where_sql}
    ORDER BY a.attendance_date DESC, a.created_at DESC, a.id DESC
";
$attendance_res = $conn->query($attendance_sql);
$attendance_list = [];
if ($attendance_res && $attendance_res->num_rows > 0) {
    while ($row = $attendance_res->fetch_assoc()) {
        $attendance_list[] = $row;
    }
}

// Start Output Buffering to include in main_master.php
ob_start();
?>

<!-- Custom CSS for Admin Attendance View -->
<style>
.filter-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
    margin-bottom: 1.5rem;
}
.filter-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    margin-bottom: 0.35rem;
}
.preset-pill {
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
.preset-pill:hover {
    background: #e2e8f0;
    color: #1e293b;
    transform: translateY(-1px);
}
.preset-pill.active {
    background: #2563eb;
    color: #ffffff !important;
    border-color: #2563eb;
    box-shadow: 0 3px 10px rgba(37, 99, 235, 0.3);
}
.stat-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem 1.4rem;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    display: flex;
    align-items: center;
    height: 100%;
}
.stat-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.06);
}
.stat-kpi-icon {
    width: 52px;
    height: 52px;
    min-width: 52px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    margin-right: 1.1rem;
}
.student-avatar {
    width: 38px;
    height: 38px;
    min-width: 38px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #e2e8f0;
}
.student-avatar-placeholder {
    width: 38px;
    height: 38px;
    min-width: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    font-weight: 700;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.status-pill {
    font-size: 0.76rem;
    font-weight: 700;
    padding: 0.38em 0.85em;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.3px;
    text-transform: capitalize;
}
.status-pill i {
    font-size: 0.65rem;
    margin-right: 0.4rem;
}
.status-pill-present { background-color: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }
.status-pill-absent  { background-color: rgba(239, 68, 68, 0.12);  color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.25); }
.status-pill-late    { background-color: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.25); }
.status-pill-leave   { background-color: rgba(2, 132, 199, 0.12);  color: #0284c7; border: 1px solid rgba(2, 132, 199, 0.25); }
.status-pill-outing  { background-color: rgba(147, 51, 234, 0.12); color: #7e22ce; border: 1px solid rgba(147, 51, 234, 0.25); }

/* Print Styles */
@media print {
    .navbar, .sidebar, .filter-card, .btn-print-hide, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info {
        display: none !important;
    }
    .content-wrapper {
        padding: 0 !important;
        background: #fff !important;
    }
    .main-panel {
        width: 100% !important;
        max-width: 100% !important;
    }
    .table-responsive {
        max-height: none !important;
        overflow: visible !important;
        border: none !important;
    }
    .table th, .table td {
        border-color: #ccc !important;
    }
}
</style>

<div class="container-fluid px-0">
    <!-- Page Header -->
    <div class="row align-items-center mb-4">
        <div class="col-md-7 col-12 mb-3 mb-md-0">
            <div class="d-flex align-items-center">
                <div class="stat-icon-shape rounded-14 mr-3" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; width: 48px; height: 48px;">
                    <i class="fa-solid fa-clipboard-user" style="font-size: 1.4rem;"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.3px;">Student Attendance Roster</h3>
                    <p class="text-muted mb-0" style="font-size: 0.88rem;">
                        View daily attendance records across all blocks with flexible date range filtering.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-5 col-12 text-md-right btn-print-hide">
            <span class="badge badge-primary-soft mr-2 py-2 px-3" style="font-size: 0.82rem;">
                <i class="fa-regular fa-calendar-check mr-1"></i> <?= htmlspecialchars($date_scope_text) ?>
            </span>
            <button onclick="window.print()" class="btn btn-outline-secondary shadow-sm btn-sm py-2 px-3" style="border-radius: 10px;">
                <i class="fa-solid fa-print mr-1"></i> Print Roster
            </button>
        </div>
    </div>

    <!-- Interactive Date Filtering Card -->
    <div class="filter-card p-3 p-md-4">
        <form method="GET" action="attendance.php" id="attendanceFilterForm">
            <!-- Top Row: Date Presets -->
            <div class="d-flex flex-wrap align-items-center mb-3 pb-3 border-bottom">
                <span class="filter-label mr-3 mb-2 mb-md-0"><i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Presets:</span>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    <a href="attendance.php?preset=today<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'today' || ($start_date === date('Y-m-d') && $end_date === date('Y-m-d'))) ? 'active' : '' ?>">Today</a>
                    <a href="attendance.php?preset=yesterday<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'yesterday') ? 'active' : '' ?>">Yesterday</a>
                    <a href="attendance.php?preset=this_week<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_week') ? 'active' : '' ?>">This Week</a>
                    <a href="attendance.php?preset=last_7_days<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'last_7_days') ? 'active' : '' ?>">Last 7 Days</a>
                    <a href="attendance.php?preset=this_month<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_month') ? 'active' : '' ?>">This Month</a>
                    <a href="attendance.php?preset=last_30_days<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'last_30_days') ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="attendance.php?preset=all<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'all' || (empty($start_date) && empty($end_date))) ? 'active' : '' ?>">All Recorded</a>
                </div>
            </div>

            <!-- Second Row: Inputs (Start Date, End Date, Status, Block, Search) -->
            <div class="row align-items-end">
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-regular fa-calendar-alt text-primary mr-1"></i> Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" style="font-weight: 500;">
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-regular fa-calendar-alt text-primary mr-1"></i> End Date</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" style="font-weight: 500;">
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-solid fa-filter text-info mr-1"></i> Status</label>
                    <select name="status" class="form-control" style="font-weight: 500;">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
                        <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
                        <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                        <option value="leave" <?= $status_filter === 'leave' ? 'selected' : '' ?>>On Leave</option>
                        <option value="outing" <?= $status_filter === 'outing' ? 'selected' : '' ?>>Outing</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-solid fa-building text-secondary mr-1"></i> Block</label>
                    <select name="block_id" class="form-control" style="font-weight: 500;">
                        <option value="0">All Blocks</option>
                        <?php foreach ($all_blocks as $blk): ?>
                            <option value="<?= $blk['id'] ?>" <?= $block_filter == $blk['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($blk['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-12 d-flex">
                    <button type="submit" class="btn btn-primary w-100 mr-2 shadow-sm d-flex align-items-center justify-content-center" style="font-weight: 600;">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i> Filter
                    </button>
                    <a href="attendance.php" class="btn btn-secondary shadow-sm d-flex align-items-center justify-content-center" title="Reset Filters" style="min-width: 44px; padding: 0.6rem 0.85rem;">
                        <i class="fa-solid fa-rotate-right"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Summary KPI Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-primary-soft text-primary">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Records</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_total) ?></h3>
                    <small class="text-primary font-weight-bold"><?= $present_pct ?>% Attendance Rate</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-success-soft text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Present</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_present) ?></h3>
                    <small class="text-success font-weight-bold"><?= $stat_total > 0 ? round(($stat_present / $stat_total) * 100, 1) : 0 ?>% of logged</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-danger-soft text-danger">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Absent</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_absent) ?></h3>
                    <small class="text-danger font-weight-bold"><?= $stat_total > 0 ? round(($stat_absent / $stat_total) * 100, 1) : 0 ?>% of logged</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-warning-soft text-warning">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Late / Leave / Outing</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_late + $stat_leave) ?></h3>
                    <small class="text-warning font-weight-bold"><?= $stat_late ?> Late &bull; <?= $stat_leave ?> Leave/Out</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-2 mb-md-0 font-weight-bold text-dark">
                    <i class="fa-solid fa-list-check text-primary mr-2"></i> Attendance Log Details
                </h5>
                <span class="text-muted" style="font-size: 0.84rem;">
                    Showing <strong><?= count($attendance_list) ?></strong> record(s) for <?= htmlspecialchars($date_scope_text) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="attendanceTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Student Resident</th>
                            <th>Hostel Allocation</th>
                            <th>Attendance Date</th>
                            <th>Status</th>
                            <th>Marked By</th>
                            <th>Remarks</th>
                            <th class="text-center btn-print-hide" style="width: 90px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance_list as $idx => $row): 
                            $status = strtolower($row['status'] ?? 'present');
                                $pill_class = 'status-pill-present';
                                $icon_class = 'fa-solid fa-circle-check';
                                if ($status === 'absent') {
                                    $pill_class = 'status-pill-absent';
                                    $icon_class = 'fa-solid fa-circle-xmark';
                                } elseif ($status === 'late') {
                                    $pill_class = 'status-pill-late';
                                    $icon_class = 'fa-solid fa-clock';
                                } elseif ($status === 'leave') {
                                    $pill_class = 'status-pill-leave';
                                    $icon_class = 'fa-solid fa-plane-departure';
                                } elseif ($status === 'outing') {
                                    $pill_class = 'status-pill-outing';
                                    $icon_class = 'fa-solid fa-person-walking';
                                }

                                $first_letter = !empty($row['student_name']) ? strtoupper(substr($row['student_name'], 0, 1)) : 'S';
                            ?>
                                <tr>
                                    <td class="font-weight-bold text-muted"><?= $idx + 1 ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($row['student_profile_image']) && file_exists("../student_profile/" . $row['student_profile_image'])): ?>
                                                <img src="../student_profile/<?= htmlspecialchars($row['student_profile_image']) ?>" class="student-avatar mr-2" alt="Student">
                                            <?php else: ?>
                                                <div class="student-avatar-placeholder mr-2"><?= $first_letter ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-weight-bold text-dark" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($row['student_name'] ?? 'Unknown Student') ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= !empty($row['admission_no']) ? 'Adm: ' . htmlspecialchars($row['admission_no']) . ' &bull; ' : '' ?>
                                                    <?= htmlspecialchars($row['student_phone'] ?? '') ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['block_name']) || !empty($row['room_number'])): ?>
                                            <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                                <i class="fa-solid fa-building text-primary mr-1"></i> <?= htmlspecialchars($row['block_name'] ?? 'Block') ?>
                                            </div>
                                            <small class="text-muted">
                                                Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?><?= !empty($row['bed_number']) ? ' (Bed ' . htmlspecialchars($row['bed_number']) . ')' : '' ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="badge badge-secondary py-1 px-2" style="font-size: 0.75rem;">Not Allocated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                            <?= date('d M, Y', strtotime($row['attendance_date'])) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('l', strtotime($row['attendance_date'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-pill <?= $pill_class ?>">
                                            <i class="<?= $icon_class ?>"></i> <?= ucfirst($status) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-dark font-weight-medium" style="font-size: 0.86rem;">
                                            <?= htmlspecialchars($row['marked_by'] ?? 'Hostel Warden') ?>
                                        </div>
                                        <?php if (!empty($row['record_created_at'])): ?>
                                            <small class="text-muted">
                                                <?= date('h:i A', strtotime($row['record_created_at'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-muted" style="font-size: 0.85rem; max-width: 220px; display: inline-block; white-space: normal;">
                                            <?= !empty($row['remarks']) ? htmlspecialchars($row['remarks']) : '<em class="text-muted">-</em>' ?>
                                        </span>
                                    </td>
                                    <td class="text-center btn-print-hide">
                                        <button type="button" class="btn btn-sm btn-outline-primary view-att-btn" 
                                            data-id="<?= $row['attendance_id'] ?>"
                                            data-name="<?= htmlspecialchars($row['student_name'] ?? '') ?>"
                                            data-adm="<?= htmlspecialchars($row['admission_no'] ?? 'N/A') ?>"
                                            data-email="<?= htmlspecialchars($row['student_email'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($row['student_phone'] ?? '') ?>"
                                            data-course="<?= htmlspecialchars(($row['course'] ?? '') . ' ' . ($row['department'] ?? '')) ?>"
                                            data-block="<?= htmlspecialchars($row['block_name'] ?? 'Not Allocated') ?>"
                                            data-room="<?= htmlspecialchars($row['room_number'] ?? 'N/A') ?>"
                                            data-bed="<?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>"
                                            data-date="<?= date('l, d F Y', strtotime($row['attendance_date'])) ?>"
                                            data-status="<?= ucfirst($status) ?>"
                                            data-pill="<?= $pill_class ?>"
                                            data-markedby="<?= htmlspecialchars($row['marked_by'] ?? 'Hostel Warden') ?>"
                                            data-time="<?= !empty($row['record_created_at']) ? date('d M Y, h:i A', strtotime($row['record_created_at'])) : 'N/A' ?>"
                                            data-remarks="<?= htmlspecialchars($row['remarks'] ?? 'No special remarks recorded.') ?>"
                                            title="View Details">
                                            <i class="fa-solid fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal: View Attendance Record Details -->
<div class="modal fade" id="attendanceDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 580px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title font-weight-bold text-dark d-flex align-items-center mb-0">
                    <i class="fa-solid fa-circle-info text-primary mr-2"></i> Attendance Record Details
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <!-- Student Summary Box -->
                <div class="p-3 mb-4 rounded-14" style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon-shape rounded-12 bg-primary text-white mr-3" style="width: 48px; height: 48px;">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <div>
                            <h5 class="font-weight-bold text-dark mb-0" id="modalStudentName">Student Name</h5>
                            <small class="text-muted d-block" id="modalStudentMeta">Adm No &bull; Course</small>
                            <small class="text-primary font-weight-bold" id="modalStudentContact">Phone &bull; Email</small>
                        </div>
                    </div>
                </div>

                <!-- Record Details Grid -->
                <div class="row">
                    <div class="col-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Attendance Date</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalDate" style="font-size: 0.95rem;">-</p>
                    </div>
                    <div class="col-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Status</small>
                        <div>
                            <span id="modalStatusBadge" class="status-pill status-pill-present font-weight-bold">Present</span>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Hostel Block & Room</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalRoomInfo" style="font-size: 0.95rem;">-</p>
                    </div>
                    <div class="col-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Bed Allocation</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalBedInfo" style="font-size: 0.95rem;">-</p>
                    </div>
                    <div class="col-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Recorded By</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalMarkedBy" style="font-size: 0.95rem;">-</p>
                    </div>
                    <div class="col-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Entry Timestamp</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalTime" style="font-size: 0.95rem;">-</p>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Warden Remarks</small>
                        <div class="p-3 bg-light rounded-12 mt-1" style="border: 1px solid #e2e8f0;">
                            <p class="mb-0 text-dark" id="modalRemarks" style="font-size: 0.9rem; line-height: 1.5;">-</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top py-3 px-4" style="background: #f8fafc; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
                <button type="button" class="btn btn-secondary px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Page Scripts -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    // Initialize DataTable if library is loaded
    if (typeof $.fn.DataTable !== 'undefined') {
        $.fn.dataTable.ext.errMode = 'none';
        if (!$.fn.DataTable.isDataTable('#attendanceTable')) {
            $('#attendanceTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [{ targets: [7], orderable: false }],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search table records...",
                    lengthMenu: "Show _MENU_ entries",
                    emptyTable: "No attendance records match your selected date range",
                    zeroRecords: "No matching attendance records found"
                },
                order: [[3, 'desc']] // Order by Date descending
            });
        }
    }

    // Handle View Details Modal
    $('.view-att-btn').on('click', function () {
        var btn = $(this);
        $('#modalStudentName').text(btn.data('name'));
        $('#modalStudentMeta').text('Adm No: ' + btn.data('adm') + ' | ' + btn.data('course'));
        $('#modalStudentContact').text('Phone: ' + btn.data('phone') + ' | Email: ' + btn.data('email'));
        $('#modalDate').text(btn.data('date'));
        
        var pillClass = btn.data('pill');
        var statusText = btn.data('status');
        $('#modalStatusBadge').attr('class', 'status-pill ' + pillClass).text(statusText);

        $('#modalRoomInfo').text(btn.data('block') + ' - Room ' + btn.data('room'));
        $('#modalBedInfo').text('Bed ' + btn.data('bed'));
        $('#modalMarkedBy').text(btn.data('markedby'));
        $('#modalTime').text(btn.data('time'));
        $('#modalRemarks').text(btn.data('remarks'));

        $('#attendanceDetailModal').modal('show');
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
