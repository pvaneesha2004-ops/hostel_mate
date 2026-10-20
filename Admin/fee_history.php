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
$block_filter = isset($_GET['block_id']) ? intval($_GET['block_id']) : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$preset       = isset($_GET['preset']) ? trim($_GET['preset']) : '';

// Apply Presets
if (!empty($preset)) {
    switch ($preset) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date   = date('Y-m-d');
            break;
        case 'this_week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date   = date('Y-m-d');
            break;
        case 'this_month':
            $start_date = date('Y-m-01');
            $end_date   = date('Y-m-t');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date   = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'last_30_days':
            $start_date = date('Y-m-d', strtotime('-29 days'));
            $end_date   = date('Y-m-d');
            break;
        case 'this_year':
            $start_date = date('Y-01-01');
            $end_date   = date('Y-12-31');
            break;
        case 'all':
            $start_date = '';
            $end_date   = '';
            break;
    }
}

// Build SQL WHERE Conditions
$where_clauses = ["1=1"];

// Date Filtering on sfh.date (or fallback to DATE(sfh.created_at))
if (!empty($start_date) && !empty($end_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $safe_end   = $conn->real_escape_string($end_date);
    $where_clauses[] = "(sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}')";
    $date_scope_text = date('d M Y', strtotime($start_date)) . " to " . date('d M Y', strtotime($end_date));
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $where_clauses[] = "sfh.date >= '{$safe_start}'";
    $date_scope_text = "From " . date('d M Y', strtotime($start_date));
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $where_clauses[] = "sfh.date <= '{$safe_end}'";
    $date_scope_text = "Until " . date('d M Y', strtotime($end_date));
} else {
    $date_scope_text = "All Recorded Dates";
}

// Block Filter
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// Text Search
if (!empty($search_query)) {
    $safe_q = $conn->real_escape_string($search_query);
    $where_clauses[] = "(u.name LIKE '%{$safe_q}%' OR u.email LIKE '%{$safe_q}%' OR u.phone LIKE '%{$safe_q}%' OR st.admission_no LIKE '%{$safe_q}%' OR rm.room_number LIKE '%{$safe_q}%' OR sfh.price LIKE '%{$safe_q}%')";
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
    $kpi_where[] = "(sfh.date BETWEEN '{$safe_start}' AND '{$safe_end}')";
} elseif (!empty($start_date)) {
    $safe_start = $conn->real_escape_string($start_date);
    $kpi_where[] = "sfh.date >= '{$safe_start}'";
} elseif (!empty($end_date)) {
    $safe_end = $conn->real_escape_string($end_date);
    $kpi_where[] = "sfh.date <= '{$safe_end}'";
}
if ($block_filter > 0) {
    $kpi_where[] = "ra.block_id = {$block_filter}";
}
$kpi_where_sql = implode(' AND ', $kpi_where);

$kpi_sql = "
    SELECT 
        COUNT(sfh.id) AS total_records,
        COALESCE(SUM(sfh.price), 0) AS total_revenue,
        COALESCE(AVG(sfh.price), 0) AS avg_fee,
        COUNT(DISTINCT sfh.student_id) AS unique_students
    FROM student_fee_history sfh
    JOIN users u ON sfh.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    WHERE {$kpi_where_sql}
";
$kpi_res = $conn->query($kpi_sql);
$kpi = ($kpi_res && $kpi_res->num_rows > 0) ? $kpi_res->fetch_assoc() : [];

$stat_total_records   = intval($kpi['total_records'] ?? 0);
$stat_total_revenue   = floatval($kpi['total_revenue'] ?? 0);
$stat_avg_fee         = floatval($kpi['avg_fee'] ?? 0);
$stat_unique_students = intval($kpi['unique_students'] ?? 0);

// Fetch Student Fee History Roster
$fees_sql = "
    SELECT 
        sfh.id AS fee_id,
        sfh.student_id,
        sfh.price,
        sfh.date AS fee_date,
        sfh.created_at,
        sfh.updated_at,
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
        rm.room_type,
        rm.price AS room_standard_price,
        bd.bed_number,
        ra.allocated_date,
        ra.status AS allocation_status
    FROM student_fee_history sfh
    JOIN users u ON sfh.student_id = u.id
    LEFT JOIN students st ON u.id = st.user_id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE {$where_sql}
    ORDER BY sfh.date DESC, sfh.id DESC
";
$fees_res = $conn->query($fees_sql);
$fees_list = [];
if ($fees_res && $fees_res->num_rows > 0) {
    while ($row = $fees_res->fetch_assoc()) {
        $fees_list[] = $row;
    }
}

// Start Output Buffering
ob_start();
?>

<!-- Custom CSS for Admin Fee History View -->
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
.fee-amount-badge {
    font-size: 0.98rem;
    font-weight: 800;
    color: #0f172a;
    font-feature-settings: "tnum";
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
                    <i class="fa-solid fa-receipt" style="font-size: 1.4rem;"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.3px;">Student Fee History</h3>
                    <p class="text-muted mb-0" style="font-size: 0.88rem;">
                        View hostel room allocations, fee billing transactions, and generated receipts with date filtering.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-md-5 col-12 text-md-right btn-print-hide">
            <span class="badge badge-primary-soft mr-2 py-2 px-3" style="font-size: 0.82rem;">
                <i class="fa-regular fa-calendar-check mr-1"></i> <?= htmlspecialchars($date_scope_text) ?>
            </span>
            <button onclick="window.print()" class="btn btn-outline-secondary shadow-sm btn-sm py-2 px-3" style="border-radius: 10px;">
                <i class="fa-solid fa-print mr-1"></i> Print Fee Report
            </button>
        </div>
    </div>

    <!-- Interactive Date Filtering Card -->
    <div class="filter-card p-3 p-md-4">
        <form method="GET" action="fee_history.php" id="feeFilterForm">
            <!-- Top Row: Date Presets -->
            <div class="d-flex flex-wrap align-items-center mb-3 pb-3 border-bottom">
                <span class="filter-label mr-3 mb-2 mb-md-0"><i class="fa-solid fa-bolt text-warning mr-1"></i> Quick Presets:</span>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    <a href="fee_history.php?preset=today<?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'today' || ($start_date === date('Y-m-d') && $end_date === date('Y-m-d'))) ? 'active' : '' ?>">Today</a>
                    <a href="fee_history.php?preset=this_week<?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_week') ? 'active' : '' ?>">This Week</a>
                    <a href="fee_history.php?preset=this_month<?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_month') ? 'active' : '' ?>">This Month</a>
                    <a href="fee_history.php?preset=last_month<?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'last_month') ? 'active' : '' ?>">Last Month</a>
                    <a href="fee_history.php?preset=last_30_days<?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'last_30_days') ? 'active' : '' ?>">Last 30 Days</a>
                    <a href="fee_history.php?preset=this_year<?= $block_filter > 0 ? '&block_id=' . $block_filter : '' ?>" class="preset-pill <?= ($preset === 'this_year') ? 'active' : '' ?>">This Year</a>
                    <a href="fee_history.php?preset=all" class="preset-pill <?= ($preset === 'all' || (empty($start_date) && empty($end_date))) ? 'active' : '' ?>">All Recorded</a>
                </div>
            </div>

            <!-- Second Row: Inputs (Start Date, End Date, Block, Search) -->
            <div class="row align-items-end">
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-regular fa-calendar-alt text-primary mr-1"></i> Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" style="font-weight: 500;">
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-regular fa-calendar-alt text-primary mr-1"></i> End Date</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" style="font-weight: 500;">
                </div>
                <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
                    <label class="filter-label"><i class="fa-solid fa-building text-secondary mr-1"></i> Student Block</label>
                    <select name="block_id" class="form-control" style="font-weight: 500;">
                        <option value="0">All Hostel Blocks</option>
                        <?php foreach ($all_blocks as $blk): ?>
                            <option value="<?= $blk['id'] ?>" <?= $block_filter == $blk['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($blk['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6 col-12 d-flex">
                    <button type="submit" class="btn btn-primary w-100 mr-2 shadow-sm d-flex align-items-center justify-content-center" style="font-weight: 600;">
                        <i class="fa-solid fa-magnifying-glass mr-1"></i> Filter
                    </button>
                    <a href="fee_history.php" class="btn btn-secondary shadow-sm d-flex align-items-center justify-content-center" title="Reset Filters" style="min-width: 44px; padding: 0.6rem 0.85rem;">
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
                <div class="stat-kpi-icon bg-success-soft text-success">
                    <i class="fa-solid fa-indian-rupee-sign"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Fees Logged</span>
                    <h3 class="font-weight-bold text-dark mb-0">₹ <?= number_format($stat_total_revenue, 2) ?></h3>
                    <small class="text-success font-weight-bold"><?= $stat_total_records ?> transaction(s)</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-primary-soft text-primary">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Total Transactions</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_total_records) ?></h3>
                    <small class="text-primary font-weight-bold">Recorded in period</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-info-soft text-info">
                    <i class="fa-solid fa-calculator"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Average Fee</span>
                    <h3 class="font-weight-bold text-dark mb-0">₹ <?= number_format($stat_avg_fee, 2) ?></h3>
                    <small class="text-info font-weight-bold">Per room allocation</small>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3 mb-xl-0">
            <div class="stat-kpi-card">
                <div class="stat-kpi-icon bg-warning-soft text-warning">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.82rem; font-weight: 600; text-transform: uppercase;">Paying Residents</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?= number_format($stat_unique_students) ?></h3>
                    <small class="text-warning font-weight-bold">Distinct students</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                <h5 class="card-title mb-2 mb-md-0 font-weight-bold text-dark">
                    <i class="fa-solid fa-list-check text-primary mr-2"></i> Student Fee Records Roster
                </h5>
                <span class="text-muted" style="font-size: 0.84rem;">
                    Showing <strong><?= count($fees_list) ?></strong> fee record(s) for <?= htmlspecialchars($date_scope_text) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="feeTable">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Student Resident</th>
                            <th>Room & Bed Allocation</th>
                            <th>Fee Amount</th>
                            <th>Billing Date</th>
                            <th>Logged At</th>
                            <th>Status</th>
                            <th class="text-center btn-print-hide" style="width: 100px;">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fees_list as $idx => $row): 
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
                                                <?= !empty($row['room_type']) ? ' &bull; ' . ucfirst($row['room_type']) : '' ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="badge badge-secondary py-1 px-2" style="font-size: 0.75rem;">Not Allocated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fee-amount-badge text-success">
                                            ₹ <?= number_format($row['price'], 2) ?>
                                        </div>
                                        <small class="text-muted">Hostel Rent / Allocation</small>
                                    </td>
                                    <td>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                            <?= date('d M, Y', strtotime($row['fee_date'])) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= date('l', strtotime($row['fee_date'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="text-dark font-weight-medium" style="font-size: 0.85rem;">
                                            <?= !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '-' ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= !empty($row['created_at']) ? date('h:i A', strtotime($row['created_at'])) : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge badge-success-soft" style="font-size: 0.75rem; letter-spacing: 0.3px;">
                                            <i class="fa-solid fa-check mr-1"></i> Recorded
                                        </span>
                                    </td>
                                    <td class="text-center btn-print-hide">
                                        <button type="button" class="btn btn-sm btn-outline-primary view-fee-btn"
                                            data-id="<?= $row['fee_id'] ?>"
                                            data-receipt="<?= $receipt_no ?>"
                                            data-name="<?= htmlspecialchars($row['student_name'] ?? '') ?>"
                                            data-adm="<?= htmlspecialchars($row['admission_no'] ?? 'N/A') ?>"
                                            data-email="<?= htmlspecialchars($row['student_email'] ?? '') ?>"
                                            data-phone="<?= htmlspecialchars($row['student_phone'] ?? '') ?>"
                                            data-course="<?= htmlspecialchars(($row['course'] ?? '') . ' ' . ($row['department'] ?? '')) ?>"
                                            data-block="<?= htmlspecialchars($row['block_name'] ?? 'Not Allocated') ?>"
                                            data-floor="<?= htmlspecialchars($row['floor_name'] ?? 'N/A') ?>"
                                            data-room="<?= htmlspecialchars($row['room_number'] ?? 'N/A') ?>"
                                            data-bed="<?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>"
                                            data-price="<?= number_format($row['price'], 2) ?>"
                                            data-date="<?= date('d F, Y', strtotime($row['fee_date'])) ?>"
                                            data-time="<?= !empty($row['created_at']) ? date('d M Y, h:i A', strtotime($row['created_at'])) : 'N/A' ?>"
                                            title="View Receipt">
                                            <i class="fa-solid fa-file-invoice"></i> Receipt
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

<!-- Modal: View Fee Receipt -->
<div class="modal fade" id="feeReceiptModal" tabindex="-1" role="dialog" aria-hidden="true">
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
                    
                    <!-- Institutional Header -->
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
                <button type="button" class="btn btn-outline-secondary" onclick="printReceiptElement('printableReceiptArea')">
                    <i class="fa-solid fa-print mr-1"></i> Print Receipt
                </button>
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
        if (!$.fn.DataTable.isDataTable('#feeTable')) {
            $('#feeTable').DataTable({
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                columnDefs: [{ targets: [7], orderable: false }],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search fee records...",
                    lengthMenu: "Show _MENU_ entries",
                    emptyTable: "No fee payment records found within this date period",
                    zeroRecords: "No matching payment records found"
                },
                order: [[4, 'desc']] // Order by Billing Date descending
            });
        }
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
include 'main_master.php';
?>
