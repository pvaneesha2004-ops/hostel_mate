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

// Handle Leave Review / Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_leave_status') {
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
            $update_stmt->bind_param("sisi", $new_status, $warden_id, $remarks, $leave_id);

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
                        $att_stmt->bind_param("isss", $student_id, $att_date, $warden_name, $att_remarks);
                        $att_stmt->execute();
                        $inserted_days++;
                    }

                    $_SESSION['msg'] = "Leave request #{$leave_id} for " . htmlspecialchars($leave['student_name']) . " was APPROVED! {$inserted_days} day(s) marked as 'Absent' in the attendance table.";
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
        $_SESSION['error'] = "Invalid status review request.";
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

// Fetch Leave Requests with Student, Room Allocation & Reviewer info
$query = "
    SELECT lr.*, 
           u.name AS student_name,
           u.email AS student_email,
           u.phone AS student_phone,
           u.profile_image,
           rm.room_number,
           bd.bed_number,
           blk.name AS block_name,
           flr.name AS floor_name,
           COALESCE(w_rev.name, u_rev.name, 'Authority') AS reviewer_name
    FROM leave_requests lr
    JOIN users u ON lr.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN wardens w_rev ON lr.reviewed_by = w_rev.id
    LEFT JOIN users u_rev ON lr.reviewed_by = u_rev.id
    $where_sql
    ORDER BY CASE WHEN lr.status = 'pending' THEN 0 ELSE 1 END, lr.id DESC
";
$leaves = $conn->query($query);

ob_start();
?>

<!-- Custom CSS for Warden Leave Requests -->
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
        background: #059669 !important;
        border-color: #059669 !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.28);
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
        font-size: 0.8rem;
        border: none;
        transition: all 0.15s ease;
        cursor: pointer;
    }
    .btn-action-circle:hover {
        transform: scale(1.1);
    }
</style>

<div class="row">
    <div class="col-12">
        <!-- Header Title Banner -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
            <div>
                <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.5px;">Student Leave Requests</h3>
                <p class="text-muted small mb-0">Review student leave applications and automatically manage their attendance entries upon approval.</p>
            </div>
            <div class="mt-3 mt-md-0 d-flex align-items-center" style="gap: 8px;">
                <span class="badge badge-light border text-muted px-3 py-2 font-weight-medium">
                    <i class="fa-solid fa-user-shield mr-1 text-success"></i> Warden Authority: <strong><?= htmlspecialchars($warden_name) ?></strong>
                </span>
            </div>
        </div>

        <!-- Session Message Alerts -->
        <?php if(isset($_SESSION['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981 !important;">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-circle-check mr-2" style="font-size: 1.15rem;"></i>
                    <span><?= htmlspecialchars($_SESSION['msg']) ?></span>
                </div>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php unset($_SESSION['msg']); endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: #fef2f2; color: #991b1b; border-left: 4px solid #ef4444 !important;">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-triangle-exclamation mr-2" style="font-size: 1.15rem;"></i>
                    <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                </div>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php unset($_SESSION['error']); endif; ?>

        <!-- KPI Metric Cards -->
        <div class="row mb-4">
            <div class="col-6 col-lg-3 mb-3 mb-lg-0">
                <div class="card kpi-card p-3">
                    <div class="d-flex align-items-center">
                        <div class="kpi-icon-wrap mr-3" style="background: #eef2ff; color: #6366f1;">
                            <i class="fa-solid fa-paper-plane"></i>
                        </div>
                        <div>
                            <div class="text-muted small font-weight-medium">Total Requests</div>
                            <h4 class="font-weight-bold mb-0 text-dark"><?= intval($metrics['total_leaves'] ?? 0) ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3 mb-3 mb-lg-0">
                <div class="card kpi-card p-3" style="<?= ($metrics['pending_leaves'] > 0) ? 'border: 1px solid #fde68a !important;' : '' ?>">
                    <div class="d-flex align-items-center">
                        <div class="kpi-icon-wrap mr-3" style="background: #fffbeb; color: #d97706;">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div>
                            <div class="text-muted small font-weight-medium d-flex align-items-center">
                                Pending Review
                                <?php if ($metrics['pending_leaves'] > 0): ?>
                                    <span class="pulse-indicator ml-2" title="Action required"></span>
                                <?php endif; ?>
                            </div>
                            <h4 class="font-weight-bold mb-0 text-warning"><?= intval($metrics['pending_leaves'] ?? 0) ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3 mb-3 mb-lg-0">
                <div class="card kpi-card p-3">
                    <div class="d-flex align-items-center">
                        <div class="kpi-icon-wrap mr-3" style="background: #ecfdf5; color: #059669;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <div class="text-muted small font-weight-medium">Approved</div>
                            <h4 class="font-weight-bold mb-0 text-success"><?= intval($metrics['approved_leaves'] ?? 0) ?></h4>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3 mb-3 mb-lg-0">
                <div class="card kpi-card p-3">
                    <div class="d-flex align-items-center">
                        <div class="kpi-icon-wrap mr-3" style="background: #f8fafc; color: #64748b;">
                            <i class="fa-solid fa-xmark"></i>
                        </div>
                        <div>
                            <div class="text-muted small font-weight-medium">Rejected / Cancelled</div>
                            <h4 class="font-weight-bold mb-0 text-muted"><?= intval(($metrics['rejected_leaves'] ?? 0) + ($metrics['cancelled_leaves'] ?? 0)) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance Automation Banner -->
        <div class="alert border-0 shadow-sm mb-4 p-3 rounded-12" style="background: #f0fdf4; border-left: 4px solid #10b981 !important;">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-wand-magic-sparkles text-success mr-3" style="font-size: 1.3rem;"></i>
                <div class="small text-dark">
                    <strong>Automated Attendance Sync:</strong> Approving a student's leave will <strong>automatically insert Absent records</strong> into the <code>attendance</code> table for all requested dates, credited under your warden name. If you later reject or the student cancels, the system automatically removes those entries.
                </div>
            </div>
        </div>

        <!-- Main Card & Filter Pills -->
        <div class="card border-0 shadow-sm rounded-16" style="background: #ffffff;">
            <div class="card-body p-4">
                <!-- Status Filter Pills Bar -->
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-3 border-bottom" style="gap: 10px;">
                    <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                        <a href="leave_requests.php?filter=all" class="filter-pill <?= ($filter === 'all') ? 'active' : '' ?>">
                            All Leaves <span class="badge badge-light ml-1"><?= intval($metrics['total_leaves'] ?? 0) ?></span>
                        </a>
                        <a href="leave_requests.php?filter=pending" class="filter-pill <?= ($filter === 'pending') ? 'active' : '' ?>">
                            Pending <span class="badge badge-warning text-dark ml-1"><?= intval($metrics['pending_leaves'] ?? 0) ?></span>
                        </a>
                        <a href="leave_requests.php?filter=approved" class="filter-pill <?= ($filter === 'approved') ? 'active' : '' ?>">
                            Approved <span class="badge badge-success ml-1"><?= intval($metrics['approved_leaves'] ?? 0) ?></span>
                        </a>
                        <a href="leave_requests.php?filter=rejected" class="filter-pill <?= ($filter === 'rejected') ? 'active' : '' ?>">
                            Rejected <span class="badge badge-danger ml-1"><?= intval($metrics['rejected_leaves'] ?? 0) ?></span>
                        </a>
                        <a href="leave_requests.php?filter=cancelled" class="filter-pill <?= ($filter === 'cancelled') ? 'active' : '' ?>">
                            Cancelled <span class="badge badge-secondary ml-1"><?= intval($metrics['cancelled_leaves'] ?? 0) ?></span>
                        </a>
                    </div>
                </div>

                <!-- Table -->
                <div class="table-responsive">
                    <table class="table table-hover datatable" id="wardenLeavesTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Details</th>
                                <th>Room & Bed</th>
                                <th>Leave Dates</th>
                                <th>Duration</th>
                                <th>Destination</th>
                                <th>Status</th>
                                <th>Review Notes</th>
                                <th>Applied At</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sl = 1;
                            if ($leaves && $leaves->num_rows > 0):
                                while ($row = $leaves->fetch_assoc()):
                                    $start_ts = strtotime($row['start_date']);
                                    $end_ts = strtotime($row['end_date']);
                                    $days = ceil(($end_ts - $start_ts) / (60 * 60 * 24)) + 1;
                                    $status = strtolower($row['status']);
                                    $studentPhoto = $row['profile_image'];
                            ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($studentPhoto) && file_exists('../student_profile/' . $studentPhoto)): ?>
                                            <img src="../student_profile/<?= htmlspecialchars($studentPhoto) ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;" class="mr-2">
                                        <?php else: ?>
                                            <div style="width: 38px; height: 38px; border-radius: 50%; background: #e2e8f0; color: #64748b; display: inline-flex; align-items: center; justify-content: center;" class="mr-2">
                                                <i class="ti-user"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <span class="font-weight-bold text-dark d-block"><?= htmlspecialchars($row['student_name']) ?></span>
                                            <small class="text-muted"><i class="ti-mobile mr-1"></i><?= htmlspecialchars($row['student_phone']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($row['room_number'])): ?>
                                        <span class="font-weight-bold text-dark">Room <?= htmlspecialchars($row['room_number']) ?></span><br>
                                        <small class="text-muted"><span class="badge badge-light border">Bed <?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?></span> <?= htmlspecialchars($row['block_name'] ?? '') ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted">Not Allocated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="font-weight-bold text-dark">
                                        <i class="fa-regular fa-calendar-days text-success mr-1"></i>
                                        <?= date('d M Y', $start_ts) ?> &rarr; <?= date('d M Y', $end_ts) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-light border text-dark font-weight-bold"><?= $days ?> <?= ($days == 1 ? 'Day' : 'Days') ?></span>
                                </td>
                                <td>
                                    <span class="font-weight-medium text-dark"><i class="fa-solid fa-location-dot text-danger mr-1 small"></i><?= htmlspecialchars($row['destination']) ?></span>
                                </td>
                                <td>
                                    <?php if ($status === 'approved'): ?>
                                        <span class="badge-status badge-status-approved"><i class="fa-solid fa-check"></i> Approved</span>
                                    <?php elseif ($status === 'rejected'): ?>
                                        <span class="badge-status badge-status-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                    <?php elseif ($status === 'cancelled'): ?>
                                        <span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-status-pending"><i class="fa-regular fa-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['reviewed_at']) && $row['reviewed_at'] !== '0000-00-00'): ?>
                                        <span class="small font-weight-medium text-dark"><?= htmlspecialchars($row['reviewer_name']) ?></span><br>
                                        <small class="text-muted"><?= htmlspecialchars($row['remarks'] ? substr($row['remarks'], 0, 30) . '...' : 'No remarks') ?></small>
                                    <?php else: ?>
                                        <span class="text-muted font-italic small">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></small>
                                </td>
                                <td class="text-center">
                                    <div class="d-inline-flex" style="gap: 6px;">
                                        <!-- View Details Button -->
                                        <button type="button" class="btn-action-circle btn-info view-btn text-white" title="View Full Details"
                                            data-id="<?= $row['id'] ?>"
                                            data-student_name="<?= htmlspecialchars($row['student_name']) ?>"
                                            data-student_phone="<?= htmlspecialchars($row['student_phone']) ?>"
                                            data-student_email="<?= htmlspecialchars($row['student_email']) ?>"
                                            data-room="<?= htmlspecialchars($row['room_number'] ?? 'Not Allocated') ?>"
                                            data-bed="<?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>"
                                            data-block="<?= htmlspecialchars($row['block_name'] ?? 'N/A') ?>"
                                            data-start_date="<?= date('d M Y', $start_ts) ?>"
                                            data-end_date="<?= date('d M Y', $end_ts) ?>"
                                            data-days="<?= $days ?>"
                                            data-destination="<?= htmlspecialchars($row['destination']) ?>"
                                            data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                            data-status="<?= $status ?>"
                                            data-reviewer="<?= htmlspecialchars($row['reviewer_name'] ?? 'Authority') ?>"
                                            data-reviewed_at="<?= (!empty($row['reviewed_at']) && $row['reviewed_at'] !== '0000-00-00') ? date('d M Y', strtotime($row['reviewed_at'])) : 'N/A' ?>"
                                            data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>"
                                            data-created_at="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>">
                                            <i class="ti-eye"></i>
                                        </button>

                                        <!-- Review / Change Status Button -->
                                        <?php if ($status === 'pending'): ?>
                                            <button type="button" class="btn-action-circle btn-warning review-btn text-dark font-weight-bold" title="Review & Take Decision"
                                                data-id="<?= $row['id'] ?>"
                                                data-student_name="<?= htmlspecialchars($row['student_name']) ?>"
                                                data-dates="<?= date('d M Y', $start_ts) ?> - <?= date('d M Y', $end_ts) ?>"
                                                data-days="<?= $days ?>"
                                                data-destination="<?= htmlspecialchars($row['destination']) ?>"
                                                data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                                data-status="<?= $status ?>"
                                                data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>">
                                                <i class="fa-solid fa-gavel"></i>
                                            </button>
                                        <?php else: ?>
                                            <!-- Option to Re-evaluate status -->
                                            <button type="button" class="btn-action-circle btn-light border review-btn text-muted" title="Re-evaluate Decision"
                                                data-id="<?= $row['id'] ?>"
                                                data-student_name="<?= htmlspecialchars($row['student_name']) ?>"
                                                data-dates="<?= date('d M Y', $start_ts) ?> - <?= date('d M Y', $end_ts) ?>"
                                                data-days="<?= $days ?>"
                                                data-destination="<?= htmlspecialchars($row['destination']) ?>"
                                                data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                                data-status="<?= $status ?>"
                                                data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Modal 1: Review & Decision Modal -->
<div class="modal fade" id="reviewLeaveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title font-weight-bold text-white"><i class="fa-solid fa-gavel mr-2"></i>Review Leave Request</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="leave_requests.php" id="reviewLeaveForm">
                <input type="hidden" name="action" value="update_leave_status">
                <input type="hidden" name="leave_id" id="review_leave_id">
                
                <div class="modal-body p-4">
                    <!-- Student & Leave Summary Box -->
                    <div class="p-3 mb-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="font-weight-bold text-dark mb-0" id="review_student_name"></h6>
                            <span class="badge badge-light border font-weight-bold text-primary" id="review_days_pill"></span>
                        </div>
                        <div class="small text-muted mb-1"><i class="fa-regular fa-calendar-days mr-1 text-success"></i> <span id="review_dates_text"></span></div>
                        <div class="small text-muted"><i class="fa-solid fa-location-dot mr-1 text-danger"></i> <span id="review_destination_text"></span></div>
                    </div>

                    <!-- Leave Reason Snippet -->
                    <div class="mb-3">
                        <small class="text-muted d-block font-weight-medium">Student Reason:</small>
                        <div class="p-2 rounded bg-light text-dark font-weight-medium small" id="review_reason_text"></div>
                    </div>

                    <!-- Automated Attendance Notice -->
                    <div class="alert alert-warning py-2 px-3 small rounded mb-3" style="border-left: 3px solid #f59e0b;">
                        <i class="fa-solid fa-circle-info mr-1"></i> <strong>Note:</strong> Approving will automatically mark the student <strong>Absent</strong> in the attendance table for all days in this leave range.
                    </div>

                    <!-- Decision Selector -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small">Warden Decision <span class="text-danger">*</span></label>
                        <select name="status" id="review_status_select" class="form-control font-weight-bold" required>
                            <option value="approved" class="text-success">&check; Approve Leave Request</option>
                            <option value="rejected" class="text-danger">&cross; Reject Leave Request</option>
                        </select>
                    </div>

                    <!-- Review Remarks / Feedback -->
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark small">Remarks / Feedback (Optional)</label>
                        <textarea name="remarks" id="review_remarks_input" class="form-control" rows="2" placeholder="e.g. Approved with parent consent."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary font-weight-medium" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold px-4" id="review_submit_btn">Save Decision</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: View Detailed Information Modal -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold text-white"><i class="fa-solid fa-circle-info mr-2"></i>Comprehensive Leave Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <!-- Student Header -->
                <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                    <div style="width: 45px; height: 45px; border-radius: 50%; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-size: 20px;" class="mr-3">
                        <i class="ti-user"></i>
                    </div>
                    <div>
                        <h5 class="font-weight-bold mb-0 text-dark" id="view_student_name"></h5>
                        <small class="text-muted"><i class="ti-email mr-1"></i><span id="view_student_email"></span> | <i class="ti-mobile mr-1"></i><span id="view_student_phone"></span></small>
                    </div>
                </div>

                <!-- Room info -->
                <div class="row mb-3 pb-3 border-bottom">
                    <div class="col-4">
                        <small class="text-muted d-block font-weight-medium">Room</small>
                        <span class="font-weight-bold text-dark" id="view_room"></span>
                    </div>
                    <div class="col-4">
                        <small class="text-muted d-block font-weight-medium">Bed</small>
                        <span class="font-weight-bold text-dark" id="view_bed"></span>
                    </div>
                    <div class="col-4">
                        <small class="text-muted d-block font-weight-medium">Block</small>
                        <span class="font-weight-bold text-dark" id="view_block"></span>
                    </div>
                </div>

                <!-- Dates & Destination -->
                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted d-block font-weight-medium">Start Date</small>
                        <span class="font-weight-bold text-dark" id="view_start_date"></span>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block font-weight-medium">End Date</small>
                        <span class="font-weight-bold text-dark" id="view_end_date"></span>
                    </div>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block font-weight-medium">Destination</small>
                    <span class="font-weight-bold text-dark" id="view_destination"></span>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block font-weight-medium">Reason</small>
                    <div class="p-2 rounded bg-light text-dark font-weight-medium small" id="view_reason"></div>
                </div>

                <!-- Decision Card -->
                <div class="card p-3 rounded-12 mb-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="font-weight-bold text-dark small"><i class="fa-solid fa-user-shield mr-1 text-success"></i> Review Decision</span>
                        <div id="view_status_badge"></div>
                    </div>
                    <div class="small mb-1"><strong>Reviewed By:</strong> <span id="view_reviewer"></span></div>
                    <div class="small mb-1"><strong>Reviewed Date:</strong> <span id="view_reviewed_at"></span></div>
                    <div class="small"><strong>Remarks:</strong> <span id="view_remarks" class="text-muted font-italic"></span></div>
                </div>

                <div class="text-muted small text-right">
                    <span>Applied on: <strong id="view_created_at"></strong></span>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#wardenLeavesTable')) {
        $('#wardenLeavesTable').DataTable({
            "order": [[ 0, "asc" ]],
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search leave requests..."
            }
        });
    }

    // View Details Button Handler
    $(document).on('click', '.view-btn', function() {
        var name = $(this).data('student_name');
        var email = $(this).data('student_email');
        var phone = $(this).data('student_phone');
        var room = $(this).data('room');
        var bed = $(this).data('bed');
        var block = $(this).data('block');
        var start = $(this).data('start_date');
        var end = $(this).data('end_date');
        var destination = $(this).data('destination');
        var reason = $(this).data('reason');
        var status = $(this).data('status');
        var reviewer = $(this).data('reviewer');
        var reviewedAt = $(this).data('reviewed_at');
        var remarks = $(this).data('remarks');
        var createdAt = $(this).data('created_at');

        $('#view_student_name').text(name);
        $('#view_student_email').text(email);
        $('#view_student_phone').text(phone);
        $('#view_room').text('Room ' + room);
        $('#view_bed').text('Bed ' + bed);
        $('#view_block').text(block);
        $('#view_start_date').text(start);
        $('#view_end_date').text(end);
        $('#view_destination').text(destination);
        $('#view_reason').text(reason);
        $('#view_reviewer').text(reviewer ? reviewer : 'Not reviewed');
        $('#view_reviewed_at').text(reviewedAt !== 'N/A' ? reviewedAt : 'Pending');
        $('#view_remarks').text(remarks ? remarks : 'None provided');
        $('#view_created_at').text(createdAt);

        var statusHtml = '';
        if (status === 'approved') {
            statusHtml = '<span class="badge-status badge-status-approved"><i class="fa-solid fa-check"></i> Approved</span>';
        } else if (status === 'rejected') {
            statusHtml = '<span class="badge-status badge-status-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>';
        } else if (status === 'cancelled') {
            statusHtml = '<span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>';
        } else {
            statusHtml = '<span class="badge-status badge-status-pending"><i class="fa-regular fa-clock"></i> Pending Review</span>';
        }
        $('#view_status_badge').html(statusHtml);

        $('#viewLeaveModal').modal('show');
    });

    // Review Decision Button Handler
    $(document).on('click', '.review-btn', function() {
        var id = $(this).data('id');
        var name = $(this).data('student_name');
        var dates = $(this).data('dates');
        var days = $(this).data('days');
        var destination = $(this).data('destination');
        var reason = $(this).data('reason');
        var status = $(this).data('status');
        var remarks = $(this).data('remarks');

        $('#review_leave_id').val(id);
        $('#review_student_name').text(name);
        $('#review_days_pill').text(days + (days == 1 ? ' Day' : ' Days'));
        $('#review_dates_text').text(dates);
        $('#review_destination_text').text(destination);
        $('#review_reason_text').text(reason);
        $('#review_remarks_input').val(remarks ? remarks : '');

        if (status === 'approved' || status === 'rejected') {
            $('#review_status_select').val(status);
        } else {
            $('#review_status_select').val('approved');
        }

        $('#reviewLeaveModal').modal('show');
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
