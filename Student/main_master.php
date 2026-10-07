<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default mockup student profile for static UI display
$student_name = $_SESSION['student_name'] ?? 'Alex Morgan';
$student_email = $_SESSION['student_email'] ?? 'alex.morgan@campus.edu';
$student_role = $_SESSION['student_role'] ?? 'Student';
$student_room = $_SESSION['student_room'] ?? 'Room B-304 (North Wing)';
$student_image = !empty($_SESSION['student_image']) ? $_SESSION['student_image'] : '../img/fav-student.png';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Student Portal | HostelMate</title>
  
  <!-- Bootstrap 4.6 CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css">
  <!-- Themify Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lykmapipo/themify-icons@0.1.2/css/themify-icons.css">
  <!-- FontAwesome 6 Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <!-- DataTables -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <!-- Favicon -->
  <link rel="shortcut icon" href="../img/fav-student.png" />
  <!-- Google Fonts: Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  
  <style>
    /* =========================================================
       STUDENT PORTAL DESIGN SYSTEM - INDIGO, VIOLET & ELECTRIC SKY
       (Distinct from Admin Blue & Warden Emerald Green)
       ========================================================= */
    :root {
      --student-primary: #6366f1;
      --student-primary-hover: #4f46e5;
      --student-primary-dark: #3730a3;
      --student-accent: #8b5cf6;
      --student-accent-pink: #ec4899;
      --student-gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
      --student-glow: 0 4px 20px rgba(99, 102, 241, 0.35);
      --student-soft-bg: rgba(99, 102, 241, 0.08);
      
      --nav-bottom-bg: #111429;
      --nav-bottom-pill: #ffffff;
      --nav-bottom-pill-text: #0f172a;
      
      --body-bg: #f8fafc;
      --card-bg: #ffffff;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --radius-xl: 24px;
      --radius-full: 9999px;
    }

    * {
      box-sizing: border-box;
    }

    /* Modern Custom Scrollbar */
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

    body {
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
      background-color: var(--body-bg) !important;
      color: var(--text-main) !important;
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding-bottom: 100px; /* Space for the floating bottom navigation bar */
      max-width: 100% !important;
      overflow-x: hidden !important;
    }

    /* =========================================================
       TOP NAVBAR (EXACT REPLICA OF THE IMAGE HEADER)
       ========================================================= */
    .top-header-navbar {
      background: rgba(255, 255, 255, 0.96) !important;
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border-bottom: 1px solid rgba(226, 232, 240, 0.9);
      padding: 0.75rem 0;
      position: sticky;
      top: 0;
      z-index: 1030;
      box-shadow: 0 2px 14px rgba(15, 23, 42, 0.03);
    }

    .top-header-navbar-inner {
      max-width: 1440px;
      margin: 0 auto;
      width: 100%;
      padding: 0 2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .brand-container {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none !important;
    }

    .brand-logo-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: var(--student-gradient);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      font-size: 1.25rem;
      box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
      transition: transform 0.2s ease;
    }

    .brand-container:hover .brand-logo-icon {
      transform: scale(1.05) rotate(4deg);
    }

    .brand-name {
      font-weight: 800;
      font-size: 1.35rem;
      letter-spacing: -0.5px;
      color: #1e1b4b;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .brand-name span {
      background: var(--student-gradient);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .brand-badge {
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      padding: 2px 7px;
      border-radius: 6px;
      background: rgba(99, 102, 241, 0.12);
      color: var(--student-primary);
      margin-left: 6px;
      letter-spacing: 0.5px;
    }

    /* Top Search Bar Pill */
    .top-search-wrapper {
      position: relative;
      max-width: 420px;
      width: 100%;
    }

    .top-search-input {
      width: 100%;
      height: 42px;
      background-color: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: var(--radius-full);
      padding: 0 1.25rem 0 2.75rem;
      font-size: 0.875rem;
      font-weight: 500;
      color: #1e293b;
      transition: all 0.2s ease;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }

    .top-search-input:focus {
      outline: none;
      border-color: var(--student-primary);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
      background: #ffffff;
    }

    .top-search-icon {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.95rem;
      pointer-events: none;
    }

    .top-search-shortcut {
      position: absolute;
      right: 0.85rem;
      top: 50%;
      transform: translateY(-50%);
      font-size: 0.7rem;
      font-weight: 600;
      color: #94a3b8;
      background: #f1f5f9;
      padding: 2px 6px;
      border-radius: 4px;
      border: 1px solid #e2e8f0;
      pointer-events: none;
    }

    /* Top Controls / Action Pills */
    .header-pills-group {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .header-pill-btn {
      height: 42px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: var(--radius-full);
      padding: 0 1.15rem;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.84rem;
      font-weight: 600;
      color: #334155;
      text-decoration: none !important;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .header-pill-btn:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      color: #0f172a;
    }

    .header-pill-btn i {
      color: #64748b;
      font-size: 0.9rem;
    }

    .header-pill-btn:hover i {
      color: var(--student-primary);
    }

    /* Notification Bell */
    .header-icon-btn {
      width: 42px;
      height: 42px;
      border-radius: var(--radius-full);
      background: #ffffff;
      border: 1px solid #e2e8f0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #475569;
      position: relative;
      cursor: pointer;
      transition: all 0.2s ease;
      text-decoration: none !important;
    }

    .header-icon-btn:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      color: var(--student-primary);
      transform: translateY(-1px);
    }

    .header-icon-btn .badge-counter {
      position: absolute;
      top: -3px;
      right: -3px;
      background: #10b981;
      color: #ffffff;
      font-size: 0.65rem;
      font-weight: 700;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid #ffffff;
      box-shadow: 0 2px 4px rgba(16, 185, 129, 0.4);
    }

    /* Profile Avatar Button */
    .header-profile-btn {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: var(--radius-full);
      padding: 3px 12px 3px 4px;
      cursor: pointer;
      text-decoration: none !important;
      transition: all 0.2s ease;
    }

    .header-profile-btn:hover {
      border-color: rgba(99, 102, 241, 0.4);
      background: #f8fafc;
    }

    .header-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      object-fit: cover;
      background: rgba(99, 102, 241, 0.1);
      border: 2px solid rgba(99, 102, 241, 0.2);
    }

    .header-profile-info {
      text-align: left;
      line-height: 1.2;
    }

    .header-profile-name {
      font-size: 0.82rem;
      font-weight: 700;
      color: #0f172a;
      display: block;
    }

    .header-profile-role {
      font-size: 0.7rem;
      font-weight: 500;
      color: var(--student-primary);
      display: block;
    }

    /* =========================================================
       MAIN CONTENT CONTAINER
       ========================================================= */
    .main-student-wrapper {
      flex: 1;
      padding: 1.75rem 2rem 2rem 2rem;
      max-width: 1440px;
      margin: 0 auto;
      width: 100%;
    }

    /* =========================================================
       FLOATING BOTTOM NAVIGATION BAR (EXACT DESIGN AS IN IMAGE)
       ========================================================= */
    .bottom-floating-nav-container {
      position: fixed;
      bottom: 22px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 1050;
      width: auto;
      max-width: 95vw;
    }

    .bottom-floating-nav {
      background: #111429;
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: var(--radius-full);
      padding: 7px 10px;
      display: flex;
      align-items: center;
      gap: 6px;
      box-shadow: 0 16px 36px rgba(17, 20, 41, 0.45), 0 0 0 1px rgba(99, 102, 241, 0.2);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
    }

    .bottom-nav-item {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      padding: 10px 18px;
      border-radius: var(--radius-full);
      font-size: 0.88rem;
      font-weight: 600;
      color: #94a3b8;
      text-decoration: none !important;
      transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
      white-space: nowrap;
    }

    .bottom-nav-item i {
      font-size: 1rem;
      transition: transform 0.2s ease;
    }

    .bottom-nav-item:hover {
      color: #ffffff;
      background: rgba(255, 255, 255, 0.08);
      transform: translateY(-1px);
    }

    .bottom-nav-item:hover i {
      transform: scale(1.1);
    }

    /* ACTIVE PILL ITEM (WHITE PILL AS IN THE REFERENCE IMAGE) */
    .bottom-nav-item.active {
      background: #ffffff !important;
      color: #0f172a !important;
      font-weight: 700 !important;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
    }

    .bottom-nav-item.active i {
      color: var(--student-primary) !important;
    }

    /* =========================================================
       TABLE SCROLLING & RESPONSIVE DATA VIEWING SYSTEM
       ========================================================= */
    .table-responsive {
      display: block !important;
      width: 100% !important;
      overflow-x: auto !important;
      overflow-y: auto !important;
      max-height: 72vh !important; /* Top-to-bottom vertical scroll */
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
      background: #f8fafc !important;
      border-radius: 6px !important;
    }
    .table-responsive::-webkit-scrollbar-thumb {
      background: #c7d2fe !important;
      border-radius: 6px !important;
    }
    .table-responsive::-webkit-scrollbar-thumb:hover {
      background: var(--student-primary) !important;
    }

    .table-responsive > .table {
      margin-bottom: 0 !important;
      width: 100% !important;
      min-width: 750px !important; /* Full left-to-right horizontal scroll */
    }

    .table th, .table td {
      vertical-align: middle !important;
      padding: 0.95rem 1rem !important;
      border-top: 1px solid #f1f5f9 !important;
      font-size: 0.88rem !important;
      white-space: nowrap !important;
    }

    .table thead th {
      background-color: #f8fafc !important;
      border-bottom: 2px solid #e2e8f0 !important;
      color: #334155 !important;
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
      background-color: rgba(99, 102, 241, 0.04) !important;
    }

    .dataTables_wrapper {
      width: 100% !important;
      position: relative;
    }

    /* Dropdown custom styling */
    .custom-dropdown-menu {
      border: 1px solid #e2e8f0;
      border-radius: var(--radius-lg);
      box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12);
      padding: 8px;
      min-width: 220px;
    }

    .custom-dropdown-item {
      padding: 10px 14px;
      border-radius: var(--radius-sm);
      font-size: 0.86rem;
      font-weight: 600;
      color: #334155;
      display: flex;
      align-items: center;
      gap: 10px;
      transition: all 0.15s ease;
    }

    .custom-dropdown-item:hover {
      background: var(--student-soft-bg);
      color: var(--student-primary);
    }

    .custom-dropdown-item i {
      font-size: 1rem;
      color: #94a3b8;
      width: 18px;
      text-align: center;
    }

    .custom-dropdown-item:hover i {
      color: var(--student-primary);
    }

    /* =========================================================
       GLOBAL RESPONSIVE SYSTEM (STUDENT PORTAL)
       ========================================================= */
    @media (max-width: 1199.98px) {
      .top-search-wrapper {
        max-width: 300px;
      }
      .bottom-nav-item {
        padding: 9px 14px;
        font-size: 0.84rem;
      }
    }

    @media (max-width: 991.98px) {
      .top-header-navbar-inner {
        padding: 0 1.25rem;
      }
      .main-student-wrapper {
        padding: 1.25rem 1.25rem;
      }
      .top-search-wrapper {
        max-width: 200px;
      }
      .brand-container img {
        width: 130px !important;
      }
      .bottom-floating-nav-container {
        bottom: 14px;
        width: 95vw;
        max-width: 600px;
      }
      .bottom-floating-nav {
        padding: 6px 8px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      .bottom-nav-item {
        padding: 8px 12px;
        font-size: 0.8rem;
        gap: 6px;
      }
      .bottom-nav-item span {
        display: none;
      }
      .bottom-nav-item.active span {
        display: inline-block;
      }
    }

    @media (max-width: 767.98px) {
      body {
        padding-bottom: 90px;
      }
      .top-header-navbar-inner {
        padding: 0 1rem;
      }
      .brand-container img {
        width: 115px !important;
      }
      .main-student-wrapper {
        padding: 1rem 1rem;
      }
      .header-pills-group {
        gap: 6px;
      }
      .header-pill-btn {
        height: 36px;
        padding: 0 0.75rem;
        font-size: 0.78rem;
      }
      .header-icon-btn {
        width: 36px;
        height: 36px;
      }
      .header-avatar {
        width: 30px;
        height: 30px;
      }
      .bottom-floating-nav-container {
        bottom: 10px;
        width: calc(100% - 16px);
        max-width: 100%;
      }
      .bottom-floating-nav {
        padding: 5px 6px;
        justify-content: space-between;
        scrollbar-width: none;
      }
      .bottom-floating-nav::-webkit-scrollbar {
        display: none;
      }
      .bottom-nav-item {
        padding: 7px 10px;
        font-size: 0.78rem;
        gap: 4px;
      }
    }

    @media (max-width: 575.98px) {
      .brand-container img {
        width: 100px !important;
      }
      .top-search-wrapper {
        display: none !important;
      }
      .header-profile-btn {
        padding: 2px 6px 2px 2px;
      }
      .header-profile-name, .header-profile-role {
        display: none !important;
      }
      .bottom-nav-item i {
        font-size: 0.95rem;
      }
      .bottom-nav-item {
        padding: 6px 8px;
      }
    }
  </style>
</head>
<body>

  <!-- =========================================================
       TOP NAVBAR (ATHERMINDS STYLE)
       ========================================================= -->
  <header class="top-header-navbar">
    <div class="top-header-navbar-inner">
      
      <!-- Brand Logo like Admin and Warden side logo -->
      <a href="home.php" class="brand-container mr-3">
        <img src="../img/logo-h-student.png" alt="HostelMate Student Logo" style="width: 155px; height: auto; max-width: none;" />
      </a>

      <!-- Right Header Actions (Region, Date, Notifications, Profile) -->
      <div class="header-pills-group">

        <!-- Date / Calendar Pill -->
        <div class="header-pill-btn d-none d-lg-inline-flex" id="datePill">
          <i class="fa-regular fa-calendar"></i>
          <span>October 2026</span>
        </div>

        <!-- Notification Bell Icon Button with Badge -->
        <div class="dropdown">
          <button class="header-icon-btn" type="button" id="notifDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Notifications">
            <i class="fa-regular fa-bell"></i>
            <span class="badge-counter">1</span>
          </button>
          <div class="dropdown-menu dropdown-menu-right custom-dropdown-menu" style="width: 320px;" aria-labelledby="notifDropdown">
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
              <span class="font-weight-bold text-dark" style="font-size: 0.9rem;">Notifications</span>
              <span class="badge badge-pill badge-primary" style="background: var(--student-primary); font-size: 0.72rem;">1 New</span>
            </div>
            <div class="py-2">
              <a class="dropdown-item custom-dropdown-item py-2" href="javascript:void(0);">
                <div class="mr-2 text-primary" style="font-size: 1.2rem;"><i class="fa-solid fa-circle-check text-success"></i></div>
                <div>
                  <div class="font-weight-bold text-dark" style="font-size: 0.84rem;">Gate Pass Approved</div>
                  <div class="text-muted" style="font-size: 0.72rem;">Weekend Pass valid until 9:00 PM today</div>
                </div>
              </a>
              <a class="dropdown-item custom-dropdown-item py-2" href="javascript:void(0);">
                <div class="mr-2 text-warning" style="font-size: 1.2rem;"><i class="fa-solid fa-utensils"></i></div>
                <div>
                  <div class="font-weight-bold text-dark" style="font-size: 0.84rem;">Special Mess Dinner</div>
                  <div class="text-muted" style="font-size: 0.72rem;">Diwali Special Feast starts at 7:30 PM</div>
                </div>
              </a>
            </div>
          </div>
        </div>

        <!-- Student Profile Dropdown -->
        <div class="dropdown">
          <button class="header-profile-btn dropdown-toggle" type="button" id="profileDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <img src="<?php echo htmlspecialchars($student_image); ?>" alt="Student Avatar" class="header-avatar" onerror="this.src='https://ui-avatars.com/api/?name=Alex+Morgan&background=6366f1&color=fff';" />
            <div class="header-profile-info d-none d-md-block">
              <span class="header-profile-name"><?php echo htmlspecialchars($student_name); ?></span>
              <span class="header-profile-role"><?php echo htmlspecialchars($student_room); ?></span>
            </div>
          </button>
          <div class="dropdown-menu dropdown-menu-right custom-dropdown-menu" aria-labelledby="profileDropdown">
            <div class="px-3 py-2 border-bottom mb-2">
              <div class="font-weight-bold text-dark"><?php echo htmlspecialchars($student_name); ?></div>
              <div class="text-muted font-weight-500" style="font-size: 0.75rem;"><?php echo htmlspecialchars($student_email); ?></div>
            </div>
            <a class="dropdown-item custom-dropdown-item" href="profile.php">
              <i class="fa-regular fa-user"></i> My Profile
            </a>
            <a class="dropdown-item custom-dropdown-item" href="profile.php">
              <i class="fa-solid fa-door-open"></i> Room Details
            </a>
            <a class="dropdown-item custom-dropdown-item" href="profile.php">
              <i class="fa-solid fa-receipt"></i> Fee Receipts
            </a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item custom-dropdown-item text-danger" href="logout.php">
              <i class="fa-solid fa-arrow-right-from-bracket text-danger"></i> Logout
            </a>
          </div>
        </div>

      </div>

    </div>
  </header>

  <!-- =========================================================
       MAIN BODY CONTENT
       ========================================================= -->
  <main class="main-student-wrapper">
    <?php echo isset($content) ? $content : ''; ?>
  </main>

  <!-- =========================================================
       BOTTOM DOCKED / FLOATING PILL NAVBAR (EXACT STYLE FROM IMAGE)
       ========================================================= -->
  <div class="bottom-floating-nav-container">
    <nav class="bottom-floating-nav">
      
      <!-- Dashboard / Home (Active by default on home.php) -->
      <a href="home.php" class="bottom-nav-item <?php echo ($current_page == 'home.php' || $current_page == '') ? 'active' : ''; ?>">
        <i class="fa-solid fa-house"></i>
        <span>Dashboard</span>
      </a>

      <!-- Attendance -->
      <a href="attendance.php" class="bottom-nav-item <?php echo ($current_page == 'attendance.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-clipboard-user"></i>
        <span>Attendance</span>
      </a>

      <!-- Food Menu -->
      <a href="food_menu.php" class="bottom-nav-item <?php echo ($current_page == 'food_menu.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-utensils"></i>
        <span>Food Menu</span>
      </a>

      <!-- Visitors -->
      <a href="visitors.php" class="bottom-nav-item <?php echo ($current_page == 'visitors.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-users"></i>
        <span>Visitors</span>
      </a>
      
      <!-- Complaints -->
      <a href="complaints.php" class="bottom-nav-item <?php echo ($current_page == 'complaints.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>Complaints</span>
      </a>

      <!-- Profile -->
      <a href="profile.php" class="bottom-nav-item <?php echo ($current_page == 'profile.php') ? 'active' : ''; ?>">
        <i class="fa-regular fa-user"></i>
        <span>Profile</span>
      </a>

    </nav>
  </div>

  <!-- Scripts -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
  
  <script>
    // Search shortcut key Ctrl/Cmd + K
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchBox = document.getElementById('globalSearchInput');
        if (searchBox) searchBox.focus();
      }
    });

    // Handle bottom navigation active state switching
    document.querySelectorAll('.bottom-nav-item').forEach(item => {
      item.addEventListener('click', function() {
        if (!this.getAttribute('href').startsWith('home.php')) {
          document.querySelectorAll('.bottom-nav-item').forEach(el => el.classList.remove('active'));
          this.classList.add('active');
        }
      });
    });
  </script>
</body>
</html>
