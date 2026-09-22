<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if student not logged in
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$student_id = intval($_SESSION['student_id']);

// Handle Profile Image Upload / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile_image') {
    $target_dir = "../student_profile/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['profile_image']['tmp_name'];
        $file_name = $_FILES['profile_image']['name'];
        $file_size = $_FILES['profile_image']['size'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!in_array($file_ext, $allowed_exts)) {
            $_SESSION['error'] = "Invalid file format. Only JPG, JPEG, PNG, WEBP, and GIF images are allowed.";
        } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
            $_SESSION['error'] = "File size exceeds 5MB limit. Please choose a smaller photo.";
        } else {
            // Generate unique filename
            $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_ext;
            $dest_path = $target_dir . $new_filename;

            if (move_uploaded_file($file_tmp, $dest_path)) {
                // Fetch previous profile image to delete old file
                $prev_stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
                $prev_stmt->bind_param("i", $student_id);
                $prev_stmt->execute();
                $prev_res = $prev_stmt->get_result();
                if ($prev_res && $prev_row = $prev_res->fetch_assoc()) {
                    $old_file = $prev_row['profile_image'];
                    if (!empty($old_file) && file_exists($target_dir . $old_file) && $old_file !== $new_filename) {
                        @unlink($target_dir . $old_file);
                    }
                }
                $prev_stmt->close();

                // Update database
                $up_stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                $up_stmt->bind_param("si", $new_filename, $student_id);
                if ($up_stmt->execute()) {
                    $_SESSION['student_image'] = '../student_profile/' . $new_filename;
                    $_SESSION['msg'] = "Profile photo updated successfully!";
                } else {
                    $_SESSION['error'] = "Failed to update profile photo in database: " . $conn->error;
                }
                $up_stmt->close();
            } else {
                $_SESSION['error'] = "Failed to upload image file to server.";
            }
        }
    } else {
        $_SESSION['error'] = "Please select a valid image file to upload.";
    }

    header("Location: profile.php");
    exit();
}

// 1. Fetch User Profile Data from `users`
$stmt = $conn->prepare("SELECT id, role, name, email, phone, profile_image, status, last_login, created_at FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$user_res = $stmt->get_result();
$student = $user_res ? $user_res->fetch_assoc() : null;

if (!$student) {
    die("Student record not found.");
}

// Update session values if fresh
$_SESSION['student_name'] = $student['name'];
$_SESSION['student_email'] = $student['email'];
$_SESSION['student_phone'] = $student['phone'];
if (!empty($student['profile_image'])) {
    $_SESSION['student_image'] = '../student_profile/' . $student['profile_image'];
}

// 2. Fetch Guardian Data from `student_guardians`
$g_stmt = $conn->prepare("SELECT id, student_id, name, relationship, phone, email, address FROM student_guardians WHERE student_id = ? LIMIT 1");
$g_stmt->bind_param("i", $student_id);
$g_stmt->execute();
$g_res = $g_stmt->get_result();
$guardian = $g_res ? $g_res->fetch_assoc() : null;

// 3. Fetch Document & Verification Data from `student_documents`
$d_stmt = $conn->prepare("
    SELECT d.*, u.name AS verifier_name 
    FROM student_documents d 
    LEFT JOIN users u ON d.verified_by = u.id 
    WHERE d.student_id = ? 
    ORDER BY d.id DESC 
    LIMIT 1
");
$d_stmt->bind_param("i", $student_id);
$d_stmt->execute();
$d_res = $d_stmt->get_result();
$document = $d_res ? $d_res->fetch_assoc() : null;

// 4. Fetch Room Allocation Data from `room_allocations`
$ra_stmt = $conn->prepare("
    SELECT ra.*, 
           b.name AS block_name, 
           f.name AS floor_name, 
           r.room_number, 
           r.room_type, 
           r.price AS room_price, 
           bd.bed_number,
           u.name AS allocator_name
    FROM room_allocations ra
    LEFT JOIN blocks b ON ra.block_id = b.id
    LEFT JOIN floors f ON ra.floor_id = f.id
    LEFT JOIN rooms r ON ra.room_id = r.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN users u ON ra.allocated_by = u.id
    WHERE ra.student_id = ?
    ORDER BY (ra.status = 'active') DESC, ra.id DESC
");
$ra_stmt->bind_param("i", $student_id);
$ra_stmt->execute();
$ra_res = $ra_stmt->get_result();
$allocations = [];
$active_allocation = null;
if ($ra_res) {
    while ($ra = $ra_res->fetch_assoc()) {
        $allocations[] = $ra;
        if ($ra['status'] === 'active' && !$active_allocation) {
            $active_allocation = $ra;
        }
    }
}

// 5. Fetch Fee History from `student_fee_history`
$fh_stmt = $conn->prepare("SELECT id, student_id, price, date, created_at, updated_at FROM student_fee_history WHERE student_id = ? ORDER BY date DESC, id DESC");
$fh_stmt->bind_param("i", $student_id);
$fh_stmt->execute();
$fh_res = $fh_stmt->get_result();
$fee_history = [];
$total_fees_paid = 0;
if ($fh_res) {
    while ($fh = $fh_res->fetch_assoc()) {
        $fee_history[] = $fh;
        $total_fees_paid += floatval($fh['price'] ?? 0);
    }
}

// Profile image handling
$profile_img_src = (!empty($student['profile_image']) && file_exists('../student_profile/' . $student['profile_image'])) 
    ? '../student_profile/' . htmlspecialchars($student['profile_image']) 
    : '../img/fav-student.png';

$verification_status = strtolower($document['verification'] ?? 'pending');
$account_status = strtolower($student['status'] ?? 'active');

ob_start();
?>

<style>
  /* =========================================================
     STUDENT PROFILE CUSTOM STYLES
     ========================================================= */
  .profile-hero-card {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    border-radius: 20px;
    color: #ffffff;
    padding: 2rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(99, 102, 241, 0.25);
    margin-bottom: 2rem;
  }

  .profile-hero-card::after {
    content: "\f007";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 20px;
    bottom: -30px;
    font-size: 150px;
    color: rgba(255, 255, 255, 0.06);
    pointer-events: none;
  }

  .profile-avatar-wrapper {
    position: relative;
    width: 105px;
    height: 105px;
    border-radius: 50%;
    border: 3.5px solid rgba(255, 255, 255, 0.95);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    background: #ffffff;
    flex-shrink: 0;
  }

  .profile-avatar-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
  }

  .avatar-edit-badge {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 32px;
    background: rgba(15, 23, 42, 0.75);
    color: #ffffff;
    border: none;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.25s ease;
    backdrop-filter: blur(4px);
    border-bottom-left-radius: 50px;
    border-bottom-right-radius: 50px;
    opacity: 0.9;
  }

  .avatar-edit-badge:hover {
    background: rgba(99, 102, 241, 0.95);
    color: #ffffff;
    opacity: 1;
  }

  .status-indicator-dot {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid #ffffff;
    z-index: 2;
  }

  .hero-quick-badge {
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    margin-right: 0.5rem;
    margin-bottom: 0.5rem;
  }

  .stat-card-widget {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
  }

  .stat-card-widget:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.08);
  }

  .section-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
    margin-bottom: 1.75rem;
    overflow: hidden;
  }

  .section-card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 1.15rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .section-card-header h5 {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 0;
    display: flex;
    align-items: center;
  }

  .section-card-body {
    padding: 1.5rem;
  }

  .info-group-box {
    background: #f8fafc;
    border: 1px solid #f1f5f9;
    border-radius: 12px;
    padding: 0.9rem 1.1rem;
    margin-bottom: 1rem;
    transition: all 0.2s ease;
  }

  .info-group-box:hover {
    background: #ffffff;
    border-color: #cbd5e1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
  }

  .info-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 0.25rem;
  }

  .info-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: #0f172a;
    word-break: break-word;
  }

  .allocation-highlight-box {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1.5px solid #86efac;
    border-radius: 14px;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
  }

  .allocation-pill {
    background: #ffffff;
    border: 1px solid #bbf7d0;
    padding: 0.4rem 0.85rem;
    border-radius: 10px;
    font-size: 0.82rem;
    font-weight: 700;
    color: #15803d;
    display: inline-flex;
    align-items: center;
  }

  .doc-preview-container {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    padding: 1.25rem;
    text-align: center;
  }

  /* Image Upload Modal Styles */
  .upload-drop-zone {
    border: 2px dashed #6366f1;
    background: #f5f3ff;
    border-radius: 16px;
    padding: 2rem 1.5rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;
  }

  .upload-drop-zone:hover {
    background: #ede9fe;
    border-color: #4f46e5;
  }

  .image-preview-circle {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid #6366f1;
    object-fit: cover;
    margin: 0 auto 1rem auto;
    display: block;
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.25);
  }
</style>

<!-- Alert Feedback -->
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: rgba(16, 185, 129, 0.15); color: #065f46;">
    <i class="fa-solid fa-circle-check mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['msg']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); color: #991b1b;">
    <i class="fa-solid fa-triangle-exclamation mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Profile Hero Banner -->
<div class="profile-hero-card">
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
    <div class="d-flex align-items-center mb-3 mb-md-0">
      
      <!-- Avatar with edit button trigger -->
      <div class="profile-avatar-wrapper mr-4">
        <img src="<?= $profile_img_src ?>" alt="<?= htmlspecialchars($student['name']) ?>" id="currentAvatarImg" onerror="this.src='../img/fav-student.png';">
        <button type="button" class="avatar-edit-badge" data-toggle="modal" data-target="#updateProfileImageModal" title="Click to Change Photo">
          <i class="fa-solid fa-camera mr-1"></i> Edit
        </button>
        <span class="status-indicator-dot <?= ($account_status === 'active') ? 'bg-success' : 'bg-danger' ?>" title="Account: <?= ucfirst($account_status) ?>"></span>
      </div>

      <div>
        <div class="d-flex align-items-center flex-wrap">
          <h2 class="font-weight-bold text-white mb-1 mr-3"><?= htmlspecialchars($student['name']) ?></h2>
          <span class="badge badge-light px-2 py-1 font-weight-bold mr-2" style="color: #4338ca; font-size: 0.75rem;">
            <i class="fa-solid fa-graduation-cap mr-1"></i> Student
          </span>
          <?php if ($account_status === 'active'): ?>
            <span class="badge badge-success px-2 py-1 font-weight-bold" style="font-size: 0.75rem;">
              <i class="fa-solid fa-circle-check mr-1"></i> Active
            </span>
          <?php else: ?>
            <span class="badge badge-danger px-2 py-1 font-weight-bold" style="font-size: 0.75rem;">
              <i class="fa-solid fa-circle-xmark mr-1"></i> Inactive
            </span>
          <?php endif; ?>
        </div>
        
        <p class="text-white-50 mb-2 font-weight-500" style="font-size: 0.9rem;">
          <i class="fa-solid fa-id-badge mr-1"></i> Student ID: #<?= str_pad($student['id'], 5, '0', STR_PAD_LEFT) ?>
        </p>

        <div class="d-flex flex-wrap mt-1">
          <span class="hero-quick-badge">
            <i class="fa-regular fa-envelope mr-1.5"></i> <?= htmlspecialchars($student['email']) ?>
          </span>
          <span class="hero-quick-badge">
            <i class="fa-solid fa-phone mr-1.5"></i> <?= htmlspecialchars($student['phone']) ?>
          </span>
          <?php if ($active_allocation): ?>
            <span class="hero-quick-badge" style="background: rgba(16, 185, 129, 0.25); border-color: rgba(16, 185, 129, 0.4);">
              <i class="fa-solid fa-door-open mr-1.5 text-success"></i> <?= htmlspecialchars($active_allocation['block_name']) ?> | Room <?= htmlspecialchars($active_allocation['room_number']) ?> (Bed <?= htmlspecialchars($active_allocation['bed_number']) ?>)
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Right Top Action Buttons -->
    <div class="text-md-right mt-3 mt-md-0">
      <button type="button" class="btn btn-light font-weight-bold shadow-sm rounded-12 px-3 py-2 mb-2" data-toggle="modal" data-target="#updateProfileImageModal" style="color: #4338ca;">
        <i class="fa-solid fa-camera mr-1 text-primary"></i> Change Photo
      </button>
      <div class="d-flex flex-column align-items-md-end text-white-50" style="font-size: 0.82rem;">
        <span><i class="fa-regular fa-calendar mr-1"></i> Member Since: <strong class="text-white"><?= date('d M, Y', strtotime($student['created_at'])) ?></strong></span>
        <span><i class="fa-regular fa-clock mr-1"></i> Last Login: <strong class="text-white"><?= !empty($student['last_login']) ? date('d M Y, h:i A', strtotime($student['last_login'])) : 'First Session' ?></strong></span>
      </div>
    </div>
  </div>
</div>

<!-- Quick Overview KPI Cards -->
<div class="row mb-4">
  <!-- 1. KYC Verification Status -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted small font-weight-bold text-uppercase">KYC Verification</span>
          <h4 class="font-weight-bold text-dark mt-1 mb-0">
            <?php if ($verification_status === 'verified'): ?>
              <span class="text-success"><i class="fa-solid fa-circle-check mr-1"></i> Verified</span>
            <?php elseif ($verification_status === 'rejected'): ?>
              <span class="text-danger"><i class="fa-solid fa-circle-xmark mr-1"></i> Rejected</span>
            <?php else: ?>
              <span class="text-warning"><i class="fa-solid fa-clock mr-1"></i> Pending</span>
            <?php endif; ?>
          </h4>
          <small class="text-muted font-weight-500">
            <?= !empty($document['document_type']) ? htmlspecialchars($document['document_type']) : 'Identity Proof' ?>
          </small>
        </div>
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(99, 102, 241, 0.1); color: #6366f1; font-size: 1.25rem;">
          <i class="fa-solid fa-id-card"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Room & Bed Status -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted small font-weight-bold text-uppercase">Hostel Room</span>
          <h4 class="font-weight-bold text-dark mt-1 mb-0">
            <?php if ($active_allocation): ?>
              <span class="text-primary">Room <?= htmlspecialchars($active_allocation['room_number']) ?></span>
            <?php else: ?>
              <span class="text-muted font-italic" style="font-size: 1.1rem;">Not Allocated</span>
            <?php endif; ?>
          </h4>
          <small class="text-muted font-weight-500">
            <?= $active_allocation ? htmlspecialchars($active_allocation['block_name']) . ' (Bed ' . htmlspecialchars($active_allocation['bed_number']) . ')' : 'Awaiting Allocation' ?>
          </small>
        </div>
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 1.25rem;">
          <i class="fa-solid fa-bed"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Guardian / Emergency Contact -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted small font-weight-bold text-uppercase">Emergency Guardian</span>
          <h4 class="font-weight-bold text-dark mt-1 mb-0" style="font-size: 1.1rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            <?= !empty($guardian['name']) ? htmlspecialchars($guardian['name']) : 'Not Registered' ?>
          </h4>
          <small class="text-muted font-weight-500">
            <?= !empty($guardian['relationship']) ? htmlspecialchars($guardian['relationship']) : 'Primary Contact' ?>
          </small>
        </div>
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(245, 158, 11, 0.1); color: #f59e0b; font-size: 1.25rem;">
          <i class="fa-solid fa-people-roof"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- 4. Total Fees Paid -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted small font-weight-bold text-uppercase">Total Fees Paid</span>
          <h4 class="font-weight-bold text-dark mt-1 mb-0" style="color: #4338ca !important;">
            ₹<?= number_format($total_fees_paid, 2) ?>
          </h4>
          <small class="text-muted font-weight-500">
            <?= count($fee_history) ?> Transaction<?= count($fee_history) === 1 ? '' : 's' ?>
          </small>
        </div>
        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(79, 70, 229, 0.1); color: #4f46e5; font-size: 1.25rem;">
          <i class="fa-solid fa-receipt"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Main Details Layout Grid -->
<div class="row">
  
  <!-- Left Column: Personal, Guardian & KYC Info -->
  <div class="col-lg-6">
    
    <!-- 1. Personal & Account Details (users table) -->
    <div class="section-card">
      <div class="section-card-header">
        <h5><i class="fa-solid fa-user-circle text-primary mr-2"></i>Personal & Account Information</h5>
        <span class="badge badge-light border text-muted">ID: #<?= $student['id'] ?></span>
      </div>
      <div class="section-card-body">
        <div class="row">
          <div class="col-sm-6">
            <div class="info-group-box">
              <div class="info-label">Full Name</div>
              <div class="info-value"><?= htmlspecialchars($student['name']) ?></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="info-group-box">
              <div class="info-label">Email Address</div>
              <div class="info-value"><a href="mailto:<?= htmlspecialchars($student['email']) ?>" class="text-dark"><?= htmlspecialchars($student['email']) ?></a></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="info-group-box">
              <div class="info-label">Contact Phone</div>
              <div class="info-value"><a href="tel:<?= htmlspecialchars($student['phone']) ?>" class="text-dark"><?= htmlspecialchars($student['phone']) ?></a></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="info-group-box">
              <div class="info-label">Role Designation</div>
              <div class="info-value text-capitalize"><?= htmlspecialchars($student['role']) ?></div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="info-group-box">
              <div class="info-label">Account Status</div>
              <div class="info-value">
                <?php if ($account_status === 'active'): ?>
                  <span class="badge badge-success px-2 py-1"><i class="fa-solid fa-check mr-1"></i> Active</span>
                <?php else: ?>
                  <span class="badge badge-danger px-2 py-1"><i class="fa-solid fa-ban mr-1"></i> Inactive</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="info-group-box">
              <div class="info-label">Registration Date</div>
              <div class="info-value"><?= date('d M, Y (h:i A)', strtotime($student['created_at'])) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 2. Guardian & Parent Details (student_guardians table) -->
    <div class="section-card">
      <div class="section-card-header">
        <h5><i class="fa-solid fa-user-shield text-warning mr-2"></i>Guardian & Emergency Contact</h5>
        <?php if ($guardian): ?>
          <span class="badge badge-warning text-dark font-weight-bold px-2 py-1"><?= htmlspecialchars($guardian['relationship'] ?? 'Guardian') ?></span>
        <?php endif; ?>
      </div>
      <div class="section-card-body">
        <?php if ($guardian): ?>
          <div class="row">
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Guardian Name</div>
                <div class="info-value"><?= htmlspecialchars($guardian['name']) ?></div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Relationship</div>
                <div class="info-value"><?= htmlspecialchars($guardian['relationship']) ?></div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Phone Number</div>
                <div class="info-value">
                  <a href="tel:<?= htmlspecialchars($guardian['phone']) ?>" class="text-primary font-weight-bold">
                    <i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($guardian['phone']) ?>
                  </a>
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Email Address</div>
                <div class="info-value">
                  <?php if (!empty($guardian['email'])): ?>
                    <a href="mailto:<?= htmlspecialchars($guardian['email']) ?>" class="text-dark">
                      <?= htmlspecialchars($guardian['email']) ?>
                    </a>
                  <?php else: ?>
                    <span class="text-muted font-italic">Not Provided</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="info-group-box mb-0">
                <div class="info-label">Residential / Permanent Address</div>
                <div class="info-value">
                  <?= !empty($guardian['address']) ? nl2br(htmlspecialchars($guardian['address'])) : '<span class="text-muted font-italic">No physical address recorded</span>' ?>
                </div>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="text-center py-4">
            <div class="text-muted mb-2"><i class="fa-solid fa-triangle-exclamation text-warning" style="font-size: 2rem;"></i></div>
            <h6 class="font-weight-bold text-dark mb-1">No Guardian Information Available</h6>
            <p class="text-muted small mb-0">Please contact the hostel warden or administration to update your emergency contact details.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 3. Document & KYC Verification Details (student_documents table) -->
    <div class="section-card">
      <div class="section-card-header">
        <h5><i class="fa-solid fa-file-shield text-info mr-2"></i>KYC Document & Verification</h5>
        <?php if ($document): ?>
          <?php if ($verification_status === 'verified'): ?>
            <span class="badge badge-success px-2 py-1"><i class="fa-solid fa-circle-check mr-1"></i> Verified</span>
          <?php elseif ($verification_status === 'rejected'): ?>
            <span class="badge badge-danger px-2 py-1"><i class="fa-solid fa-circle-xmark mr-1"></i> Rejected</span>
          <?php else: ?>
            <span class="badge badge-warning text-dark px-2 py-1"><i class="fa-solid fa-clock mr-1"></i> Pending Verification</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="section-card-body">
        <?php if ($document): ?>
          <div class="row">
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Document Type</div>
                <div class="info-value font-weight-bold"><?= htmlspecialchars($document['document_type'] ?? 'College ID / Govt ID') ?></div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Document Number / ID</div>
                <div class="info-value"><?= !empty($document['document_number']) ? htmlspecialchars($document['document_number']) : 'N/A' ?></div>
              </div>
            </div>
            
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Verified By</div>
                <div class="info-value">
                  <?= !empty($document['verifier_name']) ? htmlspecialchars($document['verifier_name']) : (!empty($document['verified_by']) ? 'Admin / Warden' : '<span class="text-muted font-italic">Pending Evaluation</span>') ?>
                </div>
              </div>
            </div>

            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Verification Date</div>
                <div class="info-value">
                  <?= !empty($document['verified_at']) ? date('d M, Y (h:i A)', strtotime($document['verified_at'])) : '<span class="text-muted font-italic">Not Verified Yet</span>' ?>
                </div>
              </div>
            </div>

            <?php if (!empty($document['remarks'])): ?>
              <div class="col-12">
                <div class="info-group-box">
                  <div class="info-label">Verification Remarks / Notes</div>
                  <div class="info-value text-muted font-italic"><?= nl2br(htmlspecialchars($document['remarks'])) ?></div>
                </div>
              </div>
            <?php endif; ?>

            <!-- Document File Attachment -->
            <div class="col-12">
              <div class="doc-preview-container">
                <?php if (!empty($document['file_path']) && file_exists('../student_docs/' . $document['file_path'])): ?>
                  <?php 
                    $ext = strtolower(pathinfo($document['file_path'], PATHINFO_EXTENSION));
                    $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                  ?>
                  <div class="d-flex align-items-center justify-content-between flex-wrap">
                    <div class="d-flex align-items-center text-left mb-2 mb-sm-0">
                      <span class="rounded p-2 mr-3 <?= $is_image ? 'bg-primary text-white' : 'bg-danger text-white' ?>" style="font-size: 1.5rem;">
                        <i class="<?= $is_image ? 'fa-regular fa-image' : 'fa-regular fa-file-pdf' ?>"></i>
                      </span>
                      <div>
                        <div class="font-weight-bold text-dark"><?= htmlspecialchars($document['file_path']) ?></div>
                        <small class="text-muted text-uppercase"><?= $ext ?> File &bull; Attached KYC Document</small>
                      </div>
                    </div>
                    <div>
                      <a href="../student_docs/<?= htmlspecialchars($document['file_path']) ?>" target="_blank" class="btn btn-primary btn-sm px-3 py-2 font-weight-600 shadow-sm">
                        <i class="fa-solid fa-up-right-from-square mr-1"></i> View Document
                      </a>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="text-muted py-2">
                    <i class="fa-solid fa-file-circle-xmark mr-1"></i> No digital document attachment uploaded.
                  </div>
                <?php endif; ?>
              </div>
            </div>

          </div>
        <?php else: ?>
          <div class="text-center py-4">
            <div class="text-muted mb-2"><i class="fa-solid fa-file-circle-question text-info" style="font-size: 2rem;"></i></div>
            <h6 class="font-weight-bold text-dark mb-1">No KYC Document Submitted</h6>
            <p class="text-muted small mb-0">Please submit your identification document to the hostel authority for KYC verification.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Right Column: Room Allocations & Fee History -->
  <div class="col-lg-6">
    
    <!-- 4. Room & Hostel Allocation Details (room_allocations table) -->
    <div class="section-card">
      <div class="section-card-header">
        <h5><i class="fa-solid fa-hotel text-success mr-2"></i>Hostel Room & Bed Allocation</h5>
        <?php if ($active_allocation): ?>
          <span class="badge badge-success px-2 py-1"><i class="fa-solid fa-circle-check mr-1"></i> Active Resident</span>
        <?php else: ?>
          <span class="badge badge-secondary px-2 py-1">Not Allocated</span>
        <?php endif; ?>
      </div>
      <div class="section-card-body">
        <?php if ($active_allocation): ?>
          
          <!-- Active Room Banner -->
          <div class="allocation-highlight-box">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <span class="text-success font-weight-bold text-uppercase small"><i class="fa-solid fa-building mr-1"></i> Current Allocation</span>
                <h4 class="font-weight-bold text-dark mb-0 mt-1">
                  <?= htmlspecialchars($active_allocation['block_name']) ?> &bull; Room <?= htmlspecialchars($active_allocation['room_number']) ?>
                </h4>
              </div>
              <span class="allocation-pill">
                <i class="fa-solid fa-bed mr-1.5 text-success"></i> Bed #<?= htmlspecialchars($active_allocation['bed_number']) ?>
              </span>
            </div>
            
            <div class="d-flex flex-wrap text-muted small mt-2">
              <span class="mr-3"><i class="fa-solid fa-layer-group mr-1 text-primary"></i>Floor: <strong class="text-dark"><?= htmlspecialchars($active_allocation['floor_name']) ?></strong></span>
              <span class="mr-3"><i class="fa-solid fa-tag mr-1 text-info"></i>Type: <strong class="text-dark"><?= htmlspecialchars($active_allocation['room_type'] ?? 'Standard') ?></strong></span>
              <?php if (!empty($active_allocation['room_price'])): ?>
                <span><i class="fa-solid fa-indian-rupee-sign mr-1 text-success"></i>Fee: <strong class="text-dark">₹<?= number_format($active_allocation['room_price'], 2) ?>/mo</strong></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="row">
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Allocation Date</div>
                <div class="info-value"><?= !empty($active_allocation['allocated_date']) ? date('d M, Y', strtotime($active_allocation['allocated_date'])) : 'N/A' ?></div>
              </div>
            </div>
            <div class="col-sm-6">
              <div class="info-group-box">
                <div class="info-label">Allocated By</div>
                <div class="info-value"><?= !empty($active_allocation['allocator_name']) ? htmlspecialchars($active_allocation['allocator_name']) : 'Hostel Authority' ?></div>
              </div>
            </div>
            <?php if (!empty($active_allocation['remarks'])): ?>
              <div class="col-12">
                <div class="info-group-box mb-0">
                  <div class="info-label">Allocation Remarks</div>
                  <div class="info-value text-muted font-italic"><?= nl2br(htmlspecialchars($active_allocation['remarks'])) ?></div>
                </div>
              </div>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <div class="text-center py-4">
            <div class="text-muted mb-2"><i class="fa-solid fa-bed text-muted" style="font-size: 2.5rem; opacity: 0.5;"></i></div>
            <h6 class="font-weight-bold text-dark mb-1">Room Not Allocated Yet</h6>
            <p class="text-muted small mb-0" style="max-width: 380px; margin: 0 auto;">
              Your KYC verification and room allocation are currently being processed by the hostel administration. You will be notified once allocated.
            </p>
          </div>
        <?php endif; ?>

        <!-- Historical Allocations List if more than 1 -->
        <?php if (count($allocations) > 1): ?>
          <div class="mt-4 pt-3 border-top">
            <h6 class="font-weight-bold text-dark mb-2" style="font-size: 0.88rem;">Previous Allocations History</h6>
            <div class="list-group list-group-flush">
              <?php foreach ($allocations as $idx => $past_alloc): ?>
                <?php if ($past_alloc['id'] == ($active_allocation['id'] ?? 0)) continue; ?>
                <div class="list-group-item px-0 py-2 border-0 d-flex justify-content-between align-items-center">
                  <div>
                    <span class="font-weight-bold text-dark"><?= htmlspecialchars($past_alloc['block_name']) ?> - Room <?= htmlspecialchars($past_alloc['room_number']) ?> (Bed <?= htmlspecialchars($past_alloc['bed_number']) ?>)</span>
                    <small class="text-muted d-block"><?= date('d M Y', strtotime($past_alloc['allocated_date'])) ?> &bull; Status: <?= ucfirst($past_alloc['status']) ?></small>
                  </div>
                  <span class="badge badge-light border"><?= ucfirst($past_alloc['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- 5. Fee History & Transaction Records (student_fee_history table) -->
    <div class="section-card">
      <div class="section-card-header">
        <h5><i class="fa-solid fa-receipt text-primary mr-2"></i>Fee History & Transactions</h5>
        <span class="badge badge-primary font-weight-bold px-2 py-1">Total: ₹<?= number_format($total_fees_paid, 2) ?></span>
      </div>
      <div class="section-card-body p-0">
        <?php if (!empty($fee_history)): ?>
          <div class="table-responsive mb-0" style="border: none; border-radius: 0;">
            <table class="table table-hover align-middle mb-0">
              <thead class="bg-light text-uppercase font-weight-bold" style="font-size: 0.75rem; color: #475569;">
                <tr>
                  <th style="width: 50px;">#</th>
                  <th>Payment Date</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Recorded At</th>
                </tr>
              </thead>
              <tbody>
                <?php $f_sl = 1; foreach ($fee_history as $fee): ?>
                  <tr>
                    <td class="font-weight-bold text-muted"><?= $f_sl++ ?></td>
                    <td>
                      <span class="font-weight-bold text-dark d-block">
                        <i class="fa-regular fa-calendar-check mr-1 text-primary"></i>
                        <?= date('d M, Y', strtotime($fee['date'])) ?>
                      </span>
                    </td>
                    <td>
                      <span class="font-weight-bold text-success" style="font-size: 0.95rem;">
                        ₹<?= number_format($fee['price'], 2) ?>
                      </span>
                    </td>
                    <td>
                      <span class="badge badge-success px-2 py-1 font-weight-bold">
                        <i class="fa-solid fa-check mr-1"></i> Paid
                      </span>
                    </td>
                    <td>
                      <small class="text-muted">
                        <?= !empty($fee['created_at']) ? date('d M Y, h:i A', strtotime($fee['created_at'])) : 'N/A' ?>
                      </small>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-center py-5">
            <div class="text-muted mb-2"><i class="fa-solid fa-receipt text-muted" style="font-size: 2.5rem; opacity: 0.5;"></i></div>
            <h6 class="font-weight-bold text-dark mb-1">No Fee Transactions Found</h6>
            <p class="text-muted small mb-0">Fee transaction receipts and invoices will be recorded here after confirmation.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

</div>

<!-- Update Profile Image Modal -->
<div class="modal fade" id="updateProfileImageModal" tabindex="-1" role="dialog" aria-labelledby="updateProfileImageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 480px;">
    <div class="modal-content border-0 shadow-lg rounded-20 overflow-hidden">
      
      <div class="modal-header bg-light py-3 px-4 border-bottom">
        <h5 class="modal-title font-weight-bold text-dark" id="updateProfileImageModalLabel">
          <i class="fa-solid fa-camera mr-2 text-primary"></i>Change Profile Photo
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form method="POST" action="profile.php" enctype="multipart/form-data" id="profileImageForm">
        <input type="hidden" name="action" value="update_profile_image">
        
        <div class="modal-body p-4 text-center">
          
          <!-- Live Preview Avatar -->
          <div class="mb-3">
            <img src="<?= $profile_img_src ?>" alt="Preview" id="avatarPreview" class="image-preview-circle" onerror="this.src='../img/fav-student.png';">
            <span class="text-muted small d-block font-weight-500">Live Photo Preview</span>
          </div>

          <!-- File Upload Box -->
          <label for="profile_image_input" class="upload-drop-zone w-100 d-block mb-3">
            <i class="fa-solid fa-cloud-arrow-up text-primary mb-2" style="font-size: 2rem;"></i>
            <div class="font-weight-bold text-dark mb-1" id="file_name_display">Click or Drag & Drop new photo here</div>
            <small class="text-muted d-block">Supported formats: JPG, PNG, JPEG, WEBP, GIF (Max 5MB)</small>
            <input type="file" name="profile_image" id="profile_image_input" accept="image/jpeg,image/png,image/jpg,image/webp,image/gif" class="d-none" required>
          </label>

          <small class="text-danger font-weight-bold d-none" id="upload_error_msg"></small>

        </div>

        <div class="modal-footer bg-light py-3 px-4 border-top">
          <button type="button" class="btn btn-secondary px-3 py-2 font-weight-600 rounded-10" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 py-2 font-weight-600 rounded-10 shadow-sm" id="saveImageBtn">
            <i class="fa-solid fa-upload mr-1"></i> Save Photo
          </button>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const fileInput = document.getElementById('profile_image_input');
  const previewImg = document.getElementById('avatarPreview');
  const fileNameDisplay = document.getElementById('file_name_display');
  const errorMsg = document.getElementById('upload_error_msg');
  const form = document.getElementById('profileImageForm');
  const saveBtn = document.getElementById('saveImageBtn');

  if (fileInput) {
    fileInput.addEventListener('change', function(e) {
      errorMsg.classList.add('d-none');
      errorMsg.textContent = '';

      if (this.files && this.files[0]) {
        const file = this.files[0];

        // Check file size (5MB limit)
        if (file.size > 5 * 1024 * 1024) {
          errorMsg.textContent = 'File size exceeds 5MB limit. Please choose a smaller image.';
          errorMsg.classList.remove('d-none');
          this.value = '';
          return;
        }

        // Check file type
        const validTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'image/gif'];
        if (!validTypes.includes(file.type)) {
          errorMsg.textContent = 'Invalid file type. Please select a JPG, PNG, WEBP, or GIF image.';
          errorMsg.classList.remove('d-none');
          this.value = '';
          return;
        }

        fileNameDisplay.innerHTML = '<span class="text-success"><i class="fa-solid fa-check-circle mr-1"></i> ' + file.name + '</span>';

        const reader = new FileReader();
        reader.onload = function(evt) {
          previewImg.src = evt.target.result;
        };
        reader.readAsDataURL(file);
      }
    });
  }

  // Form submit state
  if (form) {
    form.addEventListener('submit', function(e) {
      if (!fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        errorMsg.textContent = 'Please select an image file first.';
        errorMsg.classList.remove('d-none');
        return;
      }
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Uploading...';
    });
  }
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
