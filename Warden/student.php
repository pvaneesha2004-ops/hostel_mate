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
            $_SESSION['msg'] = "Student documents verified and account activated successfully! Room allocation is now available.";
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

// Handle Room & Bed Allocation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'allocate_bed') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $block_id = intval($_POST['block_id'] ?? 0);
    $floor_id = intval($_POST['floor_id'] ?? 0);
    $room_id = intval($_POST['room_id'] ?? 0);
    $bed_id = intval($_POST['bed_id'] ?? 0);
    $allocated_date = !empty($_POST['allocated_date']) ? $_POST['allocated_date'] : date('Y-m-d');
    $remarks = trim($_POST['remarks'] ?? '');

    // 0. Verify that student is verified before allowing allocation
    $stu_check = $conn->query("SELECT d.verification FROM users u LEFT JOIN student_documents d ON u.id = d.student_id WHERE u.id = $student_id AND u.role = 'student'");
    if ($stu_check && $stu = $stu_check->fetch_assoc()) {
        if (strtolower($stu['verification'] ?? '') !== 'verified') {
            $_SESSION['error'] = "Cannot allocate room! Student document verification is pending or rejected. Please verify the student first.";
            header("Location: student.php");
            exit();
        }
    }

    if ($student_id > 0 && $block_id > 0 && $floor_id > 0 && $room_id > 0 && $bed_id > 0) {
        // 1. Free any previous active allocation for this student
        $active_alloc = $conn->query("SELECT id, bed_id, room_id FROM room_allocations WHERE student_id=$student_id AND status='active'");
        if ($active_alloc && $active_alloc->num_rows > 0) {
            while ($prev = $active_alloc->fetch_assoc()) {
                $prev_alloc_id = $prev['id'];
                $prev_bed_id = $prev['bed_id'];
                $prev_room_id = $prev['room_id'];

                $conn->query("UPDATE room_allocations SET status='vacated', vacated_date=CURDATE(), updated_at=NOW() WHERE id=$prev_alloc_id");
                if ($prev_bed_id != $bed_id && $prev_bed_id > 0) {
                    $conn->query("UPDATE beds SET status='available', updated_at=NOW() WHERE id=$prev_bed_id");
                }
                if ($prev_room_id != $room_id && $prev_room_id > 0) {
                    $conn->query("UPDATE rooms SET status='available', updated_at=NOW() WHERE id=$prev_room_id AND status='full'");
                }
            }
        }

        // 2. Insert new room allocation
        $stmt = $conn->prepare("INSERT INTO room_allocations (student_id, block_id, floor_id, room_id, bed_id, allocated_by, allocated_date, status, remarks, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())");
        $stmt->bind_param("iiiiisss", $student_id, $block_id, $floor_id, $room_id, $bed_id, $admin_id, $allocated_date, $remarks);

        if ($stmt->execute()) {
            // 3. Mark newly allocated bed as occupied
            $conn->query("UPDATE beds SET status='occupied', updated_at=NOW() WHERE id=$bed_id");

            // 4. Update room status to 'full' if all its beds are occupied
            $bed_check = $conn->query("SELECT COUNT(*) as total_beds, SUM(CASE WHEN status='occupied' THEN 1 ELSE 0 END) as occupied_beds FROM beds WHERE room_id=$room_id");
            if ($bed_check && $b_row = $bed_check->fetch_assoc()) {
                if ($b_row['total_beds'] > 0 && $b_row['total_beds'] == $b_row['occupied_beds']) {
                    $conn->query("UPDATE rooms SET status='full', updated_at=NOW() WHERE id=$room_id");
                }
            }

            // 5. Insert data into student_fee_history table
            $room_price = 0;
            $r_check = $conn->query("SELECT price FROM rooms WHERE id=$room_id");
            if ($r_check && $r_data = $r_check->fetch_assoc()) {
                $room_price = intval($r_data['price'] ?? 0);
            }
            $fee_date = strtotime($allocated_date) ?: time();

            $fee_stmt = $conn->prepare("INSERT INTO student_fee_history (student_id, price, date, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            if ($fee_stmt) {
                $fee_stmt->bind_param("iii", $student_id, $room_price, $fee_date);
                $fee_stmt->execute();
            }

            $_SESSION['msg'] = "Room and Bed allocated successfully, and fee record added to student fee history.";
        } else {
            $_SESSION['error'] = "Failed to allocate room: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = "Please select all required allocation fields.";
    }
    header("Location: student.php");
    exit();
}

// Handle Deallocation / Vacate
if (isset($_GET['deallocate'])) {
    $alloc_id = intval($_GET['deallocate']);
    $alloc_res = $conn->query("SELECT id, bed_id, room_id FROM room_allocations WHERE id=$alloc_id AND status='active'");
    if ($alloc_res && $alloc = $alloc_res->fetch_assoc()) {
        $bed_id = $alloc['bed_id'];
        $room_id = $alloc['room_id'];

        $conn->query("UPDATE room_allocations SET status='vacated', vacated_date=CURDATE(), updated_at=NOW() WHERE id=$alloc_id");
        if ($bed_id > 0) {
            $conn->query("UPDATE beds SET status='available', updated_at=NOW() WHERE id=$bed_id");
        }
        if ($room_id > 0) {
            $conn->query("UPDATE rooms SET status='available', updated_at=NOW() WHERE id=$room_id AND status='full'");
        }
        $_SESSION['msg'] = "Student deallocated and bed marked available successfully.";
    } else {
        $_SESSION['error'] = "Active allocation not found.";
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

// Fetch data for cascading allocation dropdowns
$blocks_res = $conn->query("SELECT id, name FROM blocks WHERE status='active' OR status='1' ORDER BY name ASC");
$all_blocks = [];
if ($blocks_res && $blocks_res->num_rows > 0) {
    while($b = $blocks_res->fetch_assoc()) { $all_blocks[] = $b; }
}

$floors_res = $conn->query("SELECT id, block_id, name, floor_number FROM floors ORDER BY floor_number ASC, name ASC");
$all_floors = [];
if ($floors_res && $floors_res->num_rows > 0) {
    while($f = $floors_res->fetch_assoc()) { $all_floors[] = $f; }
}

$rooms_res = $conn->query("SELECT id, floor_id, room_number, room_type, capacity, price FROM rooms WHERE status != 'inactive' ORDER BY room_number ASC");
$all_rooms = [];
if ($rooms_res && $rooms_res->num_rows > 0) {
    while($r = $rooms_res->fetch_assoc()) { $all_rooms[] = $r; }
}

$beds_res = $conn->query("SELECT id, room_id, bed_number, status FROM beds ORDER BY bed_number ASC");
$all_beds = [];
if ($beds_res && $beds_res->num_rows > 0) {
    while($bd = $beds_res->fetch_assoc()) { $all_beds[] = $bd; }
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

<style>
/* Custom Action Button Styling matching reference UI */
.action-btn-group {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

.action-btn-group .btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 34px;
    border: none !important;
    border-radius: 50px !important;
    font-size: 0.85rem;
    font-weight: 600;
    color: #ffffff !important;
    transition: all 0.2s ease-in-out;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12);
    text-decoration: none !important;
    outline: none !important;
    padding: 0;
}

.action-btn-group .btn-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.22);
    color: #ffffff !important;
}

.action-btn-group .btn-action:active {
    transform: translateY(0);
}

/* Icon Only Pill Buttons (View / Delete) */
.action-btn-group .btn-action-icon {
    width: 44px;
    height: 34px;
}

.action-btn-group .btn-action-icon i {
    font-size: 15px;
    line-height: 1;
}

/* Text + Icon Pill Buttons (Allocate / Verify) */
.action-btn-group .btn-action-text {
    padding: 0 16px;
    height: 34px;
}

.action-btn-group .btn-action-text i {
    font-size: 14px;
    margin-right: 6px;
}

/* Color Variants matching design */
.action-btn-group .btn-view {
    background: #f59e0b !important; /* Amber / Orange */
    box-shadow: 0 4px 10px rgba(245, 158, 11, 0.35) !important;
}
.action-btn-group .btn-view:hover {
    background: #d97706 !important;
}

.action-btn-group .btn-allocate {
    background: #0d9488 !important; /* Teal */
    box-shadow: 0 4px 10px rgba(13, 148, 136, 0.35) !important;
}
.action-btn-group .btn-allocate:hover {
    background: #0f766e !important;
}

.action-btn-group .btn-verify {
    background: #f59e0b !important; /* Amber / Orange warning */
    box-shadow: 0 4px 10px rgba(245, 158, 11, 0.35) !important;
}
.action-btn-group .btn-verify:hover {
    background: #d97706 !important;
}

.action-btn-group .btn-delete {
    background: #ef4444 !important; /* Red */
    box-shadow: 0 4px 10px rgba(239, 68, 68, 0.35) !important;
}
.action-btn-group .btn-delete:hover {
    background: #dc2626 !important;
}
</style>


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
                                    <div class="action-btn-group">
                                        <!-- View Details Button -->
                                        <button class="btn-action btn-action-icon btn-view view-btn" title="View Details"
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

                                        <!-- Allocation / Verify First Button -->
                                        <?php if ($isVerified): ?>
                                            <button class="btn-action btn-action-text btn-allocate allocate-btn" title="Room & Bed Allocation"
                                                data-student_id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                                data-email="<?= htmlspecialchars($row['email']) ?>"
                                                data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                data-allocation_id="<?= $row['allocation_id'] ?? '' ?>"
                                                data-block_id="<?= $row['block_id'] ?? '' ?>"
                                                data-floor_id="<?= $row['floor_id'] ?? '' ?>"
                                                data-room_id="<?= $row['room_id'] ?? '' ?>"
                                                data-bed_id="<?= $row['bed_id'] ?? '' ?>"
                                                data-allocated_date="<?= $row['allocated_date'] ?? '' ?>"
                                                data-allocated_block_name="<?= htmlspecialchars($row['allocated_block_name'] ?? '') ?>"
                                                data-allocated_floor_name="<?= htmlspecialchars($row['allocated_floor_name'] ?? '') ?>"
                                                data-allocated_room_number="<?= htmlspecialchars($row['allocated_room_number'] ?? '') ?>"
                                                data-allocated_room_price="<?= htmlspecialchars($row['allocated_room_price'] ?? '') ?>"
                                                data-allocated_bed_number="<?= htmlspecialchars($row['allocated_bed_number'] ?? '') ?>"
                                                data-alloc_remarks="<?= htmlspecialchars($row['alloc_remarks'] ?? '') ?>"
                                                data-toggle="modal" data-target="#allocationModal"><i class="ti-layout-grid2"></i>Allocate</button>
                                        <?php else: ?>
                                            <button class="btn-action btn-action-text btn-verify verify-btn" title="Verification Required Before Allocation"
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
                                                data-toggle="modal" data-target="#verifyModal"><i class="ti-check-box"></i>Verify First</button>
                                        <?php endif; ?>

                                        <!-- Delete Button -->
                                        <a href="javascript:void(0);" data-url="student.php?delete=<?= $row['id'] ?>" class="btn-action btn-action-icon btn-delete delete-btn" title="Delete"><i class="ti-trash"></i></a>
                                    </div>
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
                <i class="ti-alert mr-1"></i> <strong>Note:</strong> Verifying documents will activate the student account and unlock the <strong>Room & Bed Allocation</strong> button.
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

<!-- Allocation Modal -->
<div class="modal fade" id="allocationModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="allocForm">
          <div class="modal-header">
            <h5 class="modal-title">Room & Bed Allocation</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="allocate_bed">
            <input type="hidden" name="student_id" id="alloc_student_id">

            <!-- Student Summary Banner -->
            <div class="p-3 mb-3 rounded" style="background: #eff6ff; border: 1px solid #bfdbfe;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1 font-weight-bold text-primary" id="alloc_student_name"></h6>
                        <small class="text-muted"><i class="ti-email mr-1"></i><span id="alloc_student_email"></span> | <i class="ti-mobile mr-1"></i><span id="alloc_student_phone"></span></small>
                    </div>
                    <span class="badge badge-success"><i class="ti-check mr-1"></i>Verified</span>
                </div>
            </div>

            <!-- Current Allocation Status Box -->
            <div id="alloc_current_status_box" class="mb-3"></div>

            <div class="form-group">
                <label>Block <span class="text-danger">*</span></label>
                <select name="block_id" id="alloc_block_id" class="form-control">
                    <option value="">Select Block</option>
                    <?php foreach($all_blocks as $blk): ?>
                        <option value="<?= $blk['id'] ?>"><?= htmlspecialchars($blk['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-danger" id="alloc_block_err"></small>
            </div>

            <div class="form-group">
                <label>Floor <span class="text-danger">*</span></label>
                <select name="floor_id" id="alloc_floor_id" class="form-control" disabled>
                    <option value="">Select Block first</option>
                </select>
                <small class="text-danger" id="alloc_floor_err"></small>
            </div>

            <div class="form-group">
                <label>Room <span class="text-danger">*</span></label>
                <select name="room_id" id="alloc_room_id" class="form-control" disabled>
                    <option value="">Select Floor first</option>
                </select>
                <small class="text-danger" id="alloc_room_err"></small>
                <small class="text-muted d-block mt-1" id="alloc_room_price_info"></small>
            </div>

            <div class="form-group">
                <label>Bed <span class="text-danger">*</span></label>
                <select name="bed_id" id="alloc_bed_id" class="form-control" disabled>
                    <option value="">Select Room first</option>
                </select>
                <small class="text-danger" id="alloc_bed_err"></small>
            </div>

            <div class="form-group">
                <label>Allocation Date <span class="text-danger">*</span></label>
                <input type="date" name="allocated_date" id="alloc_date" class="form-control" value="<?= date('Y-m-d') ?>">
                <small class="text-danger" id="alloc_date_err"></small>
            </div>

            <div class="form-group">
                <label>Remarks</label>
                <textarea name="remarks" id="alloc_remarks" class="form-control" rows="2" placeholder="Optional allocation remarks"></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="alloc_submit_btn">Save Allocation</button>
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
// JSON Data for Cascading Dropdowns
const floorsData = <?= json_encode($all_floors) ?>;
const roomsData = <?= json_encode($all_rooms) ?>;
const bedsData = <?= json_encode($all_beds) ?>;
const feeHistoryData = <?= json_encode($fee_history_by_student) ?>;

let currentAllocBedId = null;

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

    // Cascading Dropdown Handlers
    function populateFloors(blockId, selectedFloorId = null) {
        let $floorSelect = $('#alloc_floor_id');
        $floorSelect.html('<option value="">Select Floor</option>');
        
        if (!blockId) {
            $floorSelect.prop('disabled', true);
            return;
        }

        const filteredFloors = floorsData.filter(f => f.block_id == blockId);
        filteredFloors.forEach(f => {
            const sel = (selectedFloorId && f.id == selectedFloorId) ? 'selected' : '';
            $floorSelect.append('<option value="' + f.id + '" ' + sel + '>' + f.name + '</option>');
        });

        $floorSelect.prop('disabled', false);
    }

    function populateRooms(floorId, selectedRoomId = null) {
        let $roomSelect = $('#alloc_room_id');
        $roomSelect.html('<option value="">Select Room</option>');
        $('#alloc_room_price_info').html('');
        
        if (!floorId) {
            $roomSelect.prop('disabled', true);
            return;
        }

        const filteredRooms = roomsData.filter(r => r.floor_id == floorId);
        filteredRooms.forEach(r => {
            const sel = (selectedRoomId && r.id == selectedRoomId) ? 'selected' : '';
            const typeStr = r.room_type ? ' (' + r.room_type.charAt(0).toUpperCase() + r.room_type.slice(1) + ')' : '';
            const priceVal = r.price ? Number(r.price) : 0;
            const priceStr = priceVal > 0 ? ' - ₹' + priceVal.toLocaleString('en-IN') : '';
            $roomSelect.append('<option value="' + r.id + '" data-price="' + priceVal + '" ' + sel + '>Room ' + r.room_number + typeStr + priceStr + '</option>');
        });

        $roomSelect.prop('disabled', false);

        if (selectedRoomId) {
            var selectedOpt = $roomSelect.find('option:selected');
            var price = selectedOpt.data('price');
            if (price) {
                $('#alloc_room_price_info').html('<i class="ti-tag mr-1 text-success"></i>Room Price: <strong class="text-success">₹' + Number(price).toLocaleString('en-IN') + '</strong> (recorded in fee history)');
            }
        }
    }

    function populateBeds(roomId, selectedBedId = null) {
        let $bedSelect = $('#alloc_bed_id');
        $bedSelect.html('<option value="">Select Bed</option>');
        
        if (!roomId) {
            $bedSelect.prop('disabled', true);
            return;
        }

        const filteredBeds = bedsData.filter(b => b.room_id == roomId && (b.status === 'available' || b.id == selectedBedId));
        if (filteredBeds.length === 0) {
            $bedSelect.append('<option value="" disabled>No available beds in this room</option>');
        } else {
            filteredBeds.forEach(b => {
                const sel = (selectedBedId && b.id == selectedBedId) ? 'selected' : '';
                const statusStr = (b.id == selectedBedId) ? ' (Current Allocated)' : '';
                $bedSelect.append('<option value="' + b.id + '" ' + sel + '>Bed ' + b.bed_number + statusStr + '</option>');
            });
        }

        $bedSelect.prop('disabled', false);
    }

    $('#alloc_block_id').on('change', function() {
        populateFloors($(this).val());
        $('#alloc_room_id').html('<option value="">Select Floor first</option>').prop('disabled', true);
        $('#alloc_bed_id').html('<option value="">Select Room first</option>').prop('disabled', true);
        $('#alloc_room_price_info').html('');
    });

    $('#alloc_floor_id').on('change', function() {
        populateRooms($(this).val());
        $('#alloc_bed_id').html('<option value="">Select Room first</option>').prop('disabled', true);
    });

    $('#alloc_room_id').on('change', function() {
        populateBeds($(this).val(), currentAllocBedId);
        var selectedOpt = $(this).find('option:selected');
        var price = selectedOpt.data('price');
        if (price) {
            $('#alloc_room_price_info').html('<i class="ti-tag mr-1 text-success"></i>Room Price: <strong class="text-success">₹' + Number(price).toLocaleString('en-IN') + '</strong> (will be recorded in fee history)');
        } else {
            $('#alloc_room_price_info').html('');
        }
    });

    // Open Allocation Modal
    $(document).on('click', '.allocate-btn', function() {
        var studentId = $(this).data('student_id');
        var name = $(this).data('name');
        var email = $(this).data('email');
        var phone = $(this).data('phone');

        var allocId = $(this).data('allocation_id');
        var blockId = $(this).data('block_id');
        var floorId = $(this).data('floor_id');
        var roomId = $(this).data('room_id');
        var bedId = $(this).data('bed_id');
        var allocDate = $(this).data('allocated_date');
        var allocRemarks = $(this).data('alloc_remarks');

        var allocBlock = $(this).data('allocated_block_name');
        var allocFloor = $(this).data('allocated_floor_name');
        var allocRoom = $(this).data('allocated_room_number');
        var allocBed = $(this).data('allocated_bed_number');

        currentAllocBedId = bedId ? bedId : null;

        $('#alloc_student_id').val(studentId);
        $('#alloc_student_name').text(name);
        $('#alloc_student_email').text(email);
        $('#alloc_student_phone').text(phone);
        $('#alloc_remarks').val(allocRemarks ? allocRemarks : '');
        $('#alloc_date').val(allocDate ? allocDate : new Date().toISOString().split('T')[0]);

        // Clear error text
        $('#alloc_block_err').text('');
        $('#alloc_floor_err').text('');
        $('#alloc_room_err').text('');
        $('#alloc_bed_err').text('');
        $('#alloc_date_err').text('');

        if (allocId && allocId != '') {
            $('#alloc_current_status_box').html(
                '<div class="alert alert-info py-2 px-3 mb-0 d-flex justify-content-between align-items-center rounded">' +
                    '<div>' +
                        '<strong>Currently Allocated:</strong><br>' +
                        '<small>' + allocBlock + ' (' + allocFloor + ') &bull; Room ' + allocRoom + ' &bull; <strong>Bed ' + allocBed + '</strong>' + (allocDate ? ' &bull; Date: ' + allocDate : '') + '</small>' +
                    '</div>' +
                    '<a href="javascript:void(0);" data-url="student.php?deallocate=' + allocId + '" class="btn btn-danger btn-sm deallocate-btn ml-2" title="Vacate Bed"><i class="ti-close mr-1"></i>Vacate</a>' +
                '</div>'
            );
            $('#alloc_submit_btn').text('Update Allocation');

            $('#alloc_block_id').val(blockId);
            populateFloors(blockId, floorId);
            populateRooms(floorId, roomId);
            populateBeds(roomId, bedId);
        } else {
            $('#alloc_current_status_box').html(
                '<div class="alert alert-secondary py-2 px-3 mb-0 rounded text-muted font-italic">' +
                    '<small><i class="ti-info-alt mr-1"></i> Student currently has no active room/bed allocation.</small>' +
                '</div>'
            );
            $('#alloc_submit_btn').text('Allocate Room');

            $('#alloc_block_id').val('');
            $('#alloc_floor_id').html('<option value="">Select Block first</option>').prop('disabled', true);
            $('#alloc_room_id').html('<option value="">Select Floor first</option>').prop('disabled', true);
            $('#alloc_bed_id').html('<option value="">Select Room first</option>').prop('disabled', true);
        }
    });

    // Validate Allocation Form
    $('#allocForm').on('submit', function(e) {
        let valid = true;
        let blockId = $('#alloc_block_id').val();
        let floorId = $('#alloc_floor_id').val();
        let roomId = $('#alloc_room_id').val();
        let bedId = $('#alloc_bed_id').val();
        let allocDate = $('#alloc_date').val();

        if (!blockId) { $('#alloc_block_err').text('Block selection is mandatory'); valid = false; } else { $('#alloc_block_err').text(''); }
        if (!floorId) { $('#alloc_floor_err').text('Floor selection is mandatory'); valid = false; } else { $('#alloc_floor_err').text(''); }
        if (!roomId) { $('#alloc_room_err').text('Room selection is mandatory'); valid = false; } else { $('#alloc_room_err').text(''); }
        if (!bedId) { $('#alloc_bed_err').text('Bed selection is mandatory'); valid = false; } else { $('#alloc_bed_err').text(''); }
        if (!allocDate) { $('#alloc_date_err').text('Allocation Date is mandatory'); valid = false; } else { $('#alloc_date_err').text(''); }

        if (!valid) {
            e.preventDefault();
        }
    });

    // Deallocate Confirmation with SweetAlert
    $(document).on('click', '.deallocate-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');

        Swal.fire({
            title: 'Vacate this Bed?',
            text: "The student will be deallocated and the bed will become available.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, vacate it!',
            customClass: {
                popup: 'custom-swal-popup'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
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