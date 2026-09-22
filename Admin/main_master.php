<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>HOSTEL MATE - Admin Dashboard</title>
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
  <link rel="shortcut icon" href="../img/fav-admin.png" />
  <!-- Plus Jakarta Sans Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    /* =========================================================
       ADMIN MASTER DESIGN SYSTEM - ROYAL COBALT & OBSIDIAN
       ========================================================= */
    :root {
      --primary-color: #2563eb;
      --primary-hover: #1d4ed8;
      --primary-gradient: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      --primary-light: rgba(37, 99, 235, 0.08);
      --primary-glow: 0 4px 18px rgba(37, 99, 235, 0.35);
      
      --dark-sidebar: #090d16;
      --dark-sidebar-gradient: linear-gradient(180deg, #090d16 0%, #0c1222 100%);
      --dark-sidebar-hover: rgba(255, 255, 255, 0.06);
      
      --body-bg: #f8fafc;
      --card-bg: #ffffff;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      
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
      background: #cbd5e1;
      border-radius: 10px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
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
      border-bottom: 1px solid rgba(219, 234, 254, 0.9) !important;
      box-shadow: 0 4px 20px rgba(37, 99, 235, 0.04) !important;
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

    .admin-console-pill {
      background: rgba(37, 99, 235, 0.08);
      color: #2563eb;
      font-weight: 700;
      font-size: 0.72rem;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      padding: 0.28rem 0.75rem;
      border-radius: 50px;
      border: 1px solid rgba(37, 99, 235, 0.2);
    }

    .nav-profile-pill {
      background: rgba(241, 245, 249, 0.95) !important;
      border: 1.5px solid #dbeafe !important;
      padding: 0.35rem 0.45rem 0.35rem 1.35rem !important;
      border-radius: 50px !important;
      transition: all 0.25s ease !important;
      display: inline-flex !important;
      align-items: center !important;
      text-decoration: none !important;
      box-shadow: 0 2px 8px rgba(37, 99, 235, 0.04) !important;
    }

    .nav-profile-pill::after {
      display: none !important;
    }

    .nav-profile-pill:hover {
      background: #ffffff !important;
      border-color: #bfdbfe !important;
      box-shadow: 0 4px 16px rgba(37, 99, 235, 0.12) !important;
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
      background: var(--primary-gradient) !important;
      color: #ffffff !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      font-size: 1.15rem !important;
      box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3) !important;
      border: 2px solid #bfdbfe !important;
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

    /* Midnight Obsidian Sidebar */
    .sidebar {
      background: var(--dark-sidebar-gradient) !important;
      box-shadow: 4px 0 25px rgba(9, 13, 22, 0.25) !important;
      border-right: 1px solid rgba(37, 99, 235, 0.12) !important;
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
      color: #64748b;
      font-weight: 700;
      padding: 0.6rem 0.85rem 0.35rem;
      margin-top: 0.5rem;
    }

    .sidebar .nav .nav-item {
      margin: 0.2rem 0;
    }

    .sidebar .nav .nav-item .nav-link {
      border-radius: var(--radius-md) !important;
      color: #94a3b8 !important;
      padding: 0.82rem 1rem !important;
      font-weight: 500 !important;
      font-size: 0.92rem !important;
      transition: all 0.25s ease !important;
      display: flex !important;
      align-items: center !important;
    }

    .sidebar .nav .nav-item .nav-link i.menu-icon {
      color: #60a5fa !important;
      font-size: 1.15rem !important;
      margin-right: 0.85rem !important;
      transition: all 0.25s ease !important;
    }

    .sidebar .nav .nav-item .nav-link:hover {
      background: var(--dark-sidebar-hover) !important;
      color: #ffffff !important;
      transform: translateX(3px);
    }

    .sidebar .nav .nav-item .nav-link:hover i.menu-icon {
      color: #93c5fd !important;
      transform: scale(1.1);
    }

    /* Active Nav Item */
    .sidebar .nav .nav-item.active > .nav-link {
      background: var(--primary-gradient) !important;
      color: #ffffff !important;
      box-shadow: var(--primary-glow) !important;
      font-weight: 600 !important;
    }

    .sidebar .nav .nav-item.active > .nav-link i.menu-icon {
      color: #ffffff !important;
    }

    .sidebar .nav .sub-menu {
      padding: 0.35rem 0 0.35rem 1rem !important;
      background: transparent !important;
    }

    .sidebar .nav .sub-menu .nav-item .nav-link {
      padding: 0.55rem 0.95rem !important;
      font-size: 0.85rem !important;
      color: #94a3b8 !important;
      border-radius: 8px !important;
    }

    .sidebar .nav .sub-menu .nav-item .nav-link.active {
      color: #60a5fa !important;
      font-weight: 600 !important;
      background: rgba(37, 99, 235, 0.16) !important;
    }

    /* Sidebar Footer Widget */
    .sidebar-footer-box {
      margin: 1.5rem 0.65rem 0.5rem;
      padding: 1rem;
      background: rgba(37, 99, 235, 0.08);
      border: 1px solid rgba(59, 130, 246, 0.18);
      border-radius: var(--radius-md);
    }

    /* Modern Cards */
    .card {
      border: 1px solid rgba(226, 232, 240, 0.9) !important;
      border-radius: var(--radius-lg) !important;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04) !important;
      background: var(--card-bg) !important;
      transition: transform 0.25s ease, box-shadow 0.25s ease !important;
    }

    .hover-lift:hover {
      transform: translateY(-3px) !important;
      box-shadow: 0 15px 35px rgba(15, 23, 42, 0.08) !important;
    }

    .card .card-title {
      color: var(--text-main) !important;
      font-weight: 700 !important;
      font-size: 1.15rem !important;
      letter-spacing: -0.2px;
    }

    /* Stat Cards Shape */
    .stat-icon-shape {
      width: 52px;
      height: 52px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }

    .rounded-14 { border-radius: 14px !important; }
    .rounded-16 { border-radius: 16px !important; }
    .rounded-12 { border-radius: 12px !important; }

    /* Badges */
    .badge-primary-soft { background-color: rgba(37, 99, 235, 0.12) !important; color: #2563eb !important; border-radius: 8px; font-weight: 600; padding: 0.45em 0.85em; }
    .badge-success-soft { background-color: rgba(16, 185, 129, 0.12) !important; color: #059669 !important; border-radius: 8px; font-weight: 600; padding: 0.45em 0.85em; }
    .badge-warning-soft { background-color: rgba(245, 158, 11, 0.12) !important; color: #d97706 !important; border-radius: 8px; font-weight: 600; padding: 0.45em 0.85em; }
    .badge-danger-soft { background-color: rgba(244, 63, 94, 0.12) !important; color: #e11d48 !important; border-radius: 8px; font-weight: 600; padding: 0.45em 0.85em; }
    .badge-info-soft { background-color: rgba(2, 132, 199, 0.12) !important; color: #0284c7 !important; border-radius: 8px; font-weight: 600; padding: 0.45em 0.85em; }

    .bg-primary-soft { background-color: rgba(37, 99, 235, 0.12) !important; }
    .bg-info-soft { background-color: rgba(2, 132, 199, 0.12) !important; }
    .bg-success-soft { background-color: rgba(16, 185, 129, 0.12) !important; }
    .bg-warning-soft { background-color: rgba(245, 158, 11, 0.12) !important; }
    .bg-danger-soft { background-color: rgba(244, 63, 94, 0.12) !important; }

    /* Form Controls */
    .form-control, select.form-control {
      border-radius: var(--radius-sm) !important;
      border: 1px solid var(--border-color) !important;
      padding: 0.65rem 1rem !important;
      height: auto !important;
      font-size: 0.92rem !important;
      color: var(--text-main) !important;
      background-color: #ffffff !important;
      transition: all 0.2s ease !important;
    }

    .form-control:focus {
      border-color: var(--primary-color) !important;
      box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15) !important;
      background-color: #ffffff !important;
    }

    /* Buttons */
    .btn {
      border-radius: var(--radius-sm) !important;
      font-weight: 600 !important;
      padding: 0.6rem 1.25rem !important;
      transition: all 0.25s ease !important;
      font-size: 0.9rem !important;
    }

    .btn-primary {
      background: var(--primary-gradient) !important;
      border: none !important;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25) !important;
      color: #ffffff !important;
    }

    .btn-primary:hover {
      transform: translateY(-1px) !important;
      box-shadow: 0 6px 18px rgba(37, 99, 235, 0.35) !important;
      background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%) !important;
    }

    .btn-secondary {
      background-color: #e2e8f0 !important;
      color: #475569 !important;
      border: none !important;
    }

    .btn-secondary:hover {
      background-color: #cbd5e1 !important;
      color: #1e293b !important;
    }

    /* Table Action Buttons */
    .table .btn-sm {
      padding: 0.55rem 0.75rem !important;
      border-radius: 8px !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      line-height: 1 !important;
    }

    .table .btn-sm i {
      font-size: 0.95rem !important;
      margin: 0 !important;
    }

    /* =========================================================
       TABLE SCROLLING & RESPONSIVE DATA VIEWING SYSTEM
       ========================================================= */
    .table-responsive {
      display: block !important;
      width: 100% !important;
      overflow-x: auto !important;
      overflow-y: auto !important;
      max-height: 72vh !important; /* Top-to-bottom scrolling for large dataset */
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
      background: #f1f5f9 !important;
      border-radius: 6px !important;
    }
    .table-responsive::-webkit-scrollbar-thumb {
      background: #94a3b8 !important;
      border-radius: 6px !important;
    }
    .table-responsive::-webkit-scrollbar-thumb:hover {
      background: #64748b !important;
    }

    .table-responsive > .table {
      margin-bottom: 0 !important;
      width: 100% !important;
      min-width: 750px !important; /* Ensures full horizontal scroll to view all data columns */
    }

    .table th, .table td {
      vertical-align: middle !important;
      padding: 0.95rem 1rem !important;
      border-top: 1px solid #f1f5f9 !important;
      font-size: 0.88rem !important;
      white-space: nowrap !important; /* Prevents text crunching */
    }

    .table thead th {
      background-color: #f8fafc !important;
      border-bottom: 2px solid #e2e8f0 !important;
      color: #475569 !important;
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
      background-color: #f8fafc !important;
    }

    .dataTables_wrapper {
      width: 100% !important;
      position: relative;
    }

    /* DataTables Controls */
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: var(--primary-gradient) !important;
      color: #fff !important;
      border: none !important;
      border-radius: 8px !important;
    }

    .dataTables_wrapper .dataTables_filter input {
      border-radius: 8px !important;
      border: 1px solid #e2e8f0 !important;
      padding: 0.4rem 0.8rem !important;
    }

    /* Modals */
    .modal-content {
      border: none !important;
      border-radius: var(--radius-xl) !important;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.15) !important;
      overflow: hidden;
    }

    .modal-header {
      background: #f8fafc !important;
      border-bottom: 1px solid #e2e8f0 !important;
      padding: 1.25rem 1.75rem !important;
    }

    .modal-title {
      font-weight: 700 !important;
      color: var(--text-main) !important;
      font-size: 1.15rem !important;
    }

    .modal-body {
      padding: 1.75rem !important;
    }

    .modal-footer {
      background: #f8fafc !important;
      border-top: 1px solid #e2e8f0 !important;
      padding: 1.15rem 1.75rem !important;
    }

    /* SweetAlert */
    .custom-swal-popup, .swal2-popup {
      border-radius: 20px !important;
      padding: 2.25rem 2rem !important;
      font-family: 'Plus Jakarta Sans', sans-serif !important;
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
          <img src="../img/logo-h-admin.png" class="mr-2" alt="logo" style="width: 140px; height: auto; max-width: none;"/>
        </a>
      </div>
      <div class="navbar-menu-wrapper d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center">
          <button class="navbar-toggler navbar-toggler align-self-center mr-3" type="button" data-toggle="minimize" title="Toggle Sidebar">
            <span class="ti-menu" style="color: #2563eb; font-size: 1.25rem;"></span>
          </button>
          <span class="admin-console-pill d-none d-md-inline-flex align-items-center">
            <i class="ti-shield mr-1"></i> Admin Console
          </span>
        </div>

        <ul class="navbar-nav navbar-nav-right align-items-center">
          <li class="nav-item nav-profile dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center nav-profile-pill" href="#" data-toggle="dropdown" id="profileDropdown">
              <div class="profile-info-text d-none d-md-block">
                <span class="font-weight-bold d-block text-dark" style="font-size: 0.88rem; line-height: 1.25;"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin User') ?></span>
                <small class="text-primary font-weight-bold" style="font-size: 0.72rem; letter-spacing: 0.3px;">Administrator</small>
              </div>
              <div class="profile-avatar-circle">
                <i class="ti-user"></i>
              </div>
            </a>
            <div class="dropdown-menu dropdown-menu-right navbar-dropdown shadow-lg border-0" aria-labelledby="profileDropdown" style="margin-top: 10px; border-radius: 14px; min-width: 210px;">
              <div class="dropdown-header text-center py-3 border-bottom" style="background: #eff6ff; border-top-left-radius: 14px; border-top-right-radius: 14px;">
                <p class="font-weight-bold text-dark mb-0"><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin User') ?></p>
                <small class="text-muted">System Administrator</small>
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
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'home.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="home.php">
              <i class="ti-layout-grid2 menu-icon"></i>
              <span class="menu-title">Dashboard</span>
            </a>
          </li>

          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'warden.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="warden.php">
              <i class="ti-shield menu-icon"></i>
              <span class="menu-title">Wardens</span>
            </a>
          </li>
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'student.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="student.php">
              <i class="ti-id-badge menu-icon"></i>
              <span class="menu-title">Students</span>
            </a>
          </li>
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'food_menu.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="food_menu.php">
              <i class="fa-solid fa-utensils menu-icon"></i>
              <span class="menu-title">Food Menu</span>
            </a>
          </li>
          <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'notices.php') ? 'active' : ''; ?>">
            <a class="nav-link" href="notices.php">
              <i class="ti-announcement menu-icon"></i>
              <span class="menu-title">Notices</span>
            </a>
          </li>

          <li class="nav-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['block.php', 'floor.php', 'room.php', 'bed.php']) ? 'active' : ''; ?>">
            <a class="nav-link" data-toggle="collapse" href="#ui-hostel" aria-expanded="<?php echo in_array(basename($_SERVER['PHP_SELF']), ['block.php', 'floor.php', 'room.php', 'bed.php']) ? 'true' : 'false'; ?>" aria-controls="ui-hostel">
              <i class="ti-home menu-icon"></i>
              <span class="menu-title">Hostel Setup</span>
              <i class="menu-arrow"></i>
            </a>
            <div class="collapse <?php echo in_array(basename($_SERVER['PHP_SELF']), ['block.php', 'floor.php', 'room.php', 'bed.php']) ? 'show' : ''; ?>" id="ui-hostel">
              <ul class="nav flex-column sub-menu">
                <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'block.php') ? 'active' : ''; ?>"> <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'block.php') ? 'active' : ''; ?>" href="block.php">Block</a></li>
                <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'floor.php') ? 'active' : ''; ?>"> <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'floor.php') ? 'active' : ''; ?>" href="floor.php">Floor</a></li>
                <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'room.php') ? 'active' : ''; ?>"> <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'room.php') ? 'active' : ''; ?>" href="room.php">Room</a></li>
                <li class="nav-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bed.php') ? 'active' : ''; ?>"> <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'bed.php') ? 'active' : ''; ?>" href="bed.php">Bed</a></li>
              </ul>
            </div>
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
              <i class="ti-check mr-2"></i><?php echo $_SESSION['login_success']; ?>
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
