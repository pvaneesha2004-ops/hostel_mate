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

$rooms_res = $conn->query("
    SELECT r.id, r.floor_id, r.room_number, r.room_type, r.capacity, r.price, r.status,
           f.name AS floor_name, f.floor_number, f.block_id,
           b.name AS block_name
    FROM rooms r
    JOIN floors f ON r.floor_id = f.id
    JOIN blocks b ON f.block_id = b.id
    WHERE r.status != 'inactive'
    ORDER BY r.room_number ASC
");
$all_rooms = [];
if ($rooms_res && $rooms_res->num_rows > 0) {
    while($r = $rooms_res->fetch_assoc()) { $all_rooms[] = $r; }
}

$beds_res = $conn->query("SELECT id, room_id, bed_number, status FROM beds ORDER BY CAST(bed_number AS UNSIGNED) ASC, bed_number ASC");
$all_beds = [];
if ($beds_res && $beds_res->num_rows > 0) {
    while($bd = $beds_res->fetch_assoc()) { $all_beds[] = $bd; }
}

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
                                    <div class="action-btn-group">
                                        <!-- View Details Button -->
                                        <button class="btn-action btn-action-icon btn-view view-btn" title="View Details"
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

                                        <!-- Allocation / Verify First Button -->
                                        <?php if ($isVerified): ?>
                                            <button class="btn-action btn-action-text btn-allocate allocate-btn" title="Room & Bed Allocation"
                                                data-student_id="<?= $row['id'] ?>"
                                                data-name="<?= htmlspecialchars($row['name']) ?>"
                                                data-email="<?= htmlspecialchars($row['email']) ?>"
                                                data-phone="<?= htmlspecialchars($row['phone']) ?>"
                                                data-pref_room_type="<?= htmlspecialchars($row['room_type'] ?? '') ?>"
                                                data-pref_language="<?= htmlspecialchars($row['language'] ?? '') ?>"
                                                data-pref_floor_type="<?= htmlspecialchars($row['floor_type'] ?? '') ?>"
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

<!-- Allocation Modal -->
<div class="modal fade" id="allocationModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="allocForm">
          <div class="modal-header">
            <h5 class="modal-title"><i class="ti-layout-grid2 mr-2 text-primary"></i>Room & Bed Allocation</h5>
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

            <!-- Student Registration Preferences Card -->
            <div class="card mb-3 border shadow-none" style="background: #f8fafc; border-radius: 10px; border-color: #e2e8f0 !important;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="font-weight-bold text-dark small"><i class="ti-bookmark-alt mr-1 text-primary"></i> Student Registration Preferences:</span>
                        <span class="badge badge-light border text-muted" style="font-size: 0.72rem;">Check & Match</span>
                    </div>
                    <div class="row">
                        <div class="col-sm-4 mb-2 mb-sm-0">
                            <small class="text-muted d-block font-weight-medium">Requested Room Type</small>
                            <span id="alloc_pref_room_type" class="font-weight-bold text-primary"></span>
                        </div>
                        <div class="col-sm-4 mb-2 mb-sm-0">
                            <small class="text-muted d-block font-weight-medium">Requested Floor</small>
                            <span id="alloc_pref_floor_type" class="font-weight-bold text-info"></span>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block font-weight-medium">Roommate Language</small>
                            <span id="alloc_pref_language" class="font-weight-bold" style="color: #6366f1;"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Smart Matching Rooms & Roommate Reference Panel -->
            <div id="alloc_smart_matches_box" class="mb-3"></div>

            <!-- Current Allocation Status Box -->
            <div id="alloc_current_status_box" class="mb-3"></div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Block <span class="text-danger">*</span></label>
                    <select name="block_id" id="alloc_block_id" class="form-control">
                        <option value="">Select Block</option>
                        <?php foreach($all_blocks as $blk): ?>
                            <option value="<?= $blk['id'] ?>"><?= htmlspecialchars($blk['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-danger" id="alloc_block_err"></small>
                </div>

                <div class="col-md-6 form-group">
                    <label>Floor <span class="text-danger">*</span></label>
                    <select name="floor_id" id="alloc_floor_id" class="form-control" disabled>
                        <option value="">Select Block first</option>
                    </select>
                    <small class="text-danger" id="alloc_floor_err"></small>
                </div>
            </div>

            <div class="form-group">
                <label>Room <span class="text-danger">*</span></label>
                <select name="room_id" id="alloc_room_id" class="form-control" disabled>
                    <option value="">Select Floor first</option>
                </select>
                <small class="text-danger" id="alloc_room_err"></small>
                <small class="text-muted d-block mt-1" id="alloc_room_price_info"></small>
                
                <!-- Selected Room Occupants / Roommates Details Box -->
                <div id="alloc_selected_room_occupants_box" class="mt-2"></div>
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
    .smart-match-card {
        transition: all 0.2s ease;
        background: #f8fafc;
        border-color: #cbd5e1 !important;
    }
    .smart-match-card:hover {
        border-color: #6366f1 !important;
        background: #f1f5f9;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.08);
    }
    .smart-match-card.is-top-match {
        border-color: #10b981 !important;
        background: #f0fdf4;
    }
    .roommate-pill {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 0.76rem;
    }
    .btn-xs {
        padding: 3px 8px;
        font-size: 0.75rem;
        line-height: 1.2;
        border-radius: 4px;
    }
</style>
<script>
// JSON Data for Cascading Dropdowns & Occupant Matching
const floorsData = <?= json_encode($all_floors) ?>;
const roomsData = <?= json_encode($all_rooms) ?>;
const bedsData = <?= json_encode($all_beds) ?>;
const feeHistoryData = <?= json_encode($fee_history_by_student) ?>;
const roomOccupantsData = <?= json_encode($room_occupants) ?>;

let currentAllocBedId = null;
let currentStudentId = null;
let currentStudentLanguage = '';

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

    function normalizeRoomType(type) {
        if (!type) return '';
        var t = type.toLowerCase().trim();
        return t === 'thriple' ? 'triple' : t;
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

    // Check Roommate Language Match
    function getLanguageMatchInfo(studentLang, roommateLang) {
        var sLang = (studentLang || '').toLowerCase().trim();
        var rLang = (roommateLang || '').toLowerCase().trim();

        if (!sLang || sLang === 'single') {
            return { isMatch: true, badge: '<span class="badge badge-light border text-muted" style="font-size: 0.72rem;">No language constraint</span>' };
        }
        if (sLang === 'all laguage set' || sLang === 'all language set' || rLang === 'all laguage set' || rLang === 'all language set') {
            return { isMatch: true, badge: '<span class="badge badge-info" style="font-size: 0.72rem;"><i class="ti-check mr-1"></i>All Languages Compatible</span>' };
        }
        if (sLang === rLang) {
            return { isMatch: true, badge: '<span class="badge badge-success" style="font-size: 0.72rem;"><i class="ti-check mr-1"></i>Language Match (' + capitalizeText(rLang) + ')</span>' };
        }
        if (!rLang) {
            return { isMatch: true, badge: '<span class="badge badge-light border text-muted" style="font-size: 0.72rem;">Roommate Language Not Set</span>' };
        }
        return { isMatch: false, badge: '<span class="badge badge-warning text-dark" style="font-size: 0.72rem;"><i class="ti-info-alt mr-1"></i>Different Language (' + capitalizeText(rLang) + ')</span>' };
    }

    // Render Selected Room Occupants & Bed Availability in Cascading Form
    function renderSelectedRoomOccupants(roomId, studentId, studentLang) {
        var $container = $('#alloc_selected_room_occupants_box');
        if (!roomId) {
            $container.html('');
            return;
        }

        var room = roomsData.find(r => r.id == roomId);
        if (!room) {
            $container.html('');
            return;
        }

        var allBedsInRoom = bedsData.filter(b => b.room_id == roomId);
        var availBeds = bedsData.filter(b => b.room_id == roomId && (b.status === 'available' || b.id == currentAllocBedId));
        var occupants = (roomOccupantsData[roomId] || []).filter(o => o.student_id != studentId);

        var roomTypeLabel = room.room_type ? capitalizeText(room.room_type) : 'Standard';
        var isSingleRoom = normalizeRoomType(room.room_type) === 'single';

        var html = '<div class="card border p-3 shadow-none mt-2" style="background: #ffffff; border-radius: 8px; border-color: #cbd5e1 !important;">';
        html += '<div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">';
        html += '<div><strong><i class="ti-home mr-1 text-primary"></i> Room ' + room.room_number + ' (' + roomTypeLabel + ')</strong>' +
                ' &bull; <small class="text-muted">' + (room.floor_name || '') + '</small></div>';
        
        if (availBeds.length > 0) {
            html += '<span class="badge badge-success"><i class="ti-check mr-1"></i>' + availBeds.length + ' of ' + allBedsInRoom.length + ' Bed(s) Available</span>';
        } else {
            html += '<span class="badge badge-danger"><i class="ti-close mr-1"></i>Full (0 Beds Available)</span>';
        }
        html += '</div>';

        // Roommate / Other Students Section
        html += '<div class="mb-1"><small class="font-weight-bold text-dark"><i class="ti-user mr-1 text-info"></i> Roommates / Other Students in Room (' + occupants.length + '):</small></div>';
        
        if (occupants.length > 0) {
            html += '<div class="list-group list-group-flush">';
            occupants.forEach(function(occ) {
                var langMatch = getLanguageMatchInfo(studentLang, occ.student_language);
                html += '<div class="list-group-item px-2 py-2 d-flex justify-content-between align-items-center bg-light rounded mb-1 border">';
                html += '<div>';
                html += '<strong class="text-dark">' + occ.student_name + '</strong>';
                html += '<small class="text-muted ml-2"><i class="ti-layout-grid2 mr-1"></i>Bed ' + occ.bed_number + '</small>';
                if (occ.student_phone) {
                    html += '<small class="text-muted ml-2"><i class="ti-mobile mr-1"></i>' + occ.student_phone + '</small>';
                }
                html += '</div>';
                html += '<div>' + langMatch.badge + '</div>';
                html += '</div>';
            });
            html += '</div>';
        } else {
            if (isSingleRoom) {
                html += '<div class="p-2 bg-light rounded text-muted small"><i class="ti-check mr-1 text-success"></i> Single Room &mdash; Private room with no roommates.</div>';
            } else {
                html += '<div class="p-2 bg-light rounded text-muted small"><i class="ti-info-alt mr-1 text-secondary"></i> Fresh Room &mdash; No other students currently allocated in this room.</div>';
            }
        }

        html += '</div>';
        $container.html(html);
    }

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
        $('#alloc_selected_room_occupants_box').html('');
        
        if (!floorId) {
            $roomSelect.prop('disabled', true);
            return;
        }

        const filteredRooms = roomsData.filter(r => r.floor_id == floorId);
        filteredRooms.forEach(r => {
            const sel = (selectedRoomId && r.id == selectedRoomId) ? 'selected' : '';
            const typeStr = r.room_type ? ' (' + capitalizeText(r.room_type) + ')' : '';
            const priceVal = r.price ? Number(r.price) : 0;
            const priceStr = priceVal > 0 ? ' - ₹' + priceVal.toLocaleString('en-IN') : '';

            // Calculate free beds
            const availBedsCount = bedsData.filter(b => b.room_id == r.id && (b.status === 'available' || b.id == currentAllocBedId)).length;
            const availStr = availBedsCount > 0 ? ' [' + availBedsCount + ' bed(s) free]' : ' [FULL]';

            $roomSelect.append('<option value="' + r.id + '" data-price="' + priceVal + '" ' + sel + '>Room ' + r.room_number + typeStr + availStr + priceStr + '</option>');
        });

        $roomSelect.prop('disabled', false);

        if (selectedRoomId) {
            var selectedOpt = $roomSelect.find('option:selected');
            var price = selectedOpt.data('price');
            if (price) {
                $('#alloc_room_price_info').html('<i class="ti-tag mr-1 text-success"></i>Room Price: <strong class="text-success">₹' + Number(price).toLocaleString('en-IN') + '</strong> (recorded in fee history)');
            }
            renderSelectedRoomOccupants(selectedRoomId, currentStudentId, currentStudentLanguage);
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
        $('#alloc_selected_room_occupants_box').html('');
    });

    $('#alloc_floor_id').on('change', function() {
        populateRooms($(this).val());
        $('#alloc_bed_id').html('<option value="">Select Room first</option>').prop('disabled', true);
        $('#alloc_selected_room_occupants_box').html('');
    });

    $('#alloc_room_id').on('change', function() {
        var rId = $(this).val();
        populateBeds(rId, currentAllocBedId);
        var selectedOpt = $(this).find('option:selected');
        var price = selectedOpt.data('price');
        if (price) {
            $('#alloc_room_price_info').html('<i class="ti-tag mr-1 text-success"></i>Room Price: <strong class="text-success">₹' + Number(price).toLocaleString('en-IN') + '</strong> (will be recorded in fee history)');
        } else {
            $('#alloc_room_price_info').html('');
        }
        renderSelectedRoomOccupants(rId, currentStudentId, currentStudentLanguage);
    });

    // Open Allocation Modal with Preference Check, Roommate Reference & Auto-Allocation
    $(document).on('click', '.allocate-btn', function() {
        var studentId = $(this).data('student_id');
        var name = $(this).data('name');
        var email = $(this).data('email');
        var phone = $(this).data('phone');

        var prefRoomType = ($(this).data('pref_room_type') || '').trim();
        var prefLanguage = ($(this).data('pref_language') || '').trim();
        var prefFloorType = ($(this).data('pref_floor_type') || '').trim();

        currentStudentId = studentId;
        currentStudentLanguage = prefLanguage;

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

        // Display Student Allocation Preferences
        $('#alloc_pref_room_type').text(prefRoomType ? capitalizeText(prefRoomType) : 'Not Specified');
        $('#alloc_pref_floor_type').text(prefFloorType ? capitalizeText(prefFloorType) : 'Not Specified');
        $('#alloc_pref_language').text(prefLanguage ? capitalizeText(prefLanguage) : (prefRoomType.toLowerCase() === 'single' ? 'Not applicable (Single Room)' : 'Not Specified'));

        // Clear error text
        $('#alloc_block_err').text('');
        $('#alloc_floor_err').text('');
        $('#alloc_room_err').text('');
        $('#alloc_bed_err').text('');
        $('#alloc_date_err').text('');
        $('#alloc_selected_room_occupants_box').html('');

        // Find and evaluate matching rooms & roommates
        var evaluatedRooms = [];
        roomsData.forEach(function(r) {
            var availBeds = bedsData.filter(b => b.room_id == r.id && (b.status === 'available' || b.id == currentAllocBedId));
            var occupants = (roomOccupantsData[r.id] || []).filter(o => o.student_id != studentId);

            // Check room type match
            var isTypeMatch = !prefRoomType || (normalizeRoomType(r.room_type) === normalizeRoomType(prefRoomType));

            // Check floor match
            var isFloorMatch = !prefFloorType;
            if (prefFloorType) {
                var pf = prefFloorType.toLowerCase();
                var rfNumber = parseInt(r.floor_number);
                var rfName = (r.floor_name || '').toLowerCase();
                if (pf === 'ground') {
                    isFloorMatch = (rfNumber === 0 || rfName.includes('ground'));
                } else if (pf === '1st') {
                    isFloorMatch = (rfNumber === 1 || rfName.includes('1') || rfName.includes('first'));
                } else if (pf === '2nd') {
                    isFloorMatch = (rfNumber === 2 || rfName.includes('2') || rfName.includes('second'));
                } else {
                    isFloorMatch = rfName.includes(pf);
                }
            }

            // Check language compatibility with current roommates
            var hasLangMatch = false;
            if (occupants.length > 0) {
                occupants.forEach(function(occ) {
                    if (getLanguageMatchInfo(prefLanguage, occ.student_language).isMatch) {
                        hasLangMatch = true;
                    }
                });
            } else {
                hasLangMatch = true; // fresh room is compatible
            }

            evaluatedRooms.push({
                room: r,
                availBeds: availBeds,
                occupants: occupants,
                isTypeMatch: isTypeMatch,
                isFloorMatch: isFloorMatch,
                hasLangMatch: hasLangMatch,
                isPerfectMatch: isTypeMatch && isFloorMatch && availBeds.length > 0
            });
        });

        // Filter recommendations
        var recommendedRooms = evaluatedRooms.filter(item => item.isPerfectMatch);
        if (recommendedRooms.length === 0) {
            // Fallback: match room_type and available beds
            recommendedRooms = evaluatedRooms.filter(item => item.isTypeMatch && item.availBeds.length > 0);
        }
        if (recommendedRooms.length === 0) {
            // Fallback: any room with available beds
            recommendedRooms = evaluatedRooms.filter(item => item.availBeds.length > 0);
        }

        // Render Smart Recommendations Box
        if (recommendedRooms.length > 0) {
            var recHtml = '<div class="card border mb-3 shadow-none" style="border-radius: 10px; background: #ffffff;">';
            recHtml += '<div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">';
            recHtml += '<span class="font-weight-bold text-dark small"><i class="ti-light-bulb mr-1 text-warning"></i> Smart Room Recommendations & Roommate References:</span>';
            recHtml += '<span class="badge badge-primary small">' + recommendedRooms.length + ' Available Match(es)</span>';
            recHtml += '</div>';
            recHtml += '<div class="card-body p-2" style="max-height: 240px; overflow-y: auto;">';

            recommendedRooms.forEach(function(item) {
                var r = item.room;
                var availBeds = item.availBeds;
                var occupants = item.occupants;

                recHtml += '<div class="p-2 mb-2 rounded border smart-match-card ' + (item.isPerfectMatch ? 'is-top-match' : '') + '">';
                recHtml += '<div class="d-flex justify-content-between align-items-center">';
                recHtml += '<div>';
                recHtml += '<strong class="text-primary" style="font-size: 0.95rem;">Room ' + r.room_number + '</strong> ';
                recHtml += '<span class="badge badge-light border text-dark font-weight-normal">' + capitalizeText(r.room_type) + '</span> ';
                recHtml += '<small class="text-muted font-weight-medium">' + (r.block_name || '') + ' &bull; ' + (r.floor_name || '') + '</small> ';
                recHtml += '<span class="badge badge-success ml-1"><i class="ti-check mr-1"></i>' + availBeds.length + ' Bed(s) Available</span>';
                recHtml += '</div>';

                // Auto-fill button
                var firstBedId = availBeds[0] ? availBeds[0].id : '';
                recHtml += '<button type="button" class="btn btn-xs btn-outline-success font-weight-bold btn-auto-fill-room" ' +
                           'data-block="' + r.block_id + '" data-floor="' + r.floor_id + '" data-room="' + r.id + '" data-bed="' + firstBedId + '">' +
                           '<i class="ti-arrow-down mr-1"></i>Select & Auto-fill</button>';
                recHtml += '</div>';

                // Roommate Reference info
                if (occupants.length > 0) {
                    recHtml += '<div class="mt-1 pt-1 border-top small">';
                    recHtml += '<span class="text-muted mr-1"><i class="ti-user mr-1 text-info"></i><strong>Current Roommate(s):</strong></span> ';
                    occupants.forEach(function(occ) {
                        var matchInfo = getLanguageMatchInfo(prefLanguage, occ.student_language);
                        recHtml += '<span class="roommate-pill mr-2"><strong>' + occ.student_name + '</strong> (Bed ' + occ.bed_number + ') &bull; Lang: ' + (occ.student_language ? capitalizeText(occ.student_language) : 'N/A') + ' ' + matchInfo.badge + '</span> ';
                    });
                    recHtml += '</div>';
                } else {
                    recHtml += '<div class="mt-1 text-muted small"><i class="ti-home mr-1"></i>No current occupants (Empty Room).</div>';
                }

                recHtml += '</div>';
            });

            recHtml += '</div></div>';
            $('#alloc_smart_matches_box').html(recHtml);
        } else {
            $('#alloc_smart_matches_box').html(
                '<div class="alert alert-warning py-2 px-3 mb-3 rounded small">' +
                '<i class="ti-alert mr-1"></i> <strong>No rooms with available beds found matching preferences.</strong> Please create/free rooms or manually choose a room below.' +
                '</div>'
            );
        }

        // Auto-fill and load logic
        if (allocId && allocId != '') {
            // Already allocated: show status
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
            renderSelectedRoomOccupants(roomId, studentId, prefLanguage);
        } else {
            $('#alloc_submit_btn').text('Allocate Room');

            // Auto-fill with top matching room if available
            var topMatch = (recommendedRooms.length > 0) ? recommendedRooms[0] : null;

            if (topMatch && topMatch.availBeds.length > 0) {
                var r = topMatch.room;
                var b = topMatch.availBeds[0];

                $('#alloc_current_status_box').html(
                    '<div class="alert alert-success py-2 px-3 mb-2 rounded small">' +
                        '<i class="ti-check mr-1 text-success"></i> <strong>Auto-filled based on preferences:</strong> ' +
                        'Room <strong>' + r.room_number + '</strong> (' + capitalizeText(r.room_type) + ', ' + (r.floor_name || '') + ') &bull; Bed <strong>' + b.bed_number + '</strong> is available!' +
                    '</div>'
                );

                $('#alloc_block_id').val(r.block_id);
                populateFloors(r.block_id, r.floor_id);
                populateRooms(r.floor_id, r.id);
                populateBeds(r.id, b.id);
                renderSelectedRoomOccupants(r.id, studentId, prefLanguage);
            } else {
                $('#alloc_current_status_box').html(
                    '<div class="alert alert-secondary py-2 px-3 mb-0 rounded text-muted font-italic">' +
                        '<small><i class="ti-info-alt mr-1"></i> Student currently has no active room/bed allocation.</small>' +
                    '</div>'
                );
                $('#alloc_block_id').val('');
                $('#alloc_floor_id').html('<option value="">Select Block first</option>').prop('disabled', true);
                $('#alloc_room_id').html('<option value="">Select Floor first</option>').prop('disabled', true);
                $('#alloc_bed_id').html('<option value="">Select Room first</option>').prop('disabled', true);
            }
        }
    });

    // Quick Auto-fill button click from smart match card
    $(document).on('click', '.btn-auto-fill-room', function() {
        var bId = $(this).data('block');
        var fId = $(this).data('floor');
        var rId = $(this).data('room');
        var bdId = $(this).data('bed');

        $('#alloc_block_id').val(bId);
        populateFloors(bId, fId);
        populateRooms(fId, rId);
        populateBeds(rId, bdId);
        renderSelectedRoomOccupants(rId, currentStudentId, currentStudentLanguage);

        // Highlight inputs briefly
        $('#alloc_room_id, #alloc_bed_id').addClass('border-success');
        setTimeout(function() {
            $('#alloc_room_id, #alloc_bed_id').removeClass('border-success');
        }, 1500);
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