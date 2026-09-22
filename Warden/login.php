<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['warden_id'])) {
    header("Location: home.php");
    exit();
}

require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        // Find warden by email, code, or phone
        $stmt = $conn->prepare("SELECT id, name, code, email, phone, profile_image, password, status FROM wardens WHERE email = ? OR code = ? OR phone = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("sss", $username, $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $warden = $result->fetch_assoc();

                // Check active status
                $isActive = (isset($warden['status']) && ($warden['status'] === 'active' || $warden['status'] == 1 || $warden['status'] === '1'));
                if (!$isActive) {
                    $_SESSION['login_error'] = "Your warden account is currently inactive. Please contact the administrator.";
                } else {
                    // Check password
                    if (password_verify($password, $warden['password']) || $password === $warden['password']) {
                        $_SESSION['warden_id'] = $warden['id'];
                        $_SESSION['warden_name'] = $warden['name'];
                        $_SESSION['warden_code'] = $warden['code'];
                        $_SESSION['warden_email'] = $warden['email'];
                        $_SESSION['warden_phone'] = $warden['phone'];
                        $_SESSION['warden_image'] = $warden['profile_image'];
                        $_SESSION['login_success'] = "Welcome back, " . $warden['name'] . "!";

                        header("Location: home.php");
                        exit();
                    } else {
                        $_SESSION['login_error'] = "Invalid password. Please verify and try again.";
                    }
                }
            } else {
                $_SESSION['login_error'] = "No warden account found with that username, email, or code.";
            }
            $stmt->close();
        } else {
            $_SESSION['login_error'] = "Database query failed: " . $conn->error;
        }
    } else {
        $_SESSION['login_error'] = "Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>HOSTEL MATE - Warden Portal Login</title>
  <!-- Bootstrap 4.6 CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
  <!-- Themify Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lykmapipo/themify-icons@0.1.2/css/themify-icons.css">
  <!-- Google Fonts: Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="shortcut icon" href="../img/fav-warden.png" />
  
  <style>
    :root {
      --warden-primary: #059669;
      --warden-primary-hover: #047857;
      --warden-gradient: linear-gradient(135deg, #059669 0%, #0d9488 100%);
      --warden-bg-gradient: linear-gradient(135deg, #022c22 0%, #064e3b 45%, #0f172a 100%);
      --warden-accent: #10b981;
      --warden-soft: rgba(5, 150, 105, 0.12);
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      min-height: 100vh;
      max-width: 100% !important;
      overflow-x: hidden !important;
      margin: 0;
      padding: 0;
      position: relative;
    }

    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif !important;
      background: var(--warden-bg-gradient) !important;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      color: #1e293b;
      position: relative;
    }

    /* Ambient glowing background orbs */
    body::before {
      content: "";
      position: absolute;
      width: 450px;
      height: 450px;
      background: radial-gradient(circle, rgba(16, 185, 129, 0.18) 0%, rgba(5, 150, 105, 0) 70%);
      top: -100px;
      right: -100px;
      border-radius: 50%;
      pointer-events: none;
    }

    body::after {
      content: "";
      position: absolute;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(13, 148, 136, 0.2) 0%, rgba(2, 44, 34, 0) 70%);
      bottom: -100px;
      left: -100px;
      border-radius: 50%;
      pointer-events: none;
    }

    .auth-card {
      border-radius: 20px;
      box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.6);
      padding: 2rem 2.25rem;
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.35);
      width: 100%;
      max-width: 420px;
      margin: 0 auto;
      position: relative;
      z-index: 10;
      transition: all 0.3s ease;
    }

    .warden-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.75rem;
      border-radius: 50px;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      background: var(--warden-soft);
      color: var(--warden-primary);
      border: 1px solid rgba(5, 150, 105, 0.2);
      margin-bottom: 0.5rem;
    }

    .brand-title {
      font-weight: 800;
      color: #064e3b;
      letter-spacing: -0.5px;
      font-size: 1.45rem;
    }

    .form-group label {
      font-weight: 600;
      color: #334155;
      font-size: 0.82rem;
      margin-bottom: 0.35rem;
    }

    .input-wrapper {
      position: relative;
    }

    .input-wrapper i {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 1rem;
      pointer-events: none;
      transition: color 0.2s ease;
    }

    .form-control {
      border-radius: 10px !important;
      border: 1.5px solid #e2e8f0 !important;
      padding: 0.7rem 1rem 0.7rem 2.7rem !important;
      height: auto !important;
      font-size: 0.9rem !important;
      color: #1e293b !important;
      background-color: #ffffff !important;
      transition: all 0.25s ease !important;
    }

    .form-control:focus {
      border-color: var(--warden-primary) !important;
      box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.15) !important;
      background-color: #ffffff !important;
    }

    .form-control.is-invalid {
      border-color: #ef4444 !important;
      box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.12) !important;
    }

    .form-control:focus + i,
    .form-control:focus ~ i {
      color: var(--warden-primary);
    }

    .form-control.is-invalid + i,
    .form-control.is-invalid ~ i {
      color: #ef4444;
    }

    .error-text {
      color: #dc2626;
      font-size: 0.78rem;
      font-weight: 600;
      display: block;
      margin-top: 0.35rem;
      padding-left: 0.25rem;
      animation: fadeIn 0.2s ease-in-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-3px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .btn-warden {
      border-radius: 10px !important;
      font-weight: 700 !important;
      padding: 0.75rem 1.25rem !important;
      background: var(--warden-gradient) !important;
      border: none !important;
      box-shadow: 0 6px 18px rgba(5, 150, 105, 0.35) !important;
      transition: all 0.25s ease !important;
      font-size: 0.92rem !important;
      letter-spacing: 0.2px;
      color: #ffffff !important;
    }

    .btn-warden:hover {
      transform: translateY(-1px) !important;
      box-shadow: 0 10px 22px rgba(5, 150, 105, 0.45) !important;
      background: linear-gradient(135deg, #047857 0%, #0f766e 100%) !important;
    }

    @media (max-height: 580px) {
      html, body {
        overflow-y: auto;
        height: auto;
        min-height: 100vh;
      }
    }

    /* Responsive Mobile Rules */
    @media (max-width: 575.98px) {
      body {
        padding: 1rem 0.75rem !important;
      }
      .auth-card {
        padding: 2rem 1.25rem !important;
        border-radius: 20px !important;
      }
      .brand-title {
        font-size: 1.35rem !important;
      }
      .form-control {
        height: 44px !important;
        font-size: 0.88rem !important;
      }
      .btn-warden {
        padding: 0.75rem 1.25rem !important;
        font-size: 0.9rem !important;
      }
      body::before {
        width: 320px;
        height: 320px;
        top: -80px;
        right: -60px;
      }
      body::after {
        width: 280px;
        height: 280px;
        bottom: -60px;
        left: -60px;
      }
    }
  </style>
</head>
<body>

  <div class="auth-card">
    <!-- Header Logo & Heading -->
    <div class="text-center mb-3">
      <img src="../img/logo-warden.png" alt="Hostel Mate" style="width: 140px; height: auto;" class="mb-2">
      <div>
        <span class="warden-badge">
          <i class="ti-home mr-1"></i> Warden Access Portal
        </span>
      </div>
      <h3 class="brand-title mb-0">Hostel Mate</h3>
      <p class="text-muted mb-0" style="font-size: 0.84rem;">Sign in to your Warden supervisory console</p>
    </div>

    <!-- Server Error Alert -->
    <?php if (isset($_SESSION['login_error'])): ?>
      <div class="alert alert-danger border-0 shadow-sm mb-3 py-2 px-3 d-flex align-items-center" style="background: rgba(239, 68, 68, 0.12); color: #dc2626; border-radius: 10px; font-size: 0.82rem; font-weight: 500;">
        <i class="ti-alert mr-2" style="font-size: 1rem; flex-shrink: 0;"></i>
        <span><?php echo htmlspecialchars($_SESSION['login_error']); ?></span>
      </div>
      <?php unset($_SESSION['login_error']); ?>
    <?php endif; ?>

    <!-- Login Form with JS Error Validation -->
    <form action="" method="POST" id="wardenLoginForm" novalidate>
      <div class="form-group mb-2">
        <label for="username">Username / Email / Warden Code</label>
        <div class="input-wrapper">
          <input type="text" class="form-control" id="username" name="username" placeholder="Enter email or warden code" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username">
          <i class="ti-user"></i>
        </div>
        <small id="usernameError" class="error-text d-none"></small>
      </div>

      <div class="form-group mb-3">
        <label for="password">Password</label>
        <div class="input-wrapper">
          <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" autocomplete="current-password">
          <i class="ti-lock"></i>
        </div>
        <small id="passwordError" class="error-text d-none"></small>
      </div>

      <div>
        <button type="submit" class="btn btn-block btn-warden" id="submitBtn">
          <i class="ti-key mr-2"></i>Sign In to Dashboard
        </button>
      </div>
    </form>
  </div>

  <!-- Scripts -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>

  <!-- Javascript Form Validation -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const loginForm = document.getElementById('wardenLoginForm');
      const usernameInput = document.getElementById('username');
      const passwordInput = document.getElementById('password');
      const usernameError = document.getElementById('usernameError');
      const passwordError = document.getElementById('passwordError');

      // Clear error on input
      usernameInput.addEventListener('input', function () {
        if (usernameInput.value.trim() !== '') {
          usernameInput.classList.remove('is-invalid');
          usernameError.classList.add('d-none');
          usernameError.textContent = '';
        }
      });

      passwordInput.addEventListener('input', function () {
        if (passwordInput.value.trim() !== '') {
          passwordInput.classList.remove('is-invalid');
          passwordError.classList.add('d-none');
          passwordError.textContent = '';
        }
      });

      // Validate on submit
      loginForm.addEventListener('submit', function (e) {
        let isValid = true;
        const usernameVal = usernameInput.value.trim();
        const passwordVal = passwordInput.value.trim();

        // Validate Username
        if (usernameVal === '') {
          usernameInput.classList.add('is-invalid');
          usernameError.textContent = 'Please enter your username, email, or warden code.';
          usernameError.classList.remove('d-none');
          isValid = false;
        } else {
          usernameInput.classList.remove('is-invalid');
          usernameError.classList.add('d-none');
          usernameError.textContent = '';
        }

        // Validate Password
        if (passwordVal === '') {
          passwordInput.classList.add('is-invalid');
          passwordError.textContent = 'Please enter your password.';
          passwordError.classList.remove('d-none');
          isValid = false;
        } else {
          passwordInput.classList.remove('is-invalid');
          passwordError.classList.add('d-none');
          passwordError.textContent = '';
        }

        if (!isValid) {
          e.preventDefault();
          // Focus the first invalid input
          if (usernameVal === '') {
            usernameInput.focus();
          } else if (passwordVal === '') {
            passwordInput.focus();
          }
        }
      });
    });
  </script>
</body>
</html>
