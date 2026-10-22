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

// Fetch configured Outing Timings from `hostel_timings`
$hostel_timings_query = $conn->query("SELECT * FROM `hostel_timings` ORDER BY `id` DESC LIMIT 1");
$hostel_timing = ($hostel_timings_query && $hostel_timings_query->num_rows > 0) ? $hostel_timings_query->fetch_assoc() : null;
$out_start_time = $hostel_timing ? $hostel_timing['outing_start_time'] : '08:00:00';
$out_end_time   = $hostel_timing ? $hostel_timing['outing_end_time'] : '18:00:00';
$out_window_fmt = (!empty($out_start_time) && !empty($out_end_time))
    ? date('h:i A', strtotime($out_start_time)) . ' - ' . date('h:i A', strtotime($out_end_time))
    : '08:00 AM - 06:00 PM';

// Handle Status Review / Override by Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_update_outing_status') {
    $outing_id = intval($_POST['outing_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if ($outing_id > 0 && in_array($new_status, ['pending', 'approved', 'rejected', 'cancelled'])) {
        $stmt = $conn->prepare("
            UPDATE outing_requests 
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), remarks = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("sisi", $new_status, $admin_id, $remarks, $outing_id);
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

// Handle Outing Request Deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete_outing') {
    $del_id = intval($_GET['id'] ?? 0);
    if ($del_id > 0) {
        $del_stmt = $conn->prepare("DELETE FROM outing_requests WHERE id = ?");
        $del_stmt->bind_param("i", $del_id);
        if ($del_stmt->execute()) {
            $_SESSION['msg'] = "Outing request #{$del_id} was successfully deleted.";
        } else {
            $_SESSION['error'] = "Failed to delete outing request: " . $conn->error;
        }
        $del_stmt->close();
    }
    header("Location: outing_requests.php" . (!empty($_GET['filter']) ? "?filter=" . urlencode($_GET['filter']) : ""));
    exit();
}

// Status Filtering
$filter = trim($_GET['filter'] ?? 'all');
$where_clauses = [];
if (in_array($filter, ['pending', 'approved', 'rejected', 'cancelled'])) {
    $where_clauses[] = "o.status = '" . $conn->real_escape_string($filter) . "'";
}
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// KPI Metrics Query
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

// Query Outing Requests with Student, Room Allocation, Guardian, and Reviewer information
$query = "
    SELECT o.*, 
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
    LEFT JOIN student_guardians g ON u.id = g.student_id
    LEFT JOIN wardens w_rev ON o.reviewed_by = w_rev.id
    LEFT JOIN users u_rev ON o.reviewed_by = u_rev.id
    $where_sql
    ORDER BY CASE WHEN o.status = 'pending' THEN 0 ELSE 1 END, o.outing_date DESC, o.id DESC
";
$outings = $conn->query($query);

ob_start();
?>

<!-- Custom CSS for Admin Outing Requests -->
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
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .filter-pill.active {
        background-color: #2563eb !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    }
    .filter-pill:not(.active) {
        background-color: #f8fafc;
        color: #64748b;
        border: 1px solid #e2e8f0;
    }
    .filter-pill:not(.active):hover {
        background-color: #e2e8f0;
        color: #1e293b;
    }
    .avatar-circle {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        object-fit: cover;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
    }
    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
        border: none;
        transition: all 0.15s ease;
    }
    .action-btn:hover {
        transform: translateY(-2px);
    }
    .pulse-indicator {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #d97706;
        box-shadow: 0 0 0 0 rgba(217, 119, 6, 0.7);
        animation: pulse-orange 1.5s infinite;
    }
    @keyframes pulse-orange {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(217, 119, 6, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(217, 119, 6, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(217, 119, 6, 0); }
    }
    .pass-card {
        background: linear-gradient(135deg, #090d16 0%, #1e293b 100%);
        border-radius: 16px;
        color: #ffffff;
        padding: 1.25rem 1.5rem;
        position: relative;
        overflow: hidden;
    }
    .pass-card::after {
        content: '';
        position: absolute;
        right: -30px;
        bottom: -30px;
        width: 140px;
        height: 140px;
        background: rgba(37, 99, 235, 0.15);
        border-radius: 50%;
        pointer-events: none;
    }
</style>

<div class="row mb-4">
    <div class="col-12 d-flex flex-wrap justify-content-between align-items-center">
        <div>
            <h3 class="font-weight-bold text-dark mb-1">
                <i class="fa-solid fa-person-walking-luggage text-primary mr-2"></i>Outing Requests
            </h3>
            <p class="text-muted mb-0 font-weight-medium">Monitor student day outings, permissions, and authority reviews across the hostel.</p>
        </div>
        <div class="mt-2 mt-md-0 d-flex align-items-center">
            <div class="badge badge-light px-3 py-2 border shadow-sm rounded-pill d-flex align-items-center" style="background: #ffffff; font-size: 0.82rem;">
                <i class="fa-solid fa-clock text-primary mr-2"></i>
                <span class="text-muted mr-1">Permitted Hours:</span>
                <strong class="text-dark"><?= htmlspecialchars($out_window_fmt) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: rgba(16, 185, 129, 0.15); color: #065f46;">
        <i class="fa-solid fa-circle-check mr-2"></i><?= $_SESSION['msg'] ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); color: #991b1b;">
        <i class="fa-solid fa-circle-exclamation mr-2"></i><?= $_SESSION['error'] ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- KPI Metrics Overview -->
<div class="row mb-4">
    <!-- Total Outings -->
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card kpi-card p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-bold" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Total Requests</span>
                    <h3 class="font-weight-bolder text-dark mb-0 mt-1"><?= number_format($metrics['total_outings'] ?? 0) ?></h3>
                    <small class="text-muted">Hostel-wide records</small>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                    <i class="fa-solid fa-ticket"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Decisions -->
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card kpi-card p-3" style="border-left: 4px solid #f59e0b;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-bold" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Pending Approval</span>
                    <h3 class="font-weight-bolder text-dark mb-0 mt-1">
                        <?= number_format($metrics['pending_outings'] ?? 0) ?>
                        <?php if (($metrics['pending_outings'] ?? 0) > 0): ?>
                            <span class="pulse-indicator ml-1" title="Awaiting Review"></span>
                        <?php endif; ?>
                    </h3>
                    <small class="text-warning font-weight-bold">Awaiting review</small>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Approved Passes -->
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card kpi-card p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-bold" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Approved Passes</span>
                    <h3 class="font-weight-bolder text-dark mb-0 mt-1"><?= number_format($metrics['approved_outings'] ?? 0) ?></h3>
                    <small class="text-success font-weight-bold">Permissions granted</small>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejected / Cancelled -->
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card kpi-card p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-bold" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Rejected / Cancelled</span>
                    <h3 class="font-weight-bolder text-dark mb-0 mt-1"><?= number_format(($metrics['rejected_outings'] ?? 0) + ($metrics['cancelled_outings'] ?? 0)) ?></h3>
                    <small class="text-muted"><?= $metrics['rejected_outings'] ?> Rejected &bull; <?= $metrics['cancelled_outings'] ?> Cancelled</small>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Table Card -->
<div class="card border-0 shadow-sm" style="border-radius: 18px;">
    <div class="card-body p-4">
        
        <!-- Filter Pills Bar -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div class="d-flex flex-wrap gap-2 mb-2 mb-md-0" style="gap: 8px;">
                <a href="outing_requests.php?filter=all" class="filter-pill <?= ($filter === 'all') ? 'active' : '' ?>">
                    <i class="fa-solid fa-list-ul"></i> All (<?= $metrics['total_outings'] ?>)
                </a>
                <a href="outing_requests.php?filter=pending" class="filter-pill <?= ($filter === 'pending') ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock"></i> Pending (<?= $metrics['pending_outings'] ?>)
                </a>
                <a href="outing_requests.php?filter=approved" class="filter-pill <?= ($filter === 'approved') ? 'active' : '' ?>">
                    <i class="fa-solid fa-check"></i> Approved (<?= $metrics['approved_outings'] ?>)
                </a>
                <a href="outing_requests.php?filter=rejected" class="filter-pill <?= ($filter === 'rejected') ? 'active' : '' ?>">
                    <i class="fa-solid fa-xmark"></i> Rejected (<?= $metrics['rejected_outings'] ?>)
                </a>
                <a href="outing_requests.php?filter=cancelled" class="filter-pill <?= ($filter === 'cancelled') ? 'active' : '' ?>">
                    <i class="fa-solid fa-ban"></i> Cancelled (<?= $metrics['cancelled_outings'] ?>)
                </a>
            </div>
            
            <div class="text-muted small">
                Showing <strong><?= ($outings) ? $outings->num_rows : 0 ?></strong> records
            </div>
        </div>

        <!-- Outings DataTable -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="outingRequestsTable" style="width: 100%;">
                <thead class="thead-light" style="background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <tr>
                        <th class="border-0 px-3 py-3" style="width: 50px;">#</th>
                        <th class="border-0 py-3">Student Profile</th>
                        <th class="border-0 py-3">Room & Bed</th>
                        <th class="border-0 py-3">Outing Details</th>
                        <th class="border-0 py-3">Expected Timings</th>
                        <th class="border-0 py-3">Status</th>
                    </tr>
                </thead>
                <tbody style="font-size: 0.88rem;">
                    <?php if ($outings && $outings->num_rows > 0): ?>
                        <?php 
                        $sl = 1;
                        while ($row = $outings->fetch_assoc()): 
                            $name = htmlspecialchars($row['student_name'] ?? 'Unknown Student');
                            $initials = strtoupper(substr($name, 0, 1));
                            $img = htmlspecialchars($row['profile_image'] ?? '');
                            $status = $row['status'];
                            
                            $exp_out = !empty($row['expected_out_time']) ? date('h:i A', strtotime($row['expected_out_time'])) : 'N/A';
                            $exp_return = !empty($row['expected_return_time']) ? date('h:i A', strtotime($row['expected_return_time'])) : 'N/A';
                            $outing_date_fmt = date('d M Y', strtotime($row['outing_date']));
                        ?>
                        <tr>
                            <!-- Sl No -->
                            <td class="px-3 text-muted font-weight-bold"><?= $sl++ ?></td>

                            <!-- Student Info -->
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($img)): ?>
                                        <img src="../student_profile/<?= $img ?>" alt="Profile" class="avatar-circle mr-2 border">
                                    <?php else: ?>
                                        <div class="avatar-circle mr-2" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;">
                                            <?= $initials ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="font-weight-bold text-dark" style="line-height: 1.25;"><?= $name ?></div>
                                        <div class="text-muted small d-flex align-items-center mt-1">
                                            <i class="fa-solid fa-phone mr-1" style="font-size: 0.7rem;"></i> <?= htmlspecialchars($row['student_phone'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Room & Bed -->
                            <td>
                                <?php if (!empty($row['room_number'])): ?>
                                    <div class="font-weight-bold text-dark">
                                        <i class="fa-solid fa-door-closed text-primary mr-1"></i> Rm <?= htmlspecialchars($row['room_number']) ?>
                                    </div>
                                    <div class="text-muted small">
                                        Bed <?= htmlspecialchars($row['bed_number'] ?? 'N/A') ?> &bull; <?= htmlspecialchars($row['block_name'] ?? 'Main') ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted">Unallocated</span>
                                <?php endif; ?>
                            </td>

                            <!-- Destination & Date -->
                            <td>
                                <div class="font-weight-bold text-dark">
                                    <i class="fa-solid fa-location-dot text-danger mr-1"></i> <?= htmlspecialchars($row['destination']) ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="fa-regular fa-calendar mr-1"></i> <?= $outing_date_fmt ?>
                                </div>
                            </td>

                            <!-- Expected Timings -->
                            <td>
                                <div class="small">
                                    <span class="text-muted">Out:</span> <strong class="text-dark"><?= $exp_out ?></strong>
                                </div>
                                <div class="small">
                                    <span class="text-muted">In:</span> <strong class="text-dark"><?= $exp_return ?></strong>
                                </div>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php if ($status === 'pending'): ?>
                                    <span class="badge-status badge-status-pending">
                                        <span class="pulse-indicator"></span> Pending
                                    </span>
                                <?php elseif ($status === 'approved'): ?>
                                    <span class="badge-status badge-status-approved">
                                        <i class="fa-solid fa-check"></i> Approved
                                    </span>
                                <?php elseif ($status === 'rejected'): ?>
                                    <span class="badge-status badge-status-rejected">
                                        <i class="fa-solid fa-xmark"></i> Rejected
                                    </span>
                                <?php else: ?>
                                    <span class="badge-status badge-status-cancelled">
                                        <i class="fa-solid fa-ban"></i> Cancelled
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($row['reviewer_name']) && $status !== 'pending'): ?>
                                    <div class="text-muted mt-1" style="font-size: 0.72rem;">
                                        By <?= htmlspecialchars($row['reviewer_name']) ?> (<?= htmlspecialchars($row['reviewer_role']) ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="py-4">
                                    <div class="mb-3">
                                        <i class="fa-solid fa-person-walking-luggage text-muted" style="font-size: 3rem; opacity: 0.35;"></i>
                                    </div>
                                    <h5 class="text-dark font-weight-bold">No Outing Requests Found</h5>
                                    <p class="text-muted small">No outing records match the selected filter criteria.</p>
                                    <a href="outing_requests.php?filter=all" class="btn btn-sm btn-outline-primary rounded-pill px-3 mt-1">
                                        View All Outings
                                    </a>
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
     MODAL: VIEW OUTING DETAILS
     ========================================================= -->
<div class="modal fade" id="viewOutingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0">
            <div class="modal-header border-0 pb-0" style="background: transparent;">
                <div class="d-flex align-items-center">
                    <div class="p-2 rounded-12 mr-3" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                        <i class="fa-solid fa-id-card-clip fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-dark mb-0">Outing Request Details</h5>
                        <small class="text-muted">Outing Reference #<span id="modalOutingId">--</span> &bull; Submitted on <span id="modalCreated">--</span></small>
                    </div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="outline: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            
            <div class="modal-body pt-3 pb-4">
                
                <!-- Digital Pass Header Box -->
                <div class="pass-card mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div id="modalAvatarBox"></div>
                            <div>
                                <h4 class="font-weight-bold mb-1 text-white" id="modalStudentName">Student Name</h4>
                                <div class="text-white-50 small d-flex flex-wrap align-items-center">
                                    <span class="mr-3"><i class="fa-solid fa-envelope mr-1"></i> <span id="modalStudentEmail">--</span></span>
                                    <span><i class="fa-solid fa-phone mr-1"></i> <span id="modalStudentPhone">--</span></span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right mt-2 mt-sm-0">
                            <div id="modalStatusBadge"></div>
                            <small class="text-white-50 d-block mt-1" id="modalStatusDesc"></small>
                        </div>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="row">
                    <!-- Left: Allocation & Guardian -->
                    <div class="col-md-6 mb-3">
                        <div class="card border bg-light h-100" style="border-radius: 14px;">
                            <div class="card-body p-3">
                                <h6 class="font-weight-bold text-dark mb-3">
                                    <i class="fa-solid fa-building-user text-primary mr-2"></i>Hostel & Guardian
                                </h6>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Assigned Room:</span>
                                    <strong class="text-dark small" id="modalRoom">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Bed:</span>
                                    <strong class="text-dark small" id="modalBed">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Block & Floor:</span>
                                    <strong class="text-dark small" id="modalLocation">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Guardian Name:</span>
                                    <strong class="text-dark small" id="modalGuardian">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1">
                                    <span class="text-muted small">Guardian Emergency:</span>
                                    <strong class="text-danger small" id="modalGuardianPhone">--</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Destination, Reason & Schedule -->
                    <div class="col-md-6 mb-3">
                        <div class="card border bg-light h-100" style="border-radius: 14px;">
                            <div class="card-body p-3">
                                <h6 class="font-weight-bold text-dark mb-3">
                                    <i class="fa-solid fa-location-crosshairs text-danger mr-2"></i>Outing Schedule
                                </h6>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Date of Outing:</span>
                                    <strong class="text-dark small" id="modalDate">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Destination:</span>
                                    <strong class="text-dark small" id="modalDestination">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Expected Out Time:</span>
                                    <strong class="text-primary small" id="modalExpOut">--</strong>
                                </div>
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted small">Expected Return:</span>
                                    <strong class="text-success small" id="modalExpReturn">--</strong>
                                </div>
                                <div class="py-2">
                                    <span class="text-muted small d-block mb-1">Reason / Purpose:</span>
                                    <div class="p-2 rounded bg-white border text-dark small" id="modalReason" style="max-height: 80px; overflow-y: auto;">
                                        --
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Authority Review & Remarks -->
                <div class="card border bg-light" style="border-radius: 14px;">
                    <div class="card-body p-3">
                        <h6 class="font-weight-bold text-dark mb-2">
                            <i class="fa-solid fa-stamp text-info mr-2"></i>Authority Review Log
                        </h6>
                        <div class="row small">
                            <div class="col-sm-6 mb-1">
                                <span class="text-muted">Reviewed By:</span> <strong class="text-dark" id="modalReviewer">--</strong> (<span id="modalReviewerRole">--</span>)
                            </div>
                            <div class="col-sm-6 mb-1">
                                <span class="text-muted">Decision Date:</span> <strong class="text-dark" id="modalReviewedAt">--</strong>
                            </div>
                            <div class="col-12 mt-2">
                                <span class="text-muted">Remarks:</span>
                                <div class="p-2 rounded bg-white border text-dark mt-1" id="modalRemarks">
                                    --
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" id="modalBtnProceedReview">
                    <i class="fa-solid fa-pen-to-square mr-1"></i> Update Status
                </button>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL: ADMIN REVIEW & STATUS OVERRIDE
     ========================================================= -->
<div class="modal fade" id="reviewOutingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0">
            <div class="modal-header border-0 pb-0">
                <div class="d-flex align-items-center">
                    <div class="p-2 rounded-12 mr-3" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                        <i class="fa-solid fa-sliders fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="modal-title font-weight-bold text-dark mb-0">Update Outing Request</h5>
                        <small class="text-muted">Manage decision and notes for student outing</small>
                    </div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="outline: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            
            <form action="outing_requests.php<?= (!empty($filter)) ? '?filter=' . urlencode($filter) : '' ?>" method="POST" id="adminOutingReviewForm">
                <input type="hidden" name="action" value="admin_update_outing_status">
                <input type="hidden" name="outing_id" id="review_outing_id">
                
                <div class="modal-body pt-3 pb-3">
                    
                    <!-- Student & Destination Quick Info -->
                    <div class="p-3 mb-3 rounded-12 bg-light border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Student:</span>
                            <strong class="text-dark small" id="review_student_name">--</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted small">Date & Destination:</span>
                            <strong class="text-dark small" id="review_outing_summary">--</strong>
                        </div>
                    </div>

                    <!-- Status Selector -->
                    <div class="form-group mb-3">
                        <label class="form-label font-weight-bold text-dark small">Decision / Status <span class="text-danger">*</span></label>
                        <select name="status" id="review_status_select" class="form-control rounded-10" required>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>

                    <!-- Remarks -->
                    <div class="form-group mb-0">
                        <label class="form-label font-weight-bold text-dark small">Admin Remarks / Instructions</label>
                        <textarea name="remarks" id="review_remarks_input" class="form-control rounded-10" rows="3" placeholder="Provide reason or special instructions..."></textarea>
                    </div>

                </div>
                
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4" id="btnSubmitOutingReview">
                        <i class="fa-solid fa-save mr-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTables
    if (!$.fn.DataTable.isDataTable('#outingRequestsTable')) {
        $('#outingRequestsTable').DataTable({
            responsive: true,
            pageLength: 10,
            order: [], // Preserve order from SQL (Pending first, then date desc)
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search student, destination, room...",
                paginate: {
                    previous: "<i class='fa-solid fa-chevron-left'></i>",
                    next: "<i class='fa-solid fa-chevron-right'></i>"
                }
            }
        });
    }

    // View Details Modal Trigger
    $('.btn-view-outing').on('click', function() {
        var btn = $(this);
        var id = btn.data('id');
        var name = btn.data('name');
        var email = btn.data('email');
        var phone = btn.data('phone');
        var room = btn.data('room');
        var bed = btn.data('bed');
        var block = btn.data('block');
        var floor = btn.data('floor');
        var guardian = btn.data('guardian');
        var guardianPhone = btn.data('guardian-phone');
        var guardianRel = btn.data('guardian-rel');
        var date = btn.data('date');
        var dest = btn.data('dest');
        var reason = btn.data('reason');
        var expOut = btn.data('exp-out');
        var expReturn = btn.data('exp-return');
        var status = btn.data('status');
        var reviewer = btn.data('reviewer');
        var reviewerRole = btn.data('reviewer-role');
        var reviewedAt = btn.data('reviewed-at');
        var remarks = btn.data('remarks');
        var created = btn.data('created');
        var img = btn.data('image');
        var initials = btn.data('initials');

        $('#modalOutingId').text(id);
        $('#modalStudentName').text(name);
        $('#modalStudentEmail').text(email);
        $('#modalStudentPhone').text(phone);

        // Avatar
        if (img && img.trim() !== '') {
            $('#modalAvatarBox').html('<img src="../student_profile/' + img + '" class="avatar-circle mr-3" style="width: 52px; height: 52px; border: 2px solid rgba(255,255,255,0.4);">');
        } else {
            $('#modalAvatarBox').html('<div class="avatar-circle mr-3" style="width: 52px; height: 52px; background: rgba(255,255,255,0.15); color: #ffffff; border: 2px solid rgba(255,255,255,0.3); font-size: 18px;">' + initials + '</div>');
        }

        // Room details
        if (room && room !== 'Unassigned') {
            $('#modalRoom').text('Room ' + room);
            $('#modalBed').text('Bed ' + bed);
            $('#modalLocation').text(block + ' / ' + floor);
        } else {
            $('#modalRoom').text('Unallocated');
            $('#modalBed').text('N/A');
            $('#modalLocation').text('Hostel');
        }

        // Guardian
        $('#modalGuardian').text(guardian + (guardianRel ? ' (' + guardianRel + ')' : ''));
        $('#modalGuardianPhone').text(guardianPhone);

        // Outing Itinerary
        $('#modalDate').text(date);
        $('#modalDestination').text(dest);
        $('#modalReason').text(reason);
        $('#modalExpOut').text(expOut);
        $('#modalExpReturn').text(expReturn);

        // Review log
        $('#modalReviewer').text(reviewer);
        $('#modalReviewerRole').text(reviewerRole);
        $('#modalReviewedAt').text(reviewedAt);
        $('#modalRemarks').text(remarks ? remarks : 'No specific remarks.');
        $('#modalCreated').text(created);

        // Status badge
        var statusBadge = '';
        var statusDesc = '';
        if (status === 'pending') {
            statusBadge = '<span class="badge-status badge-status-pending"><span class="pulse-indicator"></span> Pending</span>';
            statusDesc = 'Awaiting review by hostel authority';
        } else if (status === 'approved') {
            statusBadge = '<span class="badge-status badge-status-approved"><i class="fa-solid fa-check"></i> Approved</span>';
            statusDesc = 'Authorized outing permission';
        } else if (status === 'rejected') {
            statusBadge = '<span class="badge-status badge-status-rejected"><i class="fa-solid fa-xmark"></i> Rejected</span>';
            statusDesc = 'Outing request declined';
        } else {
            statusBadge = '<span class="badge-status badge-status-cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>';
            statusDesc = 'Cancelled by student';
        }
        $('#modalStatusBadge').html(statusBadge);
        $('#modalStatusDesc').text(statusDesc);

        // Proceed to Review Modal handler
        $('#modalBtnProceedReview').off('click').on('click', function() {
            $('#viewOutingModal').modal('hide');
            setTimeout(function() {
                openReviewModal(id, name, date + ' to ' + dest, status, remarks);
            }, 300);
        });

        $('#viewOutingModal').modal('show');
    });

    // Function to open Review Modal
    function openReviewModal(id, name, summary, status, remarks) {
        $('#review_outing_id').val(id);
        $('#review_student_name').text(name);
        $('#review_outing_summary').text(summary);
        $('#review_status_select').val(status);
        $('#review_remarks_input').val(remarks || '');
        $('#reviewOutingModal').modal('show');
    }

    // Direct Review Button click from Table
    $('.btn-review-outing').on('click', function() {
        var btn = $(this);
        openReviewModal(
            btn.data('id'),
            btn.data('name'),
            btn.data('date') + ' to ' + btn.data('dest'),
            btn.data('status'),
            btn.data('remarks')
        );
    });

    // Delete Confirmation with SweetAlert2
    $('.btn-delete-outing').on('click', function(e) {
        e.preventDefault();
        var targetUrl = $(this).attr('href');
        Swal.fire({
            title: 'Delete Outing Request?',
            text: 'Are you sure you want to permanently delete this outing record?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Delete Record'
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
