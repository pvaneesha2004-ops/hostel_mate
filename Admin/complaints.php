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
$priority_filter= isset($_GET['priority']) ? strtolower(trim($_GET['priority'])) : 'all';
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
        case 'urgent':
            $priority_filter = 'urgent';
            break;
        case 'all':
            $start_date = '';
            $end_date   = '';
            break;
    }
}

// Build SQL WHERE Conditions
$where_clauses = ["1=1"];

// Date Filtering on c.created_at
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $where_clauses[] = "(DATE(c.created_at) BETWEEN '{$safe_start}' AND '{$safe_end}')";
    $date_scope_text = date('d M Y', strtotime($start_date)) . " to " . date('d M Y', strtotime($end_date));
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $where_clauses[] = "DATE(c.created_at) >= '{$safe_start}'";
    $date_scope_text = "From " . date('d M Y', strtotime($start_date));
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $where_clauses[] = "DATE(c.created_at) <= '{$safe_end}'";
    $date_scope_text = "Until " . date('d M Y', strtotime($end_date));
} else {
    $date_scope_text = "All Recorded Dates";
}

// Status Filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "LOWER(c.status) = '{$safe_status}'";
}

// Priority Filter
if (!empty($priority_filter) && $priority_filter !== 'all') {
    $safe_prio = $conn->real_escape_string($priority_filter);
    $where_clauses[] = "LOWER(c.priority) = '{$safe_prio}'";
}

// Block Filter
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// Text Search
if (!empty($search_query)) {
    $safe_q = $conn->real_escape_string($search_query);
    $where_clauses[] = "(c.title LIKE '%{$safe_q}%' OR c.description LIKE '%{$safe_q}%' OR u.name LIKE '%{$safe_q}%' OR u.phone LIKE '%{$safe_q}%' OR rm.room_number LIKE '%{$safe_q}%' OR c.response LIKE '%{$safe_q}%' OR w.name LIKE '%{$safe_q}%')";
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

// KPI Query (respecting date range)
$kpi_where = ["1=1"];
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $kpi_where[] = "(DATE(c.created_at) BETWEEN '{$safe_start}' AND '{$safe_end}')";
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $kpi_where[] = "DATE(c.created_at) >= '{$safe_start}'";
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $kpi_where[] = "DATE(c.created_at) <= '{$safe_end}'";
}
if ($block_filter > 0) {
    $kpi_where[] = "ra.block_id = {$block_filter}";
}
$kpi_where_sql = implode(' AND ', $kpi_where);

$kpi_sql = "
    SELECT 
        COUNT(c.id) AS total_complaints,
        SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN c.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
        SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN c.priority IN ('high', 'urgent') AND c.status != 'resolved' THEN 1 ELSE 0 END) AS urgent_open_count
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE {$kpi_where_sql}
";
$kpi_res = $conn->query($kpi_sql);
$kpi = ($kpi_res && $kpi_res->num_rows > 0) ? $kpi_res->fetch_assoc() : [];

$stat_total       = intval($kpi['total_complaints'] ?? 0);
$stat_pending     = intval($kpi['pending_count'] ?? 0);
$stat_in_progress = intval($kpi['in_progress_count'] ?? 0);
$stat_resolved    = intval($kpi['resolved_count'] ?? 0);
$stat_rejected    = intval($kpi['rejected_count'] ?? 0);
$stat_urgent_open = intval($kpi['urgent_open_count'] ?? 0);
$resolve_rate     = $stat_total > 0 ? round(($stat_resolved / $stat_total) * 100, 1) : 0;

// Fetch Complaints Roster
$complaints_sql = "
    SELECT 
        c.*,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        st.admission_no,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        bd.bed_number,
        w.name AS warden_handler_name,
        w.code AS warden_handler_code
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    LEFT JOIN students st ON u.id = st.user_id
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
    while ($row = $complaints_res->fetch_assoc()) {
        $complaints_list[] = $row;
    }
}

// Start Output Buffering
ob_start();
?>

<!-- Custom CSS for Admin Complaints View -->
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
.priority-pill {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.28em 0.75em;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.priority-pill-urgent { background: rgba(239, 68, 68, 0.15); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.3); }
.priority-pill-high   { background: rgba(249, 115, 22, 0.15); color: #ea580c; border: 1px solid rgba(249, 115, 22, 0.3); }
.priority-pill-medium { background: rgba(59, 130, 246, 0.15); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.3); }
.priority-pill-low    { background: rgba(100, 116, 139, 0.15); color: #475569; border: 1px solid rgba(100, 116, 139, 0.3); }

.c-status-pill {
    font-size: 0.76rem;
    font-weight: 700;
    padding: 0.35em 0.85em;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.3px;
    text-transform: capitalize;
}
.c-status-pending     { background-color: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.25); }
.c-status-in_progress { background-color: rgba(2, 132, 199, 0.12);  color: #0284c7; border: 1px solid rgba(2, 132, 199, 0.25); }
.c-status-resolved    { background-color: rgba(16, 185, 129, 0.12); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }
.c-status-rejected    { background-color: rgba(239, 68, 68, 0.12);  color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.25); }

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
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.4rem;"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.3px;">Resident Complaints & Issues</h3>
                    <p class="text-muted mb-0" style="font-size: 0.88rem;">
                        Monitor student grievances, resolution timelines, and assigned wardens with date filtering.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-5 col-12 text-md-right btn-print-hide">
            <span class="badge badge-primary-soft mr-2 py-2 px-3" style="font-size: 0.82rem;">
                <i class="fa-regular fa-calendar mr-1"></i> <?= htmlspecialchars($date_scope_text) ?>
            </span>
            <button onclick="window.print()" class="btn btn-outline-secondary shadow-sm btn-sm py-2 px-3" style="border-radius: 10px;">
                <i class="fa-solid fa-print mr-1"></i> Print Log
            </button>
        </div>
    </div>

    <!-- Interactive Date Filtering Card -->
    <div class="filter-card p-3 p-md-4">
        <form method="GET" action="complaints.php" id="complaintsFilterForm">
            <!-- Top Row: Date Presets -->
            <div class="d-flex flex-wrap align-items-center mb-3 pb-3 border-bottom">
                <span class="filter-label mr-3 mb-2 mb-md-0"><i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Presets:</span>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    <a href="complaints.php?preset=today<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'today' || ($start_date === date('Y-m-d') && $end_date === date('Y-m-d'))) ? 'active' : '' ?>">Today</a>
                    <a href="complaints.php?preset=yesterday<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'yesterday') ? 'active' : '' ?>">Yesterday</a>
                    <a href="complaints.php?preset=this_week<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_week') ? 'active' : '' ?>">This Week</a>
                    <a href="complaints.php?preset=last_7_days<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'last_7_days') ? 'active' : '' ?>">Last 7 Days</a>
                    <a href="complaints.php?preset=this_month<?= !empty($status_filter) && $status_filter !== 'all' ? '&status=' . urlencode($status_filter) : '' ?><?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_month') ? 'active' : '' ?>">This Month</a>
                    <a href="complaints.php?preset=pending" class="preset-pill <?= ($preset === 'pending' || $status_filter === 'pending') ? 'active' : '' ?>">Pending Only</a>
                    <a href="complaints.php?preset=urgent" class="preset-pill <?= ($preset === 'urgent' || $priority_filter === 'urgent') ? 'active' : '' ?>">Urgent Only</a>
                    <a href="complaints.php?preset=all" class="preset-pill <?= ($preset === 'all' || (empty($start_date) && empty($end_date) && $status_filter === 'all' && $priority_filter === 'all')) ? 'active' : '' ?>">All Recorded</a>
                </div>
            </div>

            <!-- Second Row: Inputs (Start Date, End Date, Status, Priority, Block, Search) -->
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
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-solid fa-triangle-exclamation text-warning mr-1"></i> Priority</label>
                    <select name="priority" class="form-control" style="font-weight: 500;">
                        <option value="all" <?= $priority_filter === 'all' ? 'selected' : '' ?>>All Priorities</option>
                        <option value="urgent" <?= $priority_filter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                        <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-12 d-flex">
                    <button type="submit" class="btn btn-primary w-100 mr-2 shadow-sm d-flex align-items-center justify-content-center" style="font-weight: 600;">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i> Filter
                    </button>
                    <a href="complaints.php" class="btn btn-secondary shadow-sm d-flex align-items-center justify-content-center" title="Reset Filters" style="min-width: 44px; padding: 0.6rem 0.85rem;">
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
                    <i class="fa-solid fa-ticket"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Complaints</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_total) ?></h3>
                    <small class="text-primary font-weight-bold"><?= $resolve_rate ?>% Resolution Rate</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-warning-soft text-warning">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Pending / In Progress</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_pending + $stat_in_progress) ?></h3>
                    <small class="text-warning font-weight-bold"><?= $stat_pending ?> Pending &bull; <?= $stat_in_progress ?> In Progress</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-success-soft text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Resolved</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_resolved) ?></h3>
                    <small class="text-success font-weight-bold"><?= $stat_total > 0 ? round(($stat_resolved / $stat_total) * 100, 1) : 0 ?>% resolved</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-danger-soft text-danger">
                    <i class="fa-solid fa-fire-flame-curved"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Urgent Unresolved</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_urgent_open) ?></h3>
                    <small class="text-danger font-weight-bold"><?= $stat_rejected ?> rejected</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-2 mb-md-0 font-weight-bold text-dark">
                    <i class="fa-solid fa-list-check text-primary mr-2"></i> Complaints Record Roster
                </h5>
                <span class="text-muted" style="font-size: 0.84rem;">
                    Showing <strong><?= count($complaints_list) ?></strong> record(s) for <?= htmlspecialchars($date_scope_text) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="complaintsTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Resident Student</th>
                            <th>Complaint Title & Category</th>
                            <th>Priority</th>
                            <th>Date Filed</th>
                            <th>Status</th>
                            <th>Assigned Warden</th>
                            <th class="text-center btn-print-hide" style="width: 90px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints_list as $idx => $row): 
                            $status = strtolower($row['status'] ?? 'pending');
                                $prio   = strtolower($row['priority'] ?? 'medium');

                                $status_badge_class = 'c-status-pending';
                                if ($status === 'in_progress') $status_badge_class = 'c-status-in_progress';
                                elseif ($status === 'resolved') $status_badge_class = 'c-status-resolved';
                                elseif ($status === 'rejected') $status_badge_class = 'c-status-rejected';

                                $prio_badge_class = 'priority-pill-medium';
                                if ($prio === 'urgent') $prio_badge_class = 'priority-pill-urgent';
                                elseif ($prio === 'high') $prio_badge_class = 'priority-pill-high';
                                elseif ($prio === 'low') $prio_badge_class = 'priority-pill-low';

                                $first_letter = !empty($row['student_name']) ? strtoupper(substr($row['student_name'], 0, 1)) : 'S';
                            ?>
                                <tr>
                                    <td class="font-weight-bold text-muted"><?= $row['id'] ?></td>
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
                                                    <?= !empty($row['block_name']) ? htmlspecialchars($row['block_name']) . ' &bull; ' : '' ?>
                                                    Room <?= htmlspecialchars($row['room_number'] ?? 'N/A') ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.9rem;">
                                            <?= htmlspecialchars($row['title']) ?>
                                        </div>
                                        <small class="text-muted" style="max-width: 260px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            <?= htmlspecialchars(strip_tags($row['description'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="priority-pill <?= $prio_badge_class ?>">
                                            <i class="fa-solid fa-flag mr-1" style="font-size: 0.62rem;"></i> <?= ucfirst($prio) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                            <?= date('d M, Y', strtotime($row['created_at'])) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('h:i A', strtotime($row['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="c-status-pill <?= $status_badge_class ?>">
                                            <?= ucfirst(str_replace('_', ' ', $status)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-dark font-weight-medium" style="font-size: 0.86rem;">
                                            <?= !empty($row['warden_handler_name']) ? htmlspecialchars($row['warden_handler_name']) : '<em class="text-muted">Unassigned</em>' ?>
                                        </div>
                                        <?php if (!empty($row['warden_handler_code'])): ?>
                                            <small class="text-primary font-weight-bold"><?= htmlspecialchars($row['warden_handler_code']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center btn-print-hide">
                                        <button type="button" class="btn btn-sm btn-outline-primary view-complaint-btn"
                                            data-id="<?= $row['id'] ?>"
                                            data-title="<?= htmlspecialchars($row['title']) ?>"
                                            data-desc="<?= htmlspecialchars($row['description']) ?>"
                                            data-image="<?= !empty($row['image']) ? '../complaints/' . htmlspecialchars($row['image']) : '' ?>"
                                            data-prio="<?= ucfirst($prio) ?>"
                                            data-prio-class="<?= $prio_badge_class ?>"
                                            data-status="<?= ucfirst(str_replace('_', ' ', $status)) ?>"
                                            data-status-class="<?= $status_badge_class ?>"
                                            data-student="<?= htmlspecialchars($row['student_name'] ?? 'Unknown') ?>"
                                            data-email="<?= htmlspecialchars($row['student_email'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($row['student_phone'] ?? '') ?>"
                                            data-room="<?= htmlspecialchars(($row['block_name'] ?? '') . ' - Room ' . ($row['room_number'] ?? 'N/A') . (!empty($row['bed_number']) ? ' (Bed ' . $row['bed_number'] . ')' : '')) ?>"
                                            data-filed="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>"
                                            data-warden="<?= htmlspecialchars($row['warden_handler_name'] ?? 'Not yet assigned') ?>"
                                            data-response="<?= htmlspecialchars($row['response'] ?? 'No resolution response logged yet.') ?>"
                                            data-resolved="<?= !empty($row['resolved_at']) ? date('d M Y, h:i A', strtotime($row['resolved_at'])) : 'Pending Resolution' ?>"
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

<!-- Modal: View Complaint Details -->
<div class="modal fade" id="complaintDetailModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-bottom py-3 px-4" style="background: #f8fafc; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title font-weight-bold text-dark d-flex align-items-center mb-0">
                    <i class="fa-solid fa-circle-info text-primary mr-2"></i> Complaint Details #<span id="modalComplaintId"></span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <!-- Resident & Priority Pill Row -->
                <div class="d-flex flex-wrap justify-content-between align-items-center p-3 mb-4 rounded-14" style="background: rgba(37, 99, 235, 0.05); border: 1px solid rgba(37, 99, 235, 0.15);">
                    <div class="d-flex align-items-center mb-2 mb-md-0">
                        <div class="stat-icon-shape rounded-12 bg-primary text-white mr-3" style="width: 46px; height: 46px;">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <div>
                            <h5 class="font-weight-bold text-dark mb-0" id="modalStudentName">Student Name</h5>
                            <small class="text-muted d-block" id="modalRoomInfo">Block &bull; Room</small>
                            <small class="text-primary font-weight-bold" id="modalStudentContact">Phone &bull; Email</small>
                        </div>
                    </div>
                    <div class="text-md-right">
                        <span id="modalPrioBadge" class="priority-pill priority-pill-medium mr-1">Medium</span>
                        <span id="modalStatusBadge" class="c-status-pill c-status-pending font-weight-bold">Pending</span>
                    </div>
                </div>

                <!-- Complaint Content -->
                <div class="mb-4">
                    <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Issue Title</small>
                    <h5 class="font-weight-bold text-dark mt-1" id="modalTitle">Title Here</h5>
                    
                    <small class="text-muted text-uppercase font-weight-bold mt-3 d-block" style="font-size: 0.72rem;">Issue Description</small>
                    <div class="p-3 bg-light rounded-12 mt-1" style="border: 1px solid #e2e8f0;">
                        <p class="mb-0 text-dark" id="modalDesc" style="font-size: 0.92rem; line-height: 1.6; white-space: pre-wrap;">-</p>
                    </div>
                </div>

                <!-- Attached Image (if any) -->
                <div id="modalImageContainer" class="mb-4" style="display: none;">
                    <small class="text-muted text-uppercase font-weight-bold d-block mb-2" style="font-size: 0.72rem;">Attached Photo Evidence</small>
                    <a id="modalImageLink" href="#" target="_blank">
                        <img id="modalImage" src="" class="img-fluid rounded-12 shadow-sm border" style="max-height: 240px; object-fit: cover;" alt="Evidence">
                    </a>
                </div>

                <!-- Metadata & Warden Response -->
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Date Filed</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalFiledDate">-</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Assigned Warden</small>
                        <p class="font-weight-bold text-dark mb-0" id="modalWarden">-</p>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Warden Resolution / Action Remarks</small>
                        <div class="p-3 rounded-12 mt-1" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25);">
                            <p class="mb-0 text-dark font-weight-medium" id="modalResponse" style="font-size: 0.92rem; line-height: 1.5;">-</p>
                            <small class="text-muted d-block mt-2">
                                <i class="fa-regular fa-clock mr-1"></i> Resolved At: <span id="modalResolvedDate" class="font-weight-bold text-dark">-</span>
                            </small>
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
        if (!$.fn.DataTable.isDataTable('#complaintsTable')) {
            $('#complaintsTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [{ targets: [7], orderable: false }],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search complaints...",
                    lengthMenu: "Show _MENU_ entries",
                    emptyTable: "No complaints found within this date period",
                    zeroRecords: "No matching complaints found"
                },
                order: [[0, 'desc']] // Order by Complaint ID descending
            });
        }
    }

    // Handle View Details Modal
    $('.view-complaint-btn').on('click', function () {
        var btn = $(this);
        $('#modalComplaintId').text(btn.data('id'));
        $('#modalStudentName').text(btn.data('student'));
        $('#modalRoomInfo').text(btn.data('room'));
        $('#modalStudentContact').text('Phone: ' + btn.data('phone') + ' | Email: ' + btn.data('email'));
        
        $('#modalPrioBadge').attr('class', 'priority-pill ' + btn.data('prio-class')).text(btn.data('prio'));
        $('#modalStatusBadge').attr('class', 'c-status-pill ' + btn.data('status-class')).text(btn.data('status'));

        $('#modalTitle').text(btn.data('title'));
        $('#modalDesc').text(btn.data('desc'));

        var imgUrl = btn.data('image');
        if (imgUrl && imgUrl.trim() !== '') {
            $('#modalImage').attr('src', imgUrl);
            $('#modalImageLink').attr('href', imgUrl);
            $('#modalImageContainer').show();
        } else {
            $('#modalImageContainer').hide();
        }

        $('#modalFiledDate').text(btn.data('filed'));
        $('#modalWarden').text(btn.data('warden'));
        $('#modalResponse').text(btn.data('response'));
        $('#modalResolvedDate').text(btn.data('resolved'));

        $('#complaintDetailModal').modal('show');
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
