<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['student_id'])) {
    header("Location: ../Student/login.php");
    exit();
}

require_once '../db.php';

$student_id = intval($_SESSION['student_id']);
$student_name = $_SESSION['student_name'] ?? 'Student';

// Fetch configured Outing Timings from `hostel_timings`
$hostel_timings_query = $conn->query("SELECT * FROM `hostel_timings` ORDER BY `id` DESC LIMIT 1");
$hostel_timing = ($hostel_timings_query && $hostel_timings_query->num_rows > 0) ? $hostel_timings_query->fetch_assoc() : null;
$out_start_time = $hostel_timing ? $hostel_timing['outing_start_time'] : '08:00:00';
$out_end_time   = $hostel_timing ? $hostel_timing['outing_end_time'] : '18:00:00';
$out_window_fmt = (!empty($out_start_time) && !empty($out_end_time))
    ? date('h:i A', strtotime($out_start_time)) . ' - ' . date('h:i A', strtotime($out_end_time))
    : '08:00 AM - 06:00 PM';

// Handle Add Outing Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_outing') {
    $outing_date = trim($_POST['outing_date'] ?? date('Y-m-d'));
    $destination = trim($_POST['destination'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $out_time_raw = trim($_POST['expected_out_time'] ?? '');
    $return_time_raw = trim($_POST['expected_return_time'] ?? '');

    if (empty($destination) || empty($reason) || empty($out_time_raw) || empty($return_time_raw)) {
        $_SESSION['error'] = "Please fill in all required fields (Destination, Out Time, Return Time, and Reason).";
    } elseif (!strtotime($outing_date)) {
        $_SESSION['error'] = "Please select a valid outing date.";
    } else {
        // Construct full datetime values
        $exp_out_dt = date('Y-m-d H:i:s', strtotime("{$outing_date} {$out_time_raw}"));
        $exp_return_dt = date('Y-m-d H:i:s', strtotime("{$outing_date} {$return_time_raw}"));

        // If return time is earlier than out time, assume next day return
        if (strtotime($exp_return_dt) <= strtotime($exp_out_dt)) {
            $exp_return_dt = date('Y-m-d H:i:s', strtotime("{$outing_date} {$return_time_raw} +1 day"));
        }

        $stmt = $conn->prepare("
            INSERT INTO outing_requests (student_id, outing_date, destination, reason, expected_out_time, expected_return_time, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
        ");
        if ($stmt) {
            $stmt->bind_param("isssss", $student_id, $outing_date, $destination, $reason, $exp_out_dt, $exp_return_dt);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Outing request for " . date('d M Y', strtotime($outing_date)) . " submitted successfully! Awaiting Warden approval.";
            } else {
                $_SESSION['error'] = "Failed to submit request: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    }
    header("Location: outing_requests.php");
    exit();
}

// Handle Edit Outing Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_outing') {
    $outing_id = intval($_POST['outing_id'] ?? 0);
    $outing_date = trim($_POST['outing_date'] ?? date('Y-m-d'));
    $destination = trim($_POST['destination'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $out_time_raw = trim($_POST['expected_out_time'] ?? '');
    $return_time_raw = trim($_POST['expected_return_time'] ?? '');

    if ($outing_id <= 0 || empty($destination) || empty($reason) || empty($out_time_raw) || empty($return_time_raw)) {
        $_SESSION['error'] = "Please provide all required fields.";
    } else {
        // Verify ownership and pending status
        $check = $conn->query("SELECT status FROM outing_requests WHERE id = {$outing_id} AND student_id = {$student_id} LIMIT 1");
        if ($check && $row = $check->fetch_assoc()) {
            if ($row['status'] === 'pending') {
                $exp_out_dt = date('Y-m-d H:i:s', strtotime("{$outing_date} {$out_time_raw}"));
                $exp_return_dt = date('Y-m-d H:i:s', strtotime("{$outing_date} {$return_time_raw}"));
                if (strtotime($exp_return_dt) <= strtotime($exp_out_dt)) {
                    $exp_return_dt = date('Y-m-d H:i:s', strtotime("{$outing_date} {$return_time_raw} +1 day"));
                }

                $stmt = $conn->prepare("
                    UPDATE outing_requests 
                    SET outing_date = ?, destination = ?, reason = ?, expected_out_time = ?, expected_return_time = ?, updated_at = NOW() 
                    WHERE id = ? AND student_id = ? AND status = 'pending'
                ");
                $stmt->bind_param("sssssii", $outing_date, $destination, $reason, $exp_out_dt, $exp_return_dt, $outing_id, $student_id);
                if ($stmt->execute()) {
                    $_SESSION['msg'] = "Outing request #{$outing_id} updated successfully.";
                } else {
                    $_SESSION['error'] = "Failed to update request.";
                }
                $stmt->close();
            } else {
                $_SESSION['error'] = "Only pending requests can be modified.";
            }
        } else {
            $_SESSION['error'] = "Request record not found.";
        }
    }
    header("Location: outing_requests.php");
    exit();
}

// Handle Cancel Outing Request
if (isset($_GET['action']) && $_GET['action'] === 'cancel_outing') {
    $cancel_id = intval($_GET['id'] ?? 0);
    if ($cancel_id > 0) {
        $check = $conn->query("SELECT status FROM outing_requests WHERE id = {$cancel_id} AND student_id = {$student_id} LIMIT 1");
        if ($check && $row = $check->fetch_assoc()) {
            if (in_array($row['status'], ['pending', 'approved'])) {
                $conn->query("UPDATE outing_requests SET status = 'cancelled', updated_at = NOW() WHERE id = {$cancel_id} AND student_id = {$student_id}");
                $_SESSION['msg'] = "Outing request #{$cancel_id} was successfully cancelled.";
            } else {
                $_SESSION['error'] = "Already processed outings cannot be cancelled.";
            }
        }
    }
    header("Location: outing_requests.php");
    exit();
}

// Filter handling
$filter = trim($_GET['filter'] ?? 'all');
$where_clauses = ["student_id = {$student_id}"];
if (in_array($filter, ['pending', 'approved', 'rejected', 'cancelled'])) {
    $where_clauses[] = "status = '" . $conn->real_escape_string($filter) . "'";
}
$where_sql = implode(' AND ', $where_clauses);

// Fetch KPI metrics for this student
$metrics_res = $conn->query("
    SELECT 
        COUNT(*) AS total_outings,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_outings,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_outings,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_outings,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_outings
    FROM outing_requests 
    WHERE student_id = {$student_id}
");
$metrics = ($metrics_res && $metrics_res->num_rows > 0) ? $metrics_res->fetch_assoc() : [
    'total_outings' => 0, 'pending_outings' => 0, 'approved_outings' => 0, 'rejected_outings' => 0, 'cancelled_outings' => 0
];

// Fetch Outing Requests with Reviewer details
$query = "
    SELECT o.*,
           COALESCE(w.name, u_rev.name, 'Authority') AS reviewer_name,
           CASE 
               WHEN w.name IS NOT NULL THEN 'Warden'
               WHEN u_rev.name IS NOT NULL THEN 'Admin'
               ELSE 'System'
           END AS reviewer_role
    FROM outing_requests o
    LEFT JOIN wardens w ON o.reviewed_by = w.id
    LEFT JOIN users u_rev ON o.reviewed_by = u_rev.id
    WHERE {$where_sql}
    ORDER BY o.outing_date DESC, o.id DESC
";
$outings = $conn->query($query);

ob_start();
?>

<!-- Custom CSS for Student Outing Requests -->
<style>
    .outing-hero-card {
        background: linear-gradient(135deg, #0f766e 0%, #0d9488 50%, #14b8a6 100%);
        border-radius: 18px;
        color: #ffffff;
        padding: 2rem 2.25rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(13, 148, 136, 0.22);
    }
    .outing-hero-card::after {
        content: '';
        position: absolute;
        right: -30px;
        bottom: -40px;
        width: 180px;
        height: 180px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 50%;
        pointer-events: none;
    }
    .stat-card-widget {
        border-radius: 16px;
        padding: 1.25rem 1.4rem;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-card-widget:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
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
    .badge-status-pending { background-color: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
    .badge-status-approved { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
    .badge-status-rejected { background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
    .badge-status-cancelled { background-color: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

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
    .filter-pill:hover { background: #f8fafc; color: #0f172a; }
    .filter-pill.active {
        background: #0d9488 !important;
        border-color: #0d9488 !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.28);
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
    .btn-action-circle:hover { transform: scale(1.1); }
</style>

<!-- Hero Section with Active Outing Window -->
<div class="row mb-4">
    <div class="col-12">
        <div class="outing-hero-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <span class="badge badge-light text-dark font-weight-bold px-3 py-1 mb-2" style="border-radius: 50px; font-size: 0.75rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-clock text-success mr-1"></i> CAMPUS PERMITTED HOURS: <?= htmlspecialchars($out_window_fmt) ?>
                    </span>
                    <h2 class="font-weight-bold text-white mb-2">Student Outing Permissions</h2>
                    <p class="text-white-50 mb-0" style="max-width: 600px; font-size: 0.95rem;">
                        Submit day outing requests, specify your destination and schedule, and track Warden authorizations.
                    </p>
                </div>
                <div class="col-md-4 text-md-right mt-3 mt-md-0">
                    <button type="button" class="btn btn-light px-4 py-2 font-weight-bold shadow-sm" data-toggle="modal" data-target="#applyOutingModal" style="border-radius: 12px; color: #0f766e;">
                        <i class="fa-solid fa-plus-circle mr-1 text-success"></i> Request Outing
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alert Notifications -->
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
    <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
        <a href="outing_requests.php?filter=all" class="text-decoration-none d-block h-100">
            <div class="stat-card-widget <?= ($filter === 'all') ? 'border-primary shadow-sm' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase;">Total Outings</span>
                        <h3 class="font-weight-bold text-dark mb-0"><?= $metrics['total_outings'] ?></h3>
                        <small class="text-muted">All-time applied</small>
                    </div>
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(13, 148, 136, 0.1); color: #0d9488; font-size: 1.25rem;">
                        <i class="fa-solid fa-ticket"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
        <a href="outing_requests.php?filter=pending" class="text-decoration-none d-block h-100">
            <div class="stat-card-widget <?= ($filter === 'pending') ? 'border-warning shadow-sm' : '' ?>" style="<?= ($metrics['pending_outings'] > 0) ? 'background: #fffdf5;' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-warning font-weight-700 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase;">Pending Decision</span>
                        <h3 class="font-weight-bold text-warning mb-0 d-flex align-items-center">
                            <?= $metrics['pending_outings'] ?>
                            <?php if ($metrics['pending_outings'] > 0): ?>
                                <span class="pulse-indicator ml-2"></span>
                            <?php endif; ?>
                        </h3>
                        <small class="text-warning font-weight-bold">Awaiting approval</small>
                    </div>
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; font-size: 1.25rem;">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
        <a href="outing_requests.php?filter=approved" class="text-decoration-none d-block h-100">
            <div class="stat-card-widget <?= ($filter === 'approved') ? 'border-success shadow-sm' : '' ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-success font-weight-700 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase;">Approved Passes</span>
                        <h3 class="font-weight-bold text-success mb-0"><?= $metrics['approved_outings'] ?></h3>
                        <small class="text-success font-weight-bold">Permissions granted</small>
                    </div>
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 1.25rem;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-lg-3 col-sm-6">
        <a href="outing_requests.php?filter=rejected" class="text-decoration-none d-block h-100">
            <div class="stat-card-widget">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase;">Rejected / Cancelled</span>
                        <h3 class="font-weight-bold text-danger mb-0"><?= ($metrics['rejected_outings'] + $metrics['cancelled_outings']) ?></h3>
                        <small class="text-muted"><?= $metrics['rejected_outings'] ?> Rejected &bull; <?= $metrics['cancelled_outings'] ?> Cancelled</small>
                    </div>
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(239, 68, 68, 0.1); color: #ef4444; font-size: 1.25rem;">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Status Filter Pills Bar -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted font-weight-bold mr-2" style="font-size: 0.82rem;">Filter Status:</span>
        <a href="outing_requests.php?filter=all" class="filter-pill <?= ($filter === 'all') ? 'active' : '' ?>">
            All <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['total_outings'] ?></span>
        </a>
        <a href="outing_requests.php?filter=pending" class="filter-pill <?= ($filter === 'pending') ? 'active' : '' ?>">
            <span class="pulse-indicator mr-1" style="width: 6px; height: 6px;"></span> Pending 
            <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['pending_outings'] ?></span>
        </a>
        <a href="outing_requests.php?filter=approved" class="filter-pill <?= ($filter === 'approved') ? 'active' : '' ?>">
            <i class="fa-solid fa-check mr-1" style="font-size: 11px;"></i> Approved 
            <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['approved_outings'] ?></span>
        </a>
        <a href="outing_requests.php?filter=rejected" class="filter-pill <?= ($filter === 'rejected') ? 'active' : '' ?>">
            Rejected <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['rejected_outings'] ?></span>
        </a>
        <a href="outing_requests.php?filter=cancelled" class="filter-pill <?= ($filter === 'cancelled') ? 'active' : '' ?>">
            Cancelled <span class="badge badge-light ml-1" style="border-radius: 50px;"><?= $metrics['cancelled_outings'] ?></span>
        </a>
    </div>
</div>

<!-- Main Outing Requests Data Card -->
<div class="card shadow-sm border-0" style="border-radius: 16px;">
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" id="outingRequestsTable">
                <thead class="bg-light text-uppercase font-weight-bold" style="font-size: 0.76rem; letter-spacing: 0.5px; color: #475569;">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Outing Date</th>
                        <th>Expected Window</th>
                        <th>Destination</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Authority Review</th>
                        <th class="text-right" style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($outings && $outings->num_rows > 0):
                        $sl = 1;
                        while ($row = $outings->fetch_assoc()):
                            $status = strtolower($row['status'] ?? 'pending');
                            $out_date = date('d M Y', strtotime($row['outing_date']));
                            $day_name = date('D', strtotime($row['outing_date']));
                            $exp_out = !empty($row['expected_out_time']) ? date('h:i A', strtotime($row['expected_out_time'])) : 'N/A';
                            $exp_ret = !empty($row['expected_return_time']) ? date('h:i A', strtotime($row['expected_return_time'])) : 'N/A';
                    ?>
                    <tr>
                        <td class="font-weight-bold text-muted"><?= $sl++ ?></td>

                        <!-- Outing Date -->
                        <td>
                            <div class="font-weight-bold text-dark" style="font-size: 0.92rem;">
                                <?= $out_date ?>
                            </div>
                            <small class="badge badge-light text-muted px-2 py-0" style="border-radius: 4px; font-size: 0.72rem;">
                                <?= $day_name ?>
                            </small>
                        </td>

                        <!-- Expected Out & Return -->
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="text-primary font-weight-bold" style="font-size: 0.85rem;"><?= $exp_out ?></span>
                                <i class="fa-solid fa-arrow-right-long text-muted mx-2" style="font-size: 0.75rem;"></i>
                                <span class="text-success font-weight-bold" style="font-size: 0.85rem;"><?= $exp_ret ?></span>
                            </div>
                            <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">
                                <i class="fa-regular fa-clock mr-1"></i>Permitted Day Window
                            </small>
                        </td>

                        <!-- Destination -->
                        <td>
                            <span class="font-weight-semibold text-dark d-inline-block text-truncate" style="max-width: 170px;" title="<?= htmlspecialchars($row['destination']) ?>">
                                <i class="fa-solid fa-location-dot text-danger mr-1"></i><?= htmlspecialchars($row['destination']) ?>
                            </span>
                        </td>

                        <!-- Reason -->
                        <td>
                            <span class="text-muted d-inline-block text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($row['reason']) ?>">
                                <?= htmlspecialchars($row['reason']) ?>
                            </span>
                        </td>

                        <!-- Status Badge -->
                        <td>
                            <?php if ($status === 'pending'): ?>
                                <span class="badge-status badge-status-pending">
                                    <span class="pulse-indicator"></span> Pending
                                </span>
                            <?php elseif ($status === 'approved'): ?>
                                <span class="badge-status badge-status-approved">
                                    <i class="fa-solid fa-circle-check"></i> Approved
                                </span>
                            <?php elseif ($status === 'rejected'): ?>
                                <span class="badge-status badge-status-rejected">
                                    <i class="fa-solid fa-circle-xmark"></i> Rejected
                                </span>
                            <?php else: ?>
                                <span class="badge-status badge-status-cancelled">
                                    <i class="fa-solid fa-ban"></i> Cancelled
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Authority Review Info -->
                        <td>
                            <?php if (!empty($row['reviewed_at']) && $status !== 'pending'): ?>
                                <div class="font-weight-bold text-dark" style="font-size: 0.82rem;">
                                    <?= htmlspecialchars($row['reviewer_name']) ?>
                                    <span class="badge badge-light border text-primary ml-1" style="font-size: 0.68rem;"><?= htmlspecialchars($row['reviewer_role']) ?></span>
                                </div>
                                <small class="text-muted d-block"><?= date('d M Y', strtotime($row['reviewed_at'])) ?></small>
                                <?php if (!empty($row['remarks'])): ?>
                                    <small class="text-muted d-block text-truncate font-italic" style="max-width: 140px;" title="<?= htmlspecialchars($row['remarks']) ?>">
                                        "<?= htmlspecialchars($row['remarks']) ?>"
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted font-italic" style="font-size: 0.82rem;">Pending Review</span>
                            <?php endif; ?>
                        </td>

                        <!-- Action Buttons -->
                        <td class="text-right">
                            <div class="d-inline-flex align-items-center gap-1">
                                <!-- View Details Modal Trigger -->
                                <button type="button" class="btn-action-circle bg-light text-primary btn-view-outing" title="View Outing Pass"
                                    data-id="<?= $row['id'] ?>"
                                    data-date="<?= $out_date ?>"
                                    data-day="<?= $day_name ?>"
                                    data-dest="<?= htmlspecialchars($row['destination']) ?>"
                                    data-reason="<?= htmlspecialchars($row['reason']) ?>"
                                    data-expout="<?= $exp_out ?>"
                                    data-expret="<?= $exp_ret ?>"
                                    data-status="<?= $status ?>"
                                    data-reviewer="<?= htmlspecialchars($row['reviewer_name']) ?>"
                                    data-reviewer-role="<?= htmlspecialchars($row['reviewer_role']) ?>"
                                    data-reviewed-at="<?= !empty($row['reviewed_at']) ? date('d M Y, h:i A', strtotime($row['reviewed_at'])) : 'Pending' ?>"
                                    data-remarks="<?= htmlspecialchars($row['remarks'] ?? 'No remarks provided.') ?>"
                                    data-created="<?= date('d M Y, h:i A', strtotime($row['created_at'])) ?>">
                                    <i class="fa-solid fa-eye"></i>
                                </button>

                                <?php if ($status === 'pending'): ?>
                                    <!-- Edit Modal Trigger -->
                                    <button type="button" class="btn-action-circle bg-light text-warning btn-edit-outing" title="Modify Pending Outing"
                                        data-id="<?= $row['id'] ?>"
                                        data-date="<?= date('Y-m-d', strtotime($row['outing_date'])) ?>"
                                        data-expout="<?= !empty($row['expected_out_time']) ? date('H:i', strtotime($row['expected_out_time'])) : '16:00' ?>"
                                        data-expret="<?= !empty($row['expected_return_time']) ? date('H:i', strtotime($row['expected_return_time'])) : '19:30' ?>"
                                        data-dest="<?= htmlspecialchars($row['destination']) ?>"
                                        data-reason="<?= htmlspecialchars($row['reason']) ?>">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>

                                    <!-- Cancel Request -->
                                    <a href="outing_requests.php?action=cancel_outing&id=<?= $row['id'] ?>" class="btn-action-circle bg-light text-danger btn-cancel-outing" title="Cancel Request">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php 
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <div class="d-flex flex-column align-items-center justify-content-center">
                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                    <i class="fa-solid fa-person-walking-luggage text-muted" style="font-size: 1.5rem;"></i>
                                </div>
                                <h6 class="font-weight-bold text-secondary mb-1">No Outing Records</h6>
                                <p class="text-muted small mb-3">You have not requested any outings matching the current filter.</p>
                                <button type="button" class="btn btn-sm btn-outline-success font-weight-bold" data-toggle="modal" data-target="#applyOutingModal" style="border-radius: 8px;">
                                    <i class="fa-solid fa-plus-circle mr-1"></i> Apply for Outing
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL: APPLY NEW OUTING REQUEST
     ========================================================= -->
<div class="modal fade" id="applyOutingModal" tabindex="-1" role="dialog" aria-labelledby="applyOutingModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 18px; border: none; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.15);">
            <div class="modal-header d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: #ffffff;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white text-success p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; color: #0d9488 !important;">
                        <i class="fa-solid fa-person-walking-luggage"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-white mb-0" id="applyOutingModalTitle">New Outing Request</h5>
                        <small class="text-white-50">Apply for day permission outside campus</small>
                    </div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form action="outing_requests.php" method="POST" id="applyOutingForm">
                <input type="hidden" name="action" value="add_outing">

                <div class="modal-body p-4">
                    <!-- Date -->
                    <div class="form-group mb-3">
                        <label for="apply_outing_date" class="font-weight-bold text-dark small text-uppercase">Outing Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="outing_date" id="apply_outing_date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required style="border-radius: 10px; height: 44px;">
                    </div>

                    <!-- Time Range -->
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group mb-3">
                                <label for="apply_out_time" class="font-weight-bold text-dark small text-uppercase">Expected Out Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="expected_out_time" id="apply_out_time" value="16:00" required style="border-radius: 10px; height: 44px;">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group mb-3">
                                <label for="apply_return_time" class="font-weight-bold text-dark small text-uppercase">Expected Return <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="expected_return_time" id="apply_return_time" value="19:30" required style="border-radius: 10px; height: 44px;">
                            </div>
                        </div>
                    </div>

                    <!-- Destination -->
                    <div class="form-group mb-3">
                        <label for="apply_destination" class="font-weight-bold text-dark small text-uppercase">Destination Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="destination" id="apply_destination" placeholder="e.g. City Central Mall / Medical Clinic / Library" required style="border-radius: 10px; height: 44px;">
                    </div>

                    <!-- Reason -->
                    <div class="form-group mb-3">
                        <label for="apply_reason" class="font-weight-bold text-dark small text-uppercase">Purpose / Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="apply_reason" rows="3" placeholder="Provide detailed explanation for the outing request..." required style="border-radius: 10px;"></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-secondary px-4 font-weight-semibold" data-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 font-weight-bold shadow-sm" style="background: #0d9488; border-color: #0d9488; border-radius: 8px;">
                        <i class="fa-solid fa-paper-plane mr-1"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL: EDIT PENDING OUTING REQUEST
     ========================================================= -->
<div class="modal fade" id="editOutingModal" tabindex="-1" role="dialog" aria-labelledby="editOutingModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 18px; border: none; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.15);">
            <div class="modal-header" style="background: #0284c7; color: #ffffff;">
                <div class="d-flex align-items-center">
                    <i class="fa-regular fa-pen-to-square mr-2 font-weight-bold" style="font-size: 1.25rem;"></i>
                    <h5 class="modal-title font-weight-bold text-white mb-0" id="editOutingModalTitle">Modify Outing Request</h5>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form action="outing_requests.php" method="POST" id="editOutingForm">
                <input type="hidden" name="action" value="edit_outing">
                <input type="hidden" name="outing_id" id="edit_outing_id">

                <div class="modal-body p-4">
                    <div class="form-group mb-3">
                        <label for="edit_outing_date" class="font-weight-bold text-dark small text-uppercase">Outing Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="outing_date" id="edit_outing_date" required style="border-radius: 10px; height: 44px;">
                    </div>

                    <div class="row">
                        <div class="col-6">
                            <div class="form-group mb-3">
                                <label for="edit_out_time" class="font-weight-bold text-dark small text-uppercase">Expected Out Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="expected_out_time" id="edit_out_time" required style="border-radius: 10px; height: 44px;">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group mb-3">
                                <label for="edit_return_time" class="font-weight-bold text-dark small text-uppercase">Expected Return <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" name="expected_return_time" id="edit_return_time" required style="border-radius: 10px; height: 44px;">
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="edit_destination" class="font-weight-bold text-dark small text-uppercase">Destination Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="destination" id="edit_destination" required style="border-radius: 10px; height: 44px;">
                    </div>

                    <div class="form-group mb-3">
                        <label for="edit_reason" class="font-weight-bold text-dark small text-uppercase">Purpose / Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="edit_reason" rows="3" required style="border-radius: 10px;"></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light d-flex justify-content-between p-3">
                    <button type="button" class="btn btn-secondary px-4 font-weight-semibold" data-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 font-weight-bold shadow-sm" style="background: #0284c7; border-color: #0284c7; border-radius: 8px;">
                        <i class="fa-solid fa-check mr-1"></i> Update Outing Pass
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL: VIEW FULL OUTING DETAILS
     ========================================================= -->
<div class="modal fade" id="viewOutingModal" tabindex="-1" role="dialog" aria-labelledby="viewOutingModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 18px; border: none; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.15);">
            <div class="modal-header d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: #ffffff;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white text-success p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.15rem; color: #0d9488 !important;">
                        <i class="fa-solid fa-id-card-clip"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-white mb-0" id="viewOutingModalTitle">Outing Pass Details</h5>
                        <small class="text-white-50">Reference ID: #<span id="modalOutingId"></span></small>
                    </div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body p-4">
                <!-- Status Banner -->
                <div class="d-flex justify-content-between align-items-center p-3 mb-3 rounded" style="background: #f0fdfa; border: 1px solid #ccfbf1;">
                    <div>
                        <span class="text-muted small d-block">Permission Status</span>
                        <div id="modalStatusBadge" class="mt-1"></div>
                    </div>
                    <div class="text-right">
                        <span class="text-muted small d-block">Requested On</span>
                        <span id="modalCreated" class="font-weight-bold text-dark small"></span>
                    </div>
                </div>

                <!-- Outing Details Box -->
                <div class="p-3 rounded bg-light border mb-3">
                    <div class="row mb-2">
                        <div class="col-6">
                            <span class="text-muted small d-block">Outing Date</span>
                            <span id="modalDate" class="font-weight-bold text-dark"></span>
                        </div>
                        <div class="col-6 text-right">
                            <span class="text-muted small d-block">Destination</span>
                            <span id="modalDest" class="font-weight-bold text-danger"></span>
                        </div>
                    </div>
                    <div class="row pt-2 border-top">
                        <div class="col-6">
                            <span class="text-muted small d-block">Expected Out Time</span>
                            <span id="modalExpOut" class="font-weight-bold text-primary"></span>
                        </div>
                        <div class="col-6 text-right">
                            <span class="text-muted small d-block">Expected Return</span>
                            <span id="modalExpRet" class="font-weight-bold text-success"></span>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="mb-3">
                    <span class="text-muted small d-block font-weight-bold mb-1">Purpose / Reason</span>
                    <div id="modalReason" class="p-2 rounded bg-light border small text-dark" style="white-space: pre-line;"></div>
                </div>

                <!-- Authority Review Notes -->
                <div class="p-3 rounded border" style="background: #f8fafc;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small font-weight-bold">Reviewed By:</span>
                        <span id="modalReviewer" class="font-weight-bold text-dark small"></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small font-weight-bold">Reviewed Date:</span>
                        <span id="modalReviewedAt" class="text-dark small"></span>
                    </div>
                    <div>
                        <span class="text-muted small font-weight-bold d-block">Authority Remarks:</span>
                        <span id="modalRemarks" class="text-dark font-italic small"></span>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light p-3">
                <button type="button" class="btn btn-secondary btn-block font-weight-semibold" data-dismiss="modal" style="border-radius: 8px;">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.32/sweetalert2.all.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize DataTables
    if ($.fn.DataTable && $('#outingRequestsTable').length) {
        $('#outingRequestsTable').DataTable({
            responsive: true,
            order: [],
            pageLength: 10,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search outings..."
            }
        });
    }

    // View Modal Trigger
    $('.btn-view-outing').on('click', function() {
        var btn = $(this);
        $('#modalOutingId').text(btn.data('id'));
        $('#modalDate').text(btn.data('date') + ' (' + btn.data('day') + ')');
        $('#modalDest').text(btn.data('dest'));
        $('#modalExpOut').text(btn.data('expout'));
        $('#modalExpRet').text(btn.data('expret'));
        $('#modalReason').text(btn.data('reason'));
        $('#modalReviewer').text(btn.data('reviewer') + ' (' + btn.data('reviewer-role') + ')');
        $('#modalReviewedAt').text(btn.data('reviewed-at'));
        $('#modalRemarks').text(btn.data('remarks'));
        $('#modalCreated').text(btn.data('created'));

        var st = btn.data('status');
        var badgeHtml = '';
        if (st === 'pending') {
            badgeHtml = '<span class="badge-status badge-status-pending"><span class="pulse-indicator"></span> Pending Review</span>';
        } else if (st === 'approved') {
            badgeHtml = '<span class="badge-status badge-status-approved"><i class="fa-solid fa-circle-check"></i> Approved Pass</span>';
        } else if (st === 'rejected') {
            badgeHtml = '<span class="badge-status badge-status-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
        } else {
            badgeHtml = '<span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>';
        }
        $('#modalStatusBadge').html(badgeHtml);
        $('#viewOutingModal').modal('show');
    });

    // Edit Modal Trigger
    $('.btn-edit-outing').on('click', function() {
        var btn = $(this);
        $('#edit_outing_id').val(btn.data('id'));
        $('#edit_outing_date').val(btn.data('date'));
        $('#edit_out_time').val(btn.data('expout'));
        $('#edit_return_time').val(btn.data('expret'));
        $('#edit_destination').val(btn.data('dest'));
        $('#edit_reason').val(btn.data('reason'));
        $('#editOutingModal').modal('show');
    });

    // Cancel Outing Confirmation with SweetAlert2
    $('.btn-cancel-outing').on('click', function(e) {
        e.preventDefault();
        var targetUrl = $(this).attr('href');
        Swal.fire({
            title: 'Cancel Outing Request?',
            text: 'Are you sure you want to cancel this campus outing permission?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Cancel Outing'
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
