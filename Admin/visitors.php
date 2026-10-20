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
$start_date     = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date       = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$status_filter  = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$block_filter   = isset($_GET['block_id']) ? intval($_GET['block_id']) : 0;
$search_query   = isset($_GET['search']) ? trim($_GET['search']) : '';
$preset         = isset($_GET['preset']) ? trim($_GET['preset']) : '';

// Apply Presets
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
        case 'pending':
            $status_filter = 'pending';
            break;
        case 'approved':
            $status_filter = 'approved';
            break;
        case 'all':
            $start_date = '';
            $end_date   = '';
            break;
    }
}

// Build SQL WHERE Conditions
$where_clauses = ["1=1"];

// Date Filtering on v.visit_date
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $where_clauses[] = "(v.visit_date BETWEEN '{$safe_start}' AND '{$safe_end}')";
    $date_scope_text = date('d M Y', strtotime($start_date)) . " to " . date('d M Y', strtotime($end_date));
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $where_clauses[] = "v.visit_date >= '{$safe_start}'";
    $date_scope_text = "From " . date('d M Y', strtotime($start_date));
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $where_clauses[] = "v.visit_date <= '{$safe_end}'";
    $date_scope_text = "Until " . date('d M Y', strtotime($end_date));
} else {
    $date_scope_text = "All Recorded Dates";
}

// Status Filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "LOWER(v.status) = '{$safe_status}'";
}

// Block Filter
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// Text Search
if (!empty($search_query)) {
    $safe_q = $conn->real_escape_string($search_query);
    $where_clauses[] = "(v.name LIKE '%{$safe_q}%' OR v.phone LIKE '%{$safe_q}%' OR v.id_proof_number LIKE '%{$safe_q}%' OR u.name LIKE '%{$safe_q}%' OR rm.room_number LIKE '%{$safe_q}%' OR v.purpose LIKE '%{$safe_q}%')";
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

// KPI Query
$kpi_where = ["1=1"];
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $kpi_where[] = "(v.visit_date BETWEEN '{$safe_start}' AND '{$safe_end}')";
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $kpi_where[] = "v.visit_date >= '{$safe_start}'";
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $kpi_where[] = "v.visit_date <= '{$safe_end}'";
}
if ($block_filter > 0) {
    $kpi_where[] = "ra.block_id = {$block_filter}";
}
$kpi_where_sql = implode(' AND ', $kpi_where);

$kpi_sql = "
    SELECT 
        COUNT(v.id) AS total_visitors,
        SUM(CASE WHEN v.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN v.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN v.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN v.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE {$kpi_where_sql}
";
$kpi_res = $conn->query($kpi_sql);
$kpi = ($kpi_res && $kpi_res->num_rows > 0) ? $kpi_res->fetch_assoc() : [];

$stat_total     = intval($kpi['total_visitors'] ?? 0);
$stat_approved  = intval($kpi['approved_count'] ?? 0);
$stat_completed = intval($kpi['completed_count'] ?? 0);
$stat_pending   = intval($kpi['pending_count'] ?? 0);
$stat_rejected  = intval($kpi['rejected_count'] ?? 0);
$approval_rate  = $stat_total > 0 ? round((($stat_approved + $stat_completed) / $stat_total) * 100, 1) : 0;

// Fetch Visitors Roster with Resident, Room, Warden and Gate Log Information
$visitors_sql = "
    SELECT 
        v.*,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        st.admission_no,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        bd.bed_number,
        w.name AS warden_approver_name,
        w.code AS warden_approver_code,
        vl.check_in_at,
        vl.check_out_at,
        vl.remarks AS log_remarks
    FROM visitors v
    JOIN users u ON v.student_id = u.id
    LEFT JOIN students st ON u.id = st.user_id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN wardens w ON v.approved_by = w.id
    LEFT JOIN visitor_logs vl ON v.id = vl.visitor_id
    WHERE {$where_sql}
    ORDER BY v.visit_date DESC, v.id DESC
";
$visitors_res = $conn->query($visitors_sql);
$visitors_list = [];
if ($visitors_res && $visitors_res->num_rows > 0) {
    while ($row = $visitors_res->fetch_assoc()) {
        $visitors_list[] = $row;
    }
}

// Start Output Buffering
ob_start();
?>

<!-- Custom CSS for Admin Visitors View -->
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
.visitor-avatar-placeholder {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    font-weight: 700;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.v-status-pill {
    font-size: 0.76rem;
    font-weight: 700;
    padding: 0.35em 0.85em;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.3px;
    text-transform: capitalize;
}
.v-status-approved  { background-color: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }
.v-status-completed { background-color: rgba(37, 99, 235, 0.12);  color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.25); }
.v-status-pending   { background-color: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.25); }
.v-status-rejected  { background-color: rgba(239, 68, 68, 0.12);  color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.25); }

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
}
</style>

<div class="container-fluid px-0">
    <!-- Page Header -->
    <div class="row align-items-center mb-4">
        <div class="col-md-7 col-12 mb-3 mb-md-0">
            <div class="d-flex align-items-center">
                <div class="stat-icon-shape rounded-14 mr-3" style="background: rgba(37, 99, 235, 0.1); color: #2563eb; width: 48px; height: 48px;">
                    <i class="fa-solid fa-users" style="font-size: 1.4rem;"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.3px;">Hostel Visitors & Guest Register</h3>
                    <p class="text-muted mb-0" style="font-size: 0.88rem;">
                        View visitor requests, warden approvals, and gate check-in/out records with date range filtering.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-5 col-12 text-md-right btn-print-hide">
            <span class="badge badge-primary-soft mr-2 py-2 px-3" style="font-size: 0.82rem;">
                <i class="fa-regular fa-calendar-check mr-1"></i> <?= htmlspecialchars($date_scope_text) ?>
            </span>
            <button onclick="window.print()" class="btn btn-outline-secondary shadow-sm btn-sm py-2 px-3" style="border-radius: 10px;">
                <i class="fa-solid fa-print mr-1"></i> Print Register
            </button>
        </div>
    </div>

    <!-- Interactive Date Filtering Card -->
    <div class="filter-card p-3 p-md-4">
        <form method="GET" action="visitors.php" id="visitorsFilterForm">
            <!-- Top Row: Date Presets -->
            <div class="d-flex flex-wrap align-items-center mb-3 pb-3 border-bottom">
                <span class="filter-label mr-3 mb-2 mb-md-0"><i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Presets:</span>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    <a href="visitors.php?preset=today<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'today' || ($start_date === date('Y-m-d') && $end_date === date('Y-m-d'))) ? 'active' : '' ?>">Today</a>
                    <a href="visitors.php?preset=yesterday<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'yesterday') ? 'active' : '' ?>">Yesterday</a>
                    <a href="visitors.php?preset=this_week<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_week') ? 'active' : '' ?>">This Week</a>
                    <a href="visitors.php?preset=last_7_days<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'last_7_days') ? 'active' : '' ?>">Last 7 Days</a>
                    <a href="visitors.php?preset=this_month<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_month') ? 'active' : '' ?>">This Month</a>
                    <a href="visitors.php?preset=approved" class="preset-pill <?= ($preset === 'approved' || $status_filter === 'approved') ? 'active' : '' ?>">Approved</a>
                    <a href="visitors.php?preset=pending" class="preset-pill <?= ($preset === 'pending' || $status_filter === 'pending') ? 'active' : '' ?>">Pending</a>
                    <a href="visitors.php?preset=all" class="preset-pill <?= ($preset === 'all' || (empty($start_date) && empty($end_date) && $status_filter === 'all')) ? 'active' : '' ?>">All Recorded</a>
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
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-solid fa-building text-secondary mr-1"></i> Student Block</label>
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
                    <a href="visitors.php" class="btn btn-secondary shadow-sm d-flex align-items-center justify-content-center" title="Reset Filters" style="min-width: 44px; padding: 0.6rem 0.85rem;">
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
                    <i class="fa-solid fa-id-card-clip"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Visitors</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_total) ?></h3>
                    <small class="text-primary font-weight-bold"><?= $approval_rate ?>% Approval Rate</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-success-soft text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Approved Visits</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_approved) ?></h3>
                    <small class="text-success font-weight-bold"><?= $stat_completed ?> Completed</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-warning-soft text-warning">
                    <i class="fa-solid fa-hourglass-start"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Pending Approvals</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_pending) ?></h3>
                    <small class="text-warning font-weight-bold">Awaiting Warden Review</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-danger-soft text-danger">
                    <i class="fa-solid fa-user-xmark"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Rejected</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_rejected) ?></h3>
                    <small class="text-danger font-weight-bold"><?= $stat_total > 0 ? round(($stat_rejected / $stat_total) * 100, 1) : 0 ?>% rejected</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-2 mb-md-0 font-weight-bold text-dark">
                    <i class="fa-solid fa-list-check text-primary mr-2"></i> Visitors Registry Table
                </h5>
                <span class="text-muted" style="font-size: 0.84rem;">
                    Showing <strong><?= count($visitors_list) ?></strong> visitor record(s) for <?= htmlspecialchars($date_scope_text) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="visitorsTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Visitor Details</th>
                            <th>Visiting Student</th>
                            <th>Visit Date & Timing</th>
                            <th>Gate Log (In / Out)</th>
                            <th>ID Proof</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th class="text-center btn-print-hide" style="width: 90px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visitors_list as $idx => $row): 
                            $status = strtolower($row['status'] ?? 'pending');
                                $status_badge_class = 'v-status-pending';
                                if ($status === 'approved')  $status_badge_class = 'v-status-approved';
                                elseif ($status === 'completed') $status_badge_class = 'v-status-completed';
                                elseif ($status === 'rejected')  $status_badge_class = 'v-status-rejected';

                                $v_first_letter = !empty($row['name']) ? strtoupper(substr($row['name'], 0, 1)) : 'V';
                            ?>
                                <tr>
                                    <td class="font-weight-bold text-muted"><?= $row['id'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="visitor-avatar-placeholder mr-2"><?= $v_first_letter ?></div>
                                            <div>
                                                <div class="font-weight-bold text-dark" style="font-size: 0.92rem;">
                                                    <?= htmlspecialchars($row['name']) ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($row['relationship']) ?> &bull; <?= htmlspecialchars($row['phone']) ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.9rem;">
                                            <?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= !empty($row['block_name']) ? htmlspecialchars($row['block_name']) . ' &bull; ' : '' ?>
                                            Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?><?= !empty($row['bed_number']) ? ' (Bed ' . $row['bed_number'] . ')' : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                            <?= date('d M, Y', strtotime($row['visit_date'])) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= !empty($row['expected_in_time']) ? date('h:i A', strtotime($row['expected_in_time'])) : '' ?>
                                            <?= !empty($row['expected_out_time']) ? ' - ' . date('h:i A', strtotime($row['expected_out_time'])) : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['check_in_at'])): ?>
                                            <div class="text-success font-weight-bold" style="font-size: 0.82rem;">
                                                <i class="fa-solid fa-arrow-right-to-bracket mr-1"></i> In: <?= date('h:i A', strtotime($row['check_in_at'])) ?>
                                            </div>
                                            <?php if (!empty($row['check_out_at'])): ?>
                                                <div class="text-secondary font-weight-bold" style="font-size: 0.82rem;">
                                                    <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> Out: <?= date('h:i A', strtotime($row['check_out_at'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge badge-warning-soft" style="font-size: 0.72rem;">Inside Hostel</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.82rem;">Not Checked In</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-dark font-weight-medium" style="font-size: 0.85rem;">
                                            <?= htmlspecialchars($row['id_proof_type'] ?? 'ID Proof') ?>
                                        </div>
                                        <small class="text-muted font-monospace">
                                            <?= !empty($row['id_proof_number']) ? htmlspecialchars($row['id_proof_number']) : '<em>Not logged</em>' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="v-status-pill <?= $status_badge_class ?>">
                                            <?= ucfirst($status) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-dark font-weight-medium" style="font-size: 0.86rem;">
                                            <?= !empty($row['warden_approver_name']) ? htmlspecialchars($row['warden_approver_name']) : '<em class="text-muted">Pending</em>' ?>
                                        </div>
                                        <?php if (!empty($row['warden_approver_code'])): ?>
                                            <small class="text-primary font-weight-bold"><?= htmlspecialchars($row['warden_approver_code']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center btn-print-hide">
                                        <button type="button" class="btn btn-sm btn-outline-primary view-visitor-btn"
                                            data-id="<?= $row['id'] ?>"
                                            data-name="<?= htmlspecialchars($row['name']) ?>"
                                            data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                            data-rel="<?= htmlspecialchars($row['relationship']) ?>"
                                            data-idtype="<?= htmlspecialchars($row['id_proof_type'] ?? 'ID Proof') ?>"
                                            data-idnum="<?= htmlspecialchars($row['id_proof_number'] ?? 'N/A') ?>"
                                            data-date="<?= date('l, d F Y', strtotime($row['visit_date'])) ?>"
                                            data-window="<?= (!empty($row['expected_in_time']) ? date('h:i A', strtotime($row['expected_in_time'])) : '') . (!empty($row['expected_out_time']) ? ' to ' . date('h:i A', strtotime($row['expected_out_time'])) : '') ?>"
                                            data-purpose="<?= htmlspecialchars($row['purpose']) ?>"
                                            data-status="<?= ucfirst($status) ?>"
                                            data-status-class="<?= $status_badge_class ?>"
                                            data-student="<?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?>"
                                            data-student-phone="<?= htmlspecialchars($row['student_phone'] ?? '') ?>"
                                            data-student-room="<?= htmlspecialchars(($row['block_name'] ?? '') . ' - Room ' . ($row['room_number'] ?? 'N/A') . (!empty($row['bed_number']) ? ' (Bed ' . $row['bed_number'] . ')' : '')) ?>"
                                            data-approver="<?= htmlspecialchars($row['warden_approver_name'] ?? 'Pending Approval') ?>"
                                            data-remarks="<?= htmlspecialchars($row['remarks'] ?? 'No approval remarks recorded.') ?>"
                                            data-checkin="<?= !empty($row['check_in_at']) ? date('d M Y, h:i A', strtotime($row['check_in_at'])) : 'Not checked in yet' ?>"
                                            data-checkout="<?= !empty($row['check_out_at']) ? date('d M Y, h:i A', strtotime($row['check_out_at'])) : (!empty($row['check_in_at']) ? 'Still Inside Hostel' : 'N/A') ?>"
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

<!-- Modal: View Visitor Details -->
<div class="modal fade" id="visitorDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title font-weight-bold text-dark d-flex align-items-center mb-0">
                    <i class="fa-solid fa-circle-info text-primary mr-2"></i> Visitor Entry Pass #<span id="modalVisitorId"></span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <!-- Visitor Profile Box -->
                <div class="d-flex flex-wrap justify-content-between align-items-center p-3 mb-4 rounded-14" style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);">
                    <div class="d-flex align-items-center mb-2 mb-md-0">
                        <div class="stat-icon-shape rounded-12 bg-primary text-white mr-3" style="width: 48px; height: 48px;">
                            <i class="fa-solid fa-user-tag"></i>
                        </div>
                        <div>
                            <h5 class="font-weight-bold text-dark mb-0" id="modalVisitorName">Visitor Name</h5>
                            <small class="text-muted d-block" id="modalVisitorMeta">Relationship &bull; Phone</small>
                            <small class="text-primary font-weight-bold" id="modalIdProof">ID Proof Info</small>
                        </div>
                    </div>
                    <div>
                        <span id="modalStatusBadge" class="v-status-pill v-status-approved font-weight-bold">Approved</span>
                    </div>
                </div>

                <!-- Visiting Resident Student Info -->
                <div class="p-3 mb-4 rounded-12 bg-light" style="border: 1px solid #e2e8f0;">
                    <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Visiting Resident Student</small>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <div>
                            <h6 class="font-weight-bold text-dark mb-0" id="modalStudentName">Resident Student</h6>
                            <small class="text-muted" id="modalStudentRoom">Block & Room Details</small>
                        </div>
                        <small class="text-primary font-weight-bold" id="modalStudentPhone">Student Phone</small>
                    </div>
                </div>

                <!-- Schedule & Purpose -->
                <div class="row mb-3">
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Date of Visit</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalVisitDate">-</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Authorized Window</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalVisitWindow">-</p>
                    </div>
                    <div class="col-12 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Purpose of Visit</small>
                        <div class="p-3 bg-light rounded-12 mt-1" style="border: 1px solid #e2e8f0;">
                            <p class="mb-0 text-dark" id="modalPurpose" style="font-size: 0.92rem; line-height: 1.5;">-</p>
                        </div>
                    </div>
                </div>

                <!-- Gate Timestamps & Warden Approval -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Gate Check-In</small>
                        <p class="font-weight-bold text-success mb-0" id="modalCheckin">-</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Gate Check-Out</small>
                        <p class="font-weight-bold text-secondary mb-0" id="modalCheckout">-</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Approved By</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalApprover">-</p>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Warden Approval Remarks</small>
                        <div class="p-3 rounded-12 mt-1" style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);">
                            <p class="mb-0 text-dark font-weight-medium" id="modalRemarks" style="font-size: 0.9rem; line-height: 1.5;">-</p>
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
    if (typeof $.fn.DataTable !== 'undefined') {
        $.fn.dataTable.ext.errMode = 'none';
        if (!$.fn.DataTable.isDataTable('#visitorsTable')) {
            $('#visitorsTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [{ targets: [8], orderable: false }],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search visitors...",
                    lengthMenu: "Show _MENU_ entries",
                    emptyTable: "No visitor entries found in this date range",
                    zeroRecords: "No matching visitor entries found"
                },
                order: [[0, 'desc']] // Order by Visitor ID descending
            });
        }
    }

    // Handle View Details Modal
    $('.view-visitor-btn').on('click', function () {
        var btn = $(this);
        $('#modalVisitorId').text(btn.data('id'));
        $('#modalVisitorName').text(btn.data('name'));
        $('#modalVisitorMeta').text(btn.data('rel') + ' | Phone: ' + btn.data('phone'));
        $('#modalIdProof').text(btn.data('idtype') + ': ' + btn.data('idnum'));

        $('#modalStatusBadge').attr('class', 'v-status-pill ' + btn.data('status-class')).text(btn.data('status'));

        $('#modalStudentName').text(btn.data('student'));
        $('#modalStudentRoom').text(btn.data('student-room'));
        $('#modalStudentPhone').text(btn.data('student-phone'));

        $('#modalVisitDate').text(btn.data('date'));
        $('#modalVisitWindow').text(btn.data('window'));
        $('#modalPurpose').text(btn.data('purpose'));

        $('#modalCheckin').text(btn.data('checkin'));
        $('#modalCheckout').text(btn.data('checkout'));
        $('#modalApprover').text(btn.data('approver'));
        $('#modalRemarks').text(btn.data('remarks'));

        $('#visitorDetailModal').modal('show');
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
