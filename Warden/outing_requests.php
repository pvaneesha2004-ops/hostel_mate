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

// Handle Outing Review / Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_outing_status') {
    $outing_id = intval($_POST['outing_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($outing_id > 0 && in_array($new_status, ['approved', 'rejected'])) {
        $stmt = $conn->prepare("
            UPDATE outing_requests 
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("sisi", $new_status, $warden_id, $remarks, $outing_id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Outing request #{$outing_id} status updated to '" . ucfirst($new_status) . "'.";
            } else {
                $_SESSION['error'] = "Failed to update outing status: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        $_SESSION['error'] = "Invalid status update request.";
    }
    header("Location: outing_requests.php" . (!empty($_GET['filter']) ? "?filter=" . urlencode($_GET['filter']) : ""));
    exit();
}

// Filter logic
$filter = trim($_GET['filter'] ?? 'all');
$where_clauses = [];
if (in_array($filter, ['pending', 'approved', 'rejected', 'cancelled'])) {
    $where_clauses[] = "o.status = '" . $conn->real_escape_string($filter) . "'";
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// KPI Metrics query
$metrics_res = $conn->query("
    SELECT 
        COUNT(*) AS total_outings,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_outings,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_outings,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_outings,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_outings
    FROM outing_requests
");
$metrics = ($metrics_res && $metrics_res->num_rows > 0) ? $metrics_res->fetch_assoc() : [
    'total_outings' => 0, 'pending_outings' => 0, 'approved_outings' => 0, 'rejected_outings' => 0, 'cancelled_outings' => 0
];

// Fetch Outing Requests with Student and Room Allocation details
$query = "
    SELECT o.*, 
           u.name AS student_name,
           u.email AS student_email,
           u.phone AS student_phone,
           u.profile_image,
           rm.room_number,
           bd.bed_number,
           blk.name AS block_name,
           flr.name AS floor_name,
           COALESCE(w_rev.name, u_rev.name, 'Authority') AS reviewer_name,
           CASE 
               WHEN w_rev.name IS NOT NULL THEN 'Warden'
               WHEN u_rev.name IS NOT NULL THEN 'Admin'
               ELSE 'System'
           END AS reviewer_role
    FROM outing_requests o
    JOIN users u ON o.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN wardens w_rev ON o.reviewed_by = w_rev.id
    LEFT JOIN users u_rev ON o.reviewed_by = u_rev.id
    $where_sql
    ORDER BY CASE WHEN o.status = 'pending' THEN 0 ELSE 1 END, o.outing_date DESC, o.id DESC
";
$outings = $conn->query($query);

ob_start();
?>

<!-- Custom CSS for Warden Outing Requests -->
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
        font-size: 13px;
        transition: all 0.2s ease;
        border: none;
    }
    .btn-action-circle:hover { transform: scale(1.1); }
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
                    <i class="fa-solid fa-person-walking-luggage text-success mr-2"></i>Outing Permission Requests
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Review, approve, and verify daily outing permission requests for hostel residents.
                </p>
            </div>
            <div class="mt-2 mt-md-0">
                <span class="badge badge-light border px-3 py-2 text-muted" style="border-radius: 50px; font-weight: 600;">
                    <i class="fa-regular fa-clock mr-1 text-success"></i> <?= date('d M Y') ?>
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
                            <h3 class="font-weight-bold text-dark mt-1 mb-0"><?= number_format($metrics['total_outings']) ?></h3>
                            <small class="text-muted">Hostel all-time</small>
                        </div>
                        <div class="kpi-icon-wrap" style="background: rgba(13, 148, 136, 0.1); color: #0d9488;">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-xl-3 mb-3 mb-xl-0">
                <div class="card kpi-card">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase" style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px;">Pending Approval</span>
                            <h3 class="font-weight-bold text-warning mt-1 mb-0 d-flex align-items-center">
                                <?= number_format($metrics['pending_outings']) ?>
                                <?php if ($metrics['pending_outings'] > 0): ?>
                                    <span class="pulse-indicator ml-2"></span>
                                <?php endif; ?>
                            </h3>
                            <small class="text-warning font-weight-bold">Awaiting decision</small>
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
                            <span class="text-muted text-uppercase" style="font-size: 0.72rem; font-weight: 700; letter-spacing: 0.5px;">Approved Passes</span>
                            <h3 class="font-weight-bold text-success mt-1 mb-0"><?= number_format($metrics['approved_outings']) ?></h3>
                            <small class="text-success font-weight-bold">Permissions granted</small>
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
                            <h3 class="font-weight-bold text-danger mt-1 mb-0"><?= number_format(($metrics['rejected_outings'] ?? 0) + ($metrics['cancelled_outings'] ?? 0)) ?></h3>
                            <small class="text-muted"><?= $metrics['rejected_outings'] ?> Rejected &bull; <?= $metrics['cancelled_outings'] ?> Cancelled</small>
                        </div>
                        <div class="kpi-icon-wrap" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Pills Bar -->
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

            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-sm btn-outline-success" onclick="window.print()" style="border-radius: 8px;">
                    <i class="fa-solid fa-print mr-1"></i> Print Outing Roster
                </button>
            </div>
        </div>

        <!-- Main Outing Requests Data Card -->
        <div class="card shadow-sm border-0" style="border-radius: 16px;">
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle datatable" id="wardenOutingTable" style="width: 100%;">
                        <thead class="bg-light text-uppercase font-weight-bold" style="font-size: 0.76rem; letter-spacing: 0.5px; color: #475569;">
                            <tr>
                                <th style="width: 45px;">#</th>
                                <th>Student Details</th>
                                <th>Room & Bed</th>
                                <th>Outing Date & Window</th>
                                <th>Destination</th>
                                <th>Reason</th>
                                <th>Status</th>
                                <th>Review Info</th>
                                <th class="text-right" style="width: 90px;">Actions</th>
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

                                <!-- Student Profile -->
                                <td>
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($row['profile_image']) && file_exists('../student_profile/' . $row['profile_image'])): ?>
                                            <img src="../student_profile/<?= htmlspecialchars($row['profile_image']) ?>" alt="Student" class="avatar-circle mr-3" style="box-shadow: 0 2px 6px rgba(0,0,0,0.1);">
                                        <?php else: ?>
                                            <div class="avatar-circle mr-3" style="background: #ccfbf1; color: #0f766e;">
                                                <?= $initials ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-weight-bold text-dark" style="font-size: 0.92rem;"><?= htmlspecialchars($row['student_name']) ?></div>
                                            <small class="text-muted d-block"><i class="fa-solid fa-phone mr-1" style="font-size: 10px;"></i><?= htmlspecialchars($row['student_phone'] ?? 'N/A') ?></small>
                                        </div>
                                    </div>
                                </td>

                                <!-- Room & Bed -->
                                <td>
                                    <?php if (!empty($row['room_number'])): ?>
                                        <div class="font-weight-bold text-dark">Room <?= htmlspecialchars($row['room_number']) ?></div>
                                        <small class="badge badge-info text-white font-weight-bold px-2 py-0" style="border-radius: 4px; font-size: 0.72rem;">
                                            Bed <?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?>
                                        </small>
                                        <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                                            <?= htmlspecialchars($row['block_name'] ?? '') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted">Unallocated</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Outing Date & Expected Times -->
                                <td>
                                    <div class="font-weight-bold text-dark" style="font-size: 0.88rem;">
                                        <i class="fa-regular fa-calendar text-success mr-1"></i><?= $out_date ?>
                                    </div>
                                    <div class="text-muted small">
                                        <span class="text-primary font-weight-semibold"><?= $exp_out ?></span> &rarr; <span class="text-danger font-weight-semibold"><?= $exp_ret ?></span>
                                    </div>
                                </td>

                                <!-- Destination -->
                                <td>
                                    <span class="font-weight-semibold text-dark d-inline-block text-truncate" style="max-width: 160px;" title="<?= htmlspecialchars($row['destination']) ?>">
                                        <i class="fa-solid fa-location-dot text-danger mr-1"></i><?= htmlspecialchars($row['destination']) ?>
                                    </span>
                                </td>

                                <!-- Reason -->
                                <td>
                                    <span class="text-muted d-inline-block text-truncate" style="max-width: 170px;" title="<?= htmlspecialchars($row['reason']) ?>">
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

                                <!-- Review Info -->
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

                                <!-- Actions -->
                                <td class="text-right">
                                    <!-- Review / Change Status Modal Trigger -->
                                    <button type="button" class="btn-action-circle <?= ($status === 'pending') ? 'bg-success text-white shadow-sm' : 'bg-light text-secondary' ?> btn-review-outing" title="Review Outing Permission"
                                        data-id="<?= $row['id'] ?>"
                                        data-name="<?= htmlspecialchars($row['student_name']) ?>"
                                        data-date="<?= $out_date ?>"
                                        data-window="<?= $exp_out ?> to <?= $exp_ret ?>"
                                        data-dest="<?= htmlspecialchars($row['destination']) ?>"
                                        data-status="<?= $status ?>"
                                        data-remarks="<?= htmlspecialchars($row['remarks'] ?? '') ?>">
                                        <i class="fa-solid fa-gavel"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="d-flex flex-column align-items-center justify-content-center">
                                        <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                            <i class="fa-solid fa-person-walking-luggage text-muted" style="font-size: 1.5rem;"></i>
                                        </div>
                                        <h6 class="font-weight-bold text-secondary mb-1">No Outing Requests Found</h6>
                                        <p class="text-muted small mb-0">No records found matching the "<?= ucfirst($filter) ?>" filter criteria.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- =========================================================
     MODAL: WARDEN REVIEW / DECISION
     ========================================================= -->
<div class="modal fade" id="reviewOutingModal" tabindex="-1" role="dialog" aria-labelledby="reviewOutingModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 18px; border: none; overflow: hidden; box-shadow: 0 15px 35px rgba(0,0,0,0.15);">
            <div class="modal-header d-flex align-items-center justify-content-between" style="background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%); color: #ffffff;">
                <div class="d-flex align-items-center">
                    <div class="rounded-circle bg-white text-success p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 1.1rem; color: #0d9488 !important;">
                        <i class="fa-solid fa-gavel"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-white mb-0" id="reviewOutingModalTitle">Outing Decision Review</h5>
                        <small class="text-white-50">Authorize or reject student campus outing</small>
                    </div>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <form action="outing_requests.php<?= (!empty($filter)) ? '?filter=' . urlencode($filter) : '' ?>" method="POST" id="reviewOutingForm">
                <input type="hidden" name="action" value="update_outing_status">
                <input type="hidden" name="outing_id" id="review_outing_id">
                <input type="hidden" name="status" id="review_status_input">

                <div class="modal-body p-4">
                    <!-- Request Summary Box -->
                    <div class="p-3 mb-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small font-weight-bold">Student Name:</span>
                            <span id="review_student_name" class="font-weight-bold text-dark"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="text-muted small font-weight-bold">Outing Schedule:</span>
                            <span id="review_schedule" class="text-dark small font-weight-bold"></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small font-weight-bold">Destination:</span>
                            <span id="review_destination" class="text-dark small"></span>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="review_remarks" class="font-weight-bold text-dark small text-uppercase">
                            Warden Remarks / Instructions <span class="text-muted">(Optional)</span>
                        </label>
                        <textarea class="form-control" name="remarks" id="review_remarks" rows="3" placeholder="Add approval notes or rejection reasoning..." style="border-radius: 10px;"></textarea>
                    </div>

                    <div class="d-flex justify-content-center gap-3 mt-4">
                        <button type="button" class="btn btn-outline-danger flex-fill py-2 font-weight-bold" id="btnRejectOuting" style="border-radius: 10px;">
                            <i class="fa-solid fa-circle-xmark mr-1"></i> Reject Outing
                        </button>
                        <button type="button" class="btn btn-success flex-fill py-2 font-weight-bold" id="btnApproveOuting" style="border-radius: 10px;">
                            <i class="fa-solid fa-circle-check mr-1"></i> Approve Outing
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    // Initialize DataTables
    if ($.fn.DataTable && $('#wardenOutingTable').length) {
        $('#wardenOutingTable').DataTable({
            responsive: true,
            order: [],
            pageLength: 10,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search student, room, destination..."
            }
        });
    }

    // Open Review Modal
    $('.btn-review-outing').on('click', function() {
        var btn = $(this);
        $('#review_outing_id').val(btn.data('id'));
        $('#review_student_name').text(btn.data('name'));
        $('#review_schedule').text(btn.data('date') + ' (' + btn.data('window') + ')');
        $('#review_destination').text(btn.data('dest'));
        $('#review_remarks').val(btn.data('remarks') || '');
        $('#reviewOutingModal').modal('show');
    });

    // Approve Button Action
    $('#btnApproveOuting').on('click', function() {
        $('#review_status_input').val('approved');
        Swal.fire({
            title: 'Approve Outing Permission?',
            text: 'This authorizes the student to leave the hostel campus for the requested duration.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Grant Permission'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#reviewOutingForm').submit();
            }
        });
    });

    // Reject Button Action
    $('#btnRejectOuting').on('click', function() {
        $('#review_status_input').val('rejected');
        Swal.fire({
            title: 'Reject Outing Request?',
            text: 'Are you sure you want to deny this campus outing request?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Reject'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#reviewOutingForm').submit();
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
