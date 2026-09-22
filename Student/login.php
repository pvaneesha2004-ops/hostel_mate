<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['student_id'])) {
    header("Location: home.php");
    exit();
}

require_once '../db.php';

$login_error = '';
$login_success = $_SESSION['reg_success'] ?? '';
unset($_SESSION['reg_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_input = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($login_input)) {
        $login_error = "Please enter your registered email address or phone number.";
    } elseif (empty($password)) {
        $login_error = "Please enter your password.";
    } else {
        // Query users table for student role
        $stmt = $conn->prepare("SELECT id, role, name, email, phone, password, profile_image, status FROM users WHERE (email = ? OR phone = ?) AND role = 'student' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ss", $login_input, $login_input);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();

                // Check password hash or plaintext fallback
                if (password_verify($password, $user['password']) || $password === $user['password']) {
                    // Check status - inactive users cannot log in
                    $status_val = strtolower(trim((string)($user['status'] ?? '')));
                    $is_active = ($status_val === 'active' || $status_val === '1');
                    if (!$is_active) {
                        $login_error = "Your account is inactive. Please contact Admin or Warden.";
                    } else {
                        $_SESSION['student_id'] = $user['id'];
                        $_SESSION['student_name'] = $user['name'];
                        $_SESSION['student_email'] = $user['email'];
                        $_SESSION['student_phone'] = $user['phone'];
                        $_SESSION['student_role'] = 'student';
                        $_SESSION['student_image'] = !empty($user['profile_image']) ? '../student_profile/' . $user['profile_image'] : '../img/fav-student.png';

                        // Update last_login
                        $up_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        if ($up_stmt) {
                            $up_stmt->bind_param("i", $user['id']);
                            $up_stmt->execute();
                            $up_stmt->close();
                        }

                        header("Location: home.php");
                        exit();
                    }
                } else {
                    $login_error = "Invalid password. Please check your credentials and try again.";
                }
            } else {
                $login_error = "No student account found with that email or phone number.";
            }
            $stmt->close();
        } else {
            $login_error = "Database query failed: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Student Portal Login | HostelMate</title>
  
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
      padding: 1.5rem;
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

    .login-container {
      width: 100%;
      max-width: 460px;
      position: relative;
      z-index: 10;
    }

    .login-glass-card {
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

    .login-subtitle {
      font-size: 0.86rem;
      color: #64748b;
      font-weight: 500;
      margin-bottom: 1.75rem;
    }

    .form-group label {
      font-size: 0.82rem;
      font-weight: 700;
      color: #334155;
      margin-bottom: 0.45rem;
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
      font-size: 0.95rem;
      transition: color 0.2s ease;
      pointer-events: none;
    }

    .form-control-modern {
      height: 48px;
      background: #f8fafc;
      border: 1.5px solid #e2e8f0;
      border-radius: 12px;
      padding: 0 1rem 0 2.75rem;
      font-size: 0.92rem;
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
      font-size: 0.95rem;
      padding: 0;
      transition: color 0.2s ease;
    }

    .password-toggle-btn:hover {
      color: var(--student-primary);
    }

    .btn-login {
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

    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(99, 102, 241, 0.45);
      color: #ffffff;
    }

    .auth-footer-links {
      margin-top: 1.75rem;
      padding-top: 1.25rem;
      border-top: 1px solid #f1f5f9;
      text-align: center;
    }

    .auth-link {
      color: var(--student-primary);
      font-weight: 700;
      text-decoration: none !important;
      transition: color 0.15s ease;
    }

    .auth-link:hover {
      color: var(--student-primary-hover);
      text-decoration: underline !important;
    }

    /* Responsive Mobile Rules */
    @media (max-width: 575.98px) {
      body {
        padding: 1rem 0.75rem;
      }
      .login-glass-card {
        padding: 2rem 1.25rem;
        border-radius: 20px;
      }
      .form-control-modern {
        height: 44px;
        font-size: 0.86rem;
      }
      .btn-login {
        height: 44px;
        font-size: 0.9rem;
      }
    }
  </style>
</head>
<body>

  <div class="login-container">
    <div class="login-glass-card">
      
      <!-- Brand Logo & Header -->
      <div class="text-center mb-3">
        <img src="../img/logo-student.png" alt="HostelMate Student Logo" style="width: 165px; height: auto;" class="mb-2" />
        <div class="d-block">
          <span class="portal-tag"><i class="fa-solid fa-graduation-cap mr-1"></i> Student Portal</span>
        </div>
        <p class="login-subtitle mb-3">Sign in to access your room, meals & gate pass</p>
      </div>

      <!-- Server Alerts -->
      <?php if (!empty($login_error)): ?>
        <div class="alert alert-danger border-0 shadow-sm p-3 mb-3 rounded-12 d-flex align-items-center" style="background: rgba(239, 68, 68, 0.12); color: #b91c1c; font-size: 0.84rem;">
          <i class="fa-solid fa-circle-exclamation mr-2" style="font-size: 1.1rem; flex-shrink: 0;"></i>
          <span><?php echo htmlspecialchars($login_error); ?></span>
        </div>
      <?php endif; ?>

      <?php if (!empty($login_success)): ?>
        <div class="alert alert-success border-0 shadow-sm p-3 mb-3 rounded-12 d-flex align-items-center" style="background: rgba(16, 185, 129, 0.12); color: #047857; font-size: 0.84rem;">
          <i class="fa-solid fa-circle-check mr-2" style="font-size: 1.1rem; flex-shrink: 0;"></i>
          <span><?php echo htmlspecialchars($login_success); ?></span>
        </div>
      <?php endif; ?>

      <!-- Login Form (Validated with JavaScript error text, required removed) -->
      <form action="login.php" method="POST" id="studentLoginForm" novalidate>
        
        <!-- Email or Phone Input -->
        <div class="form-group mb-3">
          <label for="studentEmail">Email / Phone Number</label>
          <div class="input-wrapper">
            <input 
              type="text" 
              class="form-control-modern" 
              id="studentEmail" 
              name="email"
              placeholder="Enter your registered email or phone" 
              value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
              autocomplete="username"
            />
            <i class="fa-regular fa-envelope input-icon"></i>
          </div>
          <div class="field-error-text" id="emailError">
            <i class="fa-solid fa-circle-xmark"></i> <span>Please enter your email or phone number.</span>
          </div>
        </div>

        <!-- Password Input -->
        <div class="form-group mb-4">
          <label for="studentPassword">
            <span>Password</span>
          </label>
          <div class="input-wrapper">
            <input 
              type="password" 
              class="form-control-modern" 
              id="studentPassword" 
              name="password"
              placeholder="Enter your password"
              autocomplete="current-password"
            />
            <i class="fa-solid fa-lock input-icon"></i>
            <button type="button" class="password-toggle-btn" id="togglePasswordBtn" aria-label="Toggle password visibility">
              <i class="fa-regular fa-eye" id="toggleIcon"></i>
            </button>
          </div>
          <div class="field-error-text" id="passwordError">
            <i class="fa-solid fa-circle-xmark"></i> <span>Please enter your password.</span>
          </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn btn-login" id="loginSubmitBtn">
          <span>Sign In to Portal</span>
          <i class="fa-solid fa-arrow-right"></i>
        </button>

      </form>

      <!-- Link to Register -->
      <div class="auth-footer-links">
        <span class="text-muted small">New student at hostel? </span>
        <a href="register.php" class="auth-link small">Create Student Account</a>
      </div>

    </div>
  </div>

  <!-- JavaScript Form Validation (Error Text) -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const loginForm = document.getElementById('studentLoginForm');
      const emailInput = document.getElementById('studentEmail');
      const passwordInput = document.getElementById('studentPassword');
      const emailError = document.getElementById('emailError');
      const passwordError = document.getElementById('passwordError');
      const toggleBtn = document.getElementById('togglePasswordBtn');
      const toggleIcon = document.getElementById('toggleIcon');

      // Toggle Password Visibility
      toggleBtn.addEventListener('click', function() {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
        toggleIcon.classList.toggle('fa-eye', !isPassword);
        toggleIcon.classList.toggle('fa-eye-slash', isPassword);
      });

      // Helper function to show error text
      function showError(input, errorElement, message) {
        input.classList.add('is-invalid');
        errorElement.querySelector('span').textContent = message;
        errorElement.classList.add('show');
      }

      // Helper function to clear error text
      function clearError(input, errorElement) {
        input.classList.remove('is-invalid');
        errorElement.classList.remove('show');
      }

      // Live validation on typing
      emailInput.addEventListener('input', function() {
        if (emailInput.value.trim() !== '') {
          clearError(emailInput, emailError);
        }
      });

      passwordInput.addEventListener('input', function() {
        if (passwordInput.value !== '') {
          clearError(passwordInput, passwordError);
        }
      });

      // Form Submit Validation
      loginForm.addEventListener('submit', function(e) {
        let isValid = true;

        const emailVal = emailInput.value.trim();
        const passwordVal = passwordInput.value;

        // Validate Email / Phone
        if (emailVal === '') {
          showError(emailInput, emailError, 'Email address or phone number is required.');
          isValid = false;
        } else if (emailVal.includes('@')) {
          const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          if (!emailRegex.test(emailVal)) {
            showError(emailInput, emailError, 'Please enter a valid email address.');
            isValid = false;
          } else {
            clearError(emailInput, emailError);
          }
        } else {
          clearError(emailInput, emailError);
        }

        // Validate Password
        if (passwordVal === '') {
          showError(passwordInput, passwordError, 'Password is required.');
          isValid = false;
        } else if (passwordVal.length < 4) {
          showError(passwordInput, passwordError, 'Password must be at least 4 characters.');
          isValid = false;
        } else {
          clearError(passwordInput, passwordError);
        }

        if (!isValid) {
          e.preventDefault();
        }
      });
    });
  </script>
</body>
</html>
