<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>HOSTEL MATE - Warden Portal</title>
  <!-- Bootstrap 4.6 CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
  <!-- Themify Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lykmapipo/themify-icons@0.1.2/css/themify-icons.css">
  <!-- FontAwesome 6 Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <!-- DataTables -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../assets/js/select.dataTables.min.css">
  <link rel="stylesheet" href="../assets/css/vertical-layout-light/style.css">
  <link rel="shortcut icon" href="../img/fav-warden.png" />
  <!-- Google Fonts: Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    /* =========================================================
       WARDEN MASTER DESIGN SYSTEM - EMERALD & FOREST OBSIDIAN
       ========================================================= */
    :root {
      --warden-primary: #059669;
      --warden-primary-dark: #047857;
      --warden-accent: #10b981;
      --warden-gradient: linear-gradient(135deg, #059669 0%, #0d9488 100%);
      --warden-glow: 0 4px 18px rgba(5, 150, 105, 0.4);
      --warden-soft-bg: rgba(5, 150, 105, 0.08);
      
      --sidebar-dark-bg: #03201a;
      --sidebar-dark-gradient: linear-gradient(180deg, #03201a 0%, #063c32 100%);
      --sidebar-hover-bg: rgba(255, 255, 255, 0.06);
      
      --body-bg: #f4fbf7;
      --card-bg: #ffffff;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-color: #d1fae5;
      
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --radius-xl: 20px;
    }

    * {
      box-sizing: border-box;
    }

    /* Custom Modern Scrollbar */
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }
    ::-webkit-scrollbar-track {
      background: transparent;
    }
    ::-webkit-scrollbar-thumb {
      background: #a7f3d0;
      border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #6ee7b7;
    }

    html, body {
      max-width: 100% !important;
      overflow-x: hidden !important;
      position: relative;
    }

    .container-scroller {
      overflow-x: hidden !important;
      max-width: 100% !important;
      position: relative;
    }

    body, .content-wrapper, .page-body-wrapper {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
      background-color: var(--body-bg) !important;
      color: var(--text-main) !important;
      -webkit-font-smoothing: antialiased;
    }

    .page-body-wrapper {
      padding-top: 70px !important;
      min-height: calc(100vh - 70px);
      padding-left: 0 !important;
      padding-right: 0 !important;
      max-width: 100% !important;
      overflow-x: hidden !important;
    }

    .main-panel {
      max-width: calc(100% - 255px) !important;
      overflow-x: hidden !important;
    }

    @media (max-width: 991.98px) {
      .main-panel {
        max-width: 100% !important;
        width: 100% !important;
      }
    }

    .content-wrapper {
      padding: 1.75rem 2rem !important;
      background-color: var(--body-bg) !important;
      max-width: 100% !important;
      overflow-x: hidden !important;
    }

    /* Top Navbar */
    .navbar {
      background: rgba(255, 255, 255, 0.95) !important;
      backdrop-filter: blur(16px) !important;
      -webkit-backdrop-filter: blur(16px) !important;
      border-bottom: 1px solid rgba(167, 243, 208, 0.9) !important;
      box-shadow: 0 4px 20px rgba(5, 150, 105, 0.04) !important;
      height: 70px !important;
    }

    .navbar .navbar-brand-wrapper {
      background: transparent !important;
      border-right: none !important;
      height: 70px !important;
      width: 255px !important;
      min-width: 255px !important;
      padding: 0 1.5rem !important;
    }

    .navbar .navbar-menu-wrapper {
      background: transparent !important;
      box-shadow: none !important;
      height: 70px !important;
      width: calc(100% - 255px) !important;
      padding: 0 2rem 0 1.5rem !important;
    }

    .warden-tag {
      background: rgba(5, 150, 105, 0.08);
      color: #059669;
      font-weight: 700;
      font-size: 0.72rem;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      padding: 0.28rem 0.75rem;
      border-radius: 50px;
      border: 1px solid rgba(5, 150, 105, 0.2);
    }

    .nav-profile-pill {
      background: rgba(240, 253, 244, 0.95) !important;
      border: 1.5px solid #d1fae5 !important;
      padding: 0.35rem 0.45rem 0.35rem 1.35rem !important;
      border-radius: 50px !important;
      transition: all 0.25s ease !important;
      display: inline-flex !important;
      align-items: center !important;
      text-decoration: none !important;
      box-shadow: 0 2px 8px rgba(5, 150, 105, 0.04) !important;
    }

    .nav-profile-pill::after {
      display: none !important;
    }

    .nav-profile-pill:hover {
      background: #ffffff !important;
      border-color: #a7f3d0 !important;
      box-shadow: 0 4px 16px rgba(5, 150, 105, 0.12) !important;
    }

    .profile-info-text {
      padding-right: 0.9rem !important;
      text-align: right !important;
    }

    .profile-avatar-circle {
      width: 40px !important;
      height: 40px !important;
      min-width: 40px !important;
      min-height: 40px !important;
      border-radius: 50% !important;
      background: var(--warden-gradient) !important;
      color: #ffffff !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-size: 1.15rem !important;
      box-shadow: 0 4px 10px rgba(5, 150, 105, 0.3) !important;
      border: 2px solid #a7f3d0 !important;
      flex-shrink: 0 !important;
      margin: 0 !important;
      padding: 0 !important;
      line-height: 1 !important;
    }

    .profile-avatar-circle i {
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      width: 100% !important;
      height: 100% !important;
      margin: 0 !important;
      padding: 0 !important;
      line-height: 1 !important;
      vertical-align: middle !important;
    }

    /* Dark Emerald Sidebar */
    .sidebar {
      background: var(--sidebar-dark-gradient) !important;
      box-shadow: 4px 0 25px rgba(3, 32, 26, 0.25) !important;
      border-right: 1px solid rgba(16, 185, 129, 0.12) !important;
      width: 255px !important;
      min-width: 255px !important;
    }

    .sidebar .nav {
      padding: 1.25rem 0.65rem;
    }

    .sidebar-section-heading {
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: #6ee7b7;
      font-weight: 700;
      padding: 0.6rem 0.85rem 0.35rem;
      margin-top: 0.5rem;
    }

    .sidebar .nav .nav-item {
      margin: 0.2rem 0;
    }

    .sidebar .nav .nav-item .nav-link {
      border-radius: var(--radius-md) !important;
      color: #a7f3d0 !important;
      padding: 0.82rem 1rem !important;
      font-weight: 500 !important;
      font-size: 0.92rem !important;
      transition: all 0.25s ease !important;
      display: flex !important;
      align-items: center !important;
    }

    .sidebar .nav .nav-item .nav-link i.menu-icon {
      color: #6ee7b7 !important;
      font-size: 1.15rem !important;
      margin-right: 0.85rem !important;
      transition: all 0.25s ease !important;
    }

    .sidebar .nav .nav-item .nav-link:hover {
      background: var(--sidebar-hover-bg) !important;
      color: #ffffff !important;
      transform: translateX(3px);
    }

    .sidebar .nav .nav-item .nav-link:hover i.menu-icon {
      color: #ffffff !important;
      transform: scale(1.1);
    }

    /* Active Nav Item */
    .sidebar .nav .nav-item.active > .nav-link {
      background: var(--warden-gradient) !important;
      color: #ffffff !important;
      box-shadow: var(--warden-glow) !important;
      font-weight: 600 !important;
    }

    .sidebar .nav .nav-item.active > .nav-link i.menu-icon {
      color: #ffffff !important;
    }

    /* Cards */
    .card {
      border: 1px solid rgba(16, 185, 129, 0.15) !important;
      border-radius: var(--radius-lg) !important;
      box-shadow: 0 10px 30px rgba(5, 150, 105, 0.04) !important;
      background: var(--card-bg) !important;
      transition: transform 0.25s ease, box-shadow 0.25s ease !important;
    }

    /* =========================================================
       WARDEN TEMPLATE BUTTON DESIGN SYSTEM (EMERALD & TEAL)
       ========================================================= */
    .btn {
      font-weight: 600;
      border-radius: 8px;
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .btn-primary {
      background: var(--warden-gradient) !important;
      border: 1px solid #059669 !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
    }

    .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
      background: linear-gradient(135deg, #047857 0%, #0f766e 100%) !important;
      border-color: #047857 !important;
      color: #ffffff !important;
      box-shadow: 0 6px 18px rgba(5, 150, 105, 0.4) !important;
      transform: translateY(-1px);
    }

    .btn-outline-primary {
      color: #059669 !important;
      border: 1.5px solid #059669 !important;
      background: transparent !important;
    }

    .btn-outline-primary:hover, .btn-outline-primary:focus, .btn-outline-primary:active {
      background: var(--warden-gradient) !important;
      border-color: #059669 !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3) !important;
      transform: translateY(-1px);
    }

    .btn-success {
      background: #10b981 !important;
      border: 1px solid #10b981 !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }

    .btn-success:hover {
      background: #059669 !important;
      border-color: #059669 !important;
      color: #ffffff !important;
      transform: translateY(-1px);
    }

    .btn-info {
      background: #0d9488 !important;
      border: 1px solid #0d9488 !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(13, 148, 136, 0.25);
    }

    .btn-info:hover {
      background: #0f766e !important;
      border-color: #0f766e !important;
      color: #ffffff !important;
      transform: translateY(-1px);
    }

    .btn-warning {
      background: #f59e0b !important;
      border: 1px solid #f59e0b !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
    }

    .btn-warning:hover {
      background: #d97706 !important;
      border-color: #d97706 !important;
      color: #ffffff !important;
      transform: translateY(-1px);
    }

    .btn-danger {
      background: #ef4444 !important;
      border: 1px solid #ef4444 !important;
      color: #ffffff !important;
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
    }

    .btn-danger:hover {
      background: #dc2626 !important;
      border-color: #dc2626 !important;
      color: #ffffff !important;
      transform: translateY(-1px);
    }

    .btn-secondary {
      background: #64748b !important;
      border: 1px solid #64748b !important;
      color: #ffffff !important;
    }

    .btn-secondary:hover {
      background: #475569 !important;
      border-color: #475569 !important;
      color: #ffffff !important;
    }

    .page-item.active .page-link {
      background-color: #059669 !important;
      border-color: #059669 !important;
      color: #ffffff !important;
      box-shadow: 0 2px 8px rgba(5, 150, 105, 0.3) !important;
    }

    .page-link {
      color: #059669 !important;
    }

    .page-link:hover {
      color: #047857 !important;
      background-color: #ecfdf5 !important;
    }

    /* =========================================================
       TABLE SCROLLING & RESPONSIVE DATA VIEWING SYSTEM
       ========================================================= */
    .table-responsive {
      display: block !important;
      width: 100% !important;
      overflow-x: auto !important;
      overflow-y: auto !important;
      max-height: 72vh !important; /* Top-to-bottom scrolling for large datasets */
      -webkit-overflow-scrolling: touch !important;
      border-radius: var(--radius-md) !important;
      border: 1px solid #e2e8f0 !important;
      margin-bottom: 1rem;
      position: relative;
    }

    /* Table Scrollbar Styling (Horizontal & Vertical) */
    .table-responsive::-webkit-scrollbar {
      width: 8px !important;
      height: 8px !important;
      display: block !important;
    }
    .table-responsive::-webkit-scrollbar-track {
      background: #f0fdf4 !important;
      border-radius: 6px !important;
    }
    .table-responsive::-webkit-scrollbar-thumb {
      background: #6ee7b7 !important;
      border-radius: 6px !important;
    }
    .table-responsive::-webkit-scrollbar-thumb:hover {
      background: #059669 !important;
    }

    .table-responsive > .table {
      margin-bottom: 0 !important;
      width: 100% !important;
      min-width: 750px !important; /* Ensures full horizontal scroll to view all data columns */
    }

    .table th, .table td {
      vertical-align: middle !important;
      padding: 0.95rem 1rem !important;
      border-top: 1px solid #f0fdf4 !important;
      font-size: 0.88rem !important;
      white-space: nowrap !important; /* Prevents text crunching */
    }

    .table thead th {
      background-color: #f0fdf4 !important;
      border-bottom: 2px solid #a7f3d0 !important;
      color: #065f46 !important;
      font-weight: 700 !important;
      text-transform: uppercase;
      font-size: 0.74rem !important;
      letter-spacing: 0.6px;
      position: sticky !important;
      top: 0 !important;
      z-index: 2 !important;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05) !important;
    }

    .table tbody tr {
      transition: background-color 0.2s ease !important;
    }

    .table tbody tr:hover {
      background-color: #f0fdf4 !important;
    }

    .dataTables_wrapper {
      width: 100% !important;
      position: relative;
    }

    /* =========================================================
       GLOBAL RESPONSIVE SYSTEM (MOBILE, TABLET, DESKTOP)
       ========================================================= */
    @media (max-width: 991.98px) {
      .navbar .navbar-brand-wrapper {
        width: auto !important;
        padding-left: 55px !important;
        margin-right: auto;
      }
      .navbar .navbar-brand-wrapper .brand-logo img {
        width: 120px !important;
        height: auto !important;
      }
      .navbar .navbar-menu-wrapper {
        width: auto !important;
        padding-left: 10px !important;
        padding-right: 15px !important;
      }
      .navbar .navbar-toggler-right.d-lg-none {
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        padding-left: 14px;
        padding-right: 14px;
        border: none;
        z-index: 10;
        background: transparent;
      }
      .content-wrapper {
        padding: 1.25rem 1rem !important;
      }
      .sidebar-offcanvas {
        position: fixed;
        max-height: calc(100vh - 70px);
        top: 70px;
        bottom: 0;
        overflow: auto;
        right: -260px;
        -webkit-transition: all 0.25s ease-out;
        -moz-transition: all 0.25s ease-out;
        -ms-transition: all 0.25s ease-out;
        -o-transition: all 0.25s ease-out;
        transition: all 0.25s ease-out;
        z-index: 1040;
      }
      .sidebar-offcanvas.active {
        right: 0;
      }
      .card-body {
        padding: 1.25rem 1rem !important;
      }
    }

    @media (max-width: 767.98px) {
      .content-wrapper {
        padding: 1rem 0.75rem !important;
      }
      .navbar {
        height: 62px !important;
      }
      .page-body-wrapper {
        padding-top: 62px !important;
        min-height: calc(100vh - 62px);
      }
      .sidebar-offcanvas {
        top: 62px;
        max-height: calc(100vh - 62px);
      }
      .table-responsive {
        border-radius: 12px;
        -webkit-overflow-scrolling: touch;
      }
      .btn {
        font-size: 0.82rem !important;
      }
      .modal-dialog {
        margin: 0.75rem !important;
      }
      .dataTables_wrapper .dataTables_filter,
      .dataTables_wrapper .dataTables_length {
        text-align: left !important;
        margin-bottom: 0.5rem;
      }
      .dataTables_wrapper .dataTables_paginate {
        text-align: center !important;
        margin-top: 0.75rem;
      }
    }

    @media (max-width: 575.98px) {
      .navbar-brand-wrapper .brand-logo img {
        width: 105px !important;
      }
      .profile-info {
        display: none !important;
      }
      .card {
        border-radius: 14px !important;
      }
      .card-body {
        padding: 1rem 0.85rem !important;
      }
      .modal-content {
        border-radius: 16px !important;
      }
      .modal-header, .modal-body, .modal-footer {
        padding: 1rem !important;
      }
    }
  </style>
</head>
<body>
  <div class="container-scroller">
    <!-- Navbar -->
    <nav class="navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
      <div class="text-center navbar-brand-wrapper d-flex align-items-center justify-content-center">
        <a class="navbar-brand brand-logo mr-3" href="home.php">
          <img src="../img/logo-h-warden.png" class="mr-2" alt="logo" style="width: 140px; height: auto; max-width: none;"/>
        </a>
      </div>
      <div class="navbar-menu-wrapper d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <button class="navbar-toggler navbar-toggler align-self-center mr-3" type="button" data-toggle="minimize" title="Toggle Sidebar">
            <span class="ti-menu" style="color: #059669; font-size: 1.25rem;"></span>
          </button>
          <span class="warden-tag d-none d-md-inline-flex align-items-center">
            <i class="ti-shield mr-1"></i> Warden Desk
          </span>
        </div>

        <ul class="navbar-nav navbar-nav-right align-items-center">
          <li class="nav-item nav-profile dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center nav-profile-pill" href="#" data-toggle="dropdown" id="profileDropdown">
              <div class="profile-info-text d-none d-md-block">
                <span class="font-weight-bold d-block text-dark" style="font-size: 0.88rem; line-height: 1.25;"><?= htmlspecialchars($_SESSION['warden_name'] ?? 'Hostel Warden') ?></span>
                <small class="text-success font-weight-bold" style="font-size: 0.72rem; letter-spacing: 0.3px;"><?= htmlspecialchars(!empty($_SESSION['warden_code']) ? $_SESSION['warden_code'] : 'Supervisory Authority') ?></small>
              </div>
              <div class="profile-avatar-circle">
                <i class="ti-shield"></i>
              </div>
            </a>
            <div class="dropdown-menu dropdown-menu-right navbar-dropdown shadow-lg border-0" aria-labelledby="profileDropdown" style="margin-top: 10px; border-radius: 14px; min-width: 210px;">
              <div class="dropdown-header text-center py-3 border-bottom" style="background: #f0fdf4; border-top-left-radius: 14px; border-top-right-radius: 14px;">
                <p class="font-weight-bold text-dark mb-0"><?= htmlspecialchars($_SESSION['warden_name'] ?? 'Hostel Warden') ?></p>
                <small class="text-muted"><?= htmlspecialchars($_SESSION['warden_email'] ?? 'warden@hostelmate.com') ?></small>
              </div>
              <a class="dropdown-item py-2 text-danger font-weight-medium" href="logout.php">
                <i class="ti-power-off text-danger mr-2"></i>
                Logout
              </a>
            </div>
          </li>
        </ul>
        <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas">
          <span class="ti-menu"></span>
        </button>
      </div>
    </nav>
    
    <!-- Page Body Wrapper -->
    <div class="container-fluid page-body-wrapper">     
      <!-- Sidebar -->
      <nav class="sidebar sidebar-offcanvas" id="sidebar">
        <ul class="nav">
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'home.php' || basename($_SERVER['PHP_SELF']) == '') ? 'active' : ''; ?>">
            <a class="nav-link" href="home.php">
              <i class="ti-layout-grid2 menu-icon"></i>
              <span class="menu-title">Dashboard</span>
            </a>
          </li>
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'student.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="student.php">
              <i class="ti-user menu-icon"></i>
              <span class="menu-title">Students</span>
            </a>
          </li>
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'food_menu.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="food_menu.php">
              <i class="fa-solid fa-utensils menu-icon"></i>
              <span class="menu-title">Food & Menu</span>
            </a>
          </li>
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'notices.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="notices.php">
              <i class="ti-announcement menu-icon"></i>
              <span class="menu-title">Notices</span>
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="logout.php">
              <i class="ti-power-off menu-icon text-danger"></i>
              <span class="menu-title text-danger">Logout</span>
            </a>
          </li>
        </ul>
      </nav>
      
      <!-- Main Panel -->
      <div class="main-panel">
        <div class="content-wrapper">
          <?php if (isset($_SESSION['login_success'])): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-12" role="alert" style="background: rgba(16, 185, 129, 0.15); color: #065f46;">
              <i class="ti-check mr-2"></i><?php echo htmlspecialchars($_SESSION['login_success']); ?>
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <?php unset($_SESSION['login_success']); ?>
          <?php endif; ?>
          <?php echo isset($content) ? $content : ''; ?>
        </div>
      </div>
    </div>   
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.9.4/Chart.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
  <script src="../assets/js/dataTables.select.min.js"></script>
  <script src="../assets/js/off-canvas.js"></script>
  <script src="../assets/js/hoverable-collapse.js"></script>
  <script src="../assets/js/template.js"></script>
  <script src="../assets/js/settings.js"></script>
  <script src="../assets/js/todolist.js"></script>
  <script src="../assets/js/dashboard.js"></script>
  <script src="../assets/js/Chart.roundedBarCharts.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    setTimeout(function() {
      $('.alert').fadeOut('slow');
    }, 3000);
  </script>
</body>
</html>
