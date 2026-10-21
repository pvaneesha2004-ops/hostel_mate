<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Directories for student profiles and documents
$profile_dir = "../student_profile/";
$doc_dir = "../student_docs/";
if (!file_exists($profile_dir)) {
    mkdir($profile_dir, 0777, true);
}
if (!file_exists($doc_dir)) {
    mkdir($doc_dir, 0777, true);
}

$admin_id = $_SESSION['admin_id'] ?? 1;

// Handle Document Verification (Approve / Reject / Reset)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_verification') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $verification_status = $_POST['verification_status'] ?? 'pending';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($student_id > 0 && in_array($verification_status, ['verified', 'rejected', 'pending'])) {
        $account_status = ($verification_status == 'verified') ? 'active' : 'inactive';
        $verified_by_val = ($verification_status == 'verified') ? $admin_id : NULL;
        $verified_at_val = ($verification_status == 'verified') ? date('Y-m-d H:i:s') : NULL;

        // Check if student document record exists
        $doc_check = $conn->query("SELECT id FROM student_documents WHERE student_id=$student_id");
        if ($doc_check && $doc_check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE student_documents SET verification=?, verified_by=?, verified_at=?, remarks=?, updated_at=NOW() WHERE student_id=?");
            $stmt->bind_param("sissi", $verification_status, $verified_by_val, $verified_at_val, $remarks, $student_id);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO student_documents (student_id, document_type, document_number, file_path, verification, verified_by, verified_at, remarks, created_at, updated_at) VALUES (?, 'College ID', 'N/A', '', ?, ?, ?, ?, NOW(), NOW())");
            $stmt->bind_param("isiss", $student_id, $verification_status, $verified_by_val, $verified_at_val, $remarks);
            $stmt->execute();
        }

        // Update student account status in users table
        $u_stmt = $conn->prepare("UPDATE users SET status=?, updated_at=NOW() WHERE id=? AND role='student'");
        $u_stmt->bind_param("si", $account_status, $student_id);
        $u_stmt->execute();

        // If rejected or reset to pending, vacate any active room allocation
        if ($verification_status != 'verified') {
            $alloc_res = $conn->query("SELECT id, bed_id, room_id FROM room_allocations WHERE student_id=$student_id AND status='active'");
            if ($alloc_res && $alloc = $alloc_res->fetch_assoc()) {
                $alloc_id = $alloc['id'];
                $bed_id = $alloc['bed_id'];
                $room_id = $alloc['room_id'];
                $conn->query("UPDATE room_allocations SET status='vacated', vacated_date=CURDATE(), updated_at=NOW() WHERE id=$alloc_id");
                if ($bed_id > 0) { $conn->query("UPDATE beds SET status='available', updated_at=NOW() WHERE id=$bed_id"); }
                if ($room_id > 0) { $conn->query("UPDATE rooms SET status='available', updated_at=NOW() WHERE id=$room_id AND status='full'"); }
            }
        }

        if ($verification_status == 'verified') {
            $_SESSION['msg'] = "Student documents verified and account activated successfully!";
        } elseif ($verification_status == 'rejected') {
            $_SESSION['msg'] = "Student verification marked as Rejected and account set to Inactive.";
        } else {
            $_SESSION['msg'] = "Student verification status reset to Pending.";
        }
    } else {
        $_SESSION['error'] = "Invalid verification request.";
    }
    header("Location: student.php");
    exit();
}

// Handle Delete Student Action
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    // Free active bed before deletion
    $alloc_res = $conn->query("SELECT bed_id, room_id FROM room_allocations WHERE student_id=$id AND status='active'");
    if ($alloc_res && $alloc = $alloc_res->fetch_assoc()) {
        $bed_id = $alloc['bed_id'];
        $room_id = $alloc['room_id'];
        if ($bed_id > 0) {
            $conn->query("UPDATE beds SET status='available', updated_at=NOW() WHERE id=$bed_id");
        }
        if ($room_id > 0) {
            $conn->query("UPDATE rooms SET status='available', updated_at=NOW() WHERE id=$room_id AND status='full'");
        }
    }
    $conn->query("DELETE FROM room_allocations WHERE student_id=$id");

    // Delete profile image & document file if exist
    $u_res = $conn->query("SELECT profile_image FROM users WHERE id=$id");
    if ($u_res && $u_row = $u_res->fetch_assoc()) {
        if (!empty($u_row['profile_image']) && file_exists($profile_dir . $u_row['profile_image'])) {
            @unlink($profile_dir . $u_row['profile_image']);
        }
    }
    $d_res = $conn->query("SELECT file_path FROM student_documents WHERE student_id=$id");
    if ($d_res && $d_row = $d_res->fetch_assoc()) {
        if (!empty($d_row['file_path']) && file_exists($doc_dir . $d_row['file_path'])) {
            @unlink($doc_dir . $d_row['file_path']);
        }
    }

    $conn->query("DELETE FROM student_documents WHERE student_id=$id");
    $conn->query("DELETE FROM student_guardians WHERE student_id=$id");
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND role='student'");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Student deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete student.";
    }
    header("Location: student.php");
    exit();
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

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="card-title mb-0">Student Management</h4>
                </div>
                
                <?php if(isset($_SESSION['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['msg']) ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['msg']); endif; ?>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>SlNo</th>
                                <th>Profile</th>
                                <th>Name</th>
                                <th>Email/Phone</th>
                                <th>Verification Status</th>
                                <th>Allocated Bed</th>
                                <th>Account Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sl = 1; while($row = $students->fetch_assoc()): ?>
                            <?php $isVerified = (strtolower($row['verification'] ?? '') === 'verified'); ?>
                            <tr>
                                <td><?= $sl++ ?></td>
                                <td>
                                    <?php if (!empty($row['profile_image']) && file_exists('../student_profile/' . $row['profile_image'])): ?>
                                        <img src="../student_profile/<?= htmlspecialchars($row['profile_image']) ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0e0e0; color: #666; display: inline-flex; align-items: center; justify-content: center;">
                                            <i class="ti-user"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="font-weight-bold"><?= htmlspecialchars($row['name']) ?></span></td>
                                <td><?= htmlspecialchars($row['email']) ?><br><small class="text-muted"><?= htmlspecialchars($row['phone']) ?></small></td>
                                <td>
                                    <?php 
                                        $ver = strtolower($row['verification'] ?? 'pending');
                                        if($ver == 'verified'): ?>
                                            <span class="badge badge-success"><i class="ti-check mr-1"></i>Verified</span>
                                        <?php elseif($ver == 'rejected'): ?>
                                            <button class="btn btn-outline-danger btn-xs verify-btn" 
                                                data-student_id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                                data-email="<?= htmlspecialchars($row['email']) ?>"
                                                data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                data-profile_image="<?= htmlspecialchars($row['profile_image'] ?? '') ?>"
                                                data-document_type="<?= htmlspecialchars($row['document_type'] ?? 'College ID') ?>"
                                                data-document_number="<?= htmlspecialchars($row['document_number'] ?? 'N/A') ?>"
                                                data-doc_file_path="<?= htmlspecialchars($row['doc_file_path'] ?? '') ?>"
                                                data-verification="<?= htmlspecialchars($row['verification'] ?? 'rejected') ?>"
                                                data-doc_remarks="<?= htmlspecialchars($row['doc_remarks'] ?? '') ?>"
                                                data-toggle="modal" data-target="#verifyModal" title="Click to Re-evaluate Verification">
                                                <i class="ti-close mr-1"></i>Rejected
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-warning btn-xs verify-btn font-weight-bold" 
                                                data-student_id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                                data-email="<?= htmlspecialchars($row['email']) ?>"
                                                data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                data-profile_image="<?= htmlspecialchars($row['profile_image'] ?? '') ?>"
                                                data-document_type="<?= htmlspecialchars($row['document_type'] ?? 'College ID') ?>"
                                                data-document_number="<?= htmlspecialchars($row['document_number'] ?? 'N/A') ?>"
                                                data-doc_file_path="<?= htmlspecialchars($row['doc_file_path'] ?? '') ?>"
                                                data-verification="<?= htmlspecialchars($row['verification'] ?? 'pending') ?>"
                                                data-doc_remarks="<?= htmlspecialchars($row['doc_remarks'] ?? '') ?>"
                                                data-toggle="modal" data-target="#verifyModal" title="Click to Verify Documents">
                                                <i class="ti-time mr-1"></i>Pending (Verify)
                                            </button>
                                        <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['allocation_id'])): ?>
                                        <span class="badge badge-info mb-1"><i class="ti-home mr-1"></i><?= htmlspecialchars($row['allocated_block_name'] ?? '') ?> (<?= htmlspecialchars($row['allocated_floor_name'] ?? '') ?>)</span><br>
                                        <small class="font-weight-bold text-dark">Room <?= htmlspecialchars($row['allocated_room_number'] ?? '') ?> | Bed <?= htmlspecialchars($row['allocated_bed_number'] ?? '') ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted">Not Allocated</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($row['status'] == 'active'): ?>
                                        <label class="badge badge-success">Active</label>
                                    <?php else: ?>
                                        <label class="badge badge-danger">Inactive</label>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- View Details Button -->
                                    <button class="btn btn-warning btn-sm view-btn" title="View Details"
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
                                        data-toggle="modal" data-target="#viewModal"><i class="ti-eye"></i></button>

                                    <!-- Delete Button -->
                                    <a href="javascript:void(0);" data-url="student.php?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm delete-btn" title="Delete"><i class="ti-trash"></i></a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Verification Modal -->
<div class="modal fade" id="verifyModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="verifyForm">
          <div class="modal-header">
            <h5 class="modal-title">Document & Student Verification</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="update_verification">
            <input type="hidden" name="student_id" id="verify_student_id">
            <input type="hidden" name="verification_status" id="verify_status_input" value="verified">

            <!-- Student Summary Banner -->
            <div class="d-flex align-items-center p-3 mb-3 rounded" style="background: #f8f9fa; border: 1px solid #e9ecef;">
                <div id="verify_profile_img_wrapper" class="mr-3"></div>
                <div>
                    <h5 class="mb-1 font-weight-bold" id="verify_student_name"></h5>
                    <p class="mb-0 text-muted small"><i class="ti-email mr-1"></i><span id="verify_student_email"></span> | <i class="ti-mobile mr-1"></i><span id="verify_student_phone"></span></p>
                </div>
            </div>

            <!-- Verification Notice Box -->
            <div class="alert alert-warning py-2 px-3 mb-3 rounded small" id="verify_notice_box">
                <i class="ti-alert mr-1"></i> <strong>Note:</strong> Verifying documents will activate the student account.
            </div>

            <!-- Document Details Card -->
            <div class="card border mb-3">
                <div class="card-header bg-light py-2 font-weight-bold">
                    <i class="ti-id-badge mr-1"></i> Submitted Document Details
                </div>
                <div class="card-body p-3">
                    <div class="mb-2"><strong>Document Type:</strong> <span id="verify_doc_type" class="text-primary font-weight-medium"></span></div>
                    <div class="mb-2"><strong>Document Number:</strong> <span id="verify_doc_number" class="font-weight-medium"></span></div>
                    <div class="mb-2"><strong>Current Status:</strong> <span id="verify_current_status_badge"></span></div>
                    <div class="mt-3" id="verify_doc_file_wrapper"></div>
                </div>
            </div>

            <!-- Verification Remarks -->
            <div class="form-group">
                <label>Verification Remarks / Feedback</label>
                <textarea name="remarks" id="verify_remarks" class="form-control" rows="2" placeholder="e.g. Documents verified against original college ID"></textarea>
            </div>
          </div>
          <div class="modal-footer d-flex justify-content-between">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            <div>
                <button type="button" class="btn btn-danger mr-1" id="btn_reject_verification"><i class="ti-close mr-1"></i>Reject</button>
                <button type="button" class="btn btn-success" id="btn_approve_verification"><i class="ti-check mr-1"></i>Approve & Verify</button>
            </div>
          </div>
      </form>
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

<style>
    .custom-swal-popup {
        padding: 35px 30px !important;
        border-radius: 12px !important;
    }
</style>
<script>
const feeHistoryData = <?= json_encode($fee_history_by_student) ?>;

document.addEventListener("DOMContentLoaded", function() {
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

    // Verification Modal Handler
    $(document).on('click', '.verify-btn', function() {
        var studentId = $(this).data('student_id');
        var name = $(this).data('name');
        var email = $(this).data('email');
        var phone = $(this).data('phone');
        var profileImage = $(this).data('profile_image');

        var docType = $(this).data('document_type');
        var docNum = $(this).data('document_number');
        var docFilePath = $(this).data('doc_file_path');
        var verification = $(this).data('verification');
        var docRemarks = $(this).data('doc_remarks');

        $('#verify_student_id').val(studentId);
        $('#verify_student_name').text(name);
        $('#verify_student_email').text(email);
        $('#verify_student_phone').text(phone);
        $('#verify_doc_type').text(docType ? docType : 'Not specified');
        $('#verify_doc_number').text(docNum ? docNum : 'N/A');
        $('#verify_remarks').val(docRemarks ? docRemarks : '');

        if (profileImage && profileImage.trim() !== '') {
            $('#verify_profile_img_wrapper').html('<img src="../student_profile/' + profileImage + '" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover;" alt="Profile">');
        } else {
            $('#verify_profile_img_wrapper').html('<div style="width: 50px; height: 50px; border-radius: 50%; background: #e0e0e0; color: #666; display: flex; align-items: center; justify-content: center; font-size: 20px;"><i class="ti-user"></i></div>');
        }

        if (verification === 'verified') {
            $('#verify_current_status_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Verified</span>');
        } else if (verification === 'rejected') {
            $('#verify_current_status_badge').html('<span class="badge badge-danger"><i class="ti-close mr-1"></i>Rejected</span>');
        } else {
            $('#verify_current_status_badge').html('<span class="badge badge-warning"><i class="ti-time mr-1"></i>Pending</span>');
        }

        if (docFilePath && docFilePath.trim() !== '') {
            $('#verify_doc_file_wrapper').html('<a href="../student_docs/' + docFilePath + '" target="_blank" class="btn btn-outline-primary btn-sm"><i class="ti-file mr-1"></i> View / Download Document (' + docFilePath + ')</a>');
        } else {
            $('#verify_doc_file_wrapper').html('<span class="text-danger font-italic small"><i class="ti-alert mr-1"></i> No document file uploaded by student.</span>');
        }
    });

    // Approve Button Click
    $('#btn_approve_verification').on('click', function() {
        $('#verify_status_input').val('verified');
        $('#verifyForm').submit();
    });

    // Reject Button Click
    $('#btn_reject_verification').on('click', function() {
        $('#verify_status_input').val('rejected');
        $('#verifyForm').submit();
    });

    // Delete Confirmation with SweetAlert
    $(document).on('click', '.delete-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');

        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            customClass: {
                popup: 'custom-swal-popup'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>