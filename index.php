<?php
// ==========================================================================
// HOSTEL MATE - Official Brand Website
// ==========================================================================

// Attempt database connection for dynamic data
$db_connected = false;
$notices = [];
$today_menu = null;
$total_students = 1250;
$total_rooms = 450;
$total_wardens = 14;

if (file_exists(__DIR__ . '/db.php')) {
    try {
        @include_once __DIR__ . '/db.php';
        if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
            $db_connected = true;

            // Fetch published notices
            $notice_res = $conn->query("SELECT id, title, description, created_at FROM notices WHERE status = 'published' ORDER BY created_at DESC LIMIT 4");
            if ($notice_res) {
                while ($row = $notice_res->fetch_assoc()) {
                    $notices[] = $row;
                }
            }

            // Fetch today's food menu
            $day_of_week = date('l');
            $menu_stmt = $conn->prepare("SELECT * FROM food_menus WHERE menu_day = ? LIMIT 1");
            if ($menu_stmt) {
                $menu_stmt->bind_param("s", $day_of_week);
                $menu_stmt->execute();
                $menu_res = $menu_stmt->get_result();
                if ($menu_res && $menu_res->num_rows > 0) {
                    $today_menu = $menu_res->fetch_assoc();
                }
                $menu_stmt->close();
            }

            // Fetch real counts if available
            $c_stud = $conn->query("SELECT COUNT(*) AS cnt FROM students");
            if ($c_stud && ($r = $c_stud->fetch_assoc()) && $r['cnt'] > 0) {
                $total_students = $r['cnt'];
            }
            $c_room = $conn->query("SELECT COUNT(*) AS cnt FROM rooms");
            if ($c_room && ($r = $c_room->fetch_assoc()) && $r['cnt'] > 0) {
                $total_rooms = $r['cnt'];
            }
            $c_ward = $conn->query("SELECT COUNT(*) AS cnt FROM wardens");
            if ($c_ward && ($r = $c_ward->fetch_assoc()) && $r['cnt'] > 0) {
                $total_wardens = $r['cnt'];
            }
        }
    } catch (Exception $e) {
        $db_connected = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>HOSTEL MATE | Next-Gen Smart Campus Living & Residence Management</title>
  <meta name="description" content="Hostel Mate offers premium student hostel accommodation with digital attendance, biometric security, hygienic dining, and dedicated Admin, Warden, and Student portals.">
  
  <!-- Favicon -->
  <link rel="shortcut icon" href="img/fav.png" type="image/png" />
  <link rel="apple-touch-icon" href="img/fav.png" />

  <!-- FontAwesome 6 Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <!-- Custom Website Stylesheet -->
  <link rel="stylesheet" href="assets/css/website.css">
</head>
<body>

  <!-- Top Announcement Bar -->
  <div class="top-announcement">
    <div class="container">
      <div class="announcement-inner">
        <div class="announcement-left">
          <span class="badge-tag">Admissions 2026-27</span>
          <span>Hostel applications are now open for all academic blocks. Reserve your room early!</span>
        </div>
        <div class="announcement-right">
          <a href="tel:+919876543210"><i class="fa-solid fa-phone"></i> +91 98765 43210</a>
          <a href="mailto:support@hostelmate.com"><i class="fa-solid fa-envelope"></i> helpdesk@hostelmate.com</a>
          <span><i class="fa-solid fa-shield-halved text-emerald"></i> ISO 9001:2026 Certified</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Header & Sticky Navbar -->
  <header class="site-header">
    <div class="container">
      <nav class="navbar">
        <!-- Brand Logo -->
        <a href="index.php" class="brand-logo-wrap" title="Hostel Mate Home">
          <img src="img/logo-h.png" alt="Hostel Mate Logo" onerror="this.src='imag/logo-h.png'">
        </a>

        <!-- Desktop Navigation Links -->
        <ul class="nav-links">
          <li><a href="#hero" class="nav-link">Home</a></li>
          <li><a href="#portals" class="nav-link">Portals</a></li>
          <li><a href="#amenities" class="nav-link">Amenities</a></li>
          <li><a href="#rooms" class="nav-link">Rooms</a></li>
          <li><a href="#dining" class="nav-link">Dining</a></li>
          <li><a href="#safety" class="nav-link">Safety</a></li>
          <li><a href="#faq" class="nav-link">FAQs</a></li>
          <li><a href="#contact" class="nav-link">Contact</a></li>
        </ul>

        <!-- 3 Prominent Portal Links in Navbar: Admin, Warden, Student -->
        <div class="nav-portals-group">
          <!-- 1. Admin Login Link -->
          <a href="Admin/login.php" class="nav-portal-btn btn-nav-admin" title="Admin Control Dashboard">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Admin</span>
          </a>

          <!-- 2. Warden Login Link -->
          <a href="Warden/login.php" class="nav-portal-btn btn-nav-warden" title="Warden Operations Portal">
            <i class="fa-solid fa-user-tie"></i>
            <span>Warden</span>
          </a>

          <!-- 3. Student Login Link -->
          <a href="Student/login.php" class="nav-portal-btn btn-nav-student" title="Student Resident Portal">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Student</span>
          </a>
        </div>

        <!-- Mobile Menu Toggle Button -->
        <button class="mobile-toggle-btn" aria-label="Toggle navigation menu">
          <i class="fa-solid fa-bars"></i>
        </button>
      </nav>
    </div>
  </header>

  <!-- Hero Banner Carousel Section -->
  <section id="hero" class="hero-slider-section">
    <div class="hero-slider-track">
      
      <!-- Slide 1: Modern Campus Living -->
      <div class="slide-item active">
        <div class="slide-bg" style="background-image: url('img/banner-campus.jpg');"></div>
        <div class="slide-overlay"></div>
        <div class="container">
          <div class="slide-content-wrap">
            <div class="slide-pill">
              <span class="pulse-dot"></span>
              <span>Next-Gen Smart Campus Living</span>
            </div>
            <h1 class="slide-title">
              Comfortable, Secure & <span>Connected Student Living</span>
            </h1>
            <p class="slide-desc">
              Experience the pinnacle of campus residency. Seamless room allotment, biometric gate access, hygienic dining, and 24/7 warden supervision powered by the unified Hostel Mate platform.
            </p>
            <div class="slide-buttons">
              <a href="#rooms" class="btn-primary-glow">
                <span>Explore Rooms</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="Student/login.php" class="btn-glass">
                <i class="fa-solid fa-user-graduate"></i>
                <span>Student Login</span>
              </a>
              <a href="#portals" class="btn-glass">
                <i class="fa-solid fa-key"></i>
                <span>Access Portals</span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Slide 2: Cozy Ergonomic Dorms -->
      <div class="slide-item">
        <div class="slide-bg" style="background-image: url('img/banner-room.jpg');"></div>
        <div class="slide-overlay"></div>
        <div class="container">
          <div class="slide-content-wrap">
            <div class="slide-pill">
              <span class="pulse-dot"></span>
              <span>Ergonomic Living Spaces</span>
            </div>
            <h2 class="slide-title">
              Designed for <span>Focus, Rest & Peace of Mind</span>
            </h2>
            <p class="slide-desc">
              From private executive suites to premium shared rooms, each living space is equipped with ergonomic study setups, high-speed fiber Wi-Fi, ample sunlight, and 100% power backup.
            </p>
            <div class="slide-buttons">
              <a href="#amenities" class="btn-primary-glow">
                <span>View Amenities</span>
                <i class="fa-solid fa-sparkles"></i>
              </a>
              <a href="Student/register.php" class="btn-glass">
                <i class="fa-solid fa-id-card"></i>
                <span>New Student Registration</span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Slide 3: 4-Meal Hygienic Dining -->
      <div class="slide-item">
        <div class="slide-bg" style="background-image: url('img/banner-dining.jpg');"></div>
        <div class="slide-overlay"></div>
        <div class="container">
          <div class="slide-content-wrap">
            <div class="slide-pill">
              <span class="pulse-dot"></span>
              <span>Wholesome Dining Hall</span>
            </div>
            <h2 class="slide-title">
              Nutritious <span>4-Meal Dining Everyday</span>
            </h2>
            <p class="slide-desc">
              Fuel your academic goals with delicious, chef-prepared meals served in a certified hygienic dining hall. Weekly rotational menus curated with student feedback and nutritional balance.
            </p>
            <div class="slide-buttons">
              <a href="#dining" class="btn-primary-glow">
                <span>Today's Mess Menu</span>
                <i class="fa-solid fa-utensils"></i>
              </a>
              <a href="Warden/login.php" class="btn-glass">
                <i class="fa-solid fa-user-tie"></i>
                <span>Warden Portal</span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Carousel Navigation Arrows -->
      <button class="slider-arrow slider-prev" aria-label="Previous Slide">
        <i class="fa-solid fa-chevron-left"></i>
      </button>
      <button class="slider-arrow slider-next" aria-label="Next Slide">
        <i class="fa-solid fa-chevron-right"></i>
      </button>

      <!-- Carousel Navigation Dots -->
      <div class="slider-dots">
        <span class="slider-dot active"></span>
        <span class="slider-dot"></span>
        <span class="slider-dot"></span>
      </div>

    </div>
  </section>

  <!-- Hero Floating Quick Stats Bar -->
  <div class="stats-floating-bar">
    <div class="container">
      <div class="stats-card-grid">
        <div class="stat-item">
          <div class="stat-icon blue">
            <i class="fa-solid fa-building"></i>
          </div>
          <div>
            <div class="stat-num" data-target="<?php echo htmlspecialchars($total_rooms); ?>" data-suffix="+"><?php echo htmlspecialchars($total_rooms); ?>+</div>
            <div class="stat-label">Modern Rooms & Beds</div>
          </div>
        </div>

        <div class="stat-item">
          <div class="stat-icon purple">
            <i class="fa-solid fa-user-graduate"></i>
          </div>
          <div>
            <div class="stat-num" data-target="<?php echo htmlspecialchars($total_students); ?>" data-suffix="+"><?php echo htmlspecialchars($total_students); ?>+</div>
            <div class="stat-label">Happy Resident Students</div>
          </div>
        </div>

        <div class="stat-item">
          <div class="stat-icon emerald">
            <i class="fa-solid fa-user-shield"></i>
          </div>
          <div>
            <div class="stat-num" data-target="<?php echo htmlspecialchars($total_wardens); ?>" data-suffix=""><?php echo htmlspecialchars($total_wardens); ?></div>
            <div class="stat-label">Certified Resident Wardens</div>
          </div>
        </div>

        <div class="stat-item">
          <div class="stat-icon amber">
            <i class="fa-solid fa-bolt"></i>
          </div>
          <div>
            <div class="stat-num" data-target="99" data-suffix=".6%">99.6%</div>
            <div class="stat-label">Safety & Satisfaction Score</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PORTALS SHOWCASE SECTION (Admin, Warden, Student Links Highlighted) -->
  <section id="portals" class="section section-muted">
    <div class="container">
      <div class="section-header">
        <span class="section-tag"><i class="fa-solid fa-fingerprint"></i> Unified Campus Access</span>
        <h2 class="section-title">Dedicated Portals for Every Role</h2>
        <p class="section-subtitle">
          Hostel Mate powers seamless communication and administration through tailored role-based interfaces designed for total security, transparency, and speed.
        </p>
      </div>

      <div class="portals-grid">
        
        <!-- Portal 1: Admin Management Hub -->
        <div class="portal-card card-admin">
          <div class="portal-badge-row">
            <span class="portal-role-badge">
              <i class="fa-solid fa-shield-halved"></i> Governance
            </span>
            <img src="img/fav-admin.png" alt="Admin Icon" style="width: 28px; height: 28px;" onerror="this.src='imag/fav-admin.png'">
          </div>
          <div class="portal-icon-box">
            <i class="fa-solid fa-user-gear"></i>
          </div>
          <h3 class="portal-title">Admin Portal</h3>
          <p class="portal-desc">
            Complete institutional control over hostel blocks, room assignments, fee tracking, warden deployment, and real-time occupancy analytics.
          </p>
          <ul class="portal-features-list">
            <li><i class="fa-solid fa-check"></i> Real-time room inventory & bed occupancy</li>
            <li><i class="fa-solid fa-check"></i> Fee transaction history & payment records</li>
            <li><i class="fa-solid fa-check"></i> Warden management & block assignment</li>
            <li><i class="fa-solid fa-check"></i> Comprehensive student verification & reports</li>
            <li><i class="fa-solid fa-check"></i> Institutional broadcast notices & alerts</li>
          </ul>
          <div class="portal-action-box">
            <a href="Admin/login.php" class="portal-btn">
              <span>Admin Login</span>
              <i class="fa-solid fa-arrow-right"></i>
            </a>
          </div>
        </div>

        <!-- Portal 2: Warden Operations Desk -->
        <div class="portal-card card-warden">
          <div class="portal-badge-row">
            <span class="portal-role-badge">
              <i class="fa-solid fa-user-tie"></i> Supervision
            </span>
            <img src="img/fav-warden.png" alt="Warden Icon" style="width: 28px; height: 28px;" onerror="this.src='imag/fav-warden.png'">
          </div>
          <div class="portal-icon-box">
            <i class="fa-solid fa-clipboard-check"></i>
          </div>
          <h3 class="portal-title">Warden Portal</h3>
          <p class="portal-desc">
            On-ground supervisory dashboard for daily student attendance, curfew enforcement, digital gate passes, visitor logs, and grievance resolution.
          </p>
          <ul class="portal-features-list">
            <li><i class="fa-solid fa-check"></i> Daily digital night roll call & attendance</li>
            <li><i class="fa-solid fa-check"></i> Gate pass, leave & outing approvals</li>
            <li><i class="fa-solid fa-check"></i> Student maintenance complaints tracking</li>
            <li><i class="fa-solid fa-check"></i> Visitor entry/exit authorization logs</li>
            <li><i class="fa-solid fa-check"></i> Emergency protocols & parent communication</li>
          </ul>
          <div class="portal-action-box">
            <a href="Warden/login.php" class="portal-btn">
              <span>Warden Login</span>
              <i class="fa-solid fa-arrow-right"></i>
            </a>
          </div>
        </div>

        <!-- Portal 3: Student Resident Lounge -->
        <div class="portal-card card-student">
          <div class="portal-badge-row">
            <span class="portal-role-badge">
              <i class="fa-solid fa-graduation-cap"></i> Residents
            </span>
            <img src="img/fav-student.png" alt="Student Icon" style="width: 28px; height: 28px;" onerror="this.src='imag/fav-student.png'">
          </div>
          <div class="portal-icon-box">
            <i class="fa-solid fa-house-user"></i>
          </div>
          <h3 class="portal-title">Student Portal</h3>
          <p class="portal-desc">
            Your personal digital hostel companion. View room details, check today's dining menu, submit maintenance tickets, and request gate passes effortlessly.
          </p>
          <ul class="portal-features-list">
            <li><i class="fa-solid fa-check"></i> Instant room & bed allotment status</li>
            <li><i class="fa-solid fa-check"></i> Live 4-meal daily dining mess menu</li>
            <li><i class="fa-solid fa-check"></i> One-click digital gate pass & leave requests</li>
            <li><i class="fa-solid fa-check"></i> Direct complaint submission & status updates</li>
            <li><i class="fa-solid fa-check"></i> Digital payment receipts & attendance ledger</li>
          </ul>
          <div class="portal-action-box">
            <a href="Student/login.php" class="portal-btn">
              <span>Student Login</span>
              <i class="fa-solid fa-arrow-right"></i>
            </a>
            <a href="Student/register.php" class="student-extra-link">
              New to Hostel Mate? Register for Room Allocation &rarr;
            </a>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- AMENITIES & FACILITIES SECTION -->
  <section id="amenities" class="section section-light">
    <div class="container">
      <div class="section-header">
        <span class="section-tag"><i class="fa-solid fa-sparkles"></i> World-Class Amenities</span>
        <h2 class="section-title">Everything You Need for Academic Excellence</h2>
        <p class="section-subtitle">
          Designed with comfort, security, and convenience in mind so you can focus 100% on your studies and personal growth.
        </p>
      </div>

      <div class="amenities-grid">
        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-wifi"></i>
          </div>
          <h4 class="amenity-title">High-Speed Wi-Fi</h4>
          <p class="amenity-desc">Dual-band gigabit fiber network with coverage across all student rooms, study lounges, and corridors.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-video"></i>
          </div>
          <h4 class="amenity-title">24/7 CCTV & Security</h4>
          <p class="amenity-desc">Multi-tier perimeter surveillance, biometric turnstile entries, and trained security personnel around the clock.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-utensils"></i>
          </div>
          <h4 class="amenity-title">Hygienic 4-Meal Mess</h4>
          <p class="amenity-desc">Wholesome breakfast, lunch, evening tea with snacks, and dinner prepared under strict FSSAI guidelines.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-book-open-reader"></i>
          </div>
          <h4 class="amenity-title">Quiet Study Lounges</h4>
          <p class="amenity-desc">Air-conditioned quiet study halls equipped with reading lamps, power outlets, and ergonomic seating.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-shirt"></i>
          </div>
          <h4 class="amenity-title">Automated Laundry</h4>
          <p class="amenity-desc">Commercial washers and dryers with weekly pickup and ironing service for hassle-free student living.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-plug-circle-bolt"></i>
          </div>
          <h4 class="amenity-title">100% Power Backup</h4>
          <p class="amenity-desc">Heavy-duty diesel generators ensure zero interruption to Wi-Fi, air cooling, lighting, and study spaces.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-heart-pulse"></i>
          </div>
          <h4 class="amenity-title">Medical Care on Call</h4>
          <p class="amenity-desc">On-premises first aid station, emergency doctor on-call, and tie-ups with leading multi-specialty hospitals.</p>
        </div>

        <div class="amenity-card">
          <div class="amenity-icon-circle">
            <i class="fa-solid fa-dumbbell"></i>
          </div>
          <h4 class="amenity-title">Fitness & Recreation</h4>
          <p class="amenity-desc">Indoor recreation hub featuring table tennis, chess, gym equipment, and social television lounge.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ROOM TYPES & LIVING PLANS -->
  <section id="rooms" class="section section-muted">
    <div class="container">
      <div class="section-header">
        <span class="section-tag"><i class="fa-solid fa-bed"></i> Accommodations</span>
        <h2 class="section-title">Select Your Ideal Living Space</h2>
        <p class="section-subtitle">
          Spacious, well-ventilated, and fully furnished student quarters configured for single privacy or shared camaraderie. All campus living spaces are 100% non-AC rooms equipped with high-speed ceiling fans and natural airflow.
        </p>
      </div>

      <div class="rooms-grid">
        
        <!-- Room 1: Single Executive -->
        <div class="room-card">
          <div class="room-img-wrap">
            <img src="img/banner-room.jpg" alt="Single Non-AC Room">
            <span class="room-badge">Non-AC Single</span>
          </div>
          <div class="room-body">
            <h3 class="room-title">Single Executive Room</h3>
            <div class="room-specs">
              <span><i class="fa-solid fa-user"></i> 1 Resident</span>
              <span><i class="fa-solid fa-bath"></i> Attached Bath</span>
              <span><i class="fa-solid fa-fan"></i> Non-AC Comfort</span>
            </div>
            <ul class="room-perks">
              <li><i class="fa-solid fa-circle-check"></i> Private balcony with natural sunlight & ventilation</li>
              <li><i class="fa-solid fa-circle-check"></i> Dedicated executive study desk & bookshelf</li>
              <li><i class="fa-solid fa-circle-check"></i> 6-door wardrobe with personal locker</li>
              <li><i class="fa-solid fa-circle-check"></i> Weekly deep cleaning & linen change</li>
            </ul>
            <div class="room-footer">
              <div class="room-feature-tag">
                <i class="fa-solid fa-fan"></i> Non-AC Room
              </div>
              <a href="#contact" class="btn-book-room">
                <span>Inquire Room</span>
                <i class="fa-solid fa-chevron-right"></i>
              </a>
            </div>
          </div>
        </div>

        <!-- Room 2: Twin Sharing -->
        <div class="room-card">
          <div class="room-img-wrap">
            <img src="room_images/room_1790079560_9931.png" alt="Twin Deluxe Sharing" onerror="this.src='img/banner-campus.jpg'">
            <span class="room-badge">Non-AC Twin</span>
          </div>
          <div class="room-body">
            <h3 class="room-title">Twin Deluxe Sharing</h3>
            <div class="room-specs">
              <span><i class="fa-solid fa-users"></i> 2 Residents</span>
              <span><i class="fa-solid fa-bath"></i> En-suite Bath</span>
              <span><i class="fa-solid fa-fan"></i> Non-AC Comfort</span>
            </div>
            <ul class="room-perks">
              <li><i class="fa-solid fa-circle-check"></i> Twin ergonomic study tables with pin boards</li>
              <li><i class="fa-solid fa-circle-check"></i> Individual full-height wardrobes & lockers</li>
              <li><i class="fa-solid fa-circle-check"></i> High-speed LAN ports & power strip at each desk</li>
              <li><i class="fa-solid fa-circle-check"></i> Smart roommate matching algorithm</li>
            </ul>
            <div class="room-footer">
              <div class="room-feature-tag">
                <i class="fa-solid fa-fan"></i> Non-AC Room
              </div>
              <a href="#contact" class="btn-book-room">
                <span>Inquire Room</span>
                <i class="fa-solid fa-chevron-right"></i>
              </a>
            </div>
          </div>
        </div>

        <!-- Room 3: Triple Sharing -->
        <div class="room-card">
          <div class="room-img-wrap">
            <img src="img/hostel_login_bg.jpg" alt="Triple Standard Room">
            <span class="room-badge">Non-AC Triple</span>
          </div>
          <div class="room-body">
            <h3 class="room-title">Triple Standard Sharing</h3>
            <div class="room-specs">
              <span><i class="fa-solid fa-people-group"></i> 3 Residents</span>
              <span><i class="fa-solid fa-shower"></i> Modern Bath</span>
              <span><i class="fa-solid fa-fan"></i> Non-AC Comfort</span>
            </div>
            <ul class="room-perks">
              <li><i class="fa-solid fa-circle-check"></i> Generous room dimensions with ample movement space</li>
              <li><i class="fa-solid fa-circle-check"></i> Individual study station and lockable drawers</li>
              <li><i class="fa-solid fa-circle-check"></i> Individual wardrobe unit per student</li>
              <li><i class="fa-solid fa-circle-check"></i> Inclusive of all 4 daily mess meals</li>
            </ul>
            <div class="room-footer">
              <div class="room-feature-tag">
                <i class="fa-solid fa-fan"></i> Non-AC Room
              </div>
              <a href="#contact" class="btn-book-room">
                <span>Inquire Room</span>
                <i class="fa-solid fa-chevron-right"></i>
              </a>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- DINING & MESS SHOWCASE -->
  <section id="dining" class="section section-light">
    <div class="container">
      <div class="dining-section-wrap">
        
        <!-- Dining Information & Interactive Tabs -->
        <div>
          <span class="section-tag"><i class="fa-solid fa-bowl-food"></i> Farm-to-Table Nutrition</span>
          <h2 class="section-title">Hygienic 4-Meal Dining with Chef Curations</h2>
          <p class="section-subtitle" style="margin-bottom: 2rem;">
            Good health fuels academic success. Our professional kitchen team prepares fresh, multi-cuisine meals every single day with top-grade ingredients, filtered water, and balanced proteins.
          </p>

          <!-- Meal Time Tabs -->
          <div class="dining-tabs">
            <button class="dining-tab-btn active" data-meal="breakfast">
              <i class="fa-solid fa-mug-saucer mr-1"></i> Breakfast
            </button>
            <button class="dining-tab-btn" data-meal="lunch">
              <i class="fa-solid fa-plate-wheat mr-1"></i> Lunch
            </button>
            <button class="dining-tab-btn" data-meal="tea">
              <i class="fa-solid fa-cookie-bite mr-1"></i> Evening Tea
            </button>
            <button class="dining-tab-btn" data-meal="dinner">
              <i class="fa-solid fa-bowl-rice mr-1"></i> Dinner
            </button>
          </div>

          <!-- Tab Content: Breakfast -->
          <div id="meal-breakfast" class="dining-content-panel active">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="font-weight-bold text-dark mb-0">Breakfast Menu</h5>
              <span class="badge-tag">
                <?php echo isset($today_menu['breakfast_time']) ? htmlspecialchars($today_menu['breakfast_time']) : '07:30 AM - 09:30 AM'; ?>
              </span>
            </div>
            <p class="text-muted small mb-3">Freshly prepared hot breakfast with seasonal fruits and milk/tea/coffee.</p>
            <div class="meal-items-grid">
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span><?php echo isset($today_menu['break_fast']) ? htmlspecialchars($today_menu['break_fast']) : 'Masala Dosa / Idli Sambhar & Chutneys'; ?></span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Boiled Farm Eggs / Sprouts</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Toasted Bread, Butter & Jam</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Hot Filter Coffee & Tea</span></div>
            </div>
          </div>

          <!-- Tab Content: Lunch -->
          <div id="meal-lunch" class="dining-content-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="font-weight-bold text-dark mb-0">Wholesome Lunch</h5>
              <span class="badge-tag">
                <?php echo isset($today_menu['lunch_time']) ? htmlspecialchars($today_menu['lunch_time']) : '12:30 PM - 02:30 PM'; ?>
              </span>
            </div>
            <p class="text-muted small mb-3">Complete balanced thali meal with salads, curries, and accompaniments.</p>
            <div class="meal-items-grid">
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span><?php echo isset($today_menu['lunch']) ? htmlspecialchars($today_menu['lunch']) : 'Steamed Basmati Rice & Dal Tadka'; ?></span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Paneer Butter Masala / Chicken Curry</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Seasonal Veg Thoran & Papad</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Fresh Curd & Mango Pickle</span></div>
            </div>
          </div>

          <!-- Tab Content: Evening Tea -->
          <div id="meal-tea" class="dining-content-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="font-weight-bold text-dark mb-0">Evening High Tea</h5>
              <span class="badge-tag">
                <?php echo isset($today_menu['evening_tea_time']) ? htmlspecialchars($today_menu['evening_tea_time']) : '05:00 PM - 06:00 PM'; ?>
              </span>
            </div>
            <p class="text-muted small mb-3">Crispy evening refreshment to re-energize after college lectures.</p>
            <div class="meal-items-grid">
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span><?php echo isset($today_menu['evening_tea']) ? htmlspecialchars($today_menu['evening_tea']) : 'Hot Veg Samosa & Mint Chutney'; ?></span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Banana Fritters / Biscuits</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Cardamom Masala Tea</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Instant Brewed Coffee</span></div>
            </div>
          </div>

          <!-- Tab Content: Dinner -->
          <div id="meal-dinner" class="dining-content-panel">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="font-weight-bold text-dark mb-0">Hearty Dinner</h5>
              <span class="badge-tag">
                <?php echo isset($today_menu['dinner_time']) ? htmlspecialchars($today_menu['dinner_time']) : '07:30 PM - 09:30 PM'; ?>
              </span>
            </div>
            <p class="text-muted small mb-3">Warm, comforting dinner prepared light and easy to digest before bedtime.</p>
            <div class="meal-items-grid">
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span><?php echo isset($today_menu['dinner']) ? htmlspecialchars($today_menu['dinner']) : 'Soft Phulka Rotis / Kerala Parotta'; ?></span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Mixed Veg Korma / Grilled Chicken</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Jeera Pulao & Tomato Rasam</span></div>
              <div class="meal-item"><i class="fa-solid fa-circle-check"></i> <span>Cucumber Salad & Sweet Dessert</span></div>
            </div>
          </div>

        </div>

        <!-- Dining Image with Floating Badge -->
        <div class="dining-img-container">
          <img src="img/banner-dining.jpg" alt="Dining Hall">
          <div class="dining-floating-badge">
            <i class="fa-solid fa-award"></i>
            <div>
              <div style="font-weight: 800; color: #0f172a; font-size: 1.1rem;">FSSAI 5-Star Kitchen</div>
              <div style="font-size: 0.8rem; color: #64748b;">100% RO Purified Cooking & Drinking Water</div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- CAMPUS SAFETY & WARDEN SUPERVISION -->
  <section id="safety" class="section section-dark">
    <div class="container">
      <div class="section-header">
        <span class="section-tag" style="background: rgba(56, 189, 248, 0.15); color: #38bdf8;">
          <i class="fa-solid fa-shield-cat"></i> Safety Above All
        </span>
        <h2 class="section-title" style="color: #fff;">Uncompromised Resident Security</h2>
        <p class="section-subtitle" style="color: #94a3b8;">
          Parents entrust us with their young adults. We honor that trust with biometric authentication, verified wardens, and strict access protocols.
        </p>
      </div>

      <div class="safety-features-grid">
        <div class="safety-card">
          <div class="safety-icon">
            <i class="fa-solid fa-fingerprint"></i>
          </div>
          <h4>Biometric Access Turnstiles</h4>
          <p>Automated fingerprint and RFID smart card entry gates at all hostel block portals prevent unauthorized trespassing.</p>
        </div>

        <div class="safety-card">
          <div class="safety-icon">
            <i class="fa-solid fa-user-shield"></i>
          </div>
          <h4>Resident Wardens On-Campus</h4>
          <p>Qualified resident wardens stationed inside every hostel block to provide academic guidance, discipline, and emotional support.</p>
        </div>

        <div class="safety-card">
          <div class="safety-icon">
            <i class="fa-solid fa-mobile-screen-button"></i>
          </div>
          <h4>Digital Gate Pass System</h4>
          <p>Students request leaves or outings directly through their Student Portal with instant Warden approval and parent SMS notifications.</p>
        </div>

        <div class="safety-card">
          <div class="safety-icon">
            <i class="fa-solid fa-fire-extinguisher"></i>
          </div>
          <h4>Fire & Hazard Safety</h4>
          <p>Smoke detectors, emergency alarm points, fire exit stairs on each floor, and regular bi-annual evacuation drill exercises.</p>
        </div>

        <div class="safety-card">
          <div class="safety-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
          </div>
          <h4>Zero Ragging Strict Policy</h4>
          <p>Strict anti-ragging oversight committee with zero tolerance protocol to maintain a welcoming, respectful community.</p>
        </div>

        <div class="safety-card">
          <div class="safety-icon">
            <i class="fa-solid fa-truck-medical"></i>
          </div>
          <h4>24/7 Emergency Ambulance</h4>
          <p>Dedicated on-campus medical transport vehicle ready for immediate emergency dispatch with hospital coordination.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- LATEST NOTICES & UPDATES BOARD -->
  <?php if (!empty($notices)): ?>
  <section class="section section-light" style="padding: 4rem 0;">
    <div class="container">
      <div class="section-header" style="margin-bottom: 2rem;">
        <span class="section-tag"><i class="fa-solid fa-bullhorn"></i> Campus Feed</span>
        <h2 class="section-title">Latest Announcements & Circulars</h2>
      </div>

      <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
        <?php foreach ($notices as $notice): ?>
          <div style="background: #ffffff; border: 1px solid var(--slate-200); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm); position: relative;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
              <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; padding: 0.2rem 0.6rem; border-radius: var(--radius-full); background: #e0e7ff; color: #4338ca;">
                <i class="fa-solid fa-bullhorn" style="font-size: 0.65rem; margin-right: 4px;"></i> Notice
              </span>
              <span style="font-size: 0.8rem; color: #94a3b8;">
                <i class="fa-regular fa-clock mr-1"></i> <?php echo date('M d, Y', strtotime($notice['created_at'])); ?>
              </span>
            </div>
            <h4 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--slate-900);">
              <?php echo htmlspecialchars($notice['title']); ?>
            </h4>
            <p style="font-size: 0.875rem; color: var(--slate-600); line-height: 1.5; margin-bottom: 1rem;">
              <?php echo htmlspecialchars(substr($notice['description'], 0, 140)) . (strlen($notice['description']) > 140 ? '...' : ''); ?>
            </p>
            <a href="Student/login.php" style="font-size: 0.825rem; font-weight: 700; color: var(--hm-primary);">
              Login to view full notice &rarr;
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- STUDENT TESTIMONIALS -->
  <section class="section section-muted">
    <div class="container">
      <div class="section-header">
        <span class="section-tag"><i class="fa-solid fa-comments"></i> Resident Voices</span>
        <h2 class="section-title">Loved by Students, Trusted by Parents</h2>
        <p class="section-subtitle">Read what our hostel residents have to say about their daily life at Hostel Mate.</p>
      </div>

      <div class="testimonials-grid">
        <div class="testimonial-card">
          <div class="testimonial-stars">
            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
          </div>
          <p class="testimonial-text">
            "Moving away from home for college was daunting, but Hostel Mate made it so effortless. The Wi-Fi is super fast for late-night project submissions, and the warden is genuinely supportive like family."
          </p>
          <div class="testimonial-author">
            <div class="author-avatar">AK</div>
            <div class="author-info">
              <h5>Aravind Krishna</h5>
              <p>Computer Science, 3rd Year • Block A</p>
            </div>
          </div>
        </div>

        <div class="testimonial-card">
          <div class="testimonial-stars">
            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
          </div>
          <p class="testimonial-text">
            "The food quality here is unmatched compared to other hostels. Getting 4 hot meals including evening snacks saves us so much time and money. Plus, the room maintenance complaints get resolved within hours!"
          </p>
          <div class="testimonial-author">
            <div class="author-avatar">SN</div>
            <div class="author-info">
              <h5>Sneha Nair</h5>
              <p>Biotechnology, 2nd Year • Block B</p>
            </div>
          </div>
        </div>

        <div class="testimonial-card">
          <div class="testimonial-stars">
            <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
          </div>
          <p class="testimonial-text">
            "My parents love the digital gate pass system. Whenever I travel home for vacation, they receive verified alerts, and my attendance records are completely transparent in the student portal."
          </p>
          <div class="testimonial-author">
            <div class="author-avatar">RD</div>
            <div class="author-info">
              <h5>Rohit Das</h5>
              <p>Mechanical Engg, 4th Year • Block A</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FREQUENTLY ASKED QUESTIONS -->
  <section id="faq" class="section section-light">
    <div class="container">
      <div class="section-header">
        <span class="section-tag"><i class="fa-solid fa-circle-question"></i> Help & Guidance</span>
        <h2 class="section-title">Frequently Asked Questions</h2>
        <p class="section-subtitle">Find fast answers to common questions about hostel admissions, curfew, visitors, and rules.</p>
      </div>

      <div class="faq-accordion">
        <div class="faq-item active">
          <button class="faq-question">
            <span>What are the daily hostel curfew and entry timings?</span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="faq-answer">
            Hostel gates close at 09:00 PM on weekdays and 09:30 PM on weekends. Night roll call is conducted by the resident warden at 09:30 PM. Late entries require a pre-approved digital gate pass through the Student Portal endorsed by the warden.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question">
            <span>How does the digital gate pass and leave request work?</span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="faq-answer">
            Students can submit outing or vacation leave requests from their Student Portal dashboard. Once the warden reviews and approves the request, an automated digital pass with QR code is generated, and confirmation is logged in the system.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question">
            <span>Are visitors or parents allowed to stay on campus?</span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="faq-answer">
            Parents and authorized guardians can visit residents in the designated campus visitor reception lounge between 10:00 AM and 06:00 PM. All visitors must be registered at the security gate with government photo ID proof. Overnight guest stays in student rooms are strictly prohibited.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question">
            <span>How are room maintenance issues and complaints addressed?</span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="faq-answer">
            Residents can submit maintenance complaints (electrical, plumbing, Wi-Fi, carpentry) directly on the Student Portal. The warden assigns the ticket to on-campus technicians, and status updates are tracked in real-time until resolution.
          </div>
        </div>

        <div class="faq-item">
          <button class="faq-question">
            <span>Can I choose my roommate or request a room change?</span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="faq-answer">
            Yes! Students can specify roommate preferences during admission registration. Mutual roommate requests are accommodated based on floor availability and verified by the warden.
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CONTACT & CAMPUS LOCATION -->
  <section id="contact" class="section section-muted">
    <div class="container">
      <div class="contact-grid">
        
        <!-- Left: Contact Details -->
        <div class="contact-info-panel">
          <div>
            <span class="badge-tag" style="background: rgba(255, 255, 255, 0.15); color: #fff;">Get In Touch</span>
            <h3 style="margin-top: 1rem;">Campus Admissions & Warden Helpdesk</h3>
            <p>Have questions about hostel admissions, room availability, or campus tours? Reach out to our team anytime.</p>

            <div class="contact-detail-item">
              <div class="contact-detail-icon"><i class="fa-solid fa-location-dot"></i></div>
              <div class="contact-detail-text">
                <h6>Campus Location</h6>
                <span>Hostel Mate Residency Campus, University Hillway, Academic Zone - 682024</span>
              </div>
            </div>

            <div class="contact-detail-item">
              <div class="contact-detail-icon"><i class="fa-solid fa-phone-volume"></i></div>
              <div class="contact-detail-text">
                <h6>24/7 Helpline & Warden Desk</h6>
                <span>+91 98765 43210 / +91 94471 23456</span>
              </div>
            </div>

            <div class="contact-detail-item">
              <div class="contact-detail-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
              <div class="contact-detail-text">
                <h6>Official Inquiries</h6>
                <span>admissions@hostelmate.com / support@hostelmate.com</span>
              </div>
            </div>
          </div>

          <div style="padding-top: 2rem; border-top: 1px solid rgba(255, 255, 255, 0.1);">
            <div style="font-size: 0.85rem; color: #94a3b8;">Working Hours:</div>
            <div style="font-size: 0.95rem; font-weight: 700; color: #fff;">Admissions Office: Mon - Sat (08:30 AM - 05:30 PM)</div>
            <div style="font-size: 0.85rem; color: #38bdf8;">Warden & Emergency Desk: 24 Hours / 7 Days a Week</div>
          </div>
        </div>

        <!-- Right: Inquiry Form -->
        <div class="contact-form-panel">
          <h4>Send an Admission Inquiry</h4>
          <p>Fill out the details below and our resident warden office will get back to you with room availability details.</p>

          <form id="hostelInquiryForm">
            <div class="form-group">
              <label for="fullName">Full Name *</label>
              <input type="text" id="fullName" class="form-control" placeholder="e.g. Rahul Sharma" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
              <div class="form-group">
                <label for="emailAddr">Email Address *</label>
                <input type="email" id="emailAddr" class="form-control" placeholder="rahul@example.com" required>
              </div>
              <div class="form-group">
                <label for="phoneNum">Phone Number *</label>
                <input type="tel" id="phoneNum" class="form-control" placeholder="+91 98765 43210" required>
              </div>
            </div>

            <div class="form-group">
              <label for="roomPref">Preferred Room Type (All Non-AC)</label>
              <select id="roomPref" class="form-control">
                <option value="Single Executive Room">Single Room (Non-AC, Attached Bath)</option>
                <option value="Twin Deluxe Sharing" selected>Twin Deluxe Sharing (Non-AC)</option>
                <option value="Triple Standard Sharing">Triple Standard Sharing (Non-AC)</option>
                <option value="General Inquiry">General Campus Admission Inquiry</option>
              </select>
            </div>

            <div class="form-group">
              <label for="userMessage">Message / Specific Requirements</label>
              <textarea id="userMessage" class="form-control" placeholder="Tell us your course, preferred joining date, or dietary preferences..."></textarea>
            </div>

            <button type="submit" class="btn-submit-contact">
              <span>Submit Inquiry</span>
              <i class="fa-solid fa-paper-plane"></i>
            </button>
          </form>
        </div>

      </div>
    </div>
  </section>

  <!-- RICH MODERN FOOTER -->
  <footer class="site-footer">
    <div class="container">
      <div class="footer-grid">
        
        <!-- Col 1: Brand Info -->
        <div class="footer-brand-col">
          <img src="img/logo-h.png" alt="Hostel Mate Logo" class="footer-logo" onerror="this.src='imag/logo-h.png'">
          <p class="footer-tagline">
            Hostel Mate is an enterprise student residence management ecosystem offering state-of-the-art hostel accommodations, digital roll calls, and transparent portal administration.
          </p>
          <div class="footer-socials">
            <a href="#" class="social-link" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="#" class="social-link" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
            <a href="#" class="social-link" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
            <a href="#" class="social-link" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
          </div>
        </div>

        <!-- Col 2: 3 Portal Login Links (Required) -->
        <div class="footer-col">
          <h5>Portal Gateway</h5>
          <ul class="footer-links">
            <!-- 1. Admin Login -->
            <li>
              <a href="Admin/login.php" class="footer-portal-link footer-portal-admin" title="Admin Portal">
                <span><i class="fa-solid fa-shield-halved"></i> Admin Login</span>
                <i class="fa-solid fa-arrow-right-long"></i>
              </a>
            </li>
            <!-- 2. Warden Login -->
            <li>
              <a href="Warden/login.php" class="footer-portal-link footer-portal-warden" title="Warden Portal">
                <span><i class="fa-solid fa-user-tie"></i> Warden Login</span>
                <i class="fa-solid fa-arrow-right-long"></i>
              </a>
            </li>
            <!-- 3. Student Login -->
            <li>
              <a href="Student/login.php" class="footer-portal-link footer-portal-student" title="Student Portal">
                <span><i class="fa-solid fa-graduation-cap"></i> Student Login</span>
                <i class="fa-solid fa-arrow-right-long"></i>
              </a>
            </li>
            <!-- Extra Registration Link -->
            <li style="margin-top: 1rem;">
              <a href="Student/register.php">
                <i class="fa-solid fa-user-plus"></i> New Student Registration
              </a>
            </li>
          </ul>
        </div>

        <!-- Col 3: Quick Navigation -->
        <div class="footer-col">
          <h5>Quick Links</h5>
          <ul class="footer-links">
            <li><a href="#hero"><i class="fa-solid fa-angle-right"></i> Home</a></li>
            <li><a href="#portals"><i class="fa-solid fa-angle-right"></i> Portal Overview</a></li>
            <li><a href="#amenities"><i class="fa-solid fa-angle-right"></i> Amenities & Wi-Fi</a></li>
            <li><a href="#rooms"><i class="fa-solid fa-angle-right"></i> Room Types & Plans</a></li>
            <li><a href="#dining"><i class="fa-solid fa-angle-right"></i> 4-Meal Mess Menu</a></li>
            <li><a href="#safety"><i class="fa-solid fa-angle-right"></i> Safety & Security</a></li>
            <li><a href="#faq"><i class="fa-solid fa-angle-right"></i> Common FAQs</a></li>
            <li><a href="#contact"><i class="fa-solid fa-angle-right"></i> Contact Helpdesk</a></li>
          </ul>
        </div>

        <!-- Col 4: Campus Contact Info -->
        <div class="footer-col">
          <h5>Campus Desk</h5>
          <ul class="footer-links">
            <li style="display: flex; gap: 0.6rem; color: var(--slate-400); font-size: 0.9rem;">
              <i class="fa-solid fa-location-dot" style="margin-top: 0.3rem; color: #38bdf8;"></i>
              <span>Hostel Mate Residency Campus, University Hillway, Academic Zone - 682024</span>
            </li>
            <li style="display: flex; gap: 0.6rem; color: var(--slate-400); font-size: 0.9rem;">
              <i class="fa-solid fa-phone" style="margin-top: 0.3rem; color: #10b981;"></i>
              <span>+91 98765 43210 (24x7 Helpline)</span>
            </li>
            <li style="display: flex; gap: 0.6rem; color: var(--slate-400); font-size: 0.9rem;">
              <i class="fa-solid fa-envelope" style="margin-top: 0.3rem; color: #f59e0b;"></i>
              <span>support@hostelmate.com</span>
            </li>
          </ul>
          <div style="margin-top: 1.5rem;">
            <div class="system-status-indicator">
              <span class="pulse-dot"></span>
              <span>All Hostels & Portals Operational</span>
            </div>
          </div>
        </div>

      </div>

      <!-- Bottom Bar -->
      <div class="footer-bottom">
        <div>
          &copy; <?php echo date('Y'); ?> <strong>Hostel Mate</strong>. All Rights Reserved. Built for smarter campus residency.
        </div>
        <div style="display: flex; gap: 1.5rem;">
          <a href="#" style="color: var(--slate-500);">Privacy Policy</a>
          <a href="#" style="color: var(--slate-500);">Hostel Code of Conduct</a>
          <a href="#" style="color: var(--slate-500);">Anti-Ragging Undertaking</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- Back to Top Floating Button -->
  <a href="#hero" class="back-to-top" aria-label="Back to Top">
    <i class="fa-solid fa-arrow-up"></i>
  </a>

  <!-- JavaScript Interaction File -->
  <script src="assets/js/website.js"></script>
</body>
</html>
