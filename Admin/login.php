<?php
session_start();
if (isset($_SESSION['admin_id'])) {
    header("Location: home.php");
    exit();
}

require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, name, password, role FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password']) || $password === $user['password']) {
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_name'] = $user['name'];
                    
                    $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    if ($update_stmt) {
                        $update_stmt->bind_param("i", $user['id']);
                        $update_stmt->execute();
                    }
                    
                    $_SESSION['login_success'] = "Welcome back, " . $user['name'] . "!";
                    header("Location: home.php");
                    exit();
                } else {
                    $_SESSION['login_error'] = "Invalid password.";
                }
            } else {
                $_SESSION['login_error'] = "Admin user not found with that email.";
            }
            $stmt->close();
        } else {
            $_SESSION['login_error'] = "Database error: Failed to prepare statement.";
        }
    } else {
        $_SESSION['login_error'] = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>HOSTEL MATE - Admin Login</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lykmapipo/themify-icons@0.1.2/css/themify-icons.css">
  <link rel="stylesheet" href="../assets/css/vertical-layout-light/style.css">
  <link rel="shortcut icon" href="../img/fav-admin.png" />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --admin-primary: #2563eb;
      --admin-primary-hover: #1d4ed8;
      --admin-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 50%, #3b82f6 100%);
      --admin-bg-gradient: linear-gradient(135deg, #090d16 0%, #0f172a 45%, #1e293b 100%);
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
      background: var(--admin-bg-gradient) !important;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      overflow-x: hidden;
      margin: 0;
      padding: 1.5rem;
    }

    /* Ambient glowing background accents */
    body::before {
      content: "";
      position: absolute;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(37, 99, 235, 0.22) 0%, rgba(15, 23, 42, 0) 70%);
      top: -120px;
      right: -100px;
      border-radius: 50%;
      pointer-events: none;
    }

    body::after {
      content: "";
      position: absolute;
      width: 450px;
      height: 450px;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, rgba(9, 13, 22, 0) 70%);
      bottom: -100px;
      left: -100px;
      border-radius: 50%;
      pointer-events: none;
    }
    
    .auth-card {
      border-radius: 24px !important;
      box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.6) !important;
      padding: 3rem 2.5rem !important;
      background: rgba(255, 255, 255, 0.98) !important;
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.3) !important;
      position: relative;
      z-index: 10;
      width: 100%;
      max-width: 440px;
      margin: 0 auto;
    }

    .admin-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.35rem 0.9rem;
      border-radius: 50px;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      background: rgba(37, 99, 235, 0.12);
      color: #2563eb;
      border: 1px solid rgba(37, 99, 235, 0.2);
      margin-bottom: 1rem;
    }

    .brand-title {
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.5px;
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
      font-size: 1.05rem;
      pointer-events: none;
      transition: color 0.2s ease;
    }

    .form-control {
      border-radius: 12px !important;
      border: 1.5px solid #e2e8f0 !important;
      padding: 0.82rem 1.15rem 0.82rem 2.85rem !important;
      height: auto !important;
      font-size: 0.95rem !important;
      color: #1e293b !important;
      background-color: #ffffff !important;
      transition: all 0.25s ease !important;
    }
    
    .form-control:focus {
      border-color: var(--admin-primary) !important;
      box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15) !important;
      background-color: #ffffff !important;
    }
    
    .btn-login {
      border-radius: 12px !important;
      font-weight: 700 !important;
      padding: 0.85rem 1.5rem !important;
      background: var(--admin-gradient) !important;
      border: none !important;
      box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35) !important;
      transition: all 0.25s ease !important;
      color: #ffffff !important;
    }
    
    .btn-login:hover {
      transform: translateY(-2px) !important;
      box-shadow: 0 12px 25px rgba(37, 99, 235, 0.45) !important;
      background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%) !important;
    }

    .footer-link {
      color: #64748b;
      font-size: 0.84rem;
      text-decoration: none;
      transition: color 0.2s ease;
    }

    .footer-link:hover {
      color: #2563eb;
      text-decoration: none;
    }

    /* Responsive Mobile Rules 
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
      .btn-login {
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
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-md-6 col-lg-5">
        <div class="auth-card text-left">
          <div class="text-center mb-4">
            <img src="../img/logo-admin.png" alt="logo" style="width: 180px; height: auto;" class="mb-3">
            <div>
              <span class="admin-badge">
                <i class="ti-settings mr-1"></i> Admin Portal
              </span>
            </div>
            <h4 class="brand-title mb-1">Welcome Back</h4>
            <p class="text-muted font-weight-medium" style="font-size: 0.9rem;">Sign in to access your Admin Dashboard</p>
          </div>
          
          <?php if (isset($_SESSION['login_error'])): ?>
            <div class="alert alert-danger border-0 rounded-12 shadow-sm" role="alert" style="background: rgba(244, 63, 94, 0.12); color: #e11d48; font-weight: 500;">
              <i class="ti-alert mr-2"></i><?php echo $_SESSION['login_error']; ?>
            </div>
            <?php unset($_SESSION['login_error']); ?>
          <?php endif; ?>

          <form id="loginForm" method="POST" action="" class="pt-2">
            <div class="form-group mb-3">
              <label class="font-weight-bold text-dark" style="font-size: 0.85rem;">Email Address</label>
              <div class="input-wrapper">
                <input type="email" class="form-control" id="email" name="email" placeholder="admin@example.com">
                <i class="ti-email"></i>
              </div>
              <small id="emailError" class="text-danger d-none mt-1" style="font-size: 13px;">Email is required</small>
            </div>
            
            <div class="form-group mb-4">
              <label class="font-weight-bold text-dark" style="font-size: 0.85rem;">Password</label>
              <div class="input-wrapper">
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••">
                <i class="ti-lock"></i>
              </div>
              <small id="passwordError" class="text-danger d-none mt-1" style="font-size: 13px;">Password is required</small>
            </div>
            
            <div class="mt-4">
              <button type="submit" class="btn btn-block btn-login">
                <i class="ti-lock mr-2"></i>Sign In to Dashboard
              </button>
            </div>               
          </form>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
  <script>
    setTimeout(function() {
      $('.alert').fadeOut('slow');
    }, 3000);

    document.getElementById('loginForm').addEventListener('submit', function(e) {
      let email = document.getElementById('email').value.trim();
      let password = document.getElementById('password').value.trim();
      let isValid = true;
      
      if (!email) {
        document.getElementById('emailError').classList.remove('d-none');
        isValid = false;
      } else {
        document.getElementById('emailError').classList.add('d-none');
      }
      
      if (!password) {
        document.getElementById('passwordError').classList.remove('d-none');
        isValid = false;
      } else {
        document.getElementById('passwordError').classList.add('d-none');
      }
      
      if (!isValid) {
        e.preventDefault();
      }
    });
  </script>
</body>
</html>
