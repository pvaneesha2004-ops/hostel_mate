<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$student_id = intval($_SESSION['student_id']);

// Handle POST actions: add, edit, cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. ADD LEAVE REQUEST
    if ($action === 'add_leave') {
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (empty($start_date) || empty($end_date) || empty($destination) || empty($reason)) {
            $_SESSION['error'] = "All fields are required. Please fill in all details.";
        } elseif (strtotime($end_date) < strtotime($start_date)) {
            $_SESSION['error'] = "End date cannot be earlier than start date.";
        } else {
            // Check for overlapping active (pending or approved) leaves
            $overlap_stmt = $conn->prepare("
                SELECT id FROM leave_requests 
                WHERE student_id = ? 
                  AND status IN ('pending', 'approved') 
                  AND NOT (end_date < ? OR start_date > ?)
                LIMIT 1
            ");
            $overlap_stmt->bind_param("iss", $student_id, $start_date, $end_date);
            $overlap_stmt->execute();
            $overlap_res = $overlap_stmt->get_result();

            if ($overlap_res && $overlap_res->num_rows > 0) {
                $_SESSION['error'] = "You already have a pending or approved leave request overlapping with this date range.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO leave_requests (student_id, start_date, end_date, reason, destination, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())
                ");
                $stmt->bind_param("issss", $student_id, $start_date, $end_date, $reason, $destination);

                if ($stmt->execute()) {
                    $_SESSION['msg'] = "Leave request submitted successfully! Awaiting Warden approval.";
                } else {
                    $_SESSION['error'] = "Failed to submit leave request: " . $conn->error;
                }
            }
        }
        header("Location: leave_requests.php");
        exit();
    }

    // 2. EDIT LEAVE REQUEST (Only when pending)
    if ($action === 'edit_leave') {
        $leave_id = intval($_POST['leave_id'] ?? 0);
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        // Verify leave exists, belongs to student, and is pending
        $check = $conn->query("SELECT id, status FROM leave_requests WHERE id = $leave_id AND student_id = $student_id LIMIT 1");
        if ($check && $row = $check->fetch_assoc()) {
            if ($row['status'] !== 'pending') {
                $_SESSION['error'] = "Only pending leave requests can be edited.";
            } elseif (empty($start_date) || empty($end_date) || empty($destination) || empty($reason)) {
                $_SESSION['error'] = "All fields are required.";
            } elseif (strtotime($end_date) < strtotime($start_date)) {
                $_SESSION['error'] = "End date cannot be earlier than start date.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE leave_requests 
                    SET start_date = ?, end_date = ?, destination = ?, reason = ?, updated_at = NOW() 
                    WHERE id = ? AND student_id = ? AND status = 'pending'
                ");
                $stmt->bind_param("ssssii", $start_date, $end_date, $destination, $reason, $leave_id, $student_id);
                if ($stmt->execute()) {
                    $_SESSION['msg'] = "Leave request updated successfully.";
                } else {
                    $_SESSION['error'] = "Failed to update leave request.";
                }
            }
        } else {
            $_SESSION['error'] = "Invalid leave request.";
        }
        header("Location: leave_requests.php");
        exit();
    }

    // 3. CANCEL LEAVE REQUEST (And delete from attendance table)
    if ($action === 'cancel_leave') {
        $leave_id = intval($_POST['leave_id'] ?? 0);

        $check = $conn->query("SELECT id, student_id, start_date, end_date, status FROM leave_requests WHERE id = $leave_id AND student_id = $student_id LIMIT 1");
        if ($check && $row = $check->fetch_assoc()) {
            if ($row['status'] === 'cancelled') {
                $_SESSION['error'] = "This leave request is already cancelled.";
            } else {
                $start_date = $row['start_date'];
                $end_date = $row['end_date'];

                // Update status to cancelled
                $stmt = $conn->prepare("UPDATE leave_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND student_id = ?");
                $stmt->bind_param("ii", $leave_id, $student_id);

                if ($stmt->execute()) {
                    // CRITICAL: Delete any recorded attendance for this leave period
                    $leave_pattern = "%Leave #{$leave_id}%";
                    $del_stmt = $conn->prepare("
                        DELETE FROM attendance 
                        WHERE student_id = ? 
                          AND attendance_date >= ? 
                          AND attendance_date <= ? 
                          AND (remarks LIKE ? OR remarks LIKE '%Approved Leave%' OR status = 'absent')
                    ");
                    $del_stmt->bind_param("isss", $student_id, $start_date, $end_date, $leave_pattern);
                    $del_stmt->execute();
                    $deleted_count = $del_stmt->affected_rows;

                    if ($deleted_count > 0) {
                        $_SESSION['msg'] = "Leave request cancelled successfully. {$deleted_count} attendance record(s) for this period were deleted.";
                    } else {
                        $_SESSION['msg'] = "Leave request cancelled successfully.";
                    }
                } else {
                    $_SESSION['error'] = "Failed to cancel leave request.";
                }
            }
        } else {
            $_SESSION['error'] = "Invalid leave request.";
        }
        header("Location: leave_requests.php");
        exit();
    }
}

// Fetch Student details & active room allocation
$student_info_res = $conn->query("
    SELECT u.name, u.email, u.phone, u.profile_image,
           rm.room_number, bd.bed_number, blk.name AS block_name
    FROM users u
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    WHERE u.id = $student_id
");
$student_info = ($student_info_res && $student_info_res->num_rows > 0) ? $student_info_res->fetch_assoc() : null;

// Fetch metrics for this student
$stats_res = $conn->query("
    SELECT 
        COUNT(*) AS total_leaves,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_leaves,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_leaves,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_leaves,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_leaves
    FROM leave_requests 
    WHERE student_id = $student_id
");
$stats = ($stats_res && $stats_res->num_rows > 0) ? $stats_res->fetch_assoc() : [
    'total_leaves' => 0, 'pending_leaves' => 0, 'approved_leaves' => 0, 'rejected_leaves' => 0, 'cancelled_leaves' => 0
];

// Fetch all leave requests of this student with reviewer names
$leaves_query = "
    SELECT lr.*, 
           COALESCE(w.name, u_rev.name, 'Authority') AS reviewer_name
    FROM leave_requests lr
    LEFT JOIN wardens w ON lr.reviewed_by = w.id
    LEFT JOIN users u_rev ON lr.reviewed_by = u_rev.id
    WHERE lr.student_id = $student_id
    ORDER BY lr.id DESC
";
$leaves_res = $conn->query($leaves_query);

ob_start();
?>

<!-- Custom CSS for Student Leave Requests Page -->
<style>
    .leave-stat-card {
        border: none;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        overflow: hidden;
        background: #ffffff;
    }
    .leave-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
    }
    .stat-icon-bubble {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    .badge-status {
        padding: 6px 12px;
        font-size: 0.78rem;
        font-weight: 600;
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
        background-color: #f0fdf4;
        color: #16a34a;
        border: 1px solid #bbf7d0;
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
    .action-btn-pill {
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
    .action-btn-pill:hover {
        transform: scale(1.1);
    }
    .leave-timeline-item {
        position: relative;
        padding-left: 28px;
        padding-bottom: 20px;
    }
    .leave-timeline-item:before {
        content: '';
        position: absolute;
        left: 7px;
        top: 22px;
        bottom: 0;
        width: 2px;
        background: #e2e8f0;
    }
    .leave-timeline-item:last-child:before {
        display: none;
    }
    .leave-timeline-dot {
        position: absolute;
        left: 0;
        top: 4px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: #6366f1;
        border: 3px solid #ffffff;
        box-shadow: 0 0 0 2px #e0e7ff;
    }
</style>

<div class="container-fluid px-0">
    <!-- Header Page Banner -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div>
            <h3 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.5px;">Leave & Outing Requests</h3>
            <p class="text-muted small mb-0">Apply for hostel leaves, monitor approval status, or cancel submitted requests.</p>
        </div>
        <div class="mt-3 mt-md-0">
            <button type="button" class="btn btn-primary shadow-sm" data-toggle="modal" data-target="#applyLeaveModal" style="border-radius: 50px; padding: 10px 22px; font-weight: 600;">
                <i class="fa-solid fa-plus mr-2"></i> Apply for Leave
            </button>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if(isset($_SESSION['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: #f0fdf4; color: #166534; border-left: 4px solid #22c55e !important;">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-circle-check mr-2" style="font-size: 1.1rem;"></i>
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
                <i class="fa-solid fa-triangle-exclamation mr-2" style="font-size: 1.1rem;"></i>
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
            <div class="card leave-stat-card p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-bubble mr-3" style="background: #eef2ff; color: #6366f1;">
                        <i class="fa-solid fa-paper-plane"></i>
                    </div>
                    <div>
                        <div class="text-muted small font-weight-medium">Total Applied</div>
                        <h4 class="font-weight-bold mb-0 text-dark"><?= intval($stats['total_leaves'] ?? 0) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card leave-stat-card p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-bubble mr-3" style="background: #fffbeb; color: #d97706;">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div>
                        <div class="text-muted small font-weight-medium">Pending Review</div>
                        <h4 class="font-weight-bold mb-0 text-warning"><?= intval($stats['pending_leaves'] ?? 0) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card leave-stat-card p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-bubble mr-3" style="background: #f0fdf4; color: #16a34a;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="text-muted small font-weight-medium">Approved</div>
                        <h4 class="font-weight-bold mb-0 text-success"><?= intval($stats['approved_leaves'] ?? 0) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3 mb-3 mb-lg-0">
            <div class="card leave-stat-card p-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-bubble mr-3" style="background: #f8fafc; color: #64748b;">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                    <div>
                        <div class="text-muted small font-weight-medium">Rejected / Cancelled</div>
                        <h4 class="font-weight-bold mb-0 text-muted"><?= intval(($stats['rejected_leaves'] ?? 0) + ($stats['cancelled_leaves'] ?? 0)) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Policy Note -->
    <div class="alert border-0 shadow-sm mb-4 p-3 rounded" style="background: #eff6ff; border-left: 4px solid #3b82f6 !important;">
        <div class="d-flex align-items-center">
            <i class="fa-solid fa-info-circle text-primary mr-3" style="font-size: 1.3rem;"></i>
            <div class="small text-dark">
                <strong>Hostel Leave & Attendance Rule:</strong> When your leave request is <strong>Approved</strong> by the Warden, your attendance is automatically marked as <span class="badge badge-danger">Absent (On Leave)</span> for the requested dates. If you <strong>Cancel</strong> the leave, those attendance entries will be automatically deleted.
            </div>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm rounded-16" style="background: #ffffff;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="card-title font-weight-bold mb-0 text-dark"><i class="fa-solid fa-list-check mr-2 text-primary"></i>My Leave History</h5>
            </div>

            <div class="table-responsive">
                <table class="table table-hover datatable" id="studentLeavesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Leave Period</th>
                            <th>Duration</th>
                            <th>Destination</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Reviewed By</th>
                            <th>Applied Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $sl = 1;
                        if ($leaves_res && $leaves_res->num_rows > 0):
                            while ($row = $leaves_res->fetch_assoc()):
                                $start_ts = strtotime($row['start_date']);
                                $end_ts = strtotime($row['end_date']);
                                $days = ceil(($end_ts - $start_ts) / (60 * 60 * 24)) + 1;
                                $status = strtolower($row['status']);
                        ?>
                        <tr>
                            <td><?= $sl++ ?></td>
                            <td>
                                <div class="font-weight-bold text-dark">
                                    <i class="fa-regular fa-calendar-days text-primary mr-1"></i>
                                    <?= date('d M Y', $start_ts) ?> &rarr; <?= date('d M Y', $end_ts) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-light border text-dark font-weight-bold"><?= $days ?> <?= ($days == 1 ? 'Day' : 'Days') ?></span>
                            </td>
                            <td>
                                <span class="font-weight-medium text-dark"><i class="fa-solid fa-location-dot text-danger mr-1 small"></i><?= htmlspecialchars($row['destination']) ?></span>
                            </td>
                            <td>
                                <span class="text-truncate d-inline-block" style="max-width: 180px;" title="<?= htmlspecialchars($row['reason']) ?>">
                                    <?= htmlspecialchars($row['reason']) ?>
                                </span>
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
                                    <div class="small font-weight-medium text-dark"><?= htmlspecialchars($row['reviewer_name']) ?></div>
                                    <small class="text-muted"><?= date('d M Y', strtotime($row['reviewed_at'])) ?></small>
                                <?php else: ?>
                                    <span class="text-muted font-italic small">Pending review</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></small>
                            </td>
                            <td class="text-center">
                                <div class="d-inline-flex" style="gap: 6px;">
                                    <!-- View Details Button -->
                                    <button type="button" class="action-btn-pill btn-info view-leave-btn text-white" title="View Details"
                                        data-id="<?= $row['id'] ?>"
                                        data-start_date="<?= date('d M Y', $start_ts) ?>"
                                        data-end_date="<?= date('d M Y', $end_ts) ?>"
                                        data-days="<?= $days ?>"
                                        data-destination="<?= htmlspecialchars($row['destination']) ?>"
                                        data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                        data-status="<?= $status ?>"
                                        data-reviewer="<?= htmlspecialchars($row['reviewer_name'] ?? 'Warden') ?>"
                                        data-reviewed_at="<?= (!empty($row['reviewed_at']) && $row['reviewed_at'] !== '0000-00-00') ? date('d M Y', strtotime($row['reviewed_at'])) : 'N/A' ?>"
                                        data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>"
                                        data-created_at="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>

                                    <!-- Edit Button (Only when pending) -->
                                    <?php if ($status === 'pending'): ?>
                                    <button type="button" class="action-btn-pill btn-warning edit-leave-btn text-dark" title="Edit Leave"
                                        data-id="<?= $row['id'] ?>"
                                        data-start_date="<?= $row['start_date'] ?>"
                                        data-end_date="<?= $row['end_date'] ?>"
                                        data-destination="<?= htmlspecialchars($row['destination']) ?>"
                                        data-reason="<?= htmlspecialchars($row['reason']) ?>">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <?php endif; ?>

                                    <!-- Cancel Button (Only when pending or approved) -->
                                    <?php if ($status === 'pending' || $status === 'approved'): ?>
                                    <button type="button" class="action-btn-pill btn-danger cancel-leave-btn text-white" title="Cancel Leave"
                                        data-id="<?= $row['id'] ?>"
                                        data-dates="<?= date('d M', $start_ts) ?> - <?= date('d M Y', $end_ts) ?>"
                                        data-is_approved="<?= ($status === 'approved') ? '1' : '0' ?>">
                                        <i class="fa-solid fa-ban"></i>
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

<!-- Modal 1: Apply for Leave -->
<div class="modal fade" id="applyLeaveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title font-weight-bold text-white"><i class="fa-solid fa-paper-plane mr-2"></i>Apply for Leave</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="leave_requests.php" id="applyLeaveForm">
                <input type="hidden" name="action" value="add_leave">
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-dark small">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="apply_start_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-dark small">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="apply_end_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Computed duration badge -->
                    <div class="mb-3 p-2 bg-light rounded d-flex align-items-center justify-content-between">
                        <span class="small text-muted font-weight-medium">Calculated Leave Duration:</span>
                        <span class="badge badge-primary font-weight-bold px-3 py-1" id="apply_duration_pill">1 Day</span>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold text-dark small">Destination / Visiting Place <span class="text-danger">*</span></label>
                        <input type="text" name="destination" class="form-control" placeholder="e.g. Home (Kannur), Hospital, College Event" required>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark small">Reason for Leave <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Provide detailed explanation for your leave request..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3 px-4">
                    <button type="button" class="btn btn-secondary font-weight-medium" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold px-4">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Edit Leave Request -->
<div class="modal fade" id="editLeaveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-warning text-dark py-3">
                <h5 class="modal-title font-weight-bold"><i class="fa-solid fa-pen mr-2"></i>Edit Leave Request</h5>
                <button type="button" class="close text-dark" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="leave_requests.php" id="editLeaveForm">
                <input type="hidden" name="action" value="edit_leave">
                <input type="hidden" name="leave_id" id="edit_leave_id">
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-dark small">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-dark small">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold text-dark small">Destination <span class="text-danger">*</span></label>
                        <input type="text" name="destination" id="edit_destination" class="form-control" required>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark small">Reason for Leave <span class="text-danger">*</span></label>
                        <textarea name="reason" id="edit_reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-3 px-4">
                    <button type="button" class="btn btn-secondary font-weight-medium" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning font-weight-bold px-4">Update Leave</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3: View Details Modal -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold text-white"><i class="fa-solid fa-circle-info mr-2"></i>Leave Request Details</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                    <div>
                        <span class="text-muted small">Status:</span>
                        <div id="modal_view_status" class="mt-1"></div>
                    </div>
                    <div class="text-right">
                        <span class="text-muted small">Duration:</span>
                        <div class="font-weight-bold text-dark h6 mb-0" id="modal_view_days"></div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted d-block font-weight-medium">Start Date</small>
                        <span class="font-weight-bold text-dark" id="modal_view_start"></span>
                    </div>
                    <div class="col-6">
                        <small class="text-muted d-block font-weight-medium">End Date</small>
                        <span class="font-weight-bold text-dark" id="modal_view_end"></span>
                    </div>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block font-weight-medium">Destination</small>
                    <span class="font-weight-bold text-dark" id="modal_view_destination"></span>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block font-weight-medium">Reason</small>
                    <div class="p-2 rounded bg-light text-dark font-weight-medium small" id="modal_view_reason"></div>
                </div>

                <!-- Review Section -->
                <div class="card bg-light border p-3 rounded-12 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="font-weight-bold text-dark small"><i class="fa-solid fa-user-shield mr-1 text-primary"></i> Warden Review:</span>
                        <span class="small text-muted" id="modal_view_reviewed_at"></span>
                    </div>
                    <div class="small mb-1"><strong>Reviewed By:</strong> <span id="modal_view_reviewer"></span></div>
                    <div class="small"><strong>Remarks:</strong> <span id="modal_view_remarks" class="text-muted font-italic"></span></div>
                </div>

                <div class="text-muted small text-right">
                    <span>Applied on: <strong id="modal_view_created_at"></strong></span>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Cancel Form -->
<form id="cancelLeaveForm" method="POST" action="leave_requests.php" style="display: none;">
    <input type="hidden" name="action" value="cancel_leave">
    <input type="hidden" name="leave_id" id="cancel_leave_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Initialize DataTable
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#studentLeavesTable')) {
        $('#studentLeavesTable').DataTable({
            "order": [[ 0, "desc" ]],
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search leave requests..."
            }
        });
    }

    // Function to calculate leave duration days
    function updateDuration(startInputId, endInputId, badgeId) {
        var start = document.getElementById(startInputId).value;
        var end = document.getElementById(endInputId).value;
        if (start && end) {
            var sDate = new Date(start);
            var eDate = new Date(end);
            var diffTime = eDate - sDate;
            if (diffTime >= 0) {
                var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                document.getElementById(badgeId).innerText = diffDays + (diffDays === 1 ? ' Day' : ' Days');
            } else {
                document.getElementById(badgeId).innerText = 'Invalid range';
            }
        }
    }

    // Live duration listener on Apply modal
    $('#apply_start_date, #apply_end_date').on('change', function() {
        var startVal = $('#apply_start_date').val();
        $('#apply_end_date').attr('min', startVal);
        updateDuration('apply_start_date', 'apply_end_date', 'apply_duration_pill');
    });

    // View Details Modal click
    $(document).on('click', '.view-leave-btn', function() {
        var start = $(this).data('start_date');
        var end = $(this).data('end_date');
        var days = $(this).data('days');
        var destination = $(this).data('destination');
        var reason = $(this).data('reason');
        var status = $(this).data('status');
        var reviewer = $(this).data('reviewer');
        var reviewedAt = $(this).data('reviewed_at');
        var remarks = $(this).data('remarks');
        var createdAt = $(this).data('created_at');

        $('#modal_view_start').text(start);
        $('#modal_view_end').text(end);
        $('#modal_view_days').text(days + (days == 1 ? ' Day' : ' Days'));
        $('#modal_view_destination').text(destination);
        $('#modal_view_reason').text(reason);
        $('#modal_view_reviewer').text(reviewer);
        $('#modal_view_reviewed_at').text(reviewedAt !== 'N/A' ? ('Date: ' + reviewedAt) : '');
        $('#modal_view_remarks').text(remarks ? remarks : 'No review remarks provided.');
        $('#modal_view_created_at').text(createdAt);

        var statusBadgeHtml = '';
        if (status === 'approved') {
            statusBadgeHtml = '<span class="badge-status badge-status-approved"><i class="fa-solid fa-check"></i> Approved</span>';
        } else if (status === 'rejected') {
            statusBadgeHtml = '<span class="badge-status badge-status-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>';
        } else if (status === 'cancelled') {
            statusBadgeHtml = '<span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>';
        } else {
            statusBadgeHtml = '<span class="badge-status badge-status-pending"><i class="fa-regular fa-clock"></i> Pending Review</span>';
        }
        $('#modal_view_status').html(statusBadgeHtml);

        $('#viewLeaveModal').modal('show');
    });

    // Edit Leave Modal click
    $(document).on('click', '.edit-leave-btn', function() {
        var id = $(this).data('id');
        var start = $(this).data('start_date');
        var end = $(this).data('end_date');
        var destination = $(this).data('destination');
        var reason = $(this).data('reason');

        $('#edit_leave_id').val(id);
        $('#edit_start_date').val(start);
        $('#edit_end_date').val(end);
        $('#edit_destination').val(destination);
        $('#edit_reason').val(reason);

        $('#editLeaveModal').modal('show');
    });

    // Cancel Leave Confirmation click
    $(document).on('click', '.cancel-leave-btn', function() {
        var id = $(this).data('id');
        var dates = $(this).data('dates');
        var isApproved = $(this).data('is_approved') === '1' || $(this).data('is_approved') === 1;

        var warningText = "Are you sure you want to cancel this leave request for (" + dates + ")?";
        if (isApproved) {
            warningText += "\nNote: Since this leave was approved, any absent attendance entries for these dates will be automatically deleted from the attendance table.";
        }

        Swal.fire({
            title: 'Cancel Leave Request?',
            text: warningText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Cancel Leave',
            cancelButtonText: 'No, Keep It'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#cancel_leave_id').val(id);
                $('#cancelLeaveForm').submit();
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
