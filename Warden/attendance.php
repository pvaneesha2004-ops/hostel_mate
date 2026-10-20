<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['warden_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

$warden_id = $_SESSION['warden_id'];
$warden_name = $_SESSION['warden_name'] ?? 'Hostel Warden';
$warden_code = $_SESSION['warden_code'] ?? 'Warden';
$warden_signature = "Warden: " . $warden_name . " (" . $warden_code . ")";

// Auto-create attendance table if not exists (safety fallback)
$create_table_sql = "CREATE TABLE IF NOT EXISTS `attendance` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `student_id` BIGINT NOT NULL,
    `attendance_date` DATE NOT NULL,
    `status` ENUM('present','absent','late','leave','outing') NOT NULL DEFAULT 'present',
    `marked_by` VARCHAR(100) DEFAULT 'Hostel Warden',
    `remarks` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `student_date_unique` (`student_id`, `attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_table_sql);

// Handle POST actions: Mark Single Attendance
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_attendance') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $attendance_date = !empty($_POST['attendance_date']) ? trim($_POST['attendance_date']) : date('Y-m-d');
    $status = strtolower(trim($_POST['status'] ?? 'present'));
    $allowed_statuses = ['present', 'absent', 'late', 'leave', 'outing'];
    if (!in_array($status, $allowed_statuses)) {
        $status = 'present';
    }
    $remarks = trim($_POST['remarks'] ?? '');

    if ($student_id <= 0) {
        $_SESSION['error'] = "Please select a valid student.";
    } elseif (empty($attendance_date) || !strtotime($attendance_date)) {
        $_SESSION['error'] = "Please provide a valid attendance date.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO `attendance` (`student_id`, `attendance_date`, `status`, `marked_by`, `remarks`, `created_at`, `updated_at`) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                `status` = VALUES(`status`),
                `marked_by` = VALUES(`marked_by`),
                `remarks` = VALUES(`remarks`),
                `updated_at` = NOW()
        ");
        if ($stmt) {
            $stmt->bind_param("issss", $student_id, $attendance_date, $status, $warden_signature, $remarks);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Attendance recorded successfully for " . date('d M, Y', strtotime($attendance_date)) . " as " . ucfirst($status) . ".";
            } else {
                $_SESSION['error'] = "Failed to record attendance: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    }
    // Maintain filter in redirect
    $redirect_url = "attendance.php?date=" . urlencode($attendance_date);
    header("Location: " . $redirect_url);
    exit();
}

// Handle POST actions: Edit Attendance
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_attendance') {
    $att_id = intval($_POST['id'] ?? 0);
    $status = strtolower(trim($_POST['status'] ?? 'present'));
    $allowed_statuses = ['present', 'absent', 'late', 'leave', 'outing'];
    if (!in_array($status, $allowed_statuses)) {
        $status = 'present';
    }
    $remarks = trim($_POST['remarks'] ?? '');
    $filter_date = trim($_POST['return_date'] ?? '');

    if ($att_id > 0) {
        $stmt = $conn->prepare("UPDATE `attendance` SET `status` = ?, `marked_by` = ?, `remarks` = ?, `updated_at` = NOW() WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param("sssi", $status, $warden_signature, $remarks, $att_id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Attendance entry updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update attendance record.";
            }
            $stmt->close();
        }
    } else {
        $_SESSION['error'] = "Invalid attendance record ID.";
    }
    
    $redirect_url = !empty($filter_date) ? "attendance.php?date=" . urlencode($filter_date) : "attendance.php";
    header("Location: " . $redirect_url);
    exit();
}

// Handle POST actions: Bulk Daily Roll Call
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_roll_call') {
    $bulk_date = !empty($_POST['bulk_date']) ? trim($_POST['bulk_date']) : date('Y-m-d');
    $student_statuses = $_POST['student_status'] ?? [];
    $student_remarks = $_POST['student_remarks'] ?? [];
    $allowed_statuses = ['present', 'absent', 'late', 'leave', 'outing'];

    $recorded_count = 0;
    if (is_array($student_statuses) && count($student_statuses) > 0) {
        $stmt = $conn->prepare("
            INSERT INTO `attendance` (`student_id`, `attendance_date`, `status`, `marked_by`, `remarks`, `created_at`, `updated_at`) 
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                `status` = VALUES(`status`),
                `marked_by` = VALUES(`marked_by`),
                `remarks` = VALUES(`remarks`),
                `updated_at` = NOW()
        ");
        foreach ($student_statuses as $stu_id => $st_val) {
            $s_id = intval($stu_id);
            $st_clean = strtolower(trim($st_val));
            if (!in_array($st_clean, $allowed_statuses)) {
                $st_clean = 'present';
            }
            $rmk = trim($student_remarks[$s_id] ?? 'Roll Call marked by Warden');

            if ($s_id > 0 && $stmt) {
                $stmt->bind_param("issss", $s_id, $bulk_date, $st_clean, $warden_signature, $rmk);
                if ($stmt->execute()) {
                    $recorded_count++;
                }
            }
        }
        if ($stmt) {
            $stmt->close();
        }
        $_SESSION['msg'] = "Daily roll call completed! Successfully logged attendance for {$recorded_count} student(s) on " . date('d M, Y', strtotime($bulk_date)) . ".";
    } else {
        $_SESSION['error'] = "No student attendance data was provided.";
    }

    header("Location: attendance.php?date=" . urlencode($bulk_date));
    exit();
}

// Handle GET actions: Delete Attendance Record
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $return_date = trim($_GET['return_date'] ?? '');
    if ($del_id > 0) {
        $del_stmt = $conn->prepare("DELETE FROM `attendance` WHERE `id` = ?");
        if ($del_stmt) {
            $del_stmt->bind_param("i", $del_id);
            if ($del_stmt->execute()) {
                $_SESSION['msg'] = "Attendance entry deleted successfully.";
            } else {
                $_SESSION['error'] = "Failed to delete attendance record.";
            }
            $del_stmt->close();
        }
    }
    $redirect_url = !empty($return_date) ? "attendance.php?date=" . urlencode($return_date) : "attendance.php";
    header("Location: " . $redirect_url);
    exit();
}

// Fetch Hostel Timings
$hostel_timings_query = $conn->query("SELECT * FROM `hostel_timings` ORDER BY `id` DESC LIMIT 1");
$hostel_timing = ($hostel_timings_query && $hostel_timings_query->num_rows > 0) ? $hostel_timings_query->fetch_assoc() : null;
$att_start_time = $hostel_timing ? $hostel_timing['attendance_start_time'] : '20:00:00';
$att_end_time   = $hostel_timing ? $hostel_timing['attendance_end_time'] : '21:30:00';
$out_start_time = $hostel_timing ? $hostel_timing['outing_start_time'] : '06:00:00';
$out_end_time   = $hostel_timing ? $hostel_timing['outing_end_time'] : '20:00:00';
$hostel_lat     = $hostel_timing ? $hostel_timing['latitude'] : '';
$hostel_long    = $hostel_timing ? $hostel_timing['longitude'] : '';

$att_window_fmt = (!empty($att_start_time) && !empty($att_end_time))
    ? date('h:i A', strtotime($att_start_time)) . ' - ' . date('h:i A', strtotime($att_end_time))
    : '08:00 PM - 09:30 PM';
$out_window_fmt = (!empty($out_start_time) && !empty($out_end_time))
    ? date('h:i A', strtotime($out_start_time)) . ' - ' . date('h:i A', strtotime($out_end_time))
    : '06:00 AM - 08:00 PM';

// Format Attendance Status text and calculate exact Late duration (matching Student/attendance.php)
function formatAttendanceStatusText($status_val, $created_at, $att_end_time = '21:30:00', $attendance_date = '') {
    $fallback_end_time = !empty($att_end_time) ? $att_end_time : '21:30:00';
    $is_late_status = (stripos($status_val, 'Late') !== false);
    
    if (!empty($created_at)) {
        $created_timestamp = strtotime($created_at);
        if ($created_timestamp !== false && $created_timestamp > 0) {
            $record_date_str = !empty($attendance_date) ? $attendance_date : date('Y-m-d', $created_timestamp);
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
    return ucfirst($status_val);
}

// -------------------------------------------------------------
// FILTER HANDLING (DATE-WISE, STATUS, BLOCK, PRESETS)
// -------------------------------------------------------------
$preset = $_GET['preset'] ?? '';
$filter_mode = $_GET['filter_mode'] ?? 'single';
$filter_date = isset($_GET['date']) ? trim($_GET['date']) : '';
$from_date   = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date     = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$status_filter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : 'all';
$block_filter = isset($_GET['block_id']) ? intval($_GET['block_id']) : 0;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// First visit default behavior: Default to Today
if (!isset($_GET['date']) && !isset($_GET['preset']) && !isset($_GET['from_date']) && !isset($_GET['all'])) {
    $preset = 'today';
    $filter_date = date('Y-m-d');
    $filter_mode = 'single';
}

// Preset overrides
$active_preset_name = '';
if ($preset === 'today') {
    $filter_mode = 'single';
    $filter_date = date('Y-m-d');
    $from_date = '';
    $to_date = '';
    $active_preset_name = 'today';
} elseif ($preset === 'yesterday') {
    $filter_mode = 'single';
    $filter_date = date('Y-m-d', strtotime('-1 day'));
    $from_date = '';
    $to_date = '';
    $active_preset_name = 'yesterday';
} elseif ($preset === 'last7') {
    $filter_mode = 'range';
    $from_date = date('Y-m-d', strtotime('-6 days'));
    $to_date = date('Y-m-d');
    $filter_date = '';
    $active_preset_name = 'last7';
} elseif ($preset === 'this_month') {
    $filter_mode = 'range';
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-t');
    $filter_date = '';
    $active_preset_name = 'this_month';
} elseif ($preset === 'all') {
    $filter_mode = 'all';
    $filter_date = '';
    $from_date = '';
    $to_date = '';
    $active_preset_name = 'all';
} else {
    // Custom date / range input
    if (!empty($from_date) && !empty($to_date)) {
        $filter_mode = 'range';
    } elseif (!empty($filter_date)) {
        $filter_mode = 'single';
        if ($filter_date === date('Y-m-d')) {
            $active_preset_name = 'today';
        } elseif ($filter_date === date('Y-m-d', strtotime('-1 day'))) {
            $active_preset_name = 'yesterday';
        }
    } else {
        $filter_mode = 'all';
        $active_preset_name = 'all';
    }
}

// Build SQL WHERE condition
$where_clauses = ["u.role = 'student'"];

// Date Filtering
if ($filter_mode === 'range' && !empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $where_clauses[] = "(a.attendance_date BETWEEN '{$safe_from}' AND '{$safe_to}')";
    $date_badge_label = date('d M, Y', strtotime($from_date)) . " to " . date('d M, Y', strtotime($to_date));
} elseif ($filter_mode === 'single' && !empty($filter_date)) {
    $safe_date = $conn->real_escape_string($filter_date);
    $where_clauses[] = "a.attendance_date = '{$safe_date}'";
    $date_badge_label = date('l, d M Y', strtotime($filter_date));
} else {
    // All dates
    $date_badge_label = "All Recorded Dates";
}

// Status filter
if (!empty($status_filter) && $status_filter !== 'all') {
    $safe_status = $conn->real_escape_string($status_filter);
    $where_clauses[] = "LOWER(a.status) = '{$safe_status}'";
}

// Block filter
if ($block_filter > 0) {
    $where_clauses[] = "ra.block_id = {$block_filter}";
}

// Text search
if (!empty($search_query)) {
    $safe_q = $conn->real_escape_string($search_query);
    $where_clauses[] = "(u.name LIKE '%{$safe_q}%' OR u.email LIKE '%{$safe_q}%' OR u.phone LIKE '%{$safe_q}%' OR rm.room_number LIKE '%{$safe_q}%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch Attendance Data
$query_sql = "
    SELECT 
        a.id AS attendance_id,
        a.student_id,
        a.attendance_date,
        a.status,
        a.marked_by,
        a.remarks,
        a.created_at AS record_created_at,
        a.updated_at AS record_updated_at,
        u.name AS student_name,
        u.email AS student_email,
        u.phone AS student_phone,
        u.profile_image AS student_profile_image,
        u.status AS student_account_status,
        ra.id AS allocation_id,
        ra.block_id,
        ra.floor_id,
        ra.room_id,
        ra.bed_id,
        blk.name AS block_name,
        flr.name AS floor_name,
        rm.room_number,
        rm.room_type,
        bd.bed_number,
        g.name AS guardian_name,
        g.phone AS guardian_phone,
        g.relationship AS guardian_rel
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN floors flr ON ra.floor_id = flr.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    LEFT JOIN student_guardians g ON u.id = g.student_id
    WHERE {$where_sql}
    ORDER BY a.attendance_date DESC, a.created_at DESC, a.id DESC
";

$records_res = $conn->query($query_sql);
$attendance_records = [];
if ($records_res && $records_res->num_rows > 0) {
    while ($row = $records_res->fetch_assoc()) {
        $attendance_records[] = $row;
    }
}

// Calculate KPI summary metrics for the filtered result set
$kpi_total   = count($attendance_records);
$kpi_present = 0;
$kpi_late    = 0;
$kpi_absent  = 0;
$kpi_leave   = 0;
$kpi_outing  = 0;

foreach ($attendance_records as $rec) {
    $st = strtolower($rec['status'] ?? 'present');
    $calc_label = formatAttendanceStatusText($st, $rec['record_created_at'] ?? '', $att_end_time, $rec['attendance_date'] ?? '');
    $is_late = (stripos($calc_label, 'Late') !== false) || ($st === 'late');

    if ($is_late) {
        $kpi_late++;
    } elseif ($st === 'present') {
        $kpi_present++;
    } elseif ($st === 'absent') {
        $kpi_absent++;
    } elseif ($st === 'leave') {
        $kpi_leave++;
    } elseif ($st === 'outing') {
        $kpi_outing++;
    }
}

$kpi_present_pct = ($kpi_total > 0) ? round(($kpi_present / $kpi_total) * 100, 1) : 0;
$kpi_late_pct    = ($kpi_total > 0) ? round(($kpi_late / $kpi_total) * 100, 1) : 0;
$kpi_absent_pct  = ($kpi_total > 0) ? round(($kpi_absent / $kpi_total) * 100, 1) : 0;

// Fetch active student list for the manual entry & roll call dropdown
$students_sql = "
    SELECT 
        u.id, 
        u.name, 
        u.email, 
        u.phone, 
        u.profile_image,
        blk.name AS block_name,
        rm.room_number,
        bd.bed_number
    FROM users u
    LEFT JOIN room_allocations ra ON u.id = ra.student_id AND ra.status = 'active'
    LEFT JOIN blocks blk ON ra.block_id = blk.id
    LEFT JOIN rooms rm ON ra.room_id = rm.id
    LEFT JOIN beds bd ON ra.bed_id = bd.id
    WHERE u.role = 'student' AND (u.status = 'active' OR u.status = '1')
    ORDER BY u.name ASC
";
$active_students_res = $conn->query($students_sql);
$active_students = [];
if ($active_students_res && $active_students_res->num_rows > 0) {
    while ($s = $active_students_res->fetch_assoc()) {
        $active_students[] = $s;
    }
}

// Fetch all blocks for the filter dropdown
$blocks_res = $conn->query("SELECT id, name FROM blocks WHERE status = 'active' OR status = '1' ORDER BY name ASC");
$all_blocks = [];
if ($blocks_res && $blocks_res->num_rows > 0) {
    while ($b = $blocks_res->fetch_assoc()) {
        $all_blocks[] = $b;
    }
}

ob_start();
?>

<style>
  /* =========================================================
     WARDEN ATTENDANCE MODULE - PREMIUM EMERALD OBSIDIAN STYLES
     ========================================================= */
  :root {
    --att-emerald-gradient: linear-gradient(135deg, #059669 0%, #0d9488 100%);
    --att-card-bg: #ffffff;
    --att-border: #e2e8f0;
  }

  .att-hero-card {
    background: linear-gradient(135deg, #064e3b 0%, #059669 50%, #0d9488 100%);
    border-radius: 20px;
    padding: 2rem 2.25rem;
    color: #ffffff;
    box-shadow: 0 10px 30px -5px rgba(5, 150, 105, 0.35);
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
  }

  .att-hero-card::after {
    content: '';
    position: absolute;
    right: -50px;
    bottom: -50px;
    width: 250px;
    height: 250px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
    pointer-events: none;
  }

  .att-badge-tag {
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .att-timing-chip {
    background: rgba(0, 0, 0, 0.22);
    border: 1px solid rgba(255, 255, 255, 0.15);
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    font-size: 0.82rem;
    color: #ecfdf5;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  /* Filter Panel Box */
  .att-filter-card {
    background: #ffffff;
    border-radius: 18px;
    padding: 1.5rem 1.75rem;
    border: 1px solid #e6f4ea;
    box-shadow: 0 4px 20px rgba(5, 150, 105, 0.05);
    margin-bottom: 2rem;
  }

  .date-preset-pill {
    padding: 0.45rem 1rem;
    border-radius: 50px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1.5px solid #d1fae5;
    background: #f0fdf4;
    color: #047857;
    text-decoration: none !important;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-right: 0.4rem;
    margin-bottom: 0.4rem;
  }

  .date-preset-pill:hover {
    background: #10b981;
    color: #ffffff !important;
    border-color: #10b981;
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(16, 185, 129, 0.25);
  }

  .date-preset-pill.active {
    background: var(--att-emerald-gradient) !important;
    color: #ffffff !important;
    border-color: #059669 !important;
    box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
  }

  /* Metric KPI Cards */
  .metric-kpi-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 1.25rem 1.4rem;
    border: 1px solid #ecfdf5;
    box-shadow: 0 4px 18px rgba(5, 150, 105, 0.04);
    transition: all 0.25s ease;
    height: 100%;
    position: relative;
    overflow: hidden;
  }

  .metric-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(5, 150, 105, 0.1);
  }

  .metric-kpi-card .kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
  }

  .icon-total   { background: #e0f2fe; color: #0284c7; }
  .icon-present { background: #d1fae5; color: #059669; }
  .icon-late    { background: #fef3c7; color: #d97706; }
  .icon-absent  { background: #fee2e2; color: #dc2626; }
  .icon-leave   { background: #f3e8ff; color: #7c3aed; }

  /* Status Badges */
  .badge-att-status {
    padding: 0.45rem 0.85rem;
    border-radius: 50px;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0.3px;
  }

  .badge-status-present {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #a7f3d0;
  }

  .badge-status-late {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
  }

  .badge-status-absent {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
  }

  .badge-status-leave {
    background: #e0f2fe;
    color: #075985;
    border: 1px solid #bae6fd;
  }

  .badge-status-outing {
    background: #f3e8ff;
    color: #6b21a8;
    border: 1px solid #e9d5ff;
  }

  /* Table Customization */
  #attendanceMasterTable th {
    background: #f8fafc !important;
    color: #334155 !important;
    font-weight: 700;
    font-size: 0.76rem !important;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border-bottom: 2px solid #e2e8f0 !important;
    padding: 1rem 1.1rem !important;
    white-space: nowrap;
  }

  #attendanceMasterTable td {
    padding: 1rem 1.1rem !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #f1f5f9 !important;
  }

  .student-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #d1fae5;
    background: #ecfdf5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #059669;
    font-size: 0.95rem;
  }



  .custom-form-input {
    border-radius: 10px !important;
    border: 1.5px solid #d1fae5 !important;
    padding: 0.6rem 0.9rem !important;
    font-size: 0.88rem !important;
    font-weight: 500 !important;
  }

  .custom-form-input:focus {
    border-color: #059669 !important;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15) !important;
  }

  .mode-switch-btn {
    border-radius: 8px;
    padding: 0.35rem 0.75rem;
    font-size: 0.78rem;
    font-weight: 600;
  }

  @media print {
    .navbar, .sidebar, .att-hero-card, .att-filter-card, .no-print, .btn, .dataTables_filter, .dataTables_length, .dataTables_paginate {
      display: none !important;
    }
    .main-panel, .content-wrapper {
      width: 100% !important;
      max-width: 100% !important;
      padding: 0 !important;
    }
    #attendanceMasterTable {
      width: 100% !important;
    }
  }
</style>

<!-- =========================================================
     HERO BANNER
     ========================================================= -->
<div class="att-hero-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center">
    <div class="mb-3 mb-lg-0">
      <div class="d-flex align-items-center mb-2">
        <span class="att-badge-tag mr-2">
          <i class="fa-solid fa-clipboard-user"></i> Warden Desk
        </span>
        <span class="att-badge-tag">
          <i class="fa-solid fa-calendar-day"></i> <?= htmlspecialchars($date_badge_label) ?>
        </span>
      </div>
      <h2 class="font-weight-800 text-white mb-2" style="letter-spacing: -0.5px;">Student Attendance Records</h2>
      <p class="text-white-50 mb-3" style="max-width: 650px; font-size: 0.94rem; line-height: 1.5;">
        Monitor real-time hostel presence, daily check-in timestamps, late flag violations, and leave status across student dormitories.
      </p>

      <!-- Active Hostel Timing Chips -->
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="att-timing-chip mr-2 mb-1">
          <i class="fa-solid fa-clock text-warning"></i> Curfew Window: <strong><?= htmlspecialchars($att_window_fmt) ?></strong>
        </span>
        <span class="att-timing-chip mr-2 mb-1">
          <i class="fa-solid fa-door-open text-info"></i> Outing Window: <strong><?= htmlspecialchars($out_window_fmt) ?></strong>
        </span>
        <?php if (!empty($hostel_lat) && !empty($hostel_long)): ?>
          <span class="att-timing-chip mb-1">
            <i class="fa-solid fa-location-dot text-success"></i> Geofence Active (100m)
          </span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- =========================================================
     ALERT NOTIFICATIONS
     ========================================================= -->
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4" role="alert" style="background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7 !important;">
    <i class="fa-solid fa-circle-check mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['msg']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show rounded-12 border-0 shadow-sm mb-4" role="alert" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5 !important;">
    <i class="fa-solid fa-circle-exclamation mr-2 font-weight-bold"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- =========================================================
     DATE-WISE & ADVANCED FILTER CARD
     ========================================================= -->
<div class="att-filter-card">
  <!-- Preset Date Pills -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-3 border-bottom">
    <div class="d-flex flex-wrap align-items-center">
      <span class="font-weight-700 text-dark mr-3 mb-1" style="font-size: 0.86rem;">
        <i class="fa-solid fa-filter text-success mr-1"></i> Quick Date Filter:
      </span>
      <a href="attendance.php?preset=today" class="date-preset-pill <?= ($active_preset_name === 'today') ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-day"></i> Today (<?= date('d M') ?>)
      </a>
      <a href="attendance.php?preset=yesterday" class="date-preset-pill <?= ($active_preset_name === 'yesterday') ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-minus"></i> Yesterday
      </a>
      <a href="attendance.php?preset=last7" class="date-preset-pill <?= ($active_preset_name === 'last7') ? 'active' : '' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i> Last 7 Days
      </a>
      <a href="attendance.php?preset=this_month" class="date-preset-pill <?= ($active_preset_name === 'this_month') ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-week"></i> This Month (<?= date('M Y') ?>)
      </a>
      <a href="attendance.php?preset=all" class="date-preset-pill <?= ($active_preset_name === 'all') ? 'active' : '' ?>">
        <i class="fa-solid fa-layer-group"></i> All Records
      </a>
    </div>

    <!-- Single Date vs Range Toggle Buttons -->
    <div class="btn-group btn-group-sm mt-2 mt-md-0" role="group">
      <button type="button" id="btnModeSingle" class="btn mode-switch-btn <?= ($filter_mode === 'single') ? 'btn-success text-white' : 'btn-outline-secondary' ?>" onclick="switchDateMode('single')">
        <i class="fa-solid fa-calendar mr-1"></i> Specific Date
      </button>
      <button type="button" id="btnModeRange" class="btn mode-switch-btn <?= ($filter_mode === 'range') ? 'btn-success text-white' : 'btn-outline-secondary' ?>" onclick="switchDateMode('range')">
        <i class="fa-solid fa-calendar-range mr-1"></i> Date Range
      </button>
    </div>
  </div>

  <!-- Detailed Filter Form -->
  <form method="GET" action="attendance.php" id="attendanceFilterForm" class="row align-items-end">
    <input type="hidden" name="filter_mode" id="input_filter_mode" value="<?= htmlspecialchars($filter_mode) ?>">

    <!-- Single Date Field -->
    <div class="col-lg-3 col-md-6 col-sm-12 mb-3 mb-lg-0" id="singleDateContainer" style="<?= ($filter_mode === 'range') ? 'display: none;' : '' ?>">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-regular fa-calendar-check text-success mr-1"></i> Select Date
      </label>
      <input type="date" name="date" id="input_filter_date" class="form-control custom-form-input" value="<?= htmlspecialchars(!empty($filter_date) ? $filter_date : ($filter_mode === 'single' ? date('Y-m-d') : '')) ?>">
    </div>

    <!-- Date Range Fields (From / To) -->
    <div class="col-lg-3 col-md-6 col-sm-12 mb-3 mb-lg-0" id="rangeDateContainer" style="<?= ($filter_mode === 'range') ? '' : 'display: none;' ?>">
      <div class="row no-gutters">
        <div class="col-6 pr-1">
          <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">From Date</label>
          <input type="date" name="from_date" id="input_from_date" class="form-control custom-form-input" value="<?= htmlspecialchars($from_date) ?>">
        </div>
        <div class="col-6 pl-1">
          <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">To Date</label>
          <input type="date" name="to_date" id="input_to_date" class="form-control custom-form-input" value="<?= htmlspecialchars($to_date) ?>">
        </div>
      </div>
    </div>

    <!-- Status Dropdown -->
    <div class="col-lg-2 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-solid fa-flag text-success mr-1"></i> Status
      </label>
      <select name="status" class="form-control custom-form-input">
        <option value="all" <?= ($status_filter === 'all') ? 'selected' : '' ?>>All Statuses</option>
        <option value="present" <?= ($status_filter === 'present') ? 'selected' : '' ?>>Present</option>
        <option value="late" <?= ($status_filter === 'late') ? 'selected' : '' ?>>Late</option>
        <option value="absent" <?= ($status_filter === 'absent') ? 'selected' : '' ?>>Absent</option>
        <option value="leave" <?= ($status_filter === 'leave') ? 'selected' : '' ?>>Leave</option>
        <option value="outing" <?= ($status_filter === 'outing') ? 'selected' : '' ?>>Outing</option>
      </select>
    </div>

    <!-- Block Dropdown -->
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-solid fa-building text-success mr-1"></i> Hostel Block
      </label>
      <select name="block_id" class="form-control custom-form-input">
        <option value="0">All Hostel Blocks</option>
        <?php foreach ($all_blocks as $blk): ?>
          <option value="<?= $blk['id'] ?>" <?= ($block_filter == $blk['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($blk['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Search keyword -->
    <div class="col-lg-2 col-md-6 col-sm-6 mb-3 mb-lg-0">
      <label class="font-weight-700 text-dark mb-1" style="font-size: 0.82rem;">
        <i class="fa-solid fa-magnifying-glass text-success mr-1"></i> Search Student
      </label>
      <input type="text" name="search" class="form-control custom-form-input" placeholder="Name or Room..." value="<?= htmlspecialchars($search_query) ?>">
    </div>

    <!-- Submit & Reset Buttons -->
    <div class="col-lg-2 col-md-6 col-sm-6 d-flex align-items-center mt-3 mt-lg-0">
      <button type="submit" class="btn btn-success font-weight-700 rounded-10 flex-grow-1 shadow-sm" style="background: var(--att-emerald-gradient); border: none; height: 42px;">
        <i class="fa-solid fa-filter mr-1"></i> Apply
      </button>
      <a href="attendance.php?preset=today" class="btn btn-light border font-weight-600 rounded-10 ml-2" title="Reset Filters" style="height: 42px; display: inline-flex; align-items: center; justify-content: center;">
        <i class="fa-solid fa-arrows-rotate"></i>
      </a>
    </div>
  </form>
</div>

<!-- =========================================================
     LIVE KPI METRIC CARDS FOR FILTERED TIMEFRAME
     ========================================================= -->
<div class="row mb-4">
  <!-- Total Records Logged -->
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="metric-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Total Logs</span>
          <h3 class="font-weight-800 text-dark mb-0"><?= $kpi_total ?></h3>
          <small class="text-muted font-weight-600" style="font-size: 0.75rem;">Across all dorms</small>
        </div>
        <div class="kpi-icon icon-total">
          <i class="fa-solid fa-clipboard-list"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Present Students -->
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="metric-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Present Students</span>
          <h3 class="font-weight-800 text-success mb-0"><?= $kpi_present ?></h3>
          <small class="text-success font-weight-700" style="font-size: 0.75rem;">
            <i class="fa-solid fa-circle-check mr-1"></i><?= $kpi_present_pct ?>% On-time Rate
          </small>
        </div>
        <div class="kpi-icon icon-present">
          <i class="fa-solid fa-user-check"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Late Check-ins -->
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="metric-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Late Check-ins</span>
          <h3 class="font-weight-800 text-warning mb-0" style="color: #d97706 !important;"><?= $kpi_late ?></h3>
          <small class="text-warning font-weight-700" style="font-size: 0.75rem;">
            <i class="fa-solid fa-clock mr-1"></i><?= $kpi_late_pct ?>% Past Curfew
          </small>
        </div>
        <div class="kpi-icon icon-late">
          <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
      </div>
    </div>
  </div>

  <!-- Absent / Leave / Outing -->
  <div class="col-xl-3 col-md-6 mb-3">
    <div class="metric-kpi-card">
      <div class="d-flex align-items-center justify-content-between">
        <div>
          <span class="text-muted font-weight-700 d-block mb-1" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Absent / Leave / Outing</span>
          <h3 class="font-weight-800 text-danger mb-0"><?= ($kpi_absent + $kpi_leave + $kpi_outing) ?></h3>
          <small class="text-danger font-weight-700" style="font-size: 0.75rem;">
            <?= $kpi_absent ?> Absent | <?= ($kpi_leave + $kpi_outing) ?> Leave/Out
          </small>
        </div>
        <div class="kpi-icon icon-absent">
          <i class="fa-solid fa-user-xmark"></i>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- =========================================================
     ATTENDANCE DATA TABLE CARD
     ========================================================= -->
<div class="card shadow-sm border-0 rounded-16">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom">
      <div>
        <h4 class="font-weight-800 text-dark mb-1" style="font-size: 1.18rem;">
          <i class="fa-solid fa-table-list text-success mr-2"></i> Attendance Log Roster
        </h4>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">
          Displaying records filtered by: <strong class="text-dark"><?= htmlspecialchars($date_badge_label) ?></strong> 
          (<?= count($attendance_records) ?> entries found)
        </p>
      </div>

      <div class="mt-2 mt-sm-0">
        <span class="badge badge-light border text-muted px-3 py-2 font-weight-600" style="font-size: 0.82rem;">
          <i class="fa-solid fa-clipboard-check text-success mr-1"></i> Student Attendance Roster
        </span>
      </div>
    </div>

    <?php if (empty($attendance_records)): ?>
      <div class="text-center py-5">
        <div class="mb-3">
          <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 80px; height: 80px; background: #ecfdf5; color: #059669; font-size: 2rem;">
            <i class="fa-solid fa-calendar-xmark"></i>
          </span>
        </div>
        <h5 class="font-weight-700 text-dark mb-1">No Attendance Records Found</h5>
        <p class="text-muted mb-3" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
          No attendance logs match your selected date or filter parameters. You can select another date or view all history.
        </p>
        <div class="d-flex justify-content-center gap-2">
          <a href="attendance.php?preset=all" class="btn btn-outline-success font-weight-600 rounded-10 px-3 py-2">
            <i class="fa-solid fa-layer-group mr-1"></i> View All Dates
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle" id="attendanceMasterTable" style="width: 100%;">
          <thead>
            <tr>
              <th style="width: 40px;" class="text-center">#</th>
              <th style="width: 120px;">Date</th>
              <th style="min-width: 220px;">Student Information</th>
              <th style="min-width: 160px;">Room & Bed</th>
              <th style="width: 140px;">Status</th>
              <th style="width: 150px;">Check-in / Late Time</th>
              <th style="min-width: 180px;">Marked By</th>
              <th style="min-width: 200px;">Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $sl = 1;
            foreach ($attendance_records as $row): 
                $date_ts = strtotime($row['attendance_date']);
                $formatted_date = date('d M, Y', $date_ts);
                $day_name = date('D', $date_ts);
                $status_clean = strtolower($row['status'] ?? 'present');
                
                // Student avatar
                $avatar_img = !empty($row['student_profile_image']) ? '../student_profile/' . $row['student_profile_image'] : '';
                $student_initial = strtoupper(substr($row['student_name'] ?? 'S', 0, 1));

                // Room representation
                $room_desc = !empty($row['room_number']) 
                    ? htmlspecialchars($row['block_name'] ?? 'Block') . " • Room " . htmlspecialchars($row['room_number']) . " (Bed " . htmlspecialchars($row['bed_number'] ?? '1') . ")"
                    : '<span class="text-muted font-italic">Unassigned</span>';

                // Recorded time
                $record_time_fmt = !empty($row['record_created_at']) ? date('h:i A', strtotime($row['record_created_at'])) : 'N/A';
            ?>
              <tr>
                <td class="text-center font-weight-600 text-muted"><?= $sl++ ?></td>
                
                <!-- Date -->
                <td>
                  <span class="font-weight-700 text-dark d-block" style="font-size: 0.88rem;"><?= $formatted_date ?></span>
                  <small class="badge badge-light border text-muted px-2 py-0" style="font-size: 0.72rem;"><?= $day_name ?></small>
                </td>

                <!-- Student Information -->
                <td>
                  <div class="d-flex align-items-center">
                    <?php if (!empty($avatar_img) && file_exists($avatar_img)): ?>
                      <img src="<?= htmlspecialchars($avatar_img) ?>" alt="Student" class="student-avatar mr-2">
                    <?php else: ?>
                      <div class="student-avatar mr-2"><?= $student_initial ?></div>
                    <?php endif; ?>
                    <div>
                      <span class="font-weight-700 text-dark d-block" style="font-size: 0.9rem; line-height: 1.2;">
                        <?= htmlspecialchars($row['student_name']) ?>
                      </span>
                      <small class="text-muted d-block" style="font-size: 0.76rem;">
                        <i class="fa-solid fa-phone mr-1"></i><?= htmlspecialchars($row['student_phone'] ?? 'N/A') ?>
                      </small>
                    </div>
                  </div>
                </td>

                <!-- Room & Bed -->
                <td>
                  <span style="font-size: 0.86rem;" class="font-weight-600 text-dark">
                    <?= $room_desc ?>
                  </span>
                </td>

                <!-- Status Badge (with Late time calculation) -->
                <td>
                  <?php 
                  $formatted_status_label = formatAttendanceStatusText($status_clean, $row['record_created_at'] ?? '', $att_end_time, $row['attendance_date'] ?? '');
                  $is_calculated_late = (stripos($formatted_status_label, 'Late') !== false) || ($status_clean === 'late');
                  ?>

                  <?php if ($is_calculated_late): ?>
                    <span class="badge-att-status badge-status-late">
                      <i class="fa-solid fa-clock"></i> <?= htmlspecialchars($formatted_status_label) ?>
                    </span>
                  <?php elseif ($status_clean === 'present'): ?>
                    <span class="badge-att-status badge-status-present">
                      <i class="fa-solid fa-circle-check"></i> Present
                    </span>
                  <?php elseif ($status_clean === 'absent'): ?>
                    <span class="badge-att-status badge-status-absent">
                      <i class="fa-solid fa-circle-xmark"></i> Absent
                    </span>
                  <?php elseif ($status_clean === 'leave'): ?>
                    <span class="badge-att-status badge-status-leave">
                      <i class="fa-solid fa-plane-departure"></i> Leave
                    </span>
                  <?php elseif ($status_clean === 'outing'): ?>
                    <span class="badge-att-status badge-status-outing">
                      <i class="fa-solid fa-person-walking-luggage"></i> Outing
                    </span>
                  <?php else: ?>
                    <span class="badge-att-status badge-status-present">
                      <?= htmlspecialchars(ucfirst($status_clean)) ?>
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Recorded Time & Late Time -->
                <td>
                  <span class="text-dark font-weight-600" style="font-size: 0.84rem;">
                    <i class="fa-regular fa-clock text-muted mr-1"></i><?= $record_time_fmt ?>
                  </span>
                  <?php if ($is_calculated_late): ?>
                    <small class="badge badge-warning text-dark font-weight-bold d-block mt-1" style="font-size: 0.72rem; background: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
                      <i class="fa-solid fa-clock mr-1"></i><?= htmlspecialchars($formatted_status_label) ?>
                    </small>
                  <?php endif; ?>
                </td>

                <!-- Marked By -->
                <td>
                  <span class="text-secondary font-weight-500" style="font-size: 0.82rem;">
                    <?= htmlspecialchars($row['marked_by'] ?? 'Hostel Timings System') ?>
                  </span>
                </td>

                <!-- Remarks -->
                <td>
                  <span class="text-muted" style="font-size: 0.82rem; white-space: normal; word-break: break-word;">
                    <?= !empty($row['remarks']) ? htmlspecialchars($row['remarks']) : '<span class="text-muted font-italic">-</span>' ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- =========================================================
     JAVASCRIPT LOGIC
     ========================================================= -->
<script>
// Switch between Single Date & Date Range mode
function switchDateMode(mode) {
  const singleBox = document.getElementById('singleDateContainer');
  const rangeBox  = document.getElementById('rangeDateContainer');
  const inputMode = document.getElementById('input_filter_mode');
  const btnSingle = document.getElementById('btnModeSingle');
  const btnRange  = document.getElementById('btnModeRange');

  if (mode === 'range') {
    singleBox.style.display = 'none';
    rangeBox.style.display  = 'block';
    inputMode.value = 'range';
    btnRange.classList.remove('btn-outline-secondary');
    btnRange.classList.add('btn-success', 'text-white');
    btnSingle.classList.remove('btn-success', 'text-white');
    btnSingle.classList.add('btn-outline-secondary');
  } else {
    rangeBox.style.display  = 'none';
    singleBox.style.display = 'block';
    inputMode.value = 'single';
    btnSingle.classList.remove('btn-outline-secondary');
    btnSingle.classList.add('btn-success', 'text-white');
    btnRange.classList.remove('btn-success', 'text-white');
    btnRange.classList.add('btn-outline-secondary');
  }
}

// Export Table Data to CSV
function exportAttendanceToCSV() {
  const table = document.getElementById('attendanceMasterTable');
  if (!table) {
    alert("No records to export.");
    return;
  }

  let csv = [];
  const rows = table.querySelectorAll('tr');

  for (let i = 0; i < rows.length; i++) {
    let row = [], cols = rows[i].querySelectorAll('td, th');
    for (let j = 0; j < cols.length; j++) {
      let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/"/g, '""').trim();
      row.push('"' + data + '"');
    }
    csv.push(row.join(','));
  }

  const csvFile = new Blob([csv.join('\n')], { type: "text/csv" });
  const downloadLink = document.createElement("a");
  const fileName = "warden_attendance_report_" + new Date().toISOString().slice(0, 10) + ".csv";

  downloadLink.download = fileName;
  downloadLink.href = window.URL.createObjectURL(csvFile);
  downloadLink.style.display = "none";
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}

// jQuery Event Listeners
$(document).ready(function() {
  // Initialize DataTables
  if ($.fn.DataTable && $('#attendanceMasterTable').length) {
    $('#attendanceMasterTable').DataTable({
      "order": [[1, "desc"]],
      "pageLength": 25,
      "language": {
        "search": "_INPUT_",
        "searchPlaceholder": "Search by student, room...",
        "emptyTable": "No attendance records found for this date/filter"
      }
    });
  }
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
