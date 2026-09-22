<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['student_id'])) {
    header("Location: home.php");
    exit();
}

require_once '../db.php';

// Profile and document upload directories
$profile_dir = "../student_profile/";
$doc_dir = "../student_docs/";
if (!file_exists($profile_dir)) {
    mkdir($profile_dir, 0777, true);
}
if (!file_exists($doc_dir)) {
    mkdir($doc_dir, 0777, true);
}

$register_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Student / User data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = 'student';
    $status = 'inactive';

    // 2. Guardian data
    $g_name = trim($_POST['guardian_name'] ?? '');
    $g_relationship = trim($_POST['guardian_relationship'] ?? '');
    $g_phone = trim($_POST['guardian_phone'] ?? '');
    $g_email = trim($_POST['guardian_email'] ?? '');
    $g_address = trim($_POST['guardian_address'] ?? '');

    // 3. Document data
    $doc_type = trim($_POST['document_type'] ?? 'College ID');
    $doc_number = trim($_POST['document_number'] ?? '');
    $remarks = trim($_POST['remarks'] ?? 'Student online registration');
    $verification = 'pending';
    $verified_by = NULL;
    $verified_at = NULL;

    // Server-side validations
    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        $register_error = "Please fill in all required student details.";
    } else {
        // Check if email or phone already registered in users table
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ? LIMIT 1");
        if ($check_stmt) {
            $check_stmt->bind_param("ss", $email, $phone);
            $check_stmt->execute();
            $check_res = $check_stmt->get_result();

            if ($check_res && $check_res->num_rows > 0) {
                $register_error = "An account with that email address or phone number is already registered.";
            } else {
                // Profile Image Upload
                $profile_image = '';
                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $profile_image = 'student_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        move_uploaded_file($_FILES['profile_image']['tmp_name'], $profile_dir . $profile_image);
                    }
                }

                // Document File Upload
                $doc_file = '';
                if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] == UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'])) {
                        $doc_file = 'doc_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        move_uploaded_file($_FILES['doc_file']['tmp_name'], $doc_dir . $doc_file);
                    }
                }

                $password_hash = password_hash($password, PASSWORD_BCRYPT);

                // Insert into `users` table with status 'inactive'
                $stmt = $conn->prepare("INSERT INTO users (role, name, email, phone, password, profile_image, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                if ($stmt) {
                    $stmt->bind_param("sssssss", $role, $name, $email, $phone, $password_hash, $profile_image, $status);
                    if ($stmt->execute()) {
                        $student_id = $conn->insert_id;

                        // Insert into `student_guardians` table
                        $g_stmt = $conn->prepare("INSERT INTO student_guardians (student_id, name, relationship, phone, email, address, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        if ($g_stmt) {
                            $g_stmt->bind_param("isssss", $student_id, $g_name, $g_relationship, $g_phone, $g_email, $g_address);
                            $g_stmt->execute();
                            $g_stmt->close();
                        }

                        // Insert into `student_documents` table
                        $d_stmt = $conn->prepare("INSERT INTO student_documents (student_id, document_type, document_number, file_path, verification, verified_by, verified_at, remarks, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        if ($d_stmt) {
                            $d_stmt->bind_param("issssiis", $student_id, $doc_type, $doc_number, $doc_file, $verification, $verified_by, $verified_at, $remarks);
                            $d_stmt->execute();
                            $d_stmt->close();
                        }

                        $_SESSION['reg_success'] = "Registration submitted successfully! Your account is currently inactive. Please contact Admin or Warden for activation.";
                        header("Location: login.php");
                        exit();
                    } else {
                        $register_error = "Failed to create student account: " . $conn->error;
                    }
                    $stmt->close();
                } else {
                    $register_error = "Database prepare error: " . $conn->error;
                }
            }
            $check_stmt->close();
        } else {
            $register_error = "Database error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Student Registration | HostelMate</title>
  
  <!-- Bootstrap 4.6 CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
  <!-- FontAwesome 6 Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <!-- Favicon -->
  <link rel="shortcut icon" href="../img/fav-student.png" />
  <!-- Google Fonts: Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --student-primary: #6366f1;
      --student-primary-hover: #4f46e5;
      --student-gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #d946ef 100%);
      --student-bg-gradient: linear-gradient(135deg, #090a1a 0%, #11142e 45%, #1e1b4b 100%);
      --student-accent: #a855f7;
      --student-glow: 0 8px 30px rgba(99, 102, 241, 0.4);
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      max-width: 100% !important;
      overflow-x: hidden !important;
      position: relative;
    }

    body {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      background: var(--student-bg-gradient) !important;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow-x: hidden;
      margin: 0;
      padding: 2.5rem 1.5rem;
    }

    /* Ambient glowing background orbs */
    body::before {
      content: "";
      position: absolute;
      width: 550px;
      height: 550px;
      background: radial-gradient(circle, rgba(99, 102, 241, 0.28) 0%, rgba(17, 20, 46, 0) 70%);
      top: -120px;
      right: -100px;
      border-radius: 50%;
      pointer-events: none;
    }

    body::after {
      content: "";
      position: absolute;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(168, 85, 247, 0.22) 0%, rgba(17, 20, 46, 0) 70%);
      bottom: -100px;
      left: -100px;
      border-radius: 50%;
      pointer-events: none;
    }

    .register-container {
      width: 100%;
      max-width: 720px;
      position: relative;
      z-index: 10;
    }

    .register-glass-card {
      background: rgba(255, 255, 255, 0.97);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border: 1px solid rgba(255, 255, 255, 0.6);
      border-radius: 24px;
      padding: 2.75rem 2.5rem;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(99, 102, 241, 0.15);
    }

    .portal-tag {
      display: inline-block;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      padding: 4px 12px;
      border-radius: 999px;
      background: rgba(99, 102, 241, 0.1);
      color: var(--student-primary);
      margin-bottom: 0.5rem;
    }

    .register-subtitle {
      font-size: 0.86rem;
      color: #64748b;
      font-weight: 500;
      margin-bottom: 1.75rem;
    }

    /* Section Step Headers */
    .form-section-divider {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 1.75rem 0 1.25rem 0;
      padding-bottom: 0.5rem;
      border-bottom: 1.5px solid #f1f5f9;
    }

    .section-number-badge {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: var(--student-primary);
      color: #ffffff;
      font-size: 0.78rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .section-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: #1e293b;
      margin: 0;
    }

    /* Profile Image Upload Box */
    .profile-upload-zone {
      display: flex;
      align-items: center;
      gap: 18px;
      background: #f8fafc;
      border: 1.5px dashed #cbd5e1;
      border-radius: 16px;
      padding: 12px 18px;
      margin-bottom: 1.25rem;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .profile-upload-zone:hover {
      border-color: var(--student-primary);
      background: rgba(99, 102, 241, 0.03);
    }

    .avatar-preview-box {
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background: rgba(99, 102, 241, 0.1);
      border: 2px solid #e2e8f0;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .avatar-preview-box img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: none;
    }

    .avatar-placeholder-icon {
      font-size: 1.5rem;
      color: #94a3b8;
    }

    .upload-action-text {
      font-size: 0.84rem;
      font-weight: 700;
      color: #1e293b;
      margin-bottom: 2px;
    }

    .upload-hint-text {
      font-size: 0.74rem;
      color: #94a3b8;
      margin: 0;
    }

    .form-group label {
      font-size: 0.8rem;
      font-weight: 700;
      color: #334155;
      margin-bottom: 0.4rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .input-wrapper {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.9rem;
      transition: color 0.2s ease;
      pointer-events: none;
    }

    .form-control-modern {
      height: 46px;
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 0 1rem 0 2.65rem;
      font-size: 0.88rem;
      font-weight: 500;
      color: #0f172a;
      transition: all 0.2s ease;
      width: 100%;
    }

    .form-control-modern:focus {
      background: #ffffff;
      border-color: var(--student-primary);
      box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
      outline: none;
    }

    .form-control-modern.is-invalid {
      border-color: #ef4444 !important;
      background: #fff5f5 !important;
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
    }

    .form-control-modern.is-invalid + .input-icon {
      color: #ef4444 !important;
    }

    .field-error-text {
      color: #ef4444;
      font-size: 0.76rem;
      font-weight: 600;
      margin-top: 4px;
      display: none;
      align-items: center;
      gap: 4px;
    }

    .field-error-text.show {
      display: flex;
    }

    .password-toggle-btn {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #94a3b8;
      cursor: pointer;
      font-size: 0.9rem;
      padding: 0;
    }

    .btn-register {
      height: 48px;
      background: var(--student-gradient);
      border: none;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 700;
      color: #ffffff;
      box-shadow: 0 8px 20px rgba(99, 102, 241, 0.35);
      transition: all 0.25s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      cursor: pointer;
      text-decoration: none !important;
    }

    .btn-register:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(99, 102, 241, 0.45);
      color: #ffffff;
    }

    .auth-footer-links {
      margin-top: 1.5rem;
      padding-top: 1.25rem;
      border-top: 1px solid #f1f5f9;
      text-align: center;
    }

    .auth-link {
      color: var(--student-primary);
      font-weight: 700;
      text-decoration: none !important;
    }

    .auth-link:hover {
      color: var(--student-primary-hover);
      text-decoration: underline !important;
    }

    /* Responsive Mobile Rules */
    @media (max-width: 575.98px) {
      body {
        padding: 1.25rem 0.75rem;
      }
      .register-glass-card {
        padding: 2rem 1.25rem;
        border-radius: 20px;
      }
      .form-control-modern {
        height: 44px;
        font-size: 0.86rem;
      }
      .btn-register {
        height: 44px;
        font-size: 0.9rem;
      }
      .profile-upload-zone {
        padding: 10px 12px;
        gap: 12px;
      }
      .avatar-preview-box {
        width: 50px;
        height: 50px;
      }
    }
  </style>
</head>
<body>

  <div class="register-container">
    <div class="register-glass-card">
      
      <!-- Brand Logo & Header -->
      <div class="text-center mb-3">
        <img src="../img/logo-student.png" alt="HostelMate Student Logo" style="width: 165px; height: auto;" class="mb-2" />
        <div class="d-block">
          <span class="portal-tag"><i class="fa-solid fa-user-plus mr-1"></i> Student Registration</span>
        </div>
        <p class="register-subtitle mb-3">Create your student account to access hostel rooms and services</p>
      </div>

      <!-- Server Alerts -->
      <?php if (!empty($register_error)): ?>
        <div class="alert alert-danger border-0 shadow-sm p-3 mb-3 rounded-12 d-flex align-items-center" style="background: rgba(239, 68, 68, 0.12); color: #b91c1c; font-size: 0.84rem;">
          <i class="fa-solid fa-circle-exclamation mr-2" style="font-size: 1.1rem; flex-shrink: 0;"></i>
          <span><?php echo htmlspecialchars($register_error); ?></span>
        </div>
      <?php endif; ?>

      <!-- Registration Form (JavaScript error text validated, required attributes removed) -->
      <form action="register.php" method="POST" enctype="multipart/form-data" id="studentRegisterForm" novalidate>
        
        <!-- =========================================================
             SECTION 1: STUDENT PROFILE & CREDENTIALS (`users`)
             ========================================================= -->
        <div class="form-section-divider mt-0">
          <span class="section-number-badge">1</span>
          <h6 class="section-title">Student Account & Details</h6>
        </div>

        <!-- Profile Image (`profile_image`) -->
        <label class="mb-1">Student Profile Photo</label>
        <div class="profile-upload-zone" onclick="document.getElementById('profileImageInput').click();">
          <div class="avatar-preview-box" id="avatarPreviewContainer">
            <i class="fa-regular fa-user avatar-placeholder-icon" id="avatarIcon"></i>
            <img src="" alt="Preview" id="avatarImg" />
          </div>
          <div>
            <div class="upload-action-text"><i class="fa-solid fa-arrow-up-from-bracket text-indigo mr-1"></i> Upload Student Photo</div>
            <p class="upload-hint-text">JPG, PNG, WEBP supported (Max 2MB)</p>
          </div>
          <input 
            type="file" 
            id="profileImageInput" 
            name="profile_image" 
            accept="image/*" 
            style="display: none;" 
            onchange="previewProfileImage(event)" 
          />
        </div>

        <!-- Full Name (`name`) -->
        <div class="form-group mb-3">
          <label for="studentName">Full Name</label>
          <div class="input-wrapper">
            <input 
              type="text" 
              class="form-control-modern" 
              id="studentName" 
              name="name" 
              placeholder="e.g. Alex Morgan" 
              value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
            />
            <i class="fa-regular fa-user input-icon"></i>
          </div>
          <div class="field-error-text" id="nameError">
            <i class="fa-solid fa-circle-xmark"></i> <span>Please enter your full name.</span>
          </div>
        </div>

        <!-- Email and Phone Row (`email`, `phone`) -->
        <div class="form-row mb-3">
          <!-- Email -->
          <div class="col-md-6 form-group mb-3 mb-md-0">
            <label for="studentEmail">Email Address</label>
            <div class="input-wrapper">
              <input 
                type="email" 
                class="form-control-modern" 
                id="studentEmail" 
                name="email" 
                placeholder="alex.morgan@campus.edu" 
                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
              />
              <i class="fa-regular fa-envelope input-icon"></i>
            </div>
            <div class="field-error-text" id="emailError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Please enter a valid email address.</span>
            </div>
          </div>

          <!-- Phone -->
          <div class="col-md-6 form-group mb-0">
            <label for="studentPhone">Phone / Mobile Number</label>
            <div class="input-wrapper">
              <input 
                type="tel" 
                class="form-control-modern" 
                id="studentPhone" 
                name="phone" 
                placeholder="10-digit mobile number" 
                value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
              />
              <i class="fa-solid fa-phone input-icon"></i>
            </div>
            <div class="field-error-text" id="phoneError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Please enter a valid 10-digit phone number.</span>
            </div>
          </div>
        </div>

        <!-- Password and Confirm Password Row (`password`) -->
        <div class="form-row mb-3">
          <!-- Password -->
          <div class="col-md-6 form-group mb-3 mb-md-0">
            <label for="studentPassword">Password</label>
            <div class="input-wrapper">
              <input 
                type="password" 
                class="form-control-modern" 
                id="studentPassword" 
                name="password" 
                placeholder="Min. 6 characters" 
              />
              <i class="fa-solid fa-lock input-icon"></i>
              <button type="button" class="password-toggle-btn" id="toggleRegPasswordBtn" aria-label="Toggle password visibility">
                <i class="fa-regular fa-eye" id="toggleRegIcon"></i>
              </button>
            </div>
            <div class="field-error-text" id="passwordError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Password must be at least 6 characters.</span>
            </div>
          </div>

          <!-- Confirm Password -->
          <div class="col-md-6 form-group mb-0">
            <label for="confirmPassword">Confirm Password</label>
            <div class="input-wrapper">
              <input 
                type="password" 
                class="form-control-modern" 
                id="confirmPassword" 
                name="confirm_password" 
                placeholder="Re-enter password" 
              />
              <i class="fa-solid fa-shield-check input-icon"></i>
            </div>
            <div class="field-error-text" id="confirmPasswordError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Passwords do not match.</span>
            </div>
          </div>
        </div>

        <!-- =========================================================
             SECTION 2: GUARDIAN DETAILS
             ========================================================= -->
        <div class="form-section-divider">
          <span class="section-number-badge">2</span>
          <h6 class="section-title">Parent / Guardian Information</h6>
        </div>

        <div class="form-row mb-3">
          <!-- Guardian Name -->
          <div class="col-md-6 form-group mb-3 mb-md-0">
            <label for="guardianName">Guardian / Parent Name</label>
            <div class="input-wrapper">
              <input 
                type="text" 
                class="form-control-modern" 
                id="guardianName" 
                name="guardian_name" 
                placeholder="e.g. Robert Morgan" 
                value="<?php echo htmlspecialchars($_POST['guardian_name'] ?? ''); ?>"
              />
              <i class="fa-solid fa-user-shield input-icon"></i>
            </div>
            <div class="field-error-text" id="guardianNameError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Guardian name is required.</span>
            </div>
          </div>

          <!-- Relationship -->
          <div class="col-md-6 form-group mb-0">
            <label for="guardianRelationship">Relationship</label>
            <div class="input-wrapper">
              <select class="form-control-modern" id="guardianRelationship" name="guardian_relationship" style="padding-left: 2.65rem;">
                <option value="Father">Father</option>
                <option value="Mother">Mother</option>
                <option value="Brother">Brother</option>
                <option value="Sister">Sister</option>
                <option value="Guardian">Guardian</option>
              </select>
              <i class="fa-solid fa-people-roof input-icon"></i>
            </div>
          </div>
        </div>

        <div class="form-row mb-3">
          <!-- Guardian Phone -->
          <div class="col-md-6 form-group mb-3 mb-md-0">
            <label for="guardianPhone">Guardian Phone</label>
            <div class="input-wrapper">
              <input 
                type="tel" 
                class="form-control-modern" 
                id="guardianPhone" 
                name="guardian_phone" 
                placeholder="Guardian contact number" 
                value="<?php echo htmlspecialchars($_POST['guardian_phone'] ?? ''); ?>"
              />
              <i class="fa-solid fa-phone input-icon"></i>
            </div>
            <div class="field-error-text" id="guardianPhoneError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Please enter guardian phone number.</span>
            </div>
          </div>

          <!-- Guardian Email (Optional) -->
          <div class="col-md-6 form-group mb-0">
            <label for="guardianEmail">Guardian Email <small class="text-muted">(Optional)</small></label>
            <div class="input-wrapper">
              <input 
                type="email" 
                class="form-control-modern" 
                id="guardianEmail" 
                name="guardian_email" 
                placeholder="guardian@example.com" 
                value="<?php echo htmlspecialchars($_POST['guardian_email'] ?? ''); ?>"
              />
              <i class="fa-regular fa-envelope input-icon"></i>
            </div>
          </div>
        </div>

        <!-- Guardian Address -->
        <div class="form-group mb-3">
          <label for="guardianAddress">Permanent Home Address</label>
          <div class="input-wrapper">
            <input 
              type="text" 
              class="form-control-modern" 
              id="guardianAddress" 
              name="guardian_address" 
              placeholder="City, State, Pincode" 
              value="<?php echo htmlspecialchars($_POST['guardian_address'] ?? ''); ?>"
            />
            <i class="fa-solid fa-location-dot input-icon"></i>
          </div>
          <div class="field-error-text" id="guardianAddressError">
            <i class="fa-solid fa-circle-xmark"></i> <span>Address is required.</span>
          </div>
        </div>

        <!-- =========================================================
             SECTION 3: Document & Verification 
             ========================================================= -->
        <div class="form-section-divider">
          <span class="section-number-badge">3</span>
          <h6 class="section-title">Document & Verification</h6>
        </div>

        <div class="form-row mb-3">
          <!-- Document Type -->
          <div class="col-md-6 form-group mb-3 mb-md-0">
            <label for="documentType">Document Type</label>
            <div class="input-wrapper">
              <select class="form-control-modern" id="documentType" name="document_type" style="padding-left: 2.65rem;">
                <option value="Aadhar Card">Aadhar Card</option>
                <option value="College ID Card">College ID Card</option>
                <option value="Passport">Passport</option>
                <option value="Driving License">Driving License</option>
                <option value="Voter ID">Voter ID</option>
              </select>
              <i class="fa-solid fa-id-card input-icon"></i>
            </div>
          </div>

          <!-- Document Number -->
          <div class="col-md-6 form-group mb-0">
            <label for="documentNumber">Document / ID Number</label>
            <div class="input-wrapper">
              <input 
                type="text" 
                class="form-control-modern" 
                id="documentNumber" 
                name="document_number" 
                placeholder="e.g. 1234 5678 9012" 
                value="<?php echo htmlspecialchars($_POST['document_number'] ?? ''); ?>"
              />
              <i class="fa-solid fa-fingerprint input-icon"></i>
            </div>
            <div class="field-error-text" id="documentNumberError">
              <i class="fa-solid fa-circle-xmark"></i> <span>Please enter document identification number.</span>
            </div>
          </div>
        </div>

        <!-- Document File Upload (`file_path`) -->
        <div class="form-group mb-4">
          <label for="docFileInput">Upload Document Copy <small class="text-muted">(PDF, JPG, PNG)</small></label>
          <div class="input-wrapper">
            <input 
              type="file" 
              class="form-control-modern pt-2" 
              id="docFileInput" 
              name="doc_file" 
              accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
            />
            <i class="fa-solid fa-file-arrow-up input-icon"></i>
          </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn btn-register" id="registerSubmitBtn">
          <span>Complete Registration</span>
          <i class="fa-solid fa-user-check"></i>
        </button>

      </form>

      <!-- Link to Login -->
      <div class="auth-footer-links">
        <span class="text-muted small">Already have a registered account? </span>
        <a href="login.php" class="auth-link small">Sign In here</a>
      </div>

    </div>
  </div>

  <!-- JavaScript Form Validation & Image Preview -->
  <script>
    // Live Image Preview
    function previewProfileImage(event) {
      const file = event.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          const avatarImg = document.getElementById('avatarImg');
          const avatarIcon = document.getElementById('avatarIcon');
          avatarImg.src = e.target.result;
          avatarImg.style.display = 'block';
          avatarIcon.style.display = 'none';
        };
        reader.readAsDataURL(file);
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const form = document.getElementById('studentRegisterForm');
      
      // Fields
      const nameInput = document.getElementById('studentName');
      const emailInput = document.getElementById('studentEmail');
      const phoneInput = document.getElementById('studentPhone');
      const passwordInput = document.getElementById('studentPassword');
      const confirmPasswordInput = document.getElementById('confirmPassword');
      const guardianNameInput = document.getElementById('guardianName');
      const guardianPhoneInput = document.getElementById('guardianPhone');
      const guardianAddressInput = document.getElementById('guardianAddress');
      const documentNumberInput = document.getElementById('documentNumber');

      // Errors
      const nameError = document.getElementById('nameError');
      const emailError = document.getElementById('emailError');
      const phoneError = document.getElementById('phoneError');
      const passwordError = document.getElementById('passwordError');
      const confirmPasswordError = document.getElementById('confirmPasswordError');
      const guardianNameError = document.getElementById('guardianNameError');
      const guardianPhoneError = document.getElementById('guardianPhoneError');
      const guardianAddressError = document.getElementById('guardianAddressError');
      const documentNumberError = document.getElementById('documentNumberError');

      // Toggle Password Visibility
      const toggleRegBtn = document.getElementById('toggleRegPasswordBtn');
      const toggleRegIcon = document.getElementById('toggleRegIcon');
      toggleRegBtn.addEventListener('click', function() {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
        toggleRegIcon.classList.toggle('fa-eye', !isPassword);
        toggleRegIcon.classList.toggle('fa-eye-slash', isPassword);
      });

      // Show Error Helper
      function showError(input, errorElem, msg) {
        input.classList.add('is-invalid');
        errorElem.querySelector('span').textContent = msg;
        errorElem.classList.add('show');
      }

      // Clear Error Helper
      function clearError(input, errorElem) {
        input.classList.remove('is-invalid');
        errorElem.classList.remove('show');
      }

      // Realtime clearing
      nameInput.addEventListener('input', () => { if (nameInput.value.trim().length >= 2) clearError(nameInput, nameError); });
      emailInput.addEventListener('input', () => { if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value.trim())) clearError(emailInput, emailError); });
      phoneInput.addEventListener('input', () => { if (/^\d{10}$/.test(phoneInput.value.trim().replace(/\D/g, ''))) clearError(phoneInput, phoneError); });
      passwordInput.addEventListener('input', () => { if (passwordInput.value.length >= 6) clearError(passwordInput, passwordError); });
      confirmPasswordInput.addEventListener('input', () => { if (confirmPasswordInput.value === passwordInput.value) clearError(confirmPasswordInput, confirmPasswordError); });
      guardianNameInput.addEventListener('input', () => { if (guardianNameInput.value.trim() !== '') clearError(guardianNameInput, guardianNameError); });
      guardianPhoneInput.addEventListener('input', () => { if (guardianPhoneInput.value.trim() !== '') clearError(guardianPhoneInput, guardianPhoneError); });
      guardianAddressInput.addEventListener('input', () => { if (guardianAddressInput.value.trim() !== '') clearError(guardianAddressInput, guardianAddressError); });
      documentNumberInput.addEventListener('input', () => { if (documentNumberInput.value.trim() !== '') clearError(documentNumberInput, documentNumberError); });

      // Form Submit Validation
      form.addEventListener('submit', function(e) {
        let isValid = true;
        let firstInvalidField = null;

        // 1. Full Name
        if (nameInput.value.trim().length < 2) {
          showError(nameInput, nameError, 'Full Name is required (at least 2 characters).');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = nameInput;
        } else {
          clearError(nameInput, nameError);
        }

        // 2. Email Address
        const emailVal = emailInput.value.trim();
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(emailVal)) {
          showError(emailInput, emailError, 'Please enter a valid email address.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = emailInput;
        } else {
          clearError(emailInput, emailError);
        }

        // 3. Phone Number
        const phoneVal = phoneInput.value.trim().replace(/\D/g, '');
        if (phoneVal.length < 10) {
          showError(phoneInput, phoneError, 'Please enter a valid 10-digit mobile number.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = phoneInput;
        } else {
          clearError(phoneInput, phoneError);
        }

        // 4. Password
        if (passwordInput.value.length < 6) {
          showError(passwordInput, passwordError, 'Password must be at least 6 characters long.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = passwordInput;
        } else {
          clearError(passwordInput, passwordError);
        }

        // 5. Confirm Password
        if (confirmPasswordInput.value !== passwordInput.value) {
          showError(confirmPasswordInput, confirmPasswordError, 'Passwords do not match.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = confirmPasswordInput;
        } else {
          clearError(confirmPasswordInput, confirmPasswordError);
        }

        // 6. Guardian Name
        if (guardianNameInput.value.trim() === '') {
          showError(guardianNameInput, guardianNameError, 'Guardian / Parent name is required.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = guardianNameInput;
        } else {
          clearError(guardianNameInput, guardianNameError);
        }

        // 7. Guardian Phone
        const gPhoneVal = guardianPhoneInput.value.trim().replace(/\D/g, '');
        if (gPhoneVal.length < 7) {
          showError(guardianPhoneInput, guardianPhoneError, 'Please enter a valid contact number for guardian.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = guardianPhoneInput;
        } else {
          clearError(guardianPhoneInput, guardianPhoneError);
        }

        // 8. Guardian Address
        if (guardianAddressInput.value.trim() === '') {
          showError(guardianAddressInput, guardianAddressError, 'Permanent address is required.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = guardianAddressInput;
        } else {
          clearError(guardianAddressInput, guardianAddressError);
        }

        // 9. Document Number
        if (documentNumberInput.value.trim() === '') {
          showError(documentNumberInput, documentNumberError, 'Document ID number is required.');
          isValid = false;
          if (!firstInvalidField) firstInvalidField = documentNumberInput;
        } else {
          clearError(documentNumberInput, documentNumberError);
        }

        // Block submit if any invalid field
        if (!isValid) {
          e.preventDefault();
          if (firstInvalidField) {
            firstInvalidField.focus();
            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }
      });
    });
  </script>
</body>
</html>
