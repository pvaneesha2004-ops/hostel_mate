<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Ensure warden is authenticated
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}

$warden_id = $_SESSION['warden_id'];

// Get assigned blocks for this warden (if any)
$assigned_blocks_res = $conn->query("SELECT wba.block_id, b.name as block_name FROM warden_block_assignments wba JOIN blocks b ON wba.block_id = b.id WHERE wba.warden_id = $warden_id AND wba.status = 'active'");
$warden_assigned_blocks = [];
$assigned_block_ids = [];
if ($assigned_blocks_res && $assigned_blocks_res->num_rows > 0) {
    while ($ab = $assigned_blocks_res->fetch_assoc()) {
        $warden_assigned_blocks[] = $ab;
        $assigned_block_ids[] = intval($ab['block_id']);
    }
}

// Fetch all blocks for optional filtering
$blocks_res = $conn->query("SELECT id, name FROM blocks WHERE status='active' OR status='1' ORDER BY name ASC");
$all_blocks = [];
if ($blocks_res && $blocks_res->num_rows > 0) {
    while($b = $blocks_res->fetch_assoc()) { $all_blocks[] = $b; }
}

// Fetch all students with guardian, document, and active room allocation details
$query = "
    SELECT u.*, 
           g.name AS guardian_name,
           g.relationship AS guardian_relationship,
           g.phone AS guardian_phone,
           g.email AS guardian_email,
           g.address AS guardian_address,
           d.document_type,
           d.document_number,
           d.file_path AS doc_file_path,
           d.verification,
           d.verified_by,
           d.verified_at,
           d.remarks AS doc_remarks,
           ra.id AS allocation_id,
           ra.block_id,
           ra.floor_id,
           ra.room_id,
           ra.bed_id,
           ra.allocated_date,
           ra.remarks AS alloc_remarks,
           blk.name AS allocated_block_name,
           flr.name AS allocated_floor_name,
           rm.room_number AS allocated_room_number,
           rm.room_type AS allocated_room_type,
           rm.price AS allocated_room_price,
           bd.bed_number AS allocated_bed_number
    FROM users u
    LEFT JOIN student_guardians g ON u.id = g.student_id
    LEFT JOIN student_documents d ON u.id = d.student_id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE u.role = 'student'
    ORDER BY u.id DESC
";
$students = $conn->query($query);

// Pre-calculate summary statistics
$total_students = 0;
$verified_count = 0;
$allocated_count = 0;
$pending_count = 0;

$student_rows = [];
if ($students && $students->num_rows > 0) {
    while ($row = $students->fetch_assoc()) {
        $student_rows[] = $row;
        $total_students++;
        if (strtolower($row['verification'] ?? '') === 'verified') {
            $verified_count++;
        } elseif (strtolower($row['verification'] ?? '') === 'pending' || empty($row['verification'])) {
            $pending_count++;
        }
        if (!empty($row['allocation_id'])) {
            $allocated_count++;
        }
    }
}

// Fetch Fee History by student
$fee_history_res = $conn->query("SELECT id, student_id, price, date, created_at FROM student_fee_history ORDER BY id DESC");
$fee_history_by_student = [];
if ($fee_history_res && $fee_history_res->num_rows > 0) {
    while ($fh = $fee_history_res->fetch_assoc()) {
        $sid = intval($fh['student_id']);
        if (!isset($fee_history_by_student[$sid])) {
            $fee_history_by_student[$sid] = [];
        }
        $rawDate = $fh['date'];
        $formattedDate = 'N/A';
        if (!empty($rawDate)) {
            if (is_numeric($rawDate) && (int)$rawDate > 100000000) {
                $formattedDate = date('d M Y', (int)$rawDate);
            } else {
                $formattedDate = date('d M Y', strtotime($rawDate));
            }
        } elseif (!empty($fh['created_at'])) {
            $formattedDate = date('d M Y', strtotime($fh['created_at']));
        }
        $fh['formatted_date'] = $formattedDate;
        $fh['formatted_price'] = '₹' . number_format((float)$fh['price'], 0);
        $fh['formatted_created_at'] = !empty($fh['created_at']) ? date('d M Y, h:i A', strtotime($fh['created_at'])) : 'N/A';
        $fee_history_by_student[$sid][] = $fh;
    }
}

ob_start();
?>

<!-- Page Header & Title -->
<div class="row mb-4">
    <div class="col-12 d-flex flex-column flex-md-row justify-content-between align-items-md-center">
        <div>
            <h3 class="font-weight-bold text-dark mb-1">Student Directory</h3>
            <p class="text-muted mb-0">View student profiles, room & bed allocations, guardian contacts, and verification records.</p>
        </div>
        <?php if (!empty($warden_assigned_blocks)): ?>
            <div class="mt-3 mt-md-0">
                <span class="badge badge-success py-2 px-3 shadow-sm" style="font-size: 0.85rem; border-radius: 8px;">
                    <i class="ti-home mr-1"></i> Assigned Block: 
                    <strong><?= htmlspecialchars(implode(', ', array_column($warden_assigned_blocks, 'block_name'))) ?></strong>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 grid-margin stretch-card mb-3 mb-xl-0">
        <div class="card border-0 shadow-sm rounded-16" style="border-left: 4px solid #059669 !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-medium small text-uppercase">Total Students</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= $total_students ?></h3>
                </div>
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(5, 150, 105, 0.12); color: #059669;">
                    <i class="ti-user" style="font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6 grid-margin stretch-card mb-3 mb-xl-0">
        <div class="card border-0 shadow-sm rounded-16" style="border-left: 4px solid #10b981 !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-medium small text-uppercase">Allocated Rooms</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= $allocated_count ?></h3>
                </div>
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(16, 185, 129, 0.12); color: #10b981;">
                    <i class="ti-layout-grid2" style="font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6 grid-margin stretch-card mb-3 mb-xl-0">
        <div class="card border-0 shadow-sm rounded-16" style="border-left: 4px solid #3b82f6 !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-medium small text-uppercase">Verified Status</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= $verified_count ?></h3>
                </div>
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(59, 130, 246, 0.12); color: #3b82f6;">
                    <i class="ti-check-box" style="font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-sm-6 grid-margin stretch-card mb-3 mb-xl-0">
        <div class="card border-0 shadow-sm rounded-16" style="border-left: 4px solid #f59e0b !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted font-weight-medium small text-uppercase">Pending / Review</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= $pending_count ?></h3>
                </div>
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(245, 158, 11, 0.12); color: #f59e0b;">
                    <i class="ti-time" style="font-size: 20px;"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Student List Table Card -->
<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card border-0 shadow-sm rounded-16">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
                    <h4 class="card-title font-weight-bold mb-0 text-dark">
                        <i class="ti-id-badge mr-2 text-success"></i>All Registered Students
                    </h4>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover datatable align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th style="width: 50px;">SlNo</th>
                                <th style="width: 60px;">Photo</th>
                                <th>Student Name</th>
                                <th>Email / Mobile</th>
                                <th>Verification</th>
                                <th>Room & Bed Allocation</th>
                                <th>Account</th>
                                <th style="width: 90px;" class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sl = 1; 
                            foreach($student_rows as $row): 
                                $isVerified = (strtolower($row['verification'] ?? '') === 'verified');
                            ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <?php if (!empty($row['profile_image']) && file_exists('../student_profile/' . $row['profile_image'])): ?>
                                        <img src="../student_profile/<?= htmlspecialchars($row['profile_image']) ?>" alt="Profile" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0;">
                                    <?php else: ?>
                                        <div style="width: 42px; height: 42px; border-radius: 50%; background: #e2e8f0; color: #64748b; display: inline-flex; align-items: center; justify-content: center; font-weight: 600;">
                                            <i class="ti-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="font-weight-bold text-dark"><?= htmlspecialchars($row['name']) ?></span>
                                </td>
                                <td>
                                    <div class="text-dark"><i class="ti-email mr-1 text-muted"></i><?= htmlspecialchars($row['email']) ?></div>
                                    <small class="text-muted"><i class="ti-mobile mr-1"></i><?= htmlspecialchars($row['phone']) ?></small>
                                </td>
                                <td>
                                    <?php 
                                        $ver = strtolower($row['verification'] ?? 'pending');
                                        if($ver == 'verified'): ?>
                                            <span class="badge badge-success py-1 px-2"><i class="ti-check mr-1"></i>Verified</span>
                                        <?php elseif($ver == 'rejected'): ?>
                                            <span class="badge badge-danger py-1 px-2"><i class="ti-close mr-1"></i>Rejected</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning text-dark py-1 px-2"><i class="ti-time mr-1"></i>Pending</span>
                                        <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['allocation_id'])): ?>
                                        <div class="badge badge-info py-1 px-2 mb-1" style="background-color: #0284c7;">
                                            <i class="ti-home mr-1"></i><?= htmlspecialchars($row['allocated_block_name'] ?? '') ?> &bull; <?= htmlspecialchars($row['allocated_floor_name'] ?? '') ?>
                                        </div><br>
                                        <small class="font-weight-bold text-dark">
                                            Room <?= htmlspecialchars($row['allocated_room_number'] ?? '') ?> &bull; 
                                            <span class="text-primary font-weight-bold">Bed <?= htmlspecialchars($row['allocated_bed_number'] ?? '') ?></span>
                                        </small>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted py-1 px-2 font-italic">Not Allocated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($row['status'] == 'active'): ?>
                                        <span class="badge badge-success py-1 px-2">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary py-1 px-2">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <!-- View Student Details Button -->
                                    <button type="button" class="btn btn-warning btn-sm view-btn shadow-sm" title="View Full Student Details"
                                        data-id="<?= $row['id'] ?>"
                                        data-name="<?= htmlspecialchars($row['name']) ?>"
                                        data-email="<?= htmlspecialchars($row['email']) ?>"
                                        data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                        data-profile_image="<?= htmlspecialchars($row['profile_image'] ?? '') ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                        data-guardian_name="<?= htmlspecialchars($row['guardian_name'] ?? '') ?>"
                                        data-guardian_relationship="<?= htmlspecialchars($row['guardian_relationship'] ?? '') ?>"
                                        data-guardian_phone="<?= htmlspecialchars($row['guardian_phone'] ?? '') ?>"
                                        data-guardian_email="<?= htmlspecialchars($row['guardian_email'] ?? '') ?>"
                                        data-guardian_address="<?= htmlspecialchars($row['guardian_address'] ?? '') ?>"
                                        data-document_type="<?= htmlspecialchars($row['document_type'] ?? '') ?>"
                                        data-document_number="<?= htmlspecialchars($row['document_number'] ?? '') ?>"
                                        data-doc_file_path="<?= htmlspecialchars($row['doc_file_path'] ?? '') ?>"
                                        data-verification="<?= htmlspecialchars($row['verification'] ?? 'pending') ?>"
                                        data-doc_remarks="<?= htmlspecialchars($row['doc_remarks'] ?? '') ?>"
                                        data-allocation_id="<?= $row['allocation_id'] ?? '' ?>"
                                        data-allocated_block_name="<?= htmlspecialchars($row['allocated_block_name'] ?? '') ?>"
                                        data-allocated_floor_name="<?= htmlspecialchars($row['allocated_floor_name'] ?? '') ?>"
                                        data-allocated_room_number="<?= htmlspecialchars($row['allocated_room_number'] ?? '') ?>"
                                        data-allocated_room_type="<?= htmlspecialchars($row['allocated_room_type'] ?? '') ?>"
                                        data-allocated_room_price="<?= htmlspecialchars($row['allocated_room_price'] ?? '') ?>"
                                        data-allocated_bed_number="<?= htmlspecialchars($row['allocated_bed_number'] ?? '') ?>"
                                        data-allocated_date="<?= htmlspecialchars($row['allocated_date'] ?? '') ?>"
                                        data-toggle="modal" data-target="#viewModal">
                                        <i class="ti-eye mr-1"></i>View
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
</div>
<!-- View Details Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document" style="max-width: 700px;">
    <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Student Profile & Details</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
            <!-- Student Header Info -->
            <div class="d-flex align-items-center mb-4 p-3 rounded" style="background: #f8f9fa;">
                <div id="view_profile_img_wrapper" class="mr-3"></div>
                <div>
                    <h4 id="view_name" class="mb-1"></h4>
                    <p class="mb-1 text-muted"><i class="ti-email mr-1"></i><span id="view_email"></span> | <i class="ti-mobile mr-1"></i><span id="view_phone"></span></p>
                    <span id="view_account_status_badge"></span>
                </div>
            </div>

            <!-- Document Verification Card -->
            <div class="card border mb-3">
                <div class="card-header bg-light font-weight-bold d-flex justify-content-between align-items-center">
                    <span><i class="ti-id-badge mr-1"></i> Document & Verification Details</span>
                    <span id="view_verification_badge"></span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>Document Type:</strong> <span id="view_document_type"></span></div>
                        <div class="col-md-6 mb-2"><strong>Document Number:</strong> <span id="view_document_number"></span></div>
                        <div class="col-12 mt-1 mb-2"><strong>Attached Document:</strong> <span id="view_doc_file_wrapper"></span></div>
                        <div class="col-12"><strong>Remarks:</strong> <span id="view_doc_remarks" class="text-muted"></span></div>
                    </div>
                </div>
            </div>

            <!-- Room & Bed Allocation Card -->
            <div class="card mb-3 border">
                <div class="card-header bg-light font-weight-bold">
                    <i class="ti-home mr-1"></i> Room & Bed Allocation
                </div>
                <div class="card-body">
                    <div id="view_allocation_wrapper"></div>
                </div>
            </div>

            <!-- Fee History Card -->
            <div class="card mb-3 border">
                <div class="card-header bg-light font-weight-bold d-flex justify-content-between align-items-center">
                    <span><i class="ti-credit-card mr-1 text-success"></i> Fee History & Transactions</span>
                    <span id="view_fee_count_badge" class="badge badge-success"></span>
                </div>
                <div class="card-body">
                    <div id="view_fee_history_wrapper"></div>
                </div>
            </div>

            <!-- Guardian Info Card -->
            <div class="card mb-3 border">
                <div class="card-header bg-light font-weight-bold">
                    <i class="ti-user mr-1"></i> Guardian Details
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>Name:</strong> <span id="view_guardian_name"></span></div>
                        <div class="col-md-6 mb-2"><strong>Relationship:</strong> <span id="view_guardian_relationship"></span></div>
                        <div class="col-md-6 mb-2"><strong>Phone:</strong> <span id="view_guardian_phone"></span></div>
                        <div class="col-md-6 mb-2"><strong>Email:</strong> <span id="view_guardian_email"></span></div>
                        <div class="col-12"><strong>Address:</strong> <span id="view_guardian_address"></span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        </div>
    </div>
  </div>
</div>

<script>
const feeHistoryData = <?= json_encode($fee_history_by_student) ?>;

document.addEventListener("DOMContentLoaded", function() {
    // Initialize DataTables
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [[ 0, "asc" ]]
        });
    }

    // View Modal Event Handler
    $(document).on('click', '.view-btn', function() {
        var name = $(this).data('name');
        var email = $(this).data('email');
        var phone = $(this).data('phone');
        var profileImage = $(this).data('profile_image');
        var status = $(this).data('status');

        var gName = $(this).data('guardian_name');
        var gRel = $(this).data('guardian_relationship');
        var gPhone = $(this).data('guardian_phone');
        var gEmail = $(this).data('guardian_email');
        var gAddr = $(this).data('guardian_address');

        var docType = $(this).data('document_type');
        var docNum = $(this).data('document_number');
        var docFilePath = $(this).data('doc_file_path');
        var verification = $(this).data('verification');
        var docRemarks = $(this).data('doc_remarks');

        var allocId = $(this).data('allocation_id');
        var allocBlock = $(this).data('allocated_block_name');
        var allocFloor = $(this).data('allocated_floor_name');
        var allocRoom = $(this).data('allocated_room_number');
        var allocRoomType = $(this).data('allocated_room_type');
        var allocRoomPrice = $(this).data('allocated_room_price');
        var allocBed = $(this).data('allocated_bed_number');
        var allocDate = $(this).data('allocated_date');

        $('#view_name').text(name);
        $('#view_email').text(email);
        $('#view_phone').text(phone);

        if (profileImage && profileImage.trim() !== '') {
            $('#view_profile_img_wrapper').html('<img src="../student_profile/' + profileImage + '" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover;" alt="Profile">');
        } else {
            $('#view_profile_img_wrapper').html('<div style="width: 70px; height: 70px; border-radius: 50%; background: #e0e0e0; color: #666; display: flex; align-items: center; justify-content: center; font-size: 24px;"><i class="ti-user"></i></div>');
        }

        if (status === 'active') {
            $('#view_account_status_badge').html('<span class="badge badge-success">Account Active</span>');
        } else {
            $('#view_account_status_badge').html('<span class="badge badge-danger">Account Inactive</span>');
        }

        // Room Allocation Info in View Modal
        if (allocId && allocId != '') {
            var priceText = allocRoomPrice ? ' &bull; <span class="text-success font-weight-bold">₹' + Number(allocRoomPrice).toLocaleString('en-IN') + '</span>' : '';
            $('#view_allocation_wrapper').html(
                '<div class="row">' +
                    '<div class="col-md-6 mb-2"><strong>Block & Floor:</strong> ' + allocBlock + ' (' + allocFloor + ')</div>' +
                    '<div class="col-md-6 mb-2"><strong>Room:</strong> Room ' + allocRoom + (allocRoomType ? ' (' + allocRoomType + ')' : '') + priceText + '</div>' +
                    '<div class="col-md-6 mb-2"><strong>Bed Number:</strong> <span class="badge badge-primary font-weight-bold">Bed ' + allocBed + '</span></div>' +
                    '<div class="col-md-6 mb-2"><strong>Allocated Date:</strong> ' + (allocDate ? allocDate : 'N/A') + '</div>' +
                '</div>'
            );
        } else {
            $('#view_allocation_wrapper').html('<span class="text-muted font-italic">No active room or bed allocated to this student.</span>');
        }

        $('#view_guardian_name').text(gName ? gName : 'Not provided');
        $('#view_guardian_relationship').text(gRel ? gRel : 'N/A');
        $('#view_guardian_phone').text(gPhone ? gPhone : 'N/A');
        $('#view_guardian_email').text(gEmail ? gEmail : 'N/A');
        $('#view_guardian_address').text(gAddr ? gAddr : 'N/A');

        $('#view_document_type').text(docType ? docType : 'Not provided');
        $('#view_document_number').text(docNum ? docNum : 'N/A');

        if (verification === 'verified') {
            $('#view_verification_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Verified</span>');
        } else if (verification === 'rejected') {
            $('#view_verification_badge').html('<span class="badge badge-danger"><i class="ti-close mr-1"></i>Rejected</span>');
        } else {
            $('#view_verification_badge').html('<span class="badge badge-warning"><i class="ti-time mr-1"></i>Pending</span>');
        }

        if (docFilePath && docFilePath.trim() !== '') {
            $('#view_doc_file_wrapper').html('<a href="../student_docs/' + docFilePath + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="ti-file mr-1"></i> View Attached Document</a>');
        } else {
            $('#view_doc_file_wrapper').html('<span class="text-muted font-italic">No document file attached</span>');
        }

        $('#view_doc_remarks').text(docRemarks ? docRemarks : 'None');

        // Render Fee History in View Modal
        var studentId = $(this).data('id');
        var studentFees = (typeof feeHistoryData !== 'undefined' && feeHistoryData[studentId]) ? feeHistoryData[studentId] : [];
        if (studentFees && studentFees.length > 0) {
            $('#view_fee_count_badge').text(studentFees.length + (studentFees.length === 1 ? ' Record' : ' Records')).show();
            var feeHtml = '<div class="table-responsive"><table class="table table-bordered table-sm table-hover mb-0">';
            feeHtml += '<thead class="bg-light"><tr><th style="width: 50px;">#</th><th>Fee Amount</th><th>Allocation / Fee Date</th><th>Logged Timestamp</th><th>Status</th></tr></thead><tbody>';
            studentFees.forEach(function(item, idx) {
                feeHtml += '<tr>' +
                    '<td>' + (idx + 1) + '</td>' +
                    '<td><strong class="text-success" style="font-size: 1.05rem;">' + item.formatted_price + '</strong></td>' +
                    '<td><i class="ti-calendar mr-1 text-muted"></i>' + item.formatted_date + '</td>' +
                    '<td><small class="text-muted"><i class="ti-time mr-1"></i>' + item.formatted_created_at + '</small></td>' +
                    '<td><span class="badge badge-success py-1 px-2"><i class="ti-check mr-1"></i>Logged</span></td>' +
                '</tr>';
            });
            feeHtml += '</tbody></table></div>';
            $('#view_fee_history_wrapper').html(feeHtml);
        } else {
            $('#view_fee_count_badge').hide();
            $('#view_fee_history_wrapper').html('<div class="p-3 bg-light rounded text-center text-muted"><i class="ti-credit-card mr-1 text-secondary" style="font-size: 22px;"></i><p class="mb-0 mt-2 small font-weight-medium">No fee history or transaction records logged for this student yet.</p></div>');
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
