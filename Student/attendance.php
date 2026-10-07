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

// Auto-create attendance table if it doesn't exist yet
$create_table_sql = "CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `attendance_date` DATE NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Present',
    `marked_by` VARCHAR(100) DEFAULT 'Hostel Warden',
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `student_date_unique` (`student_id`, `attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($create_table_sql);

// Ensure unique constraint exists on (student_id, attendance_date) safely
try {
    $check_index = $conn->query("SHOW KEYS FROM `attendance` WHERE Key_name = 'student_date_unique'");
    if (!$check_index || $check_index->num_rows === 0) {
        $conn->query("ALTER TABLE `attendance` ADD UNIQUE KEY `student_date_unique` (`student_id`, `attendance_date`)");
    }
} catch (Throwable $e) {
    // Ignore duplicate key index exceptions safely
}

// Ensure marked_by column exists on attendance table safely
try {
    $check_col = $conn->query("SHOW COLUMNS FROM `attendance` LIKE 'marked_by'");
    if (!$check_col || $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE `attendance` ADD COLUMN `marked_by` VARCHAR(100) DEFAULT 'Hostel Warden' AFTER `status`");
    }
} catch (Throwable $e) {
    // Ignore column check exceptions safely
}

// Fetch configured Hostel Timings & Geofence Location from `hostel_timings`
$hostel_timings_query = $conn->query("SELECT * FROM `hostel_timings` ORDER BY `id` DESC LIMIT 1");
$hostel_timing = ($hostel_timings_query && $hostel_timings_query->num_rows > 0) ? $hostel_timings_query->fetch_assoc() : null;

$att_start_time = $hostel_timing ? $hostel_timing['attendance_start_time'] : '20:00:00';
$att_end_time   = $hostel_timing ? $hostel_timing['attendance_end_time'] : '21:30:00';
$out_start_time = $hostel_timing ? $hostel_timing['outing_start_time'] : '06:00:00';
$out_end_time   = $hostel_timing ? $hostel_timing['outing_end_time'] : '20:00:00';

$hostel_lat  = $hostel_timing ? $hostel_timing['latitude'] : '';
$hostel_long = $hostel_timing ? $hostel_timing['longitude'] : '';

$att_window_fmt = (!empty($att_start_time) && !empty($att_end_time))
    ? date('h:i A', strtotime($att_start_time)) . ' - ' . date('h:i A', strtotime($att_end_time))
    : '08:00 PM - 09:30 PM';
$att_time_window_fmt = $att_window_fmt;

$out_window_fmt = (!empty($out_start_time) && !empty($out_end_time))
    ? date('h:i A', strtotime($out_start_time)) . ' - ' . date('h:i A', strtotime($out_end_time))
    : '06:00 AM - 08:00 PM';

// Check if student has already marked attendance for today (Daily Only One Time Rule)
$today_str = date('Y-m-d');
$check_today = $conn->query("SELECT * FROM `attendance` WHERE `student_id` = {$student_id} AND `attendance_date` = '{$today_str}' LIMIT 1");
$today_attendance = ($check_today && $check_today->num_rows > 0) ? $check_today->fetch_assoc() : null;
$already_marked_today = ($today_attendance !== null);

// Calculate computed Attendance Status & Marked By based on current server time against hostel_timings
$current_time_str = date('H:i:s');
$auto_status = 'Present';
$auto_marked_by = 'Hostel Timings System (Verified On-Time)';
$auto_remarks = "Checked in on-time during official window ({$att_window_fmt}).";

if (!empty($att_start_time) && !empty($att_end_time)) {
    if ($current_time_str >= $att_start_time && $current_time_str <= $att_end_time) {
        $auto_status = 'Present';
        $auto_marked_by = 'Hostel Timings System (Verified On-Time)';
        $auto_remarks = "Checked in on-time during official window ({$att_window_fmt}).";
    } elseif ($current_time_str > $att_end_time) {
        $auto_status = 'Late';
        $auto_marked_by = 'Hostel Timings System (Flagged Late)';
        $auto_remarks = "Checked in late past official window ({$att_window_fmt}).";
    } else {
        $auto_status = 'Present';
        $auto_marked_by = 'Hostel Timings System (Early Check-in)';
        $auto_remarks = "Early check-in logged before official window ({$att_window_fmt}).";
    }
}

function getGeofenceDistanceMeters($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;
    $dLat = deg2rad(floatval($lat2) - floatval($lat1));
    $dLon = deg2rad(floatval($lon2) - floatval($lon1));
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad(floatval($lat1))) * cos(deg2rad(floatval($lat2))) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function formatAttendanceStatusText($status_val, $created_at, $att_end_time = '21:30:00') {
    $fallback_end_time = !empty($att_end_time) ? $att_end_time : '21:30:00';
    $is_late_status = (stripos($status_val, 'Late') !== false);
    
    if (!empty($created_at)) {
        $created_timestamp = strtotime($created_at);
        if ($created_timestamp !== false && $created_timestamp > 0) {
            $record_date_str = date('Y-m-d', $created_timestamp);
            $end_time_clean = date('H:i:s', strtotime($fallback_end_time));
            $window_end_timestamp = strtotime("{$record_date_str} {$end_time_clean}");

            if ($window_end_timestamp !== false && $created_timestamp > $window_end_timestamp) {
                $diff_seconds = $created_timestamp - $window_end_timestamp;
                $diff_hours = floor($diff_seconds / 3600);
                $diff_mins = round(($diff_seconds % 3600) / 60);

                if ($diff_hours > 0 && $diff_mins > 0) {
                    return "{$diff_hours} hr {$diff_mins} min Late";
                } elseif ($diff_hours > 0) {
                    return "{$diff_hours} hr Late";
                } elseif ($diff_mins > 0) {
                    return "{$diff_mins} min Late";
                } else {
                    return "1 min Late";
                }
            }
        }
    }
    
    if ($is_late_status) {
        return 'Late';
    }
    return $status_val;
}

// Handle Add / Mark Attendance Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = date('Y-m-d');

    // 100 Meter Geofence Verification Check if hostel coordinates are set in DB
    $geofence_passed = true;
    if (!empty($hostel_lat) && !empty($hostel_long) && is_numeric($hostel_lat) && is_numeric($hostel_long)) {
        $user_lat  = isset($_POST['user_lat']) ? trim($_POST['user_lat']) : '';
        $user_long = isset($_POST['user_long']) ? trim($_POST['user_long']) : '';

        if (!empty($user_lat) && !empty($user_long) && is_numeric($user_lat) && is_numeric($user_long)) {
            $dist_meters = getGeofenceDistanceMeters($hostel_lat, $hostel_long, $user_lat, $user_long);
            if ($dist_meters > 100) {
                $geofence_passed = false;
            }
        } else {
            $geofence_passed = false;
        }
    }

    if (!$geofence_passed) {
        $_SESSION['error'] = "Cant Mark your attendance because you are not in the hostel";
    } else {
        // Enforce daily single submission check
        $stmt_check_date = $conn->prepare("SELECT id FROM `attendance` WHERE `student_id` = ? AND `attendance_date` = ?");
        $stmt_check_date->bind_param("is", $student_id, $attendance_date);
        $stmt_check_date->execute();
        $existing_res = $stmt_check_date->get_result();

        if ($existing_res && $existing_res->num_rows > 0) {
            $_SESSION['error'] = "Attendance for " . date('d M, Y', strtotime($attendance_date)) . " has already been recorded! Daily attendance can only be set once per day.";
        } else {
            // Status and Marked By automatically set based on hostel_timings or selection
            $final_status = !empty($_POST['status']) ? trim($_POST['status']) : $auto_status;
            $final_marked_by = !empty($_POST['marked_by']) ? trim($_POST['marked_by']) : $auto_marked_by;
            $combined_remarks = $auto_remarks;

            $stmt_mark = $conn->prepare("INSERT INTO `attendance` (`student_id`, `attendance_date`, `status`, `marked_by`, `remarks`) VALUES (?, ?, ?, ?, ?)");
            if ($stmt_mark) {
                $stmt_mark->bind_param("issss", $student_id, $attendance_date, $final_status, $final_marked_by, $combined_remarks);
                if ($stmt_mark->execute()) {
                    $_SESSION['msg'] = "Attendance for " . date('d M, Y', strtotime($attendance_date)) . " recorded successfully as {$final_status}!";
                } else {
                    $_SESSION['error'] = "Failed to record attendance: " . $conn->error;
                }
                $stmt_mark->close();
            } else {
                $_SESSION['error'] = "Database error: " . $conn->error;
            }
        }
        $stmt_check_date->close();
    }

    header("Location: attendance.php");
    exit();
}

// Handle Filters
$selected_month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$selected_status = isset($_GET['status']) ? trim($_GET['status']) : 'all';

// Build Query
$where_clauses = ["`student_id` = {$student_id}"];

if (!empty($selected_month)) {
    $safe_month = $conn->real_escape_string($selected_month);
    $where_clauses[] = "DATE_FORMAT(`attendance_date`, '%Y-%m') = '{$safe_month}'";
}

if (!empty($selected_status) && $selected_status !== 'all') {
    $safe_status = $conn->real_escape_string($selected_status);
    $where_clauses[] = "`status` = '{$safe_status}'";
}

$where_sql = implode(' AND ', $where_clauses);
$attendance_query = $conn->query("SELECT * FROM `attendance` WHERE {$where_sql} ORDER BY `attendance_date` DESC");

$attendance_records = [];
if ($attendance_query && $attendance_query->num_rows > 0) {
    while ($row = $attendance_query->fetch_assoc()) {
        $attendance_records[] = $row;
    }
}

// Calculate Filtered Statistics for Logged-In Student based on active filters
$stats_query = $conn->query("SELECT 
    COUNT(*) as total_days,
    SUM(CASE WHEN `status` = 'Present' THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN `status` = 'Absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN `status` = 'Late' THEN 1 ELSE 0 END) as late_days,
    SUM(CASE WHEN `status` = 'Leave' THEN 1 ELSE 0 END) as leave_days,
    SUM(CASE WHEN `status` = 'Outing' THEN 1 ELSE 0 END) as outing_days
    FROM `attendance` WHERE {$where_sql}");

$stats = $stats_query ? $stats_query->fetch_assoc() : [
    'total_days' => 0,
    'present_days' => 0,
    'absent_days' => 0,
    'late_days' => 0,
    'leave_days' => 0,
    'outing_days' => 0
];

$total_days = intval($stats['total_days']);
$present_days = intval($stats['present_days']);
$absent_days = intval($stats['absent_days']);
$late_days = intval($stats['late_days']);
$leave_days = intval($stats['leave_days']);
$outing_days = intval($stats['outing_days']);

// Percentage calculation (Present + Late counted toward attendance percentage)
$effective_present = $present_days + ($late_days * 0.8);
$attendance_percentage = ($total_days > 0) ? round(($effective_present / $total_days) * 100, 1) : 100.0;

ob_start();
?>

<style>
  /* Attendance Custom Aesthetics matching Student Section (Indigo / Violet Theme) */
  .attendance-hero-card {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    border-radius: 18px;
    color: #ffffff;
    padding: 2rem 2.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 28px rgba(99, 102, 241, 0.22);
    margin-bottom: 2rem;
  }

  .attendance-hero-card::after {
    content: "\f271";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 25px;
    bottom: -20px;
    font-size: 140px;
    color: rgba(255, 255, 255, 0.08);
    pointer-events: none;
  }

  .timing-pill-info {
    display: inline-flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.25);
    color: #ffffff;
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 600;
  }

  .stat-card-widget {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem;
    height: 100%;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
  }

  .stat-card-widget:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.12);
    border-color: rgba(99, 102, 241, 0.3);
  }

  .stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    margin-bottom: 0.85rem;
  }

  .stat-icon-present { background: #d1fae5; color: #059669; }
  .stat-icon-absent { background: #fee2e2; color: #dc2626; }
  .stat-icon-late { background: #ffedd5; color: #ea580c; }
  .stat-icon-rate { background: #e0e7ff; color: #4f46e5; }

  /* Status Badges */
  .badge-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0.35rem 0.8rem;
    border-radius: 50px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.3px;
  }

  .badge-status-present {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #6ee7b7;
  }

  .badge-status-absent {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
  }

  .badge-status-late {
    background: #ffedd5;
    color: #9a3412;
    border: 1px solid #fdba74;
  }

  .badge-status-leave {
    background: #f3e8ff;
    color: #6b21a8;
    border: 1px solid #d8b4fe;
  }

  .badge-status-outing {
    background: #e0f2fe;
    color: #0369a1;
    border: 1px solid #7dd3fc;
  }

  /* Filter Bar */
  .filter-control-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1.1rem 1.25rem;
    margin-bottom: 1.75rem;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.03);
  }

  .custom-form-control {
    height: 42px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    color: #1e293b;
    padding: 0.4rem 0.85rem;
    transition: all 0.2s ease;
  }

  .custom-form-control:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
  }

  .btn-student-primary {
    background: #6366f1;
    border-color: #6366f1;
    color: #ffffff;
    font-weight: 600;
    border-radius: 8px;
    height: 42px;
    padding: 0 1.25rem;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
  }

  .btn-student-primary:hover {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
  }

  /* Table Custom Styling */
  #attendanceTable {
    width: 100% !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
  }

  #attendanceTable th, #attendanceTable td {
    padding: 1rem 1.15rem !important;
    vertical-align: middle !important;
  }

  #attendanceTable thead th {
    background-color: #eef2ff !important;
    border-bottom: 2px solid #c7d2fe !important;
    color: #3730a3 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.6px;
    white-space: nowrap !important;
  }
</style>

<!-- Top Hero Header Card -->
<div class="attendance-hero-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
    <div>
      <div class="d-flex align-items-center mb-2">
        <span class="badge badge-pill badge-light text-primary font-weight-bold px-3 py-2 mr-2" style="color: #4338ca !important; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">
          <i class="fa-solid fa-clipboard-user mr-1"></i> Attendance Dashboard
        </span>
        <h3 class="mb-0 font-weight-bold text-white">My Attendance Log</h3>
      </div>
      <p class="text-white-50 mb-2" style="font-size: 0.92rem; max-width: 680px;">
        Track your daily hostel presence, curfew check logs, absent warnings, and leave records.
      </p>

      <!-- Active Hostel Timings Badges from `hostel_timings` table -->
      <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <span class="timing-pill-info mr-2 mb-1">
          <i class="fa-solid fa-clock mr-1 text-warning"></i> Attendance Window: <strong><?= htmlspecialchars($att_window_fmt) ?></strong>
        </span>
        <span class="timing-pill-info mr-2 mb-1">
          <i class="fa-solid fa-door-open mr-1 text-info"></i> Outing Window: <strong><?= htmlspecialchars($out_window_fmt) ?></strong>
        </span>
        <?php if (!empty($hostel_lat) && !empty($hostel_long)): ?>
          <span class="timing-pill-info mb-1">
            <i class="fa-solid fa-location-dot mr-1 text-success"></i> Geofence: <strong><?= htmlspecialchars($hostel_lat) ?>, <?= htmlspecialchars($hostel_long) ?></strong>
          </span>
        <?php endif; ?>
      </div>

    </div>
    
    <div class="mt-3 mt-md-0 d-flex align-items-center gap-3">
      <?php if ($already_marked_today): ?>
        <?php $today_status_label = formatAttendanceStatusText($today_attendance['status'], $today_attendance['created_at'], $att_end_time); ?>
        <span class="badge badge-light text-success font-weight-bold px-3 py-2 mr-3" style="font-size: 0.85rem; border-radius: 10px; background: rgba(255,255,255,0.95);">
          <i class="fa-solid fa-circle-check text-success mr-1"></i> Today's Attendance Marked (<?= htmlspecialchars($today_status_label) ?>)
        </span>
      <?php else: ?>
        <form method="POST" action="attendance.php" class="d-inline">
          <input type="hidden" name="submit_attendance" value="1">
          <button type="button" onclick="submitAttendanceWithGeofence(this.form)" class="btn btn-light font-weight-bold shadow-sm rounded-12 px-3 py-2 text-primary mr-3" style="color: #4338ca !important; border: 1px solid #e0e7ff;">
            <i class="fa-solid fa-hand-pointer mr-1"></i> Mark Today's Attendance
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Alert Notifications -->
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4 auto-dismiss-alert" role="alert" style="background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7 !important;">
    <i class="fa-solid fa-circle-check mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['msg']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4 auto-dismiss-alert" role="alert" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5 !important;">
    <i class="fa-solid fa-circle-exclamation mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- 4 Key Stat Metrics Cards -->
<div class="row mb-4">
  <!-- Total Recorded -->
  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Total Logged</span>
          <h3 class="font-weight-bold text-dark mb-0"><?= $total_days ?> Days</h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-rate">
          <i class="fa-solid fa-calendar-days"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Present Days -->
  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Present Days</span>
          <h3 class="font-weight-bold text-success mb-0"><?= $present_days ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-present">
          <i class="fa-solid fa-user-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Absent Days -->
  <div class="col-lg-3 col-sm-6 mb-3 mb-lg-0">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Absent Days</span>
          <h3 class="font-weight-bold text-danger mb-0"><?= $absent_days ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-absent">
          <i class="fa-solid fa-user-xmark"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Late / Leave / Outing -->
  <div class="col-lg-3 col-sm-6">
    <div class="stat-card-widget">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-600 d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;">Leave / Outing</span>
          <h3 class="font-weight-bold text-warning mb-0"><?= ($leave_days + $outing_days) ?></h3>
        </div>
        <div class="stat-icon-wrapper stat-icon-late">
          <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filter Controls Card -->
<div class="filter-control-card">
  <form method="GET" action="attendance.php" class="form-row align-items-end">
    <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Select Month</label>
      <input type="month" name="month" class="form-control custom-form-control" value="<?= htmlspecialchars($selected_month) ?>">
    </div>

    <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
      <label class="font-weight-600 text-dark mb-1" style="font-size: 0.83rem;">Attendance Status</label>
      <select name="status" class="form-control custom-form-control">
        <option value="all" <?= ($selected_status == 'all') ? 'selected' : '' ?>>All Statuses</option>
        <option value="Present" <?= ($selected_status == 'Present') ? 'selected' : '' ?>>Present</option>
        <option value="Absent" <?= ($selected_status == 'Absent') ? 'selected' : '' ?>>Absent</option>
        <option value="Late" <?= ($selected_status == 'Late') ? 'selected' : '' ?>>Late</option>
        <option value="Leave" <?= ($selected_status == 'Leave') ? 'selected' : '' ?>>Leave</option>
        <option value="Outing" <?= ($selected_status == 'Outing') ? 'selected' : '' ?>>Outing</option>
      </select>
    </div>

    <div class="col-md-4 col-sm-12 d-flex gap-2">
      <button type="submit" class="btn btn-student-primary flex-grow-1">
        <i class="fa-solid fa-filter"></i> Apply Filter
      </button>
      <a href="attendance.php" class="btn btn-light border font-weight-600 ml-2" style="height: 42px; display: inline-flex; align-items: center;">
        Reset
      </a>
    </div>
  </form>
</div>

<!-- Attendance Master Data Card -->
<div class="row">
  <div class="col-12">
    <div class="card shadow-sm border-0 rounded-16">
      <div class="card-body p-4">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
          <div>
            <h4 class="card-title font-weight-bold mb-1" style="font-size: 1.2rem; color: #0f172a;">
              <i class="fa-solid fa-list-check text-primary mr-2" style="color: var(--student-primary) !important;"></i>Daily Attendance Records
            </h4>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
              Detailed breakdown of daily curfew checks and hostel warden verifications.
            </p>
          </div>
          
          <?php if ($already_marked_today): ?>
            <?php $today_status_label = formatAttendanceStatusText($today_attendance['status'], $today_attendance['created_at'], $att_end_time); ?>
            <span class="badge badge-light border text-success font-weight-bold px-3 py-2 mt-2 mt-sm-0" style="font-size: 0.84rem;">
              <i class="fa-solid fa-circle-check mr-1 text-success"></i> Today Marked (<?= htmlspecialchars($today_status_label) ?>)
            </span>
          <?php else: ?>
            <form method="POST" action="attendance.php" class="d-inline">
              <input type="hidden" name="submit_attendance" value="1">
              <button type="button" onclick="submitAttendanceWithGeofence(this.form)" class="btn btn-student-primary font-weight-bold shadow-sm mt-2 mt-sm-0">
                <i class="fa-solid fa-hand-pointer mr-1"></i> Mark Today's Attendance
              </button>
            </form>
          <?php endif; ?>
        </div>

        <?php if (empty($attendance_records)): ?>
          <div class="text-center py-5">
            <div class="mb-3">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 75px; height: 75px; background: rgba(99, 102, 241, 0.08); color: #6366f1; font-size: 2rem;">
                <i class="fa-solid fa-calendar-xmark"></i>
              </span>
            </div>
            <h5 class="font-weight-bold text-dark mb-1">No Attendance Records Found</h5>
            <p class="text-muted mb-0" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
              No attendance records match your selected month or filter criteria.
            </p>
            <?php if (!$already_marked_today): ?>
              <form method="POST" action="attendance.php" class="d-inline mt-3">
                <input type="hidden" name="submit_attendance" value="1">
                <button type="button" onclick="submitAttendanceWithGeofence(this.form)" class="btn btn-student-primary font-weight-bold">
                  <i class="fa-solid fa-hand-pointer mr-1"></i> Mark Today's Attendance
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle datatable" id="attendanceTable">
              <thead class="bg-light text-uppercase font-weight-bold">
                <tr>
                  <th style="width: 55px;" class="text-center">#</th>
                  <th style="width: 140px;">Date</th>
                  <th style="width: 130px;">Day</th>
                  <th style="width: 140px;">Status</th>
                  <th style="min-width: 240px;">Remarks</th>
                  <th style="width: 140px;">Timestamp</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sl = 1;
                foreach ($attendance_records as $row): 
                  $date_time = strtotime($row['attendance_date']);
                  $day_name = date('l', $date_time);
                  $formatted_date = date('d M, Y', $date_time);
                  $status_val = $row['status'];
                ?>
                  <tr>
                    <td class="font-weight-600 text-muted text-center"><?= $sl++ ?></td>
                    <td class="font-weight-700 text-dark"><?= $formatted_date ?></td>
                    <td><span class="text-muted font-weight-500"><i class="fa-regular fa-calendar mr-1"></i><?= $day_name ?></span></td>
                    
                    <td>
                      <?php 
                      $formatted_status_label = formatAttendanceStatusText($status_val, $row['created_at'], $att_end_time);
                      $is_calculated_late = (stripos($formatted_status_label, 'Late') !== false);
                      ?>

                      <?php if ($is_calculated_late): ?>
                        <span class="badge-status badge-status-late">
                          <i class="fa-solid fa-clock"></i> <?= htmlspecialchars($formatted_status_label) ?>
                        </span>
                      <?php elseif (strcasecmp($status_val, 'Present') === 0): ?>
                        <span class="badge-status badge-status-present">
                          <i class="fa-solid fa-circle-check"></i> Present
                        </span>
                      <?php elseif (strcasecmp($status_val, 'Absent') === 0): ?>
                        <span class="badge-status badge-status-absent">
                          <i class="fa-solid fa-circle-xmark"></i> Absent
                        </span>
                      <?php elseif (strcasecmp($status_val, 'Leave') === 0): ?>
                        <span class="badge-status badge-status-leave">
                          <i class="fa-solid fa-plane-departure"></i> Leave
                        </span>
                      <?php elseif (strcasecmp($status_val, 'Outing') === 0): ?>
                        <span class="badge-status badge-status-outing">
                          <i class="fa-solid fa-person-walking-luggage"></i> Outing
                        </span>
                      <?php else: ?>
                        <span class="badge-status badge-status-leave">
                          <?= htmlspecialchars($status_val) ?>
                        </span>
                      <?php endif; ?>
                    </td>

                    <td style="white-space: normal !important; word-break: break-word;">
                      <span class="text-secondary" style="font-size: 0.86rem;">
                        <?= !empty($row['remarks']) ? htmlspecialchars($row['remarks']) : '<span class="text-muted font-italic">-</span>' ?>
                      </span>
                    </td>

                    <td>
                      <small class="text-muted font-weight-500">
                        <?= !empty($row['created_at']) ? date('h:i A', strtotime($row['created_at'])) : '09:00 PM' ?>
                      </small>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>



<script>
const HOSTEL_LAT = <?= (!empty($hostel_lat) && is_numeric($hostel_lat)) ? floatval($hostel_lat) : 'null' ?>;
const HOSTEL_LONG = <?= (!empty($hostel_long) && is_numeric($hostel_long)) ? floatval($hostel_long) : 'null' ?>;
const MAX_ALLOWED_DISTANCE_METERS = 100;

function calculateDistanceInMeters(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function submitAttendanceWithGeofence(formElement) {
    if (HOSTEL_LAT === null || HOSTEL_LONG === null) {
        // If hostel coordinates are not set in hostel_timings, submit directly
        formElement.submit();
        return;
    }

    if (!navigator.geolocation) {
        alert("Cant Mark your attendance because you are not in the hostel");
        return;
    }

    const btn = formElement.querySelector('button');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Verifying Location...';
    }

    navigator.geolocation.getCurrentPosition(
        function(position) {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;
            const distance = calculateDistanceInMeters(HOSTEL_LAT, HOSTEL_LONG, userLat, userLng);

            if (distance > MAX_ALLOWED_DISTANCE_METERS) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
                alert("Cant Mark your attendance because you are not in the hostel");
            } else {
                let inputLat = formElement.querySelector('input[name="user_lat"]');
                let inputLong = formElement.querySelector('input[name="user_long"]');
                if (!inputLat) {
                    inputLat = document.createElement('input');
                    inputLat.type = 'hidden';
                    inputLat.name = 'user_lat';
                    formElement.appendChild(inputLat);
                }
                if (!inputLong) {
                    inputLong = document.createElement('input');
                    inputLong.type = 'hidden';
                    inputLong.name = 'user_long';
                    formElement.appendChild(inputLong);
                }
                inputLat.value = userLat;
                inputLong.value = userLng;
                formElement.submit();
            }
        },
        function(error) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
            alert("Cant Mark your attendance because you are not in the hostel");
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

document.addEventListener("DOMContentLoaded", function() {
    // Auto-dismiss success/error alert messages after 3 seconds (3000ms)
    setTimeout(function() {
        const alerts = document.querySelectorAll('.auto-dismiss-alert');
        alerts.forEach(function(alertEl) {
            if (typeof $ !== 'undefined' && $.fn.fadeOut) {
                $(alertEl).fadeOut(500, function() {
                    $(this).remove();
                });
            } else {
                alertEl.style.transition = "opacity 0.5s ease";
                alertEl.style.opacity = "0";
                setTimeout(function() { alertEl.remove(); }, 500);
            }
        });
    }, 3000);

    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [],
            "paging": false,
            "info": false,
            "searching": false,
            "columnDefs": [
                { "orderable": false, "targets": [4] }
            ]
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
