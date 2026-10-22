<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$admin_id = intval($_SESSION['admin_id']);
$admin_name = $_SESSION['admin_name'] ?? 'System Administrator';

// Handle Leave Review / Status Change by Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_update_leave_status') {
    $leave_id = intval($_POST['leave_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($leave_id > 0 && in_array($new_status, ['approved', 'rejected'])) {
        // Fetch current leave request data
        $stmt_check = $conn->prepare("
            SELECT lr.*, u.name AS student_name 
            FROM leave_requests lr 
            JOIN users u ON lr.student_id = u.id 
            WHERE lr.id = ? 
            LIMIT 1
        ");
        $stmt_check->bind_param("i", $leave_id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check && $leave = $res_check->fetch_assoc()) {
            $student_id = intval($leave['student_id']);
            $start_date = $leave['start_date'];
            $end_date = $leave['end_date'];
            $prev_status = $leave['status'];

            // 1. Update leave_requests table
            $update_stmt = $conn->prepare("
                UPDATE leave_requests 
                SET status = ?, reviewed_by = ?, reviewed_at = CURDATE(), remarks = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $update_stmt->bind_param("sisi", $new_status, $admin_id, $remarks, $leave_id);

            if ($update_stmt->execute()) {
                // 2. If APPROVED: insert absent records into attendance table for each day in date range
                if ($new_status === 'approved') {
                    $start = new DateTime($start_date);
                    $end = new DateTime($end_date);
                    $end->modify('+1 day'); // Include end_date in period
                    $period = new DatePeriod($start, new DateInterval('P1D'), $end);

                    $att_remarks = "Approved Leave #{$leave_id}" . (!empty($remarks) ? ": " . $remarks : "");
                    $inserted_days = 0;

                    foreach ($period as $dt) {
                        $att_date = $dt->format('Y-m-d');
                        $att_stmt = $conn->prepare("
                            INSERT INTO attendance (student_id, attendance_date, status, marked_by, remarks, created_at, updated_at)
                            VALUES (?, ?, 'absent', ?, ?, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE 
                                status = 'absent', 
                                marked_by = VALUES(marked_by), 
                                remarks = VALUES(remarks), 
                                updated_at = NOW()
                        ");
                        $att_stmt->bind_param("isss", $student_id, $att_date, $admin_name, $att_remarks);
                        $att_stmt->execute();
                        $inserted_days++;
                    }

                    $_SESSION['msg'] = "Leave request #{$leave_id} for " . htmlspecialchars($leave['student_name']) . " was APPROVED! {$inserted_days} day(s) marked as 'Absent' in the attendance register.";
                } else {
                    // 3. If REJECTED: clean up any previously created attendance records for this leave
                    if ($prev_status === 'approved') {
                        $leave_pattern = "%Leave #{$leave_id}%";
                        $del_stmt = $conn->prepare("
                            DELETE FROM attendance 
                            WHERE student_id = ? 
                              AND attendance_date >= ? 
                              AND attendance_date <= ? 
                              AND (remarks LIKE ? OR remarks LIKE '%Approved Leave%')
                        ");
                        $del_stmt->bind_param("isss", $student_id, $start_date, $end_date, $leave_pattern);
                        $del_stmt->execute();
                    }
                    $_SESSION['msg'] = "Leave request #{$leave_id} for " . htmlspecialchars($leave['student_name']) . " was marked as REJECTED.";
                }
            } else {
                $_SESSION['error'] = "Failed to update leave status: " . $conn->error;
            }
        } else {
            $_SESSION['error'] = "Leave request not found.";
        }
    } else {
        $_SESSION['error'] = "Invalid status review parameters.";
    }
    header("Location: leave_requests.php" . (!empty($_GET['filter']) ? "?filter=" . urlencode($_GET['filter']) : ""));
    exit();
}

// Handle Admin Delete of Leave Request
if (isset($_GET['action']) && $_GET['action'] === 'delete_leave') {
    $del_id = intval($_GET['id'] ?? 0);
    if ($del_id > 0) {
        // Fetch leave details first to clean up attendance if approved
        $l_query = $conn->query("SELECT student_id, start_date, end_date, status FROM leave_requests WHERE id = $del_id");
        if ($l_query && $l_row = $l_query->fetch_assoc()) {
            if ($l_row['status'] === 'approved') {
                $sid = intval($l_row['student_id']);
                $s_date = $l_row['start_date'];
                $e_date = $l_row['end_date'];
                $leave_pattern = "%Leave #{$del_id}%";
                $del_att = $conn->prepare("
                    DELETE FROM attendance 
                    WHERE student_id = ? 
                      AND attendance_date >= ? 
                      AND attendance_date <= ? 
                      AND (remarks LIKE ? OR remarks LIKE '%Approved Leave%')
                ");
                $del_att->bind_param("isss", $sid, $s_date, $e_date, $leave_pattern);
                $del_att->execute();
            }

            $del_req = $conn->prepare("DELETE FROM leave_requests WHERE id = ?");
            $del_req->bind_param("i", $del_id);
            if ($del_req->execute()) {
                $_SESSION['msg'] = "Leave request #{$del_id} and associated attendance entries were deleted.";
            } else {
                $_SESSION['error'] = "Failed to delete leave request.";
            }
        }
    }
    header("Location: leave_requests.php" . (!empty($_GET['filter']) ? "?filter=" . urlencode($_GET['filter']) : ""));
    exit();
}

// Filter logic
$filter = trim($_GET['filter'] ?? 'all');
$where_clauses = [];
if (in_array($filter, ['pending', 'approved', 'rejected', 'cancelled'])) {
    $where_clauses[] = "lr.status = '" . $conn->real_escape_string($filter) . "'";
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Metrics Query
$metrics_res = $conn->query("
    SELECT 
        COUNT(*) AS total_leaves,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_leaves,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_leaves,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_leaves,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_leaves
    FROM leave_requests
");
$metrics = ($metrics_res && $metrics_res->num_rows > 0) ? $metrics_res->fetch_assoc() : [
    'total_leaves' => 0, 'pending_leaves' => 0, 'approved_leaves' => 0, 'rejected_leaves' => 0, 'cancelled_leaves' => 0
];

// Fetch Leave Requests with Student Profile, Room Details, Guardian & Reviewer Info
$query = "
    SELECT lr.*, 
           u.name AS student_name,
           u.email AS student_email,
           u.phone AS student_phone,
           u.profile_image,
           u.language AS student_language,
           u.room_type AS student_room_type,
           rm.room_number,
           bd.bed_number,
           blk.name AS block_name,
           flr.name AS floor_name,
           g.name AS guardian_name,
           g.phone AS guardian_phone,
           g.relationship AS guardian_relationship,
           COALESCE(w_rev.name, u_rev.name, 'Authority') AS reviewer_name,
           CASE 
               WHEN w_rev.name IS NOT NULL THEN 'Warden'
               WHEN u_rev.name IS NOT NULL THEN 'Administrator'
               ELSE 'System'
           END AS reviewer_role
    FROM leave_requests lr
    JOIN users u ON lr.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN student_guardians g ON u.id = g.student_id
    LEFT JOIN wardens w_rev ON lr.reviewed_by = w_rev.id
    LEFT JOIN users u_rev ON lr.reviewed_by = u_rev.id
    $where_sql
    ORDER BY CASE WHEN lr.status = 'pending' THEN 0 ELSE 1 END, lr.id DESC
";
$leaves = $conn->query($query);

ob_start();
?>

<!-- Custom CSS for Admin Leave Requests -->
<style>
    .kpi-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
        background: #ffffff;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
    }
    .kpi-icon-wrap {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    .badge-status {
        padding: 5px 12px;
        font-size: 0.76rem;
        font-weight: 700;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    .badge-status-pending {
        background-color: #fffbeb;
        color: #d97706;
        border: 1px solid #fde68a;
    }
    .badge-status-approved {
        background-color: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    .badge-status-rejected {
        background-color: #fef2f2;
        color: #dc2626;
        border: 1px solid #fecaca;
    }
    .badge-status-cancelled {
        background-color: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
    }
    .filter-pill {
        border-radius: 50px;
        padding: 6px 16px;
        font-size: 0.82rem;
        font-weight: 600;
        text-decoration: none !important;
        transition: all 0.2s ease;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .filter-pill:hover {
        background: #f8fafc;
        color: #0f172a;
    }
    .filter-pill.active {
        background: #2563eb !important;
        border-color: #2563eb !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
    }
    .pulse-indicator {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #f59e0b;
        display: inline-block;
        animation: pulseAnimation 1.6s infinite;
    }
    @keyframes pulseAnimation {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
    }
    .btn-action-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        transition: all 0.2s ease;
        border: none;
    }
    .btn-action-circle:hover {
        transform: scale(1.1);
    }
    .detail-label {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        font-weight: 700;
        margin-bottom: 2px;
    }
    .detail-value {
        font-size: 0.92rem;
        color: #0f172a;
        font-weight: 600;
    }
    .leave-timeline-card {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 12px;
        padding: 14px 18px;
    }
    .avatar-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        background: #e2e8f0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        color: #475569;
    }
</style>

<div class="row">
    <div class="col-12">
        <!-- Page Header -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h3 class="font-weight-bold text-dark mb-1">
                    <i class="fa-solid fa-person-walking-arrow-right text-primary mr-2"></i>Student Leave Management
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Centralized console to review student outstation leaves, verify hostel checkouts, and monitor attendance synchronization.
                </p>
            </div>
            <div class="mt-2 mt-md-0">
                <span class="badge badge-light border px-3 py-2 text-muted" style="border-radius: 50px; font-weight: 600;">
                    <i class="fa-regular fa-clock mr-1 text-primary"></i> <?= date('d M Y') ?>
                </span>
            </div>
        </div>

        <!-- Success/Error Alerts -->
        <?php if (isset($_SESSION['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-circle-check mr-2"></i> <?= htmlspecialchars($_SESSION['msg']) ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php unset($_SESSION['msg']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius: 12px;">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- KPI Metrics Row -->
        <div class="row mb-4">
            <div class="col-sm-6 col-xl-3 mb-3 mb-xl-0">
                <div class="card kpi-card">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase" style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px;">Total Requests</span>
                            <h3 class="font-weight-bold text-dark mt-1 mb-0"><?= number_format($metrics['total_leaves']) ?></h3>
                            <small class="text-muted">Hostel all-time</small>
                        </div>
                        <div class="kpi-icon-wrap" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3 mb-3 mb-xl-0">
                <div class="card kpi-card">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase" style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px;">Pending Review</span>
                            <h3 class="font-weight-bold text-warning mt-1 mb-0 d-flex align-items-center">
                                <?= number_format($metrics['pending_leaves']) ?>
                                <?php if ($metrics['pending_leaves'] > 0): ?>
                                    <span class="pulse-indicator ml-2"></span>
                                <?php endif; ?>
                            </h3>
                            <small class="text-warning font-weight-bold">Requires decision</small>
                        </div>
                        <div class="kpi-icon-wrap" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3 mb-3 mb-sm-0">
                <div class="card kpi-card">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase" style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px;">Approved Leaves</span>
                            <h3 class="font-weight-bold text-success mt-1 mb-0"><?= number_format($metrics['approved_leaves']) ?></h3>
                            <small class="text-success font-weight-bold">Synced to Attendance</small>
                        </div>
                        <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3">
                <div class="card kpi-card">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase" style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px;">Rejected / Cancelled</span>
                            <h3 class="font-weight-bold text-danger mt-1 mb-0">
                                <?= number_format($metrics['rejected_leaves'] + $metrics['cancelled_leaves']) ?>
                            </h3>
                            <small class="text-muted"><?= $metrics['rejected_leaves'] ?> rejected, <?= $metrics['cancelled_leaves'] ?> cancelled</small>
                        </div>
                        <div class="kpi-icon-wrap" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                            <i class="fa-solid fa-ban"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Pills Bar -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="text-muted font-weight-bold mr-2" style="font-size: 0.82rem;">Filter Status:</span>
                <a href="leave_requests.php?filter=all" class="filter-pill <?= ($filter === 'all') ? 'active' : '' ?>">
                    All <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['total_leaves'] ?></span>
                </a>
                <a href="leave_requests.php?filter=pending" class="filter-pill <?= ($filter === 'pending') ? 'active' : '' ?>">
                    <span class="pulse-indicator mr-1" style="width: 6px; height: 6px;"></span> Pending 
                    <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['pending_leaves'] ?></span>
                </a>
                <a href="leave_requests.php?filter=approved" class="filter-pill <?= ($filter === 'approved') ? 'active' : '' ?>">
                    <i class="fa-solid fa-check mr-1" style="font-size: 11px;"></i> Approved 
                    <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['approved_leaves'] ?></span>
                </a>
                <a href="leave_requests.php?filter=rejected" class="filter-pill <?= ($filter === 'rejected') ? 'active' : '' ?>">
                    <i class="fa-solid fa-xmark mr-1" style="font-size: 11px;"></i> Rejected 
                    <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['rejected_leaves'] ?></span>
                </a>
                <a href="leave_requests.php?filter=cancelled" class="filter-pill <?= ($filter === 'cancelled') ? 'active' : '' ?>">
                    Cancelled 
                    <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['cancelled_leaves'] ?></span>
                </a>
            </div>

            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()" style="border-radius: 8px;">
                    <i class="fa-solid fa-print mr-1"></i> Print / Export
                </button>
            </div>
        </div>

        <!-- Main Leave Requests Data Card -->
        <div class="card shadow-sm" style="border-radius: 16px; border: none;">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover datatable align-middle" id="leaveRequestsTable" style="width: 100%;">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Student Details</th>
                                <th>Room & Bed</th>
                                <th>Leave Dates & Duration</th>
                                <th>Destination</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Reviewed By</th>
                                <th class="text-right" style="width: 110px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($leaves && $leaves->num_rows > 0):
                                $sl = 1;
                                while ($row = $leaves->fetch_assoc()):
                                    $leave_status = strtolower($row['status'] ?? 'pending');
                                    $start_ts = strtotime($row['start_date']);
                                    $end_ts = strtotime($row['end_date']);
                                    $diff_days = ($end_ts >= $start_ts) ? floor(($end_ts - $start_ts) / (60 * 60 * 24)) + 1 : 1;
                                    
                                    // Initials for avatar fallback
                                    $words = explode(" ", trim($row['student_name']));
                                    $initials = "";
                                    foreach ($words as $w) {
                                        if (!empty($w)) { $initials .= strtoupper($w[0]); }
                                        if (strlen($initials) >= 2) break;
                                    }
                                    if (empty($initials)) $initials = "ST";
                            ?>
                            <tr>
                                <td class="font-weight-bold text-muted"><?= $sl++ ?></td>
                                
                                <!-- Student Info -->
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($row['profile_image']) && file_exists('../student_profile/' . $row['profile_image'])): ?>
                                            <img src="../student_profile/<?= htmlspecialchars($row['profile_image']) ?>" alt="Student" class="avatar-circle mr-3" style="box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                                        <?php else: ?>
                                            <div class="avatar-circle mr-3" style="background: #e0e7ff; color: #4338ca; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">
                                                <?= $initials ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-weight-bold text-dark" style="font-size: 0.92rem;"><?= htmlspecialchars($row['student_name']) ?></div>
                                            <small class="text-muted d-block"><i class="fa-solid fa-phone mr-1" style="font-size: 10px;"></i><?= htmlspecialchars($row['student_phone'] ?? 'N/A') ?></small>
                                            <small class="text-muted d-block"><i class="fa-regular fa-envelope mr-1" style="font-size: 10px;"></i><?= htmlspecialchars($row['student_email'] ?? 'N/A') ?></small>
                                        </div>
                                    </div>
                                </td>

                                <!-- Room & Bed Info -->
                                <td>
                                    <?php if (!empty($row['room_number'])): ?>
                                        <div class="font-weight-bold text-dark">Room <?= htmlspecialchars($row['room_number']) ?></div>
                                        <small class="badge badge-info text-white font-weight-bold px-2 py-0" style="border-radius: 4px; font-size: 0.72rem;">
                                            Bed <?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>
                                        </small>
                                        <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                                            <?= htmlspecialchars($row['block_name'] ?? '') ?>, <?= htmlspecialchars($row['floor_name'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted">No Active Room</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Dates & Duration -->
                                <td>
                                    <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                        <i class="fa-regular fa-calendar-days text-primary mr-1"></i>
                                        <?= date('d M Y', $start_ts) ?>
                                    </div>
                                    <div class="text-muted small">
                                        to <span class="font-weight-semibold text-dark"><?= date('d M Y', $end_ts) ?></span>
                                    </div>
                                    <span class="badge badge-primary px-2 py-1 mt-1 font-weight-bold" style="border-radius: 50px; font-size: 0.72rem; letter-spacing: 0.3px;">
                                        <?= $diff_days ?> <?= ($diff_days > 1) ? 'Days' : 'Day' ?>
                                    </span>
                                </td>

                                <!-- Destination -->
                                <td>
                                    <span class="font-weight-semibold text-dark" style="font-size: 0.88rem;">
                                        <i class="fa-solid fa-location-dot text-danger mr-1"></i>
                                        <?= htmlspecialchars($row['destination']) ?>
                                    </span>
                                </td>

                                <!-- Reason -->
                                <td>
                                    <span class="text-dark d-inline-block text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($row['reason']) ?>">
                                        <?= htmlspecialchars($row['reason']) ?>
                                    </span>
                                </td>

                                <!-- Status Badge -->
                                <td>
                                    <?php if ($leave_status === 'pending'): ?>
                                        <span class="badge-status badge-status-pending">
                                            <span class="pulse-indicator"></span> Pending
                                        </span>
                                    <?php elseif ($leave_status === 'approved'): ?>
                                        <span class="badge-status badge-status-approved">
                                            <i class="fa-solid fa-circle-check"></i> Approved
                                        </span>
                                    <?php elseif ($leave_status === 'rejected'): ?>
                                        <span class="badge-status badge-status-rejected">
                                            <i class="fa-solid fa-circle-xmark"></i> Rejected
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-status badge-status-cancelled">
                                            <i class="fa-solid fa-ban"></i> Cancelled
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Reviewed By / Remarks -->
                                <td>
                                    <?php if (!empty($row['reviewed_at']) && $leave_status !== 'pending'): ?>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.82rem;">
                                            <?= htmlspecialchars($row['reviewer_name']) ?>
                                            <span class="badge badge-light border text-primary ml-1" style="font-size: 0.68rem;"><?= htmlspecialchars($row['reviewer_role'] ?? 'Authority') ?></span>
                                        </div>
                                        <small class="text-muted d-block"><?= date('d M Y', strtotime($row['reviewed_at'])) ?></small>
                                        <?php if (!empty($row['remarks'])): ?>
                                            <small class="text-muted d-block text-truncate" style="max-width: 140px;" title="<?= htmlspecialchars($row['remarks']) ?>">
                                                <em>"<?= htmlspecialchars($row['remarks']) ?>"</em>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted font-italic" style="font-size: 0.82rem;">Pending Review</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Action Buttons -->
                                <td class="text-right">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- View Detail Button -->
                                        <button type="button" 
                                                class="btn-action-circle bg-light text-primary btn-view-leave" 
                                                title="View Full Leave Details"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['student_name']) ?>"
                                                data-email="<?= htmlspecialchars($row['student_email'] ?? 'N/A') ?>"
                                                data-phone="<?= htmlspecialchars($row['student_phone'] ?? 'N/A') ?>"
                                                data-room="<?= htmlspecialchars($row['room_number'] ?? 'Unassigned') ?>"
                                                data-bed="<?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>"
                                                data-block="<?= htmlspecialchars($row['block_name'] ?? 'N/A') ?>"
                                                data-floor="<?= htmlspecialchars($row['floor_name'] ?? 'N/A') ?>"
                                                data-lang="<?= htmlspecialchars($row['student_language'] ?? 'N/A') ?>"
                                                data-roomtype="<?= htmlspecialchars($row['student_room_type'] ?? 'N/A') ?>"
                                                data-guardian="<?= htmlspecialchars($row['guardian_name'] ?? 'Not Specified') ?>"
                                                data-guardian-phone="<?= htmlspecialchars($row['guardian_phone'] ?? 'N/A') ?>"
                                                data-guardian-rel="<?= htmlspecialchars($row['guardian_relationship'] ?? 'Guardian') ?>"
                                                data-start="<?= date('d M Y', $start_ts) ?>"
                                                data-end="<?= date('d M Y', $end_ts) ?>"
                                                data-days="<?= $diff_days ?>"
                                                data-dest="<?= htmlspecialchars($row['destination']) ?>"
                                                data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                                data-status="<?= $leave_status ?>"
                                                data-reviewer="<?= htmlspecialchars($row['reviewer_name'] ?? 'Authority') ?>"
                                                data-reviewer-role="<?= htmlspecialchars($row['reviewer_role'] ?? 'Authority') ?>"
                                                data-reviewed-at="<?= !empty($row['reviewed_at']) ? date('d M Y', strtotime($row['reviewed_at'])) : 'N/A' ?>"
                                                data-remarks="<?= htmlspecialchars($row['remarks'] ?? 'None') ?>"
                                                data-created="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>"
                                                data-image="<?= !empty($row['profile_image']) ? htmlspecialchars($row['profile_image']) : '' ?>"
                                                data-initials="<?= $initials ?>">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>

                                        <!-- Review Decision Button (For Pending or Override) -->
                                        <button type="button" 
                                                class="btn-action-circle <?= ($leave_status === 'pending') ? 'bg-primary text-white shadow-sm' : 'bg-light text-secondary' ?> btn-review-leave" 
                                                title="<?= ($leave_status === 'pending') ? 'Review & Decide' : 'Change Decision / Override' ?>"
                                                data-id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['student_name']) ?>"
                                                data-dates="<?= date('d M Y', $start_ts) ?> to <?= date('d M Y', $end_ts) ?> (<?= $diff_days ?> days)"
                                                data-dest="<?= htmlspecialchars($row['destination']) ?>"
                                                data-status="<?= $leave_status ?>"
                                                data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>">
                                            <i class="fa-solid fa-gavel"></i>
                                        </button>

                                        <!-- Delete Request Button -->
                                        <a href="leave_requests.php?action=delete_leave&id=<?= $row['id'] ?>" 
                                           class="btn-action-circle bg-light text-danger btn-delete-leave" 
                                           title="Delete Leave Record">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            endif; 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     DETAILED LEAVE VIEW MODAL
     ========================================== -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" role="dialog" aria-labelledby="viewLeaveModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #ffffff;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white text-primary p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; font-size: 1.15rem; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                        <i class="fa-solid fa-file-shield"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-white mb-0" id="viewLeaveModalTitle">Leave Request Details</h5>
                        <small class="text-white-50">Reference ID: #<span id="modalLeaveId"></span></small>
                    </div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body p-4">
                <!-- Status & Submission Header Banner -->
                <div class="d-flex flex-wrap justify-content-between align-items-center p-3 mb-4 rounded" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                    <div class="d-flex align-items-center">
                        <span id="modalStatusBadge" class="badge-status mr-3"></span>
                        <div>
                            <span class="text-muted small d-block">Request Status</span>
                            <span id="modalStatusDesc" class="font-weight-bold text-dark" style="font-size: 0.88rem;"></span>
                        </div>
                    </div>
                    <div class="text-md-right mt-2 mt-md-0">
                        <span class="text-muted small d-block">Submitted At</span>
                        <span id="modalCreated" class="font-weight-bold text-dark" style="font-size: 0.88rem;"></span>
                    </div>
                </div>

                <div class="row">
                    <!-- Student Profile & Hostel Room Section -->
                    <div class="col-md-5 mb-4 mb-md-0 border-right">
                        <h6 class="font-weight-bold text-dark mb-3 d-flex align-items-center">
                            <i class="fa-regular fa-user text-primary mr-2"></i> Student Information
                        </h6>

                        <div class="d-flex align-items-center mb-3">
                            <div id="modalAvatarBox"></div>
                            <div>
                                <h6 id="modalStudentName" class="font-weight-bold text-dark mb-0"></h6>
                                <span class="badge badge-light border text-muted mt-1" style="font-size: 0.72rem;">Hostel Resident</span>
                            </div>
                        </div>

                        <div class="mb-2">
                            <div class="detail-label"><i class="fa-solid fa-phone mr-1"></i> Student Phone</div>
                            <div id="modalStudentPhone" class="detail-value text-primary"></div>
                        </div>

                        <div class="mb-2">
                            <div class="detail-label"><i class="fa-regular fa-envelope mr-1"></i> Student Email</div>
                            <div id="modalStudentEmail" class="detail-value"></div>
                        </div>

                        <div class="mb-3">
                            <div class="detail-label"><i class="fa-solid fa-language mr-1"></i> Preferred Language / Room Type</div>
                            <div class="detail-value">
                                <span id="modalStudentLang" class="badge badge-light border mr-1"></span>
                                <span id="modalStudentRoomType" class="badge badge-light border"></span>
                            </div>
                        </div>

                        <hr class="my-3">

                        <h6 class="font-weight-bold text-dark mb-2" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-bed text-info mr-1"></i> Allocated Room & Bed
                        </h6>
                        <div class="p-2 rounded bg-light border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Room No:</span>
                                <span id="modalRoom" class="font-weight-bold text-dark"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Bed:</span>
                                <span id="modalBed" class="font-weight-bold text-primary"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Location:</span>
                                <span id="modalLocation" class="text-dark small font-weight-medium"></span>
                            </div>
                        </div>

                        <h6 class="font-weight-bold text-dark mb-2" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-person-breastfeeding text-secondary mr-1"></i> Emergency / Guardian
                        </h6>
                        <div class="p-2 rounded bg-light border">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Contact:</span>
                                <span id="modalGuardian" class="font-weight-bold text-dark"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Phone:</span>
                                <span id="modalGuardianPhone" class="font-weight-bold text-danger"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Leave Info & Review Notes Section -->
                    <div class="col-md-7 pl-md-4">
                        <h6 class="font-weight-bold text-dark mb-3 d-flex align-items-center">
                            <i class="fa-regular fa-calendar-check text-primary mr-2"></i> Leave Schedule & Travel
                        </h6>

                        <!-- Travel Timeline Box -->
                        <div class="leave-timeline-card mb-3">
                            <div class="row text-center">
                                <div class="col-5">
                                    <span class="detail-label d-block text-primary">Departure Date</span>
                                    <h6 id="modalStartDate" class="font-weight-bold text-dark mb-0"></h6>
                                </div>
                                <div class="col-2 d-flex flex-column align-items-center justify-content-center">
                                    <i class="fa-solid fa-arrow-right text-muted"></i>
                                    <span id="modalDuration" class="badge badge-primary px-2 py-1 mt-1 font-weight-bold" style="font-size: 0.7rem;"></span>
                                </div>
                                <div class="col-5">
                                    <span class="detail-label d-block text-success">Return Date</span>
                                    <h6 id="modalEndDate" class="font-weight-bold text-dark mb-0"></h6>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="detail-label"><i class="fa-solid fa-location-dot text-danger mr-1"></i> Destination Address</div>
                            <div id="modalDestination" class="detail-value text-dark p-2 rounded bg-light border"></div>
                        </div>

                        <div class="mb-3">
                            <div class="detail-label"><i class="fa-solid fa-align-left mr-1"></i> Stated Reason for Leave</div>
                            <div id="modalReason" class="text-dark p-3 rounded bg-light border" style="font-size: 0.88rem; line-height: 1.5; white-space: pre-line;"></div>
                        </div>

                        <hr class="my-3">

                        <h6 class="font-weight-bold text-dark mb-2 d-flex align-items-center">
                            <i class="fa-solid fa-clipboard-check text-success mr-2"></i> Authority Review Log
                        </h6>

                        <div id="modalReviewBox" class="p-3 rounded border" style="background: #fafafa;">
                            <div class="row mb-2">
                                <div class="col-6">
                                    <span class="detail-label d-block">Reviewed By</span>
                                    <span id="modalReviewer" class="font-weight-bold text-dark"></span>
                                    <span id="modalReviewerRole" class="badge badge-light border text-primary ml-1" style="font-size: 0.68rem;"></span>
                                </div>
                                <div class="col-6 text-right">
                                    <span class="detail-label d-block">Reviewed On</span>
                                    <span id="modalReviewedAt" class="font-weight-bold text-dark"></span>
                                </div>
                            </div>
                            <div>
                                <span class="detail-label d-block">Authority Remarks / Notes</span>
                                <div id="modalRemarks" class="text-dark font-italic small mt-1"></div>
                            </div>
                        </div>

                        <!-- Attendance Synchronization Status Info -->
                        <div id="modalAttendanceAlert" class="mt-3 p-3 rounded" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                            <div class="d-flex align-items-center">
                                <i class="fa-solid fa-circle-check text-success mr-2 font-weight-bold" style="font-size: 1.1rem;"></i>
                                <div class="small text-success font-weight-bold">
                                    Attendance Synchronization Active
                                </div>
                            </div>
                            <small class="text-muted d-block mt-1">
                                Absent markers are automatically recorded in the Attendance table for each day within this leave period.
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light d-flex justify-content-between">
                <button type="button" class="btn btn-secondary px-4 font-weight-semibold" data-dismiss="modal" style="border-radius: 8px;">
                    Close
                </button>
                <button type="button" class="btn btn-primary px-4 font-weight-semibold" id="modalBtnProceedReview" style="border-radius: 8px;">
                    <i class="fa-solid fa-gavel mr-1"></i> Review / Change Status
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     ADMIN REVIEW / STATUS DECISION MODAL
     ========================================== -->
<div class="modal fade" id="reviewModal" tabindex="-1" role="dialog" aria-labelledby="reviewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: #2563eb; color: #ffffff;">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-gavel mr-2" style="font-size: 1.25rem;"></i>
                    <h5 class="modal-title font-weight-bold text-white mb-0" id="reviewModalTitle">Admin Leave Review</h5>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form action="leave_requests.php<?= (!empty($_GET['filter'])) ? '?filter=' . urlencode($_GET['filter']) : '' ?>" method="POST" id="adminReviewForm">
                <input type="hidden" name="action" value="admin_update_leave_status">
                <input type="hidden" name="leave_id" id="review_leave_id">
                <input type="hidden" name="status" id="review_status_input">

                <div class="modal-body p-4">
                    <div class="p-3 rounded mb-3" style="background: #f1f5f9; border: 1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small font-weight-bold">Student:</span>
                            <span id="review_student_name" class="font-weight-bold text-dark"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small font-weight-bold">Leave Schedule:</span>
                            <span id="review_dates" class="text-dark small font-weight-bold"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small font-weight-bold">Destination:</span>
                            <span id="review_destination" class="text-dark small"></span>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3 small mb-3" style="border-radius: 8px;">
                        <i class="fa-solid fa-info-circle mr-1"></i>
                        Approving will automatically mark this student as <strong>Absent</strong> in the attendance register for each day of their leave.
                    </div>

                    <div class="form-group mb-3">
                        <label for="review_remarks" class="font-weight-bold text-dark small text-uppercase">
                            Admin Remarks / Instructions <span class="text-muted">(Optional)</span>
                        </label>
                        <textarea class="form-control" name="remarks" id="review_remarks" rows="3" placeholder="Add approval remarks or reason for rejection..." style="border-radius: 10px;"></textarea>
                    </div>

                    <div class="d-flex justify-content-center gap-3 mt-4">
                        <button type="button" class="btn btn-outline-danger flex-fill py-2 font-weight-bold" id="btnRejectLeave" style="border-radius: 10px;">
                            <i class="fa-solid fa-circle-xmark mr-1"></i> Reject Leave
                        </button>
                        <button type="button" class="btn btn-success flex-fill py-2 font-weight-bold" id="btnApproveLeave" style="border-radius: 10px;">
                            <i class="fa-solid fa-circle-check mr-1"></i> Approve Leave
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    if (!$.fn.DataTable.isDataTable('#leaveRequestsTable')) {
        $('#leaveRequestsTable').DataTable({
            responsive: true,
            pageLength: 10,
            order: [], // Preserve SQL sort (pending first, then desc)
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search student, room, reason...",
                paginate: {
                    previous: "<i class='fa-solid fa-chevron-left'></i>",
                    next: "<i class='fa-solid fa-chevron-right'></i>"
                }
            }
        });
    }

    // View Leave Details Modal Trigger
    $('.btn-view-leave').on('click', function() {
        var btn = $(this);
        var id = btn.data('id');
        var name = btn.data('name');
        var email = btn.data('email');
        var phone = btn.data('phone');
        var room = btn.data('room');
        var bed = btn.data('bed');
        var block = btn.data('block');
        var floor = btn.data('floor');
        var lang = btn.data('lang');
        var roomType = btn.data('roomtype');
        var guardian = btn.data('guardian');
        var guardianPhone = btn.data('guardian-phone');
        var guardianRel = btn.data('guardian-rel');
        var start = btn.data('start');
        var end = btn.data('end');
        var days = btn.data('days');
        var dest = btn.data('dest');
        var reason = btn.data('reason');
        var status = btn.data('status');
        var reviewer = btn.data('reviewer');
        var reviewerRole = btn.data('reviewer-role');
        var reviewedAt = btn.data('reviewed-at');
        var remarks = btn.data('remarks');
        var created = btn.data('created');
        var img = btn.data('image');
        var initials = btn.data('initials');

        $('#modalLeaveId').text(id);
        $('#modalStudentName').text(name);
        $('#modalStudentPhone').text(phone);
        $('#modalStudentEmail').text(email);
        $('#modalStudentLang').text(lang ? 'Lang: ' + lang : 'Lang: Not specified');
        $('#modalStudentRoomType').text(roomType ? 'Room: ' + roomType : 'Standard');

        // Avatar
        if (img && img.trim() !== '') {
            $('#modalAvatarBox').html('<img src="../student_profile/' + img + '" class="avatar-circle mr-3" style="width: 48px; height: 48px; box-shadow: 0 3px 8px rgba(0,0,0,0.12);">');
        } else {
            $('#modalAvatarBox').html('<div class="avatar-circle mr-3" style="width: 48px; height: 48px; font-size: 17px; background: #e0e7ff; color: #4338ca; box-shadow: 0 3px 8px rgba(0,0,0,0.08);">' + initials + '</div>');
        }

        // Room details
        if (room && room !== 'Unassigned') {
            $('#modalRoom').text('Room ' + room);
            $('#modalBed').text('Bed ' + bed);
            $('#modalLocation').text(block + ' / ' + floor);
        } else {
            $('#modalRoom').text('Not Assigned');
            $('#modalBed').text('N/A');
            $('#modalLocation').text('Hostel');
        }

        // Guardian
        $('#modalGuardian').text(guardian + (guardianRel ? ' (' + guardianRel + ')' : ''));
        $('#modalGuardianPhone').text(guardianPhone);

        // Schedule
        $('#modalStartDate').text(start);
        $('#modalEndDate').text(end);
        $('#modalDuration').text(days + (days > 1 ? ' Days' : ' Day'));
        $('#modalDestination').text(dest);
        $('#modalReason').text(reason);
        $('#modalCreated').text(created);

        // Status badge
        var statusHtml = '';
        var statusDesc = '';
        if (status === 'pending') {
            statusHtml = '<span class="badge-status badge-status-pending"><span class="pulse-indicator"></span> Pending Review</span>';
            statusDesc = 'Under evaluation by Hostel Administration.';
            $('#modalAttendanceAlert').hide();
        } else if (status === 'approved') {
            statusHtml = '<span class="badge-status badge-status-approved"><i class="fa-solid fa-circle-check"></i> Approved</span>';
            statusDesc = 'Leave approved. Absent markers synchronized to attendance table.';
            $('#modalAttendanceAlert').show();
        } else if (status === 'rejected') {
            statusHtml = '<span class="badge-status badge-status-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
            statusDesc = 'Leave request rejected by administration.';
            $('#modalAttendanceAlert').hide();
        } else {
            statusHtml = '<span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>';
            statusDesc = 'Request was cancelled by student.';
            $('#modalAttendanceAlert').hide();
        }
        $('#modalStatusBadge').html(statusHtml);
        $('#modalStatusDesc').text(statusDesc);

        // Review log
        $('#modalReviewer').text(reviewer);
        $('#modalReviewerRole').text(reviewerRole);
        $('#modalReviewedAt').text(reviewedAt);
        $('#modalRemarks').text(remarks ? remarks : 'No special remarks recorded.');

        // Pass data for quick transition to Review modal
        $('#modalBtnProceedReview').off('click').on('click', function() {
            $('#viewLeaveModal').modal('hide');
            setTimeout(function() {
                openReviewModal(id, name, start + ' to ' + end + ' (' + days + ' days)', dest, remarks);
            }, 300);
        });

        $('#viewLeaveModal').modal('show');
    });

    // Function to open review modal
    function openReviewModal(id, name, dates, dest, remarks) {
        $('#review_leave_id').val(id);
        $('#review_student_name').text(name);
        $('#review_dates').text(dates);
        $('#review_destination').text(dest);
        $('#review_remarks').val(remarks || '');
        $('#reviewModal').modal('show');
    }

    // Direct review button click from table
    $('.btn-review-leave').on('click', function() {
        var btn = $(this);
        openReviewModal(
            btn.data('id'),
            btn.data('name'),
            btn.data('dates'),
            btn.data('dest'),
            btn.data('remarks')
        );
    });

    // Approve Submit
    $('#btnApproveLeave').on('click', function() {
        $('#review_status_input').val('approved');
        Swal.fire({
            title: 'Approve Leave Request?',
            text: 'This will record Absent status in the attendance table for all dates within the requested duration.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Approve & Sync Attendance'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#adminReviewForm').submit();
            }
        });
    });

    // Reject Submit
    $('#btnRejectLeave').on('click', function() {
        $('#review_status_input').val('rejected');
        Swal.fire({
            title: 'Reject Leave Request?',
            text: 'Are you sure you want to reject this student outstation leave?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Reject Request'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#adminReviewForm').submit();
            }
        });
    });

    // Delete confirmation
    $('.btn-delete-leave').on('click', function(e) {
        e.preventDefault();
        var targetUrl = $(this).attr('href');
        Swal.fire({
            title: 'Delete Leave Request?',
            text: 'This will permanently remove the record and any synchronized attendance records.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Delete Permanently'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = targetUrl;
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
