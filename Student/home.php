<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$student_id = intval($_SESSION['student_id']);

// Auto-create notices table if not existing
$create_table_sql = "CREATE TABLE IF NOT EXISTS `notices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `created_by` INT DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `attachment` VARCHAR(255) DEFAULT NULL,
    `priority` VARCHAR(50) NOT NULL DEFAULT 'normal',
    `publish_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME DEFAULT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'published',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_publish` (`publish_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($create_table_sql);

// Fetch Active Published Notices
$notices_query = $conn->query("SELECT * FROM `notices` WHERE `status` = 'published' AND (`publish_at` IS NULL OR `publish_at` <= NOW()) AND (`expires_at` IS NULL OR `expires_at` >= NOW()) ORDER BY FIELD(`priority`, 'urgent', 'high', 'normal', 'low'), `created_at` DESC");

$notices = [];
if ($notices_query && $notices_query->num_rows > 0) {
    while ($row = $notices_query->fetch_assoc()) {
        $notices[] = $row;
    }
}

// Fetch Dashboard Metrics & Real Database Statistics
// 1. Attendance Metrics
$att_res = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN `status` = 'Present' THEN 1 ELSE 0 END) as present_cnt FROM `attendance` WHERE `student_id` = {$student_id}");
$att_data = $att_res ? $att_res->fetch_assoc() : ['total' => 0, 'present_cnt' => 0];
$total_att = intval($att_data['total']);
$present_att = intval($att_data['present_cnt']);
$attendance_pct = ($total_att > 0) ? round(($present_att / $total_att) * 100, 1) : 0;

// Monthly Attendance Trend (Last 6 Months)
$monthly_labels = [];
$monthly_pcts = [];

for ($i = 5; $i >= 0; $i--) {
    $month_str = date('Y-m', strtotime("-$i months"));
    $month_label = date('M', strtotime("-$i months"));
    $monthly_labels[] = $month_label;

    $m_res = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN `status` = 'Present' THEN 1 ELSE 0 END) as present FROM `attendance` WHERE `student_id` = {$student_id} AND DATE_FORMAT(`attendance_date`, '%Y-%m') = '{$month_str}'");
    $m_data = $m_res ? $m_res->fetch_assoc() : ['total' => 0, 'present' => 0];
    $m_tot = intval($m_data['total']);
    $m_pres = intval($m_data['present']);

    $monthly_pcts[] = ($m_tot > 0) ? round(($m_pres / $m_tot) * 100, 1) : 0;
}

// 2. Complaints Status Breakdown
$comp_res = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
    SUM(CASE WHEN `status` = 'in_progress' THEN 1 ELSE 0 END) as in_progress_cnt,
    SUM(CASE WHEN `status` IN ('resolved', 'closed') THEN 1 ELSE 0 END) as resolved_cnt
    FROM `complaints` WHERE `student_id` = {$student_id}");

$comp_data = $comp_res ? $comp_res->fetch_assoc() : ['total' => 0, 'pending_cnt' => 0, 'in_progress_cnt' => 0, 'resolved_cnt' => 0];
$pending_complaints = intval($comp_data['pending_cnt']);
$in_progress_complaints = intval($comp_data['in_progress_cnt']);
$resolved_complaints = intval($comp_data['resolved_cnt']);

// 3. Visitors Status Breakdown
$vis_res = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN `status` = 'Approved' THEN 1 ELSE 0 END) as approved_cnt,
    SUM(CASE WHEN `status` = 'Pending' THEN 1 ELSE 0 END) as pending_cnt
    FROM `visitors` WHERE `student_id` = {$student_id}");
$vis_data = $vis_res ? $vis_res->fetch_assoc() : ['total' => 0, 'approved_cnt' => 0, 'pending_cnt' => 0];
$approved_visitors = intval($vis_data['approved_cnt']);

ob_start();
?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
  /* Student Dashboard Aesthetic Styling */
  .student-welcome-hero {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    border-radius: 20px;
    color: #ffffff;
    padding: 2rem 2.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 14px 30px rgba(99, 102, 241, 0.22);
    margin-bottom: 1.75rem;
  }

  .student-welcome-hero::after {
    content: "\f0a2";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 25px;
    bottom: -25px;
    font-size: 150px;
    color: rgba(255, 255, 255, 0.07);
    pointer-events: none;
  }

  /* Metric Cards Grid */
  .metric-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem 1.4rem;
    height: 100%;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
    position: relative;
    overflow: hidden;
  }

  .metric-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 24px rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.3);
  }

  .metric-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
  }

  .icon-attendance { background: #e0e7ff; color: #4338ca; }
  .icon-complaints { background: #fee2e2; color: #dc2626; }
  .icon-visitors   { background: #d1fae5; color: #059669; }
  .icon-mess       { background: #fef3c7; color: #d97706; }

  .metric-progress {
    height: 6px;
    border-radius: 10px;
    background: #e2e8f0;
    overflow: hidden;
    margin-top: 0.75rem;
  }

  .metric-progress-bar {
    height: 100%;
    border-radius: 10px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
  }

  /* Graph & Chart Cards */
  .dashboard-graph-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1.5rem;
    height: 100%;
    box-shadow: 0 3px 12px rgba(15, 23, 42, 0.03);
  }

  .chart-canvas-container {
    position: relative;
    width: 100%;
    height: 270px;
  }

  /* Notice Board Section & 4 Cards View Carousel */
  .notice-section-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 1.75rem;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
    margin-bottom: 2.25rem;
  }

  .carousel-nav-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #334155;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    transition: all 0.2s ease;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
  }

  .carousel-nav-btn:hover {
    background: #6366f1;
    border-color: #6366f1;
    color: #ffffff;
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
  }

  .carousel-nav-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
  }

  /* 4-Cards Grid View Carousel Container */
  .notice-carousel-track-wrapper {
    overflow: hidden;
    position: relative;
    width: 100%;
    padding: 6px 2px;
  }

  .notice-carousel-track {
    display: flex;
    transition: transform 0.45s cubic-bezier(0.25, 1, 0.5, 1);
    gap: 1.25rem;
  }

  /* 4 Cards View at a time */
  .notice-card-item {
    flex: 0 0 calc((100% - 3.75rem) / 4);
    max-width: calc((100% - 3.75rem) / 4);
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 3px 10px rgba(15, 23, 42, 0.04);
  }

  .notice-card-item:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 28px rgba(99, 102, 241, 0.15);
    border-color: rgba(99, 102, 241, 0.4);
  }

  .notice-card-img-wrapper {
    height: 170px;
    width: 100%;
    position: relative;
    background: linear-gradient(135deg, #3730a3 0%, #6366f1 100%);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .notice-card-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
  }

  .notice-card-item:hover .notice-card-img {
    transform: scale(1.06);
  }

  .notice-img-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.9);
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    padding: 1rem;
    text-align: center;
  }

  .notice-priority-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 0.3rem 0.7rem;
    border-radius: 50px;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.18);
    z-index: 2;
  }

  .prio-urgent { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
  .prio-high   { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
  .prio-normal { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
  .prio-low    { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

  .notice-card-body {
    padding: 1.15rem 1.25rem;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    background: #ffffff;
  }

  .notice-card-date {
    font-size: 0.76rem;
    font-weight: 600;
    color: #6366f1;
    margin-bottom: 0.45rem;
    display: flex;
    align-items: center;
    gap: 5px;
  }

  .notice-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 0.5rem;
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    min-height: 2.7rem;
  }

  .notice-card-desc {
    font-size: 0.84rem;
    color: #64748b;
    line-height: 1.45;
    margin-bottom: 1rem;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    flex-grow: 1;
  }

  .notice-card-footer {
    border-top: 1px solid #f1f5f9;
    padding-top: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: auto;
  }

  .btn-read-more {
    color: #6366f1;
    font-size: 0.82rem;
    font-weight: 700;
    padding: 0;
    background: none;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: color 0.2s ease;
  }

  .btn-read-more:hover {
    color: #4f46e5;
    text-decoration: underline;
  }

  @media (max-width: 1199.98px) {
    .notice-card-item {
      flex: 0 0 calc((100% - 2.5rem) / 3);
      max-width: calc((100% - 2.5rem) / 3);
    }
  }

  @media (max-width: 767.98px) {
    .notice-card-item {
      flex: 0 0 calc((100% - 1.25rem) / 2);
      max-width: calc((100% - 1.25rem) / 2);
    }
  }

  @media (max-width: 575.98px) {
    .notice-card-item {
      flex: 0 0 100%;
      max-width: 100%;
    }
  }

  .shortcut-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 15px;
    text-decoration: none !important;
    transition: all 0.25s ease;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
  }

  .shortcut-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.3);
  }
</style>

<!-- Welcome Hero Header -->
<div class="student-welcome-hero">
  <div class="d-flex flex-wrap justify-content-between align-items-center">
    <div>
      <div class="d-flex align-items-center mb-2">
        <span class="badge badge-pill badge-light font-weight-bold px-3 py-1 mr-2" style="color: #4338ca !important; font-size: 0.75rem; text-transform: uppercase;">
          <i class="fa-solid fa-sparkles mr-1"></i> Student Portal Dashboard
        </span>
        <span class="text-white-50" style="font-size: 0.88rem;"><?= date('l, d F Y') ?></span>
      </div>
      <h2 class="font-weight-bold text-white mb-1">Welcome back, <?= htmlspecialchars($_SESSION['student_name']) ?>!</h2>
      <p class="text-white-50 mb-0" style="font-size: 0.95rem; max-width: 620px;">
        Track your attendance trends, registered maintenance complaints, guest entry passes, and official notices below.
      </p>
    </div>
  </div>
</div>

<!-- ========================================================= -->
<!-- 1. STAT / METRIC CARDS (4 TOP METRICS)                    -->
<!-- ========================================================= -->
<div class="row mb-4">
  <!-- Card 1: Attendance Rate -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
    <div class="metric-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Attendance Rate</span>
          <h3 class="font-weight-800 text-dark mb-0"><?= $attendance_pct ?>%</h3>
        </div>
        <div class="metric-icon-box icon-attendance">
          <i class="fa-solid fa-user-check"></i>
        </div>
      </div>
      <div class="metric-progress">
        <div class="metric-progress-bar" style="width: <?= min(100, $attendance_pct) ?>%;"></div>
      </div>
      <small class="text-muted mt-2 d-block font-weight-500" style="font-size: 0.75rem;">
        <i class="fa-solid fa-circle-check text-success mr-1"></i> <?= $present_att ?> of <?= max(1, $total_att) ?> Days Verified
      </small>
    </div>
  </div>

  <!-- Card 2: Pending Complaints -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
    <div class="metric-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Pending Complaints</span>
          <h3 class="font-weight-800 text-danger mb-0"><?= $pending_complaints ?></h3>
        </div>
        <div class="metric-icon-box icon-complaints">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
      </div>
      <div class="metric-progress">
        <div class="metric-progress-bar" style="width: <?= min(100, $pending_complaints * 25) ?>%; background: linear-gradient(90deg, #ef4444, #f97316);"></div>
      </div>
      <small class="text-muted mt-2 d-block font-weight-500" style="font-size: 0.75rem;">
        <i class="fa-solid fa-clock text-warning mr-1"></i> Awaiting Warden Review
      </small>
    </div>
  </div>

  <!-- Card 3: Approved Visitor Passes -->
  <div class="col-xl-3 col-sm-6 mb-3 mb-sm-0">
    <div class="metric-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Approved Visitor Passes</span>
          <h3 class="font-weight-800 text-success mb-0"><?= $approved_visitors ?></h3>
        </div>
        <div class="metric-icon-box icon-visitors">
          <i class="fa-solid fa-id-card"></i>
        </div>
      </div>
      <div class="metric-progress">
        <div class="metric-progress-bar" style="width: 100%; background: linear-gradient(90deg, #10b981, #059669);"></div>
      </div>
      <small class="text-muted mt-2 d-block font-weight-500" style="font-size: 0.75rem;">
        <i class="fa-solid fa-shield-check text-success mr-1"></i> Valid Guest Passes Active
      </small>
    </div>
  </div>

  <!-- Card 4: Mess Plan & Meals -->
  <div class="col-xl-3 col-sm-6">
    <div class="metric-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Mess Plan Status</span>
          <h3 class="font-weight-800 text-warning mb-0">Active</h3>
        </div>
        <div class="metric-icon-box icon-mess">
          <i class="fa-solid fa-utensils"></i>
        </div>
      </div>
      <div class="metric-progress">
        <div class="metric-progress-bar" style="width: 85%; background: linear-gradient(90deg, #f59e0b, #d97706);"></div>
      </div>
      <small class="text-muted mt-2 d-block font-weight-500" style="font-size: 0.75rem;">
        <i class="fa-solid fa-calendar-day text-primary mr-1"></i> Daily Breakfast & Dinner
      </small>
    </div>
  </div>
</div>

<!-- ========================================================= -->
<!-- 2. GRAPH & CHART SECTION (1 LINE GRAPH & 1 DOUGHNUT CHART) -->
<!-- ========================================================= -->
<div class="row mb-4">
  
  <!-- GRAPH 1: Monthly Attendance Trend Line/Area Graph -->
  <div class="col-lg-7 mb-4 mb-lg-0">
    <div class="dashboard-graph-card">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h5 class="font-weight-800 text-dark mb-1" style="font-size: 1.1rem;">
            <i class="fa-solid fa-chart-line text-primary mr-2" style="color: #6366f1 !important;"></i>Monthly Attendance Trend
          </h5>
          <p class="text-muted mb-0" style="font-size: 0.82rem;">6-Month Geofence curfew compliance & verification history (%)</p>
        </div>
        <span class="badge badge-pill badge-light border text-primary font-weight-700 px-3 py-1" style="font-size: 0.75rem;">
          Avg: <?= $attendance_pct ?>%
        </span>
      </div>

      <div class="chart-canvas-container">
        <canvas id="attendanceTrendChart"></canvas>
      </div>
    </div>
  </div>

  <!-- CHART 1: Complaints & Maintenance Breakdown Doughnut Chart -->
  <div class="col-lg-5">
    <div class="dashboard-graph-card">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <h5 class="font-weight-800 text-dark mb-1" style="font-size: 1.1rem;">
            <i class="fa-solid fa-chart-pie text-primary mr-2" style="color: #8b5cf6 !important;"></i>Complaints Resolution Status
          </h5>
          <p class="text-muted mb-0" style="font-size: 0.82rem;">Status distribution of registered hostel maintenance issues</p>
        </div>
      </div>

      <div class="chart-canvas-container d-flex align-items-center justify-content-center">
        <canvas id="complaintsStatusChart"></canvas>
      </div>
    </div>
  </div>

</div>

<!-- ========================================================= -->
<!-- 3. NOTICE BOARD - 4 CARD CAROUSEL VIEW                     -->
<!-- ========================================================= -->
<div class="notice-section-card">
  
  <!-- Section Header & Carousel Controls -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-3 border-bottom">
    <div>
      <div class="d-flex align-items-center">
        <span class="badge badge-pill text-white font-weight-bold px-3 py-1 mr-2" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); font-size: 0.72rem; text-transform: uppercase;">
          <i class="fa-solid fa-bullhorn mr-1"></i> Circulars
        </span>
        <h4 class="font-weight-800 text-dark mb-0" style="font-size: 1.3rem;">Hostel Notice Board</h4>
      </div>
      <p class="text-muted mb-0 mt-1" style="font-size: 0.88rem;">
        Showing official announcements & circulars (4 cards view at a time)
      </p>
    </div>

    <!-- Previous / Next Controls -->
    <div class="d-flex align-items-center gap-2 mt-3 mt-sm-0">
      <button type="button" class="carousel-nav-btn" id="noticePrevBtn" onclick="slideNoticeCarousel(-1)" title="Previous 4 Cards">
        <i class="fa-solid fa-chevron-left"></i>
      </button>
      <button type="button" class="carousel-nav-btn" id="noticeNextBtn" onclick="slideNoticeCarousel(1)" title="Next 4 Cards">
        <i class="fa-solid fa-chevron-right"></i>
      </button>
    </div>
  </div>

  <?php if (empty($notices)): ?>
    <div class="text-center py-5">
      <div class="mb-3">
        <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 70px; height: 70px; background: rgba(99, 102, 241, 0.08); color: #6366f1; font-size: 1.8rem;">
          <i class="fa-solid fa-envelope-open"></i>
        </span>
      </div>
      <h5 class="font-weight-bold text-dark mb-1">No Active Notices</h5>
      <p class="text-muted mb-0" style="font-size: 0.9rem;">There are currently no active announcements published on the notice board.</p>
    </div>
  <?php else: ?>
    <!-- Notice Carousel Outer Track -->
    <div class="notice-carousel-track-wrapper">
      <div class="notice-carousel-track" id="noticeCarouselTrack">
        
        <?php foreach ($notices as $row): 
          $prio = strtolower($row['priority']);
          $badge_cls = 'prio-normal';
          if ($prio === 'urgent') $badge_cls = 'prio-urgent';
          elseif ($prio === 'high') $badge_cls = 'prio-high';
          elseif ($prio === 'low') $badge_cls = 'prio-low';

          $pub_date = date('d M, Y', strtotime($row['publish_at'] ?? $row['created_at']));
          
          // Image / attachment check
          $has_image = false;
          $img_src = '';
          $attachment_name = $row['attachment'] ?? '';

          if (!empty($attachment_name)) {
              $ext = strtolower(pathinfo($attachment_name, PATHINFO_EXTENSION));
              $img_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
              if (in_array($ext, $img_exts)) {
                  if (file_exists("../notice_attachments/" . $attachment_name)) {
                      $img_src = "../notice_attachments/" . $attachment_name;
                      $has_image = true;
                  } elseif (file_exists("../uploads/notice_attachments/" . $attachment_name)) {
                      $img_src = "../uploads/notice_attachments/" . $attachment_name;
                      $has_image = true;
                  }
              }
          }
        ?>

          <!-- Individual Notice Card (4 per view) -->
          <div class="notice-card-item">
            
            <!-- Top Image Banner -->
            <div class="notice-card-img-wrapper">
              <span class="notice-priority-badge <?= $badge_cls ?>"><?= htmlspecialchars(strtoupper($prio)) ?></span>
              
              <?php if ($has_image): ?>
                <img src="<?= htmlspecialchars($img_src) ?>" alt="<?= htmlspecialchars($row['title']) ?>" class="notice-card-img" />
              <?php else: ?>
                <div class="notice-img-placeholder">
                  <i class="fa-solid fa-bullhorn mb-2" style="font-size: 2.2rem;"></i>
                  <span class="font-weight-700" style="font-size: 0.88rem; letter-spacing: 0.5px;">CAMPUS NOTICE</span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Card Content Under Image -->
            <div class="notice-card-body">
              <div class="notice-card-date">
                <i class="fa-regular fa-calendar-days"></i> <?= $pub_date ?>
              </div>

              <h5 class="notice-card-title"><?= htmlspecialchars($row['title']) ?></h5>
              <p class="notice-card-desc"><?= htmlspecialchars($row['description']) ?></p>

              <div class="notice-card-footer">
                <button type="button" class="btn-read-more" onclick='openNoticeModal(<?= json_encode($row) ?>, "<?= htmlspecialchars($img_src) ?>")'>
                  View Details <i class="fa-solid fa-arrow-right ml-1"></i>
                </button>

                <?php if (!empty($attachment_name)): ?>
                  <a href="../notice_attachments/<?= htmlspecialchars($attachment_name) ?>" target="_blank" class="text-primary font-weight-600" style="font-size: 0.78rem;" title="Download Attachment">
                    <i class="fa-solid fa-paperclip mr-1"></i> File
                  </a>
                <?php endif; ?>
              </div>
            </div>

          </div>

        <?php endforeach; ?>

      </div>
    </div>
  <?php endif; ?>

</div>

<!-- Quick Shortcuts Grid -->
<div class="row">
  <div class="col-md-3 col-sm-6 mb-3">
    <a href="attendance.php" class="shortcut-card">
      <div class="shortcut-icon" style="background: #e0e7ff; color: #4338ca;">
        <i class="fa-solid fa-clipboard-user"></i>
      </div>
      <div>
        <h6 class="font-weight-bold text-dark mb-0">Daily Attendance</h6>
        <small class="text-muted">1-Click Geofence Mark</small>
      </div>
    </a>
  </div>

  <div class="col-md-3 col-sm-6 mb-3">
    <a href="food_menu.php" class="shortcut-card">
      <div class="shortcut-icon" style="background: #fef3c7; color: #d97706;">
        <i class="fa-solid fa-utensils"></i>
      </div>
      <div>
        <h6 class="font-weight-bold text-dark mb-0">Mess Food Menu</h6>
        <small class="text-muted">Weekly Breakfast/Dinner</small>
      </div>
    </a>
  </div>

  <div class="col-md-3 col-sm-6 mb-3">
    <a href="complaints.php" class="shortcut-card">
      <div class="shortcut-icon" style="background: #fee2e2; color: #dc2626;">
        <i class="fa-solid fa-triangle-exclamation"></i>
      </div>
      <div>
        <h6 class="font-weight-bold text-dark mb-0">Helpdesk & Complaints</h6>
        <small class="text-muted">Raise Hostel Maintenance</small>
      </div>
    </a>
  </div>

  <div class="col-md-3 col-sm-6 mb-3">
    <a href="visitors.php" class="shortcut-card">
      <div class="shortcut-icon" style="background: #d1fae5; color: #059669;">
        <i class="fa-solid fa-user-plus"></i>
      </div>
      <div>
        <h6 class="font-weight-bold text-dark mb-0">Visitor Pass Log</h6>
        <small class="text-muted">Guest Entry Approval</small>
      </div>
    </a>
  </div>
</div>

<!-- ========================================================= -->
<!-- FULL NOTICE DETAILS MODAL                                 -->
<!-- ========================================================= -->
<div class="modal fade" id="noticeDetailsModal" tabindex="-1" role="dialog" aria-labelledby="noticeDetailsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
      
      <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
        <div class="d-flex align-items-center">
          <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
            <i class="fa-solid fa-bullhorn"></i>
          </div>
          <div>
            <h5 class="modal-title font-weight-bold mb-0 text-white" id="noticeModalTitle">Notice Title</h5>
            <small class="text-white-50" id="noticeModalDate">Date</small>
          </div>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body p-4" style="background: #ffffff;">
        <div id="noticeModalImgContainer" class="mb-3 text-center d-none">
          <img id="noticeModalImg" src="" alt="Notice Attachment" style="max-height: 320px; max-width: 100%; border-radius: 12px; object-fit: cover;" class="shadow-sm border">
        </div>

        <div class="mb-3">
          <span id="noticeModalPriority" class="notice-priority-badge relative-badge position-static d-inline-block mb-2"></span>
          <p id="noticeModalDesc" style="white-space: pre-line; font-size: 0.95rem; color: #334155; line-height: 1.6;"></p>
        </div>

        <div id="noticeModalAttachmentLink" class="p-3 bg-light rounded-12 border d-none">
          <i class="fa-solid fa-paperclip text-primary mr-2"></i>
          <span class="font-weight-600 text-dark">Attachment Document:</span>
          <a id="noticeModalFileAnchor" href="#" target="_blank" class="ml-2 font-weight-700 text-primary">Download File</a>
        </div>
      </div>

      <div class="modal-footer bg-light border-top py-2 px-4">
        <button type="button" class="btn btn-secondary font-weight-500 rounded-10 px-4" data-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>

<script>
let currentSlideIndex = 0;
let noticeAutoScrollInterval = null;

function slideNoticeCarousel(direction) {
    const track = document.getElementById('noticeCarouselTrack');
    if (!track) return;

    const cards = track.querySelectorAll('.notice-card-item');
    if (cards.length === 0) return;

    let cardsPerView = 4;
    const windowWidth = window.innerWidth;
    if (windowWidth < 576) {
        cardsPerView = 1;
    } else if (windowWidth < 768) {
        cardsPerView = 2;
    } else if (windowWidth < 1200) {
        cardsPerView = 3;
    }

    const maxSlide = Math.max(0, cards.length - cardsPerView);

    if (direction === 1 && currentSlideIndex >= maxSlide) {
        currentSlideIndex = 0;
    } else if (direction === -1 && currentSlideIndex <= 0) {
        currentSlideIndex = maxSlide;
    } else {
        currentSlideIndex += direction * cardsPerView;
        if (currentSlideIndex < 0) currentSlideIndex = 0;
        if (currentSlideIndex > maxSlide) currentSlideIndex = maxSlide;
    }

    const cardWidth = cards[0].offsetWidth;
    const gap = 20;
    const moveAmount = (cardWidth + gap) * currentSlideIndex;

    track.style.transform = `translateX(-${moveAmount}px)`;

    const prevBtn = document.getElementById('noticePrevBtn');
    const nextBtn = document.getElementById('noticeNextBtn');

    if (prevBtn) prevBtn.disabled = (currentSlideIndex === 0);
    if (nextBtn) nextBtn.disabled = (currentSlideIndex >= maxSlide);
}

function startNoticeAutoScroll() {
    stopNoticeAutoScroll();
    noticeAutoScrollInterval = setInterval(function() {
        slideNoticeCarousel(1);
    }, 3500);
}

function stopNoticeAutoScroll() {
    if (noticeAutoScrollInterval) {
        clearInterval(noticeAutoScrollInterval);
        noticeAutoScrollInterval = null;
    }
}

function openNoticeModal(data, imgSrc) {
    document.getElementById('noticeModalTitle').innerText = data.title;
    document.getElementById('noticeModalDate').innerText = 'Published: ' + data.publish_at;
    document.getElementById('noticeModalDesc').innerText = data.description;

    const prioEl = document.getElementById('noticeModalPriority');
    prioEl.innerText = (data.priority || 'normal').toUpperCase();
    prioEl.className = 'notice-priority-badge ' + 'prio-' + (data.priority || 'normal').toLowerCase();

    const imgContainer = document.getElementById('noticeModalImgContainer');
    const imgEl = document.getElementById('noticeModalImg');

    if (imgSrc && imgSrc.length > 0) {
        imgEl.src = imgSrc;
        imgContainer.classList.remove('d-none');
    } else {
        imgContainer.classList.add('d-none');
    }

    const attachContainer = document.getElementById('noticeModalAttachmentLink');
    const attachAnchor = document.getElementById('noticeModalFileAnchor');

    if (data.attachment && data.attachment.length > 0) {
        attachAnchor.href = '../notice_attachments/' + data.attachment;
        attachAnchor.innerText = data.attachment;
        attachContainer.classList.remove('d-none');
    } else {
        attachContainer.classList.add('d-none');
    }

    $('#noticeDetailsModal').modal('show');
}

// Chart 1 Initialization: Monthly Attendance Line & Area Graph
function initAttendanceTrendGraph() {
    const ctx = document.getElementById('attendanceTrendChart');
    if (!ctx) return;

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 250);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.35)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.01)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($monthly_labels) ?>,
            datasets: [{
                label: 'Attendance Rate (%)',
                data: <?= json_encode($monthly_pcts) ?>,
                borderColor: '#6366f1',
                borderWidth: 3,
                backgroundColor: gradient,
                fill: true,
                tension: 0.38,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#4338ca',
                pointBorderWidth: 3,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    min: 0,
                    max: 100,
                    ticks: {
                        callback: function(value) { return value + '%'; },
                        font: { family: 'Plus Jakarta Sans', size: 11 },
                        color: '#64748b'
                    },
                    grid: { color: '#f1f5f9' }
                },
                x: {
                    ticks: {
                        font: { family: 'Plus Jakarta Sans', size: 11 },
                        color: '#64748b'
                    },
                    grid: { display: false }
                }
            }
        }
    });
}

// Chart 2 Initialization: Complaints Resolution Status Doughnut Chart
function initComplaintsDoughnutChart() {
    const ctx = document.getElementById('complaintsStatusChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Resolved/Closed', 'Pending Action', 'In Progress'],
            datasets: [{
                data: [<?= $resolved_complaints ?>, <?= $pending_complaints ?>, <?= $in_progress_complaints ?>],
                backgroundColor: ['#10b981', '#ef4444', '#f59e0b'],
                borderWidth: 3,
                borderColor: '#ffffff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { family: 'Plus Jakarta Sans', size: 12, weight: '600' },
                        color: '#334155',
                        usePointStyle: true,
                        padding: 18
                    }
                }
            },
            cutout: '70%'
        }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    slideNoticeCarousel(0);
    initAttendanceTrendGraph();
    initComplaintsDoughnutChart();
    startNoticeAutoScroll();

    const trackWrapper = document.querySelector('.notice-carousel-track-wrapper');
    if (trackWrapper) {
        trackWrapper.addEventListener('mouseenter', stopNoticeAutoScroll);
        trackWrapper.addEventListener('mouseleave', startNoticeAutoScroll);
    }
});

window.addEventListener("resize", function() {
    slideNoticeCarousel(0);
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
