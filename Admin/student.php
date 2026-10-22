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

    // Delete profile image & receipt & document file if exist
    $u_res = $conn->query("SELECT profile_image, receipt FROM users WHERE id=$id");
    if ($u_res && $u_row = $u_res->fetch_assoc()) {
        if (!empty($u_row['profile_image']) && file_exists($profile_dir . $u_row['profile_image'])) {
            @unlink($profile_dir . $u_row['profile_image']);
        }
        if (!empty($u_row['receipt']) && file_exists($doc_dir . $u_row['receipt'])) {
            @unlink($doc_dir . $u_row['receipt']);
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
           verifier.name AS verifier_name,
           verifier.role AS verifier_role,
           ra.id AS allocation_id,
           ra.block_id,
           ra.floor_id,
           ra.room_id,
           ra.bed_id,
           ra.allocated_date,
           ra.remarks AS alloc_remarks,
           allocator.name AS allocator_name,
           blk.name AS allocated_block_name,
           flr.name AS allocated_floor_name,
           rm.room_number AS allocated_room_number,
           rm.room_type AS allocated_room_type,
           rm.price AS allocated_room_price,
           bd.bed_number AS allocated_bed_number
    FROM users u
    LEFT JOIN student_guardians g ON u.id = g.student_id
    LEFT JOIN student_documents d ON u.id = d.student_id
    LEFT JOIN users verifier ON d.verified_by = verifier.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN users allocator ON ra.allocated_by = allocator.id
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE u.role = 'student'
    ORDER BY u.id DESC
";
$students = $conn->query($query);

// Fetch active room occupants (roommates) with their language, room_type, and bed details
$occupants_res = $conn->query("
    SELECT ra.room_id, ra.bed_id, ra.student_id, ra.allocated_date,
           u.name AS student_name, u.email AS student_email, u.phone AS student_phone,
           u.language AS student_language, u.room_type AS student_room_type,
           b.bed_number
    FROM room_allocations ra
    JOIN users u ON ra.student_id = u.id
    JOIN beds b ON ra.bed_id = b.id
    WHERE ra.status = 'active'
    ORDER BY CAST(b.bed_number AS UNSIGNED) ASC, b.bed_number ASC
");
$room_occupants = [];
if ($occupants_res && $occupants_res->num_rows > 0) {
    while($occ = $occupants_res->fetch_assoc()) {
        $rid = intval($occ['room_id']);
        if (!isset($room_occupants[$rid])) {
            $room_occupants[$rid] = [];
        }
        $room_occupants[$rid][] = $occ;
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
        if (!empty($rawDate) && $rawDate !== '0000-00-00') {
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
                                <td>
                                    <span class="font-weight-bold"><?= htmlspecialchars($row['name']) ?></span>
                                    <?php if (!empty($row['room_type']) || !empty($row['floor_type']) || !empty($row['language']) || !empty($row['receipt'])): ?>
                                        <div class="mt-1" style="display: flex; flex-wrap: wrap; gap: 4px;">
                                            <?php if (!empty($row['room_type'])): ?>
                                                <span class="badge badge-light border text-primary" style="font-size: 0.72rem;" title="Preferred Room Type"><i class="ti-home mr-1"></i><?= ucfirst(htmlspecialchars($row['room_type'])) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($row['floor_type'])): ?>
                                                <span class="badge badge-light border text-info" style="font-size: 0.72rem;" title="Preferred Floor"><i class="ti-layers mr-1"></i><?= ucfirst(htmlspecialchars($row['floor_type'])) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($row['language'])): ?>
                                                <span class="badge badge-light border" style="color: #6366f1; font-size: 0.72rem;" title="Roommate Language"><i class="ti-comment mr-1"></i><?= ucfirst(htmlspecialchars($row['language'])) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($row['receipt'])): ?>
                                                <span class="badge badge-light border text-success" style="font-size: 0.72rem;" title="Payment / Fee Receipt Uploaded"><i class="ti-receipt mr-1"></i>Receipt</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
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
                                                data-receipt="<?= htmlspecialchars($row['receipt'] ?? '') ?>"
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
                                                data-receipt="<?= htmlspecialchars($row['receipt'] ?? '') ?>"
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
                                        data-receipt="<?= htmlspecialchars($row['receipt'] ?? '') ?>"
                                        data-status="<?= htmlspecialchars($row['status']) ?>"
                                        data-created_at="<?= htmlspecialchars($row['created_at'] ?? '') ?>"
                                        data-last_login="<?= htmlspecialchars($row['last_login'] ?? '') ?>"
                                        data-pref_room_type="<?= htmlspecialchars($row['room_type'] ?? '') ?>"
                                        data-pref_language="<?= htmlspecialchars($row['language'] ?? '') ?>"
                                        data-pref_floor_type="<?= htmlspecialchars($row['floor_type'] ?? '') ?>"
                                        data-guardian_name="<?= htmlspecialchars($row['guardian_name'] ?? '') ?>"
                                        data-guardian_relationship="<?= htmlspecialchars($row['guardian_relationship'] ?? '') ?>"
                                        data-guardian_phone="<?= htmlspecialchars($row['guardian_phone'] ?? '') ?>"
                                        data-guardian_email="<?= htmlspecialchars($row['guardian_email'] ?? '') ?>"
                                        data-guardian_address="<?= htmlspecialchars($row['guardian_address'] ?? '') ?>"
                                        data-document_type="<?= htmlspecialchars($row['document_type'] ?? '') ?>"
                                        data-document_number="<?= htmlspecialchars($row['document_number'] ?? '') ?>"
                                        data-doc_file_path="<?= htmlspecialchars($row['doc_file_path'] ?? '') ?>"
                                        data-verification="<?= htmlspecialchars($row['verification'] ?? 'pending') ?>"
                                        data-verified_by_name="<?= htmlspecialchars($row['verifier_name'] ?? '') ?>"
                                        data-verified_at="<?= htmlspecialchars($row['verified_at'] ?? '') ?>"
                                        data-doc_remarks="<?= htmlspecialchars($row['doc_remarks'] ?? '') ?>"
                                        data-allocation_id="<?= $row['allocation_id'] ?? '' ?>"
                                        data-room_id="<?= $row['room_id'] ?? '' ?>"
                                        data-allocated_block_name="<?= htmlspecialchars($row['allocated_block_name'] ?? '') ?>"
                                        data-allocated_floor_name="<?= htmlspecialchars($row['allocated_floor_name'] ?? '') ?>"
                                        data-allocated_room_number="<?= htmlspecialchars($row['allocated_room_number'] ?? '') ?>"
                                        data-allocated_room_type="<?= htmlspecialchars($row['allocated_room_type'] ?? '') ?>"
                                        data-allocated_room_price="<?= htmlspecialchars($row['allocated_room_price'] ?? '') ?>"
                                        data-allocated_bed_number="<?= htmlspecialchars($row['allocated_bed_number'] ?? '') ?>"
                                        data-allocated_date="<?= htmlspecialchars($row['allocated_date'] ?? '') ?>"
                                        data-allocator_name="<?= htmlspecialchars($row['allocator_name'] ?? '') ?>"
                                        data-alloc_remarks="<?= htmlspecialchars($row['alloc_remarks'] ?? '') ?>"
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
                    <div class="mt-3" id="verify_receipt_file_wrapper"></div>
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
  <div class="modal-dialog modal-lg" role="document" style="max-width: 820px;">
    <div class="modal-content shadow-lg border-0" style="border-radius: 12px; overflow: hidden;">
        <div class="modal-header bg-primary text-white py-3">
          <div class="d-flex align-items-center">
            <i class="ti-user mr-2" style="font-size: 20px;"></i>
            <h5 class="modal-title font-weight-bold mb-0 text-white">Student Detailed Profile & Information</h5>
          </div>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body p-4" style="background-color: #f8fafc; max-height: calc(100vh - 160px); overflow-y: auto;">
            <!-- Student Header Profile Banner -->
            <div class="card border-0 shadow-sm mb-3" style="border-radius: 10px; background: #ffffff;">
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-sm-row align-items-sm-center">
                        <div id="view_profile_img_wrapper" class="mr-sm-4 mb-3 mb-sm-0 text-center"></div>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center justify-content-between mb-1">
                                <h4 id="view_name" class="font-weight-bold mb-1 text-dark"></h4>
                                <div class="d-flex align-items-center" style="gap: 6px;">
                                    <span id="view_student_id_badge" class="badge badge-light border font-weight-bold text-muted"></span>
                                    <span id="view_account_status_badge"></span>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap text-muted small mb-2" style="gap: 15px;">
                                <span><i class="ti-email mr-1 text-primary"></i><a id="view_email" href="#" class="text-muted"></a></span>
                                <span><i class="ti-mobile mr-1 text-success"></i><a id="view_phone" href="#" class="text-muted"></a></span>
                                <span><i class="ti-shield mr-1 text-info"></i>Role: Student</span>
                            </div>
                            <div class="pt-2 border-top d-flex flex-wrap text-muted small" style="gap: 20px; font-size: 0.78rem;">
                                <span><i class="ti-calendar mr-1 text-secondary"></i><strong>Registered:</strong> <span id="view_created_at"></span></span>
                                <span><i class="ti-time mr-1 text-secondary"></i><strong>Last Login:</strong> <span id="view_last_login"></span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Registration Room & Sharing Preferences Card -->
            <div class="card border shadow-sm mb-3" style="border-radius: 10px;">
                <div class="card-header bg-white py-2 font-weight-bold d-flex align-items-center justify-content-between">
                    <span class="text-dark"><i class="ti-bookmark-alt mr-2 text-primary"></i> Registration Room & Sharing Preferences</span>
                    <span class="badge badge-light border text-muted" style="font-size: 0.72rem;">Student Request</span>
                </div>
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-sm-4 mb-2 mb-sm-0">
                            <small class="text-muted d-block font-weight-medium">Requested Room Type</small>
                            <span id="view_pref_room_type"></span>
                        </div>
                        <div class="col-sm-4 mb-2 mb-sm-0">
                            <small class="text-muted d-block font-weight-medium">Requested Floor</small>
                            <span id="view_pref_floor_type"></span>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block font-weight-medium">Preferred Roommate Language</small>
                            <span id="view_pref_language"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Document & Verification Card -->
            <div class="card border shadow-sm mb-3" style="border-radius: 10px;">
                <div class="card-header bg-white py-2 font-weight-bold d-flex justify-content-between align-items-center">
                    <span class="text-dark"><i class="ti-id-badge mr-2 text-info"></i> Document Verification & Identity</span>
                    <span id="view_verification_badge"></span>
                </div>
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>Document Type:</strong> <span id="view_document_type" class="text-primary font-weight-medium"></span></div>
                        <div class="col-md-6 mb-2"><strong>Document Number:</strong> <span id="view_document_number" class="font-weight-medium"></span></div>
                        <div class="col-md-6 mb-2" id="view_verified_by_col"><strong>Verified By:</strong> <span id="view_verified_by"></span></div>
                        <div class="col-md-6 mb-2" id="view_verified_at_col"><strong>Verified At:</strong> <span id="view_verified_at"></span></div>
                        <div class="col-12 mt-1 mb-2"><strong>Attached Document:</strong> <span id="view_doc_file_wrapper" class="ml-2"></span></div>
                        <div class="col-12 mt-1 mb-2"><strong>Payment / Fee Receipt:</strong> <span id="view_receipt_file_wrapper" class="ml-2"></span></div>
                        <div class="col-12"><strong>Verification Remarks:</strong> <span id="view_doc_remarks" class="text-muted"></span></div>
                    </div>
                </div>
            </div>

            <!-- Room & Bed Allocation Card -->
            <div class="card border shadow-sm mb-3" style="border-radius: 10px;">
                <div class="card-header bg-white py-2 font-weight-bold d-flex justify-content-between align-items-center">
                    <span class="text-dark"><i class="ti-home mr-2 text-warning"></i> Active Room & Bed Allocation</span>
                    <span id="view_allocation_status_badge"></span>
                </div>
                <div class="card-body p-3">
                    <div id="view_allocation_wrapper"></div>
                </div>
            </div>

            <!-- Guardian Info Card -->
            <div class="card border shadow-sm mb-3" style="border-radius: 10px;">
                <div class="card-header bg-white py-2 font-weight-bold text-dark">
                    <i class="ti-user mr-2 text-secondary"></i> Parent / Guardian Information
                </div>
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>Guardian Name:</strong> <span id="view_guardian_name"></span></div>
                        <div class="col-md-6 mb-2"><strong>Relationship:</strong> <span id="view_guardian_relationship"></span></div>
                        <div class="col-md-6 mb-2"><strong>Phone Number:</strong> <span id="view_guardian_phone"></span></div>
                        <div class="col-md-6 mb-2"><strong>Email Address:</strong> <span id="view_guardian_email"></span></div>
                        <div class="col-12"><strong>Permanent Address:</strong> <span id="view_guardian_address"></span></div>
                    </div>
                </div>
            </div>

            <!-- Fee History Card -->
            <div class="card border shadow-sm mb-0" style="border-radius: 10px;">
                <div class="card-header bg-white py-2 font-weight-bold d-flex justify-content-between align-items-center">
                    <span class="text-dark"><i class="ti-credit-card mr-2 text-success"></i> Fee History & Transactions</span>
                    <span id="view_fee_count_badge" class="badge badge-success"></span>
                </div>
                <div class="card-body p-3">
                    <div id="view_fee_history_wrapper"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer bg-light py-2 px-4">
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
const roomOccupantsData = <?= json_encode($room_occupants) ?>;

document.addEventListener("DOMContentLoaded", function() {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [[ 0, "asc" ]]
        });
    }

    // Helper formatting functions
    function formatDateTime(dateStr) {
        if (!dateStr || dateStr === '0000-00-00 00:00:00' || dateStr === '0000-00-00' || dateStr.trim() === '') {
            return null;
        }
        var d = new Date(dateStr.replace(/-/g, "/"));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) + ', ' + 
               d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
    }

    function formatDate(dateStr) {
        if (!dateStr || dateStr === '0000-00-00' || dateStr.trim() === '') {
            return null;
        }
        var d = new Date(dateStr.replace(/-/g, "/"));
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function capitalizeText(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    // View Modal Event Handler
    $(document).on('click', '.view-btn', function() {
        var studentId = $(this).data('id');
        var name = $(this).data('name');
        var email = $(this).data('email');
        var phone = $(this).data('phone');
        var profileImage = $(this).data('profile_image');
        var status = $(this).data('status');
        var createdAt = $(this).data('created_at');
        var lastLogin = $(this).data('last_login');

        // Header Info
        $('#view_student_id_badge').text('#STU-' + studentId);
        $('#view_name').text(name);
        $('#view_email').text(email).attr('href', 'mailto:' + email);
        $('#view_phone').text(phone).attr('href', 'tel:' + phone);

        var formattedCreatedAt = formatDateTime(createdAt);
        $('#view_created_at').text(formattedCreatedAt ? formattedCreatedAt : 'N/A');

        var formattedLastLogin = formatDateTime(lastLogin);
        if (formattedLastLogin) {
            $('#view_last_login').html(formattedLastLogin);
        } else {
            $('#view_last_login').html('<span class="text-muted font-italic">Never logged in</span>');
        }

        if (profileImage && profileImage.trim() !== '') {
            $('#view_profile_img_wrapper').html('<img src="../student_profile/' + profileImage + '" style="width: 75px; height: 75px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);" alt="Profile">');
        } else {
            $('#view_profile_img_wrapper').html('<div style="width: 75px; height: 75px; border-radius: 50%; background: #f1f5f9; color: #64748b; display: inline-flex; align-items: center; justify-content: center; font-size: 28px; border: 3px solid #e2e8f0;"><i class="ti-user"></i></div>');
        }

        if (status === 'active') {
            $('#view_account_status_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Active</span>');
        } else {
            $('#view_account_status_badge').html('<span class="badge badge-danger"><i class="ti-close mr-1"></i>Inactive</span>');
        }

        // Registration Preferences
        var prefRoomType = $(this).data('pref_room_type');
        var prefLanguage = $(this).data('pref_language');
        var prefFloorType = $(this).data('pref_floor_type');

        var roomTypeFormatted = prefRoomType ? capitalizeText(prefRoomType) : '<span class="text-muted font-italic">Not specified</span>';
        var floorTypeFormatted = prefFloorType ? capitalizeText(prefFloorType) + ' Floor' : '<span class="text-muted font-italic">Not specified</span>';
        var languageFormatted = '';

        if (prefRoomType && prefRoomType.toLowerCase() === 'single') {
            languageFormatted = '<span class="badge badge-light border text-muted">Not applicable (Single Room)</span>';
        } else if (prefLanguage) {
            languageFormatted = '<span class="badge badge-light border font-weight-bold" style="color: #6366f1;">' + capitalizeText(prefLanguage) + '</span>';
        } else {
            languageFormatted = '<span class="text-muted font-italic">Not specified</span>';
        }

        $('#view_pref_room_type').html(prefRoomType ? '<span class="badge badge-light border text-primary font-weight-bold"><i class="ti-home mr-1"></i>' + roomTypeFormatted + '</span>' : roomTypeFormatted);
        $('#view_pref_floor_type').html(prefFloorType ? '<span class="badge badge-light border text-info font-weight-bold"><i class="ti-layers mr-1"></i>' + floorTypeFormatted + '</span>' : floorTypeFormatted);
        $('#view_pref_language').html(languageFormatted);

        // Document & Verification Details
        var docType = $(this).data('document_type');
        var docNum = $(this).data('document_number');
        var docFilePath = $(this).data('doc_file_path');
        var verification = ($(this).data('verification') || 'pending').toLowerCase();
        var verifiedByName = $(this).data('verified_by_name');
        var verifiedAt = $(this).data('verified_at');
        var docRemarks = $(this).data('doc_remarks');

        $('#view_document_type').text(docType ? docType : 'Not specified');
        $('#view_document_number').text(docNum ? docNum : 'N/A');

        if (verification === 'verified') {
            $('#view_verification_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Verified</span>');
        } else if (verification === 'rejected') {
            $('#view_verification_badge').html('<span class="badge badge-danger"><i class="ti-close mr-1"></i>Rejected</span>');
        } else {
            $('#view_verification_badge').html('<span class="badge badge-warning"><i class="ti-time mr-1"></i>Pending Verification</span>');
        }

        var formattedVerifiedAt = formatDateTime(verifiedAt);
        if (verification === 'verified') {
            $('#view_verified_by').html(verifiedByName ? ('<span class="font-weight-medium">' + verifiedByName + '</span>') : '<span class="text-muted">Administrator</span>');
            $('#view_verified_at').html(formattedVerifiedAt ? formattedVerifiedAt : 'N/A');
            $('#view_verified_by_col').show();
            $('#view_verified_at_col').show();
        } else {
            $('#view_verified_by').text('N/A');
            $('#view_verified_at').text('N/A');
            $('#view_verified_by_col').hide();
            $('#view_verified_at_col').hide();
        }

        if (docFilePath && docFilePath.trim() !== '') {
            $('#view_doc_file_wrapper').html('<a href="../student_docs/' + docFilePath + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="ti-file mr-1"></i> View / Download Document (' + docFilePath + ')</a>');
        } else {
            $('#view_doc_file_wrapper').html('<span class="text-danger small font-italic"><i class="ti-alert mr-1"></i> No document file uploaded</span>');
        }

        // Receipt in View Modal
        var receipt = $(this).data('receipt');
        if (receipt && receipt.trim() !== '') {
            var ext = receipt.split('.').pop().toLowerCase();
            var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1;
            var html = '<a href="../student_docs/' + receipt + '" target="_blank" class="btn btn-sm btn-outline-success"><i class="ti-receipt mr-1"></i> View Receipt (' + receipt + ')</a>';
            if (isImg) {
                html += '<div class="mt-2"><a href="../student_docs/' + receipt + '" target="_blank"><img src="../student_docs/' + receipt + '" style="max-height: 140px; max-width: 100%; border-radius: 6px; border: 1px solid #ddd; object-fit: contain;" alt="Receipt Preview"></a></div>';
            }
            $('#view_receipt_file_wrapper').html(html);
        } else {
            $('#view_receipt_file_wrapper').html('<span class="text-muted small font-italic"><i class="ti-info-alt mr-1"></i> No receipt uploaded</span>');
        }

        $('#view_doc_remarks').text(docRemarks ? docRemarks : 'None provided');

        // Room Allocation Info in View Modal
        var allocId = $(this).data('allocation_id');
        var roomId = parseInt($(this).data('room_id') || 0);
        var allocBlock = $(this).data('allocated_block_name');
        var allocFloor = $(this).data('allocated_floor_name');
        var allocRoom = $(this).data('allocated_room_number');
        var allocRoomType = $(this).data('allocated_room_type');
        var allocRoomPrice = $(this).data('allocated_room_price');
        var allocBed = $(this).data('allocated_bed_number');
        var allocDate = $(this).data('allocated_date');
        var allocatorName = $(this).data('allocator_name');
        var allocRemarks = $(this).data('alloc_remarks');

        var currentStudentId = parseInt(studentId || 0);
        var roommates = [];
        if (roomId > 0 && typeof roomOccupantsData !== 'undefined' && roomOccupantsData[roomId]) {
            roommates = roomOccupantsData[roomId].filter(function(occ) {
                return parseInt(occ.student_id) !== currentStudentId;
            });
        }

        if (allocId && allocId != '') {
            $('#view_allocation_status_badge').html('<span class="badge badge-success"><i class="ti-check mr-1"></i>Allocated</span>');
            var priceText = allocRoomPrice ? '<span class="text-success font-weight-bold">₹' + Number(allocRoomPrice).toLocaleString('en-IN') + ' / month</span>' : 'N/A';
            var formattedAllocDate = formatDate(allocDate) || allocDate || 'N/A';
            
            var allocHtml = '<div class="row">' +
                '<div class="col-md-6 mb-2"><strong>Block & Floor:</strong> <span class="text-dark">' + allocBlock + ' (' + allocFloor + ')</span></div>' +
                '<div class="col-md-6 mb-2"><strong>Room:</strong> <span class="font-weight-medium">Room ' + allocRoom + (allocRoomType ? ' (' + capitalizeText(allocRoomType) + ')' : '') + '</span></div>' +
                '<div class="col-md-6 mb-2"><strong>Bed Number:</strong> <span class="badge badge-primary font-weight-bold px-2 py-1">Bed ' + allocBed + '</span></div>' +
                '<div class="col-md-6 mb-2"><strong>Room Price:</strong> ' + priceText + '</div>' +
                '<div class="col-md-6 mb-2"><strong>Allocated Date:</strong> <span class="text-dark">' + formattedAllocDate + '</span></div>' +
                '<div class="col-md-6 mb-2"><strong>Allocated By:</strong> <span class="text-dark">' + (allocatorName ? allocatorName : 'Hostel Administration') + '</span></div>' +
                '<div class="col-12 mb-2"><strong>Allocation Remarks:</strong> <span class="text-muted">' + (allocRemarks ? allocRemarks : 'None') + '</span></div>' +
            '</div>';

            // Roommates logic
            if (roommates && roommates.length > 0) {
                allocHtml += '<div class="mt-3 pt-3 border-top">' +
                    '<strong class="d-block mb-2 text-dark small"><i class="ti-user mr-1 text-primary"></i> Other Roommates in Room ' + allocRoom + ' (' + roommates.length + '):</strong>' +
                    '<div class="table-responsive">' +
                        '<table class="table table-sm table-bordered mb-0 bg-white">' +
                            '<thead class="bg-light">' +
                                '<tr><th>Bed</th><th>Roommate Name</th><th>Contact</th><th>Language</th><th>Allocated Date</th></tr>' +
                            '</thead>' +
                            '<tbody>';
                roommates.forEach(function(rm) {
                    var rmDate = formatDate(rm.allocated_date) || rm.allocated_date || 'N/A';
                    allocHtml += '<tr>' +
                        '<td><span class="badge badge-info font-weight-bold">Bed ' + rm.bed_number + '</span></td>' +
                        '<td><strong>' + rm.student_name + '</strong></td>' +
                        '<td>' + (rm.student_phone ? ('<a href="tel:' + rm.student_phone + '" class="text-dark"><i class="ti-mobile mr-1 text-muted"></i>' + rm.student_phone + '</a>') : (rm.student_email ? ('<a href="mailto:' + rm.student_email + '"><i class="ti-email mr-1 text-muted"></i>' + rm.student_email + '</a>') : 'N/A')) + '</td>' +
                        '<td>' + (rm.student_language ? '<span class="badge badge-light border" style="color: #6366f1;">' + capitalizeText(rm.student_language) + '</span>' : '<span class="text-muted">N/A</span>') + '</td>' +
                        '<td><small class="text-muted">' + rmDate + '</small></td>' +
                    '</tr>';
                });
                allocHtml += '</tbody></table></div></div>';
            } else {
                allocHtml += '<div class="mt-2 pt-2 border-top">' +
                    '<small class="text-muted"><i class="ti-info-alt mr-1"></i> No other roommates currently in this room (remaining beds are vacant).</small>' +
                '</div>';
            }

            $('#view_allocation_wrapper').html(allocHtml);
        } else {
            $('#view_allocation_status_badge').html('<span class="badge badge-secondary">Not Allocated</span>');
            $('#view_allocation_wrapper').html('<div class="p-3 bg-light rounded text-center text-muted"><i class="ti-home mr-1 text-secondary" style="font-size: 22px;"></i><p class="mb-0 mt-2 small font-weight-medium">No active room or bed allocated to this student yet.</p></div>');
        }

        // Guardian Details
        var gName = $(this).data('guardian_name');
        var gRel = $(this).data('guardian_relationship');
        var gPhone = $(this).data('guardian_phone');
        var gEmail = $(this).data('guardian_email');
        var gAddr = $(this).data('guardian_address');

        $('#view_guardian_name').text(gName ? gName : 'Not provided');
        $('#view_guardian_relationship').text(gRel ? gRel : 'N/A');
        if (gPhone) {
            $('#view_guardian_phone').html('<a href="tel:' + gPhone + '" class="text-dark"><i class="ti-mobile mr-1 text-muted"></i>' + gPhone + '</a>');
        } else {
            $('#view_guardian_phone').text('N/A');
        }
        if (gEmail) {
            $('#view_guardian_email').html('<a href="mailto:' + gEmail + '"><i class="ti-email mr-1 text-muted"></i>' + gEmail + '</a>');
        } else {
            $('#view_guardian_email').text('N/A');
        }
        $('#view_guardian_address').text(gAddr ? gAddr : 'Not provided');

        // Render Fee History in View Modal
        var studentFees = (typeof feeHistoryData !== 'undefined' && feeHistoryData[studentId]) ? feeHistoryData[studentId] : [];
        if (studentFees && studentFees.length > 0) {
            $('#view_fee_count_badge').text(studentFees.length + (studentFees.length === 1 ? ' Record' : ' Records')).show();
            var feeHtml = '<div class="table-responsive"><table class="table table-bordered table-sm table-hover mb-0 bg-white">';
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

        // Receipt in Verify Modal
        var receipt = $(this).data('receipt');
        if (receipt && receipt.trim() !== '') {
            var ext = receipt.split('.').pop().toLowerCase();
            var isImg = ['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1;
            var rHtml = '<div class="p-2 border rounded bg-white mt-2"><strong>Fee / Payment Receipt:</strong><br><a href="../student_docs/' + receipt + '" target="_blank" class="btn btn-outline-success btn-sm mt-1"><i class="ti-receipt mr-1"></i> View / Download Receipt (' + receipt + ')</a>';
            if (isImg) {
                rHtml += '<div class="mt-2"><a href="../student_docs/' + receipt + '" target="_blank"><img src="../student_docs/' + receipt + '" style="max-height: 120px; max-width: 100%; border-radius: 6px; border: 1px solid #ddd; object-fit: contain;" alt="Receipt Preview"></a></div>';
            }
            rHtml += '</div>';
            $('#verify_receipt_file_wrapper').html(rHtml);
        } else {
            $('#verify_receipt_file_wrapper').html('<div class="p-2 border rounded bg-white mt-2 text-muted small font-italic"><i class="ti-alert mr-1"></i> No fee receipt uploaded by student.</div>');
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