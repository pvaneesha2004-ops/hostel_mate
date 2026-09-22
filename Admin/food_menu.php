<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

// Auto-create table if not exists
$create_table_sql = "CREATE TABLE IF NOT EXISTS `food_menus` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `menu_day` VARCHAR(20) NOT NULL UNIQUE,
    `break_fast` TEXT DEFAULT NULL,
    `breakfast_time` VARCHAR(100) DEFAULT NULL,
    `lunch` TEXT DEFAULT NULL,
    `lunch_time` VARCHAR(100) DEFAULT NULL,
    `evening_tea` TEXT DEFAULT NULL,
    `evening_tea_time` VARCHAR(100) DEFAULT NULL,
    `dinner` TEXT DEFAULT NULL,
    `dinner_time` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($create_table_sql);

// Auto-add or alter new time columns if table existed without them
$time_columns = [
    'breakfast_time' => "VARCHAR(100) DEFAULT NULL AFTER `break_fast`",
    'lunch_time' => "VARCHAR(100) DEFAULT NULL AFTER `lunch`",
    'evening_tea_time' => "VARCHAR(100) DEFAULT NULL AFTER `evening_tea`",
    'dinner_time' => "VARCHAR(100) DEFAULT NULL AFTER `dinner`"
];
foreach ($time_columns as $col_name => $col_def) {
    $check_col = $conn->query("SHOW COLUMNS FROM `food_menus` LIKE '$col_name'");
    if ($check_col && $check_col->num_rows == 0) {
        $conn->query("ALTER TABLE `food_menus` ADD `$col_name` $col_def");
    } else {
        $conn->query("ALTER TABLE `food_menus` MODIFY `$col_name` VARCHAR(100) DEFAULT NULL");
    }
}
$conn->query("ALTER TABLE `food_menus` MODIFY `break_fast` TEXT DEFAULT NULL, MODIFY `lunch` TEXT DEFAULT NULL, MODIFY `evening_tea` TEXT DEFAULT NULL, MODIFY `dinner` TEXT DEFAULT NULL");


// Standard 7 days list in sequence
$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Handle Add / Set / Update Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $menu_day = trim($_POST['menu_day'] ?? '');
    $break_fast = trim($_POST['break_fast'] ?? '');
    $breakfast_time = trim($_POST['breakfast_time'] ?? '');
    $lunch = trim($_POST['lunch'] ?? '');
    $lunch_time = trim($_POST['lunch_time'] ?? '');
    $evening_tea = trim($_POST['evening_tea'] ?? '');
    $evening_tea_time = trim($_POST['evening_tea_time'] ?? '');
    $dinner = trim($_POST['dinner'] ?? '');
    $dinner_time = trim($_POST['dinner_time'] ?? '');

    if (empty($menu_day)) {
        $_SESSION['error'] = "Please select a valid day of the week.";
        header("Location: food_menu.php");
        exit();
    }

    if ($action === 'add' || $action === 'set_menu') {
        // Upsert logic (Insert or Update if day exists)
        $stmt = $conn->prepare("INSERT INTO `food_menus` 
            (`menu_day`, `break_fast`, `breakfast_time`, `lunch`, `lunch_time`, `evening_tea`, `evening_tea_time`, `dinner`, `dinner_time`, `created_at`, `updated_at`) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) 
            ON DUPLICATE KEY UPDATE 
            `break_fast` = VALUES(`break_fast`), 
            `breakfast_time` = VALUES(`breakfast_time`), 
            `lunch` = VALUES(`lunch`), 
            `lunch_time` = VALUES(`lunch_time`), 
            `evening_tea` = VALUES(`evening_tea`), 
            `evening_tea_time` = VALUES(`evening_tea_time`), 
            `dinner` = VALUES(`dinner`), 
            `dinner_time` = VALUES(`dinner_time`), 
            `updated_at` = NOW()");
        $stmt->bind_param("sssssssss", $menu_day, $break_fast, $breakfast_time, $lunch, $lunch_time, $evening_tea, $evening_tea_time, $dinner, $dinner_time);
        
        if ($stmt->execute()) {
            $_SESSION['msg'] = "Food menu for " . htmlspecialchars($menu_day) . " saved successfully!";
        } else {
            $_SESSION['error'] = "Failed to save menu: " . $conn->error;
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE `food_menus` SET 
                `menu_day` = ?, 
                `break_fast` = ?, 
                `breakfast_time` = ?, 
                `lunch` = ?, 
                `lunch_time` = ?, 
                `evening_tea` = ?, 
                `evening_tea_time` = ?, 
                `dinner` = ?, 
                `dinner_time` = ?, 
                `updated_at` = NOW() 
                WHERE `id` = ?");
            $stmt->bind_param("sssssssssi", $menu_day, $break_fast, $breakfast_time, $lunch, $lunch_time, $evening_tea, $evening_tea_time, $dinner, $dinner_time, $id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Food menu for " . htmlspecialchars($menu_day) . " updated successfully!";
            } else {
                $_SESSION['error'] = "Failed to update menu: " . $conn->error;
            }
        } else {
            $_SESSION['error'] = "Invalid menu record selected.";
        }
    }
    
    header("Location: food_menu.php");
    exit();
}

// Handle Delete Action
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM `food_menus` WHERE `id` = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['msg'] = "Food menu item deleted successfully.";
    } else {
        $_SESSION['error'] = "Failed to delete food menu item.";
    }
    header("Location: food_menu.php");
    exit();
}

// Handle Clear All Action
if (isset($_GET['clear_all'])) {
    if ($conn->query("TRUNCATE TABLE `food_menus`")) {
        $_SESSION['msg'] = "All food menu records have been cleared.";
    } else {
        $_SESSION['error'] = "Failed to clear food menu records.";
    }
    header("Location: food_menu.php");
    exit();
}

// Fetch all menus ordered by standard day sequence
$menus_raw = $conn->query("SELECT * FROM `food_menus`");
$menus_by_day = [];
$all_menus_list = [];

if ($menus_raw) {
    while ($m = $menus_raw->fetch_assoc()) {
        $menus_by_day[$m['menu_day']] = $m;
        $all_menus_list[] = $m;
    }
}

// Custom sort by Weekdays sequence: Monday -> Sunday
usort($all_menus_list, function($a, $b) use ($weekdays) {
    $pos_a = array_search($a['menu_day'], $weekdays);
    $pos_b = array_search($b['menu_day'], $weekdays);
    $pos_a = ($pos_a === false) ? 99 : $pos_a;
    $pos_b = ($pos_b === false) ? 99 : $pos_b;
    return $pos_a - $pos_b;
});

// Current Day of Week
$current_day_name = date('l'); // e.g. "Monday"
$todays_menu = $menus_by_day[$current_day_name] ?? null;

ob_start();
?>

<style>
  /* Food Menu Custom Aesthetics */
  .food-hero-card {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 50%, #3b82f6 100%);
    border-radius: 18px;
    color: #ffffff;
    padding: 1.75rem 2rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
    margin-bottom: 1.75rem;
  }

  .food-hero-card::after {
    content: "\f2e7";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 25px;
    bottom: -20px;
    font-size: 130px;
    color: rgba(255, 255, 255, 0.08);
    pointer-events: none;
  }

  .today-badge-pulse {
    display: inline-flex;
    align-items: center;
    background: #10b981;
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.3rem 0.75rem;
    border-radius: 50px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    animation: pulse-green 2s infinite;
  }

  @keyframes pulse-green {
    0% {
      transform: scale(0.95);
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
    }
    70% {
      transform: scale(1);
      box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
    }
    100% {
      transform: scale(0.95);
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
    }
  }

  .meal-preview-box {
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    padding: 1rem;
    height: 100%;
    transition: all 0.25s ease;
  }

  .meal-preview-box:hover {
    background: rgba(255, 255, 255, 0.18);
    transform: translateY(-2px);
  }

  .meal-tag {
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 0.35rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .meal-time-pill {
    font-size: 0.68rem;
    background: rgba(255, 255, 255, 0.2);
    padding: 0.15rem 0.5rem;
    border-radius: 20px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
  }

  .day-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.45rem 0.95rem;
    border-radius: 50px;
    font-weight: 700;
    font-size: 0.85rem;
    white-space: nowrap;
  }

  .day-pill-active {
    background: #dbeafe;
    color: #1e40af;
    border: 1.5px solid #93c5fd;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.12);
  }

  .day-pill-regular {
    background: #f1f5f9;
    color: #334155;
    border: 1.5px solid #e2e8f0;
  }

  /* Food Menu Table Layout & Cell Alignment */
  #foodMenuTable {
    min-width: 1280px !important;
    width: 100% !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
  }

  #foodMenuTable th,
  #foodMenuTable td {
    padding: 1.1rem 1.15rem !important;
    vertical-align: middle !important;
  }

  #foodMenuTable thead th {
    background-color: #f8fafc !important;
    border-bottom: 2px solid #cbd5e1 !important;
    color: #334155 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.6px;
    white-space: nowrap !important;
  }

  #foodMenuTable td {
    white-space: normal !important; /* Allows meal text to wrap cleanly */
  }

  .meal-cell {
    min-width: 220px !important;
    max-width: 320px !important;
    font-size: 0.88rem;
    line-height: 1.5;
    color: #1e293b;
    word-break: break-word;
    white-space: normal !important;
  }

  .meal-cell-text {
    font-size: 0.875rem;
    font-weight: 500;
    color: #1e293b;
    line-height: 1.5;
    margin-bottom: 4px;
    word-break: break-word;
  }

  .meal-cell-time {
    display: inline-flex !important;
    align-items: center !important;
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748b;
    background: #f1f5f9;
    padding: 0.2rem 0.55rem;
    border-radius: 6px;
    margin-top: 4px;
    border: 1px solid #e2e8f0;
    white-space: nowrap !important;
  }

  .meal-icon-indicator {
    width: 32px;
    height: 32px;
    min-width: 32px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    margin-right: 12px;
    flex-shrink: 0;
    margin-top: 2px;
  }

  .icon-breakfast { background: #fef3c7; color: #d97706; }
  .icon-lunch { background: #dcfce7; color: #15803d; }
  .icon-tea { background: #ffedd5; color: #ea580c; }
  .icon-dinner { background: #ede9fe; color: #6d28d9; }

  .action-btn-group .btn {
    padding: 0.45rem 0.75rem;
    font-size: 0.85rem;
    border-radius: 8px;
  }

  .form-section-divider {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
  }

  .form-section-title {
    font-size: 0.85rem;
    font-weight: 700;
    color: #1e293b;
    display: flex;
    align-items: center;
    margin-bottom: 0.65rem;
  }
</style>

<!-- Alert Feedback -->
<?php if (isset($_SESSION['msg'])): ?>
  <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: rgba(16, 185, 129, 0.15); color: #065f46;">
    <i class="fa-solid fa-circle-check mr-2"></i><?= htmlspecialchars($_SESSION['msg']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['msg']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-12 mb-4" role="alert" style="background: rgba(239, 68, 68, 0.15); color: #991b1b;">
    <i class="fa-solid fa-triangle-exclamation mr-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
      <span aria-hidden="true">&times;</span>
    </button>
  </div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Top Hero Card: Today's Menu Highlight -->
<div class="food-hero-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
      <div class="d-flex align-items-center mb-1">
        <span class="today-badge-pulse mr-2">
          <i class="fa-solid fa-calendar-day mr-1"></i> Today's Status
        </span>
        <h3 class="mb-0 font-weight-bold text-white"><?= $current_day_name ?>'s Mess Menu</h3>
      </div>
      <p class="text-white-50 mb-0" style="font-size: 0.9rem;">
        Configure and manage breakfast, lunch, evening snacks, and dinner schedules for students.
      </p>
    </div>
  </div>

  <?php if ($todays_menu): ?>
    <div class="row mt-3">
      <!-- Breakfast -->
      <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="meal-preview-box">
          <div class="meal-tag text-warning">
            <span><i class="fa-solid fa-mug-hot mr-1"></i> Breakfast</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['breakfast_time']) ? htmlspecialchars($todays_menu['breakfast_time']) : '7:30 - 9:30 AM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.4;">
            <?= !empty($todays_menu['break_fast']) ? nl2br(htmlspecialchars($todays_menu['break_fast'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
      <!-- Lunch -->
      <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="meal-preview-box">
          <div class="meal-tag text-success" style="color: #86efac !important;">
            <span><i class="fa-solid fa-bowl-rice mr-1"></i> Lunch</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['lunch_time']) ? htmlspecialchars($todays_menu['lunch_time']) : '12:30 - 2:30 PM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.4;">
            <?= !empty($todays_menu['lunch']) ? nl2br(htmlspecialchars($todays_menu['lunch'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
      <!-- Evening Snacks -->
      <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="meal-preview-box">
          <div class="meal-tag text-warning" style="color: #fdba74 !important;">
            <span><i class="fa-solid fa-cookie-bite mr-1"></i> Evening Tea</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['evening_tea_time']) ? htmlspecialchars($todays_menu['evening_tea_time']) : '5:00 - 6:00 PM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.4;">
            <?= !empty($todays_menu['evening_tea']) ? nl2br(htmlspecialchars($todays_menu['evening_tea'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
      <!-- Dinner -->
      <div class="col-md-3 col-sm-6">
        <div class="meal-preview-box">
          <div class="meal-tag text-light" style="color: #c4b5fd !important;">
            <span><i class="fa-solid fa-utensils mr-1"></i> Dinner</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['dinner_time']) ? htmlspecialchars($todays_menu['dinner_time']) : '7:30 - 9:30 PM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.4;">
            <?= !empty($todays_menu['dinner']) ? nl2br(htmlspecialchars($todays_menu['dinner'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-warning mb-0 mt-2 d-flex align-items-center justify-content-between" style="background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.25); color: #ffffff; border-radius: 12px;">
      <div>
        <i class="fa-solid fa-circle-info mr-2"></i> No food menu is added for <strong><?= $current_day_name ?></strong> yet.
      </div>
      <button class="btn btn-sm btn-light font-weight-bold" data-toggle="modal" data-target="#setMenuModal" onclick="$('#add_menu_day').val('<?= $current_day_name ?>');" style="color: #2563eb;">
        Set <?= $current_day_name ?> Menu
      </button>
    </div>
  <?php endif; ?>
</div>

<!-- Weekly Menu Master Card -->
<div class="row">
  <div class="col-12 grid-margin stretch-card">
    <div class="card shadow-sm border-0 rounded-16">
      <div class="card-body p-4">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
          <div>
            <h4 class="card-title font-weight-bold mb-1" style="font-size: 1.2rem; color: #0f172a;">
              <i class="fa-solid fa-utensils text-primary mr-2"></i>Weekly Mess Timetable & Menu
            </h4>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
              Manage meal items and serving timings for breakfast, lunch, evening tea & snacks, and night dinner.
            </p>
          </div>
          <div class="mt-2 mt-md-0 d-flex align-items-center">
            <?php if (!empty($all_menus_list)): ?>
              <a href="javascript:void(0);" data-url="food_menu.php?clear_all=1" class="btn btn-outline-danger font-weight-600 rounded-10 px-3 py-2 mr-2 clear-all-btn">
                <i class="fa-solid fa-trash-can mr-1"></i> Clear All
              </a>
            <?php endif; ?>
            <button class="btn btn-primary font-weight-600 rounded-10 px-3 py-2 shadow-sm" data-toggle="modal" data-target="#setMenuModal">
              <i class="fa-solid fa-plus mr-1"></i> Add / Set Day Menu
            </button>
          </div>
        </div>

        <?php if (empty($all_menus_list)): ?>
          <div class="text-center py-5">
            <div class="mb-3">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 75px; height: 75px; background: rgba(37, 99, 235, 0.08); color: #2563eb; font-size: 2rem;">
                <i class="fa-solid fa-bowl-food"></i>
              </span>
            </div>
            <h5 class="font-weight-bold text-dark mb-1">No Food Menu Records Found</h5>
            <p class="text-muted mb-4" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
              You haven't added any days to the mess menu yet. Click the button below to add your custom breakfast, lunch, tea, and dinner schedule.
            </p>
            <button class="btn btn-primary px-4 py-2 font-weight-bold rounded-10 shadow-sm" data-toggle="modal" data-target="#setMenuModal">
              <i class="fa-solid fa-plus-circle mr-1"></i> Add Day Menu
            </button>
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle datatable" id="foodMenuTable">
              <thead class="bg-light text-uppercase font-weight-bold">
                <tr>
                  <th style="width: 55px; min-width: 55px;" class="text-center">#</th>
                  <th style="width: 145px; min-width: 145px;">Day</th>
                  <th style="min-width: 230px;">Breakfast</th>
                  <th style="min-width: 230px;">Lunch</th>
                  <th style="min-width: 230px;">Evening Tea</th>
                  <th style="min-width: 230px;">Dinner</th>
                  <th style="width: 115px; min-width: 115px;">Updated</th>
                  <th style="width: 110px; min-width: 110px;" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sl = 1;
                foreach ($all_menus_list as $row): 
                  $is_today = (strcasecmp($row['menu_day'], $current_day_name) === 0);
                ?>
                  <tr class="<?= $is_today ? 'table-primary-light' : '' ?>" style="<?= $is_today ? 'background-color: rgba(37, 99, 235, 0.04);' : '' ?>">
                    <td class="font-weight-600 text-muted text-center"><?= $sl++ ?></td>
                    
                    <td>
                      <?php if ($is_today): ?>
                        <span class="day-pill day-pill-active">
                          <i class="fa-solid fa-star text-warning mr-1"></i> <?= htmlspecialchars($row['menu_day']) ?>
                        </span>
                      <?php else: ?>
                        <span class="day-pill day-pill-regular">
                          <i class="fa-regular fa-calendar mr-1 text-muted"></i> <?= htmlspecialchars($row['menu_day']) ?>
                        </span>
                      <?php endif; ?>
                    </td>

                    <!-- Breakfast -->
                    <td>
                      <div class="d-flex align-items-start meal-cell">
                        <span class="meal-icon-indicator icon-breakfast" title="Breakfast">
                          <i class="fa-solid fa-mug-hot"></i>
                        </span>
                        <div class="flex-grow-1">
                          <div class="meal-cell-text"><?= !empty($row['break_fast']) ? nl2br(htmlspecialchars($row['break_fast'])) : '<span class="text-muted font-italic">-</span>' ?></div>
                          <?php if (!empty($row['breakfast_time'])): ?>
                            <span class="meal-cell-time"><i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($row['breakfast_time']) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <!-- Lunch -->
                    <td>
                      <div class="d-flex align-items-start meal-cell">
                        <span class="meal-icon-indicator icon-lunch" title="Lunch">
                          <i class="fa-solid fa-bowl-rice"></i>
                        </span>
                        <div class="flex-grow-1">
                          <div class="meal-cell-text"><?= !empty($row['lunch']) ? nl2br(htmlspecialchars($row['lunch'])) : '<span class="text-muted font-italic">-</span>' ?></div>
                          <?php if (!empty($row['lunch_time'])): ?>
                            <span class="meal-cell-time"><i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($row['lunch_time']) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <!-- Evening Tea -->
                    <td>
                      <div class="d-flex align-items-start meal-cell">
                        <span class="meal-icon-indicator icon-tea" title="Evening Tea">
                          <i class="fa-solid fa-cookie-bite"></i>
                        </span>
                        <div class="flex-grow-1">
                          <div class="meal-cell-text"><?= !empty($row['evening_tea']) ? nl2br(htmlspecialchars($row['evening_tea'])) : '<span class="text-muted font-italic">-</span>' ?></div>
                          <?php if (!empty($row['evening_tea_time'])): ?>
                            <span class="meal-cell-time"><i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($row['evening_tea_time']) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <!-- Dinner -->
                    <td>
                      <div class="d-flex align-items-start meal-cell">
                        <span class="meal-icon-indicator icon-dinner" title="Dinner">
                          <i class="fa-solid fa-utensils"></i>
                        </span>
                        <div class="flex-grow-1">
                          <div class="meal-cell-text"><?= !empty($row['dinner']) ? nl2br(htmlspecialchars($row['dinner'])) : '<span class="text-muted font-italic">-</span>' ?></div>
                          <?php if (!empty($row['dinner_time'])): ?>
                            <span class="meal-cell-time"><i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($row['dinner_time']) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>

                    <td>
                      <small class="text-muted font-weight-500 text-nowrap">
                        <?= !empty($row['updated_at']) ? date('d M, Y', strtotime($row['updated_at'])) : date('d M, Y') ?>
                      </small>
                    </td>

                    <td class="text-center">
                      <div class="action-btn-group d-inline-flex">
                        <!-- Edit Button -->
                        <button type="button" class="btn btn-sm btn-primary edit-menu-btn mr-1 shadow-sm" 
                                title="Edit Menu"
                                data-id="<?= $row['id'] ?>"
                                data-day="<?= htmlspecialchars($row['menu_day']) ?>"
                                data-breakfast="<?= htmlspecialchars($row['break_fast']) ?>"
                                data-breakfast-time="<?= htmlspecialchars($row['breakfast_time'] ?? '') ?>"
                                data-lunch="<?= htmlspecialchars($row['lunch']) ?>"
                                data-lunch-time="<?= htmlspecialchars($row['lunch_time'] ?? '') ?>"
                                data-tea="<?= htmlspecialchars($row['evening_tea']) ?>"
                                data-tea-time="<?= htmlspecialchars($row['evening_tea_time'] ?? '') ?>"
                                data-dinner="<?= htmlspecialchars($row['dinner']) ?>"
                                data-dinner-time="<?= htmlspecialchars($row['dinner_time'] ?? '') ?>">
                          <i class="fa-solid fa-pen-to-square"></i>
                        </button>

                        <!-- Delete Button -->
                        <a href="javascript:void(0);" 
                           data-url="food_menu.php?delete=<?= $row['id'] ?>" 
                           data-day="<?= htmlspecialchars($row['menu_day']) ?>"
                           class="btn btn-sm btn-outline-danger delete-menu-btn shadow-sm" 
                           title="Delete Day Menu">
                          <i class="fa-solid fa-trash-can"></i>
                        </a>
                      </div>
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

<!-- Set / Add Menu Modal -->
<div class="modal fade" id="setMenuModal" tabindex="-1" role="dialog" aria-labelledby="setMenuModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16">
      <form method="POST" action="food_menu.php" id="setMenuForm" novalidate>
        <input type="hidden" name="action" value="set_menu">
        
        <div class="modal-header bg-light py-3 px-4 rounded-top-16 border-bottom">
          <div class="d-flex align-items-center">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mr-3" style="width: 38px; height: 38px;">
              <i class="fa-solid fa-utensils"></i>
            </div>
            <div>
              <h5 class="modal-title font-weight-bold text-dark mb-0" id="setMenuModalLabel">Add / Set Day Menu</h5>
              <small class="text-muted">Configure meal items and timings for any selected day</small>
            </div>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4">
          <div class="form-group mb-4">
            <label class="font-weight-bold text-dark mb-1">
              Select Day <span class="text-danger">*</span>
            </label>
            <select name="menu_day" id="add_menu_day" class="form-control form-control-lg rounded-10">
              <option value="">-- Choose Day of Week --</option>
              <?php foreach ($weekdays as $wday): ?>
                <option value="<?= $wday ?>"><?= $wday ?><?= ($wday === $current_day_name) ? ' (Today)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-danger font-weight-bold mt-1 d-block" id="add_day_err"></small>
          </div>

          <!-- Breakfast Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-breakfast"><i class="fa-solid fa-mug-hot"></i></span>
              Breakfast Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="break_fast" id="add_break_fast" class="form-control rounded-10" rows="2" placeholder="e.g. Idli, Sambar, Coconut Chutney, Tea / Coffee"></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_break_fast_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="breakfast_time" id="add_breakfast_time" class="form-control rounded-10" placeholder="e.g. 07:30 AM - 09:30 AM" value="07:30 AM - 09:30 AM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_breakfast_time_err"></small>
              </div>
            </div>
          </div>

          <!-- Lunch Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-lunch"><i class="fa-solid fa-bowl-rice"></i></span>
              Lunch Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="lunch" id="add_lunch" class="form-control rounded-10" rows="2" placeholder="e.g. Steamed Rice, Dal Tadka, Paneer Butter Masala, Roti, Salad"></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_lunch_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="lunch_time" id="add_lunch_time" class="form-control rounded-10" placeholder="e.g. 12:30 PM - 02:30 PM" value="12:30 PM - 02:30 PM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_lunch_time_err"></small>
              </div>
            </div>
          </div>

          <!-- Evening Tea Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-tea"><i class="fa-solid fa-cookie-bite"></i></span>
              Evening Tea & Snacks Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="evening_tea" id="add_evening_tea" class="form-control rounded-10" rows="2" placeholder="e.g. Masala Chai, Veg Samosa / Pakoda, Biscuits"></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_evening_tea_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="evening_tea_time" id="add_evening_tea_time" class="form-control rounded-10" placeholder="e.g. 05:00 PM - 06:00 PM" value="05:00 PM - 06:00 PM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_evening_tea_time_err"></small>
              </div>
            </div>
          </div>

          <!-- Dinner Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-dinner"><i class="fa-solid fa-utensils"></i></span>
              Dinner Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="dinner" id="add_dinner" class="form-control rounded-10" rows="2" placeholder="e.g. Chapati, Veg Pulao, Dal Makhani, Gulab Jamun"></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_dinner_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="dinner_time" id="add_dinner_time" class="form-control rounded-10" placeholder="e.g. 07:30 PM - 09:30 PM" value="07:30 PM - 09:30 PM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="add_dinner_time_err"></small>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer bg-light px-4 py-3 rounded-bottom-16">
          <button type="button" class="btn btn-secondary px-4 rounded-10 font-weight-500" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 rounded-10 font-weight-bold shadow-sm">
            <i class="fa-solid fa-floppy-disk mr-1"></i> Save Menu
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Menu Modal -->
<div class="modal fade" id="editMenuModal" tabindex="-1" role="dialog" aria-labelledby="editMenuModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16">
      <form method="POST" action="food_menu.php" id="editMenuForm" novalidate>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="edit_menu_id">
        
        <div class="modal-header bg-light py-3 px-4 rounded-top-16 border-bottom">
          <div class="d-flex align-items-center">
            <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center mr-3" style="width: 38px; height: 38px;">
              <i class="fa-solid fa-pen-to-square"></i>
            </div>
            <div>
              <h5 class="modal-title font-weight-bold text-dark mb-0" id="editMenuModalLabel">Update Menu: <span id="edit_day_display" class="text-primary"></span></h5>
              <small class="text-muted">Modify food menu items and timings for the selected day</small>
            </div>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4">
          <div class="form-group mb-4">
            <label class="font-weight-bold text-dark mb-1">
              Menu Day <span class="text-danger">*</span>
            </label>
            <select name="menu_day" id="edit_menu_day" class="form-control form-control-lg rounded-10">
              <?php foreach ($weekdays as $wday): ?>
                <option value="<?= $wday ?>"><?= $wday ?></option>
              <?php endforeach; ?>
            </select>
            <small class="text-danger font-weight-bold mt-1 d-block" id="edit_day_err"></small>
          </div>

          <!-- Breakfast Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-breakfast"><i class="fa-solid fa-mug-hot"></i></span>
              Breakfast Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="break_fast" id="edit_break_fast" class="form-control rounded-10" rows="2" placeholder="Enter morning breakfast menu..."></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_break_fast_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="breakfast_time" id="edit_breakfast_time" class="form-control rounded-10" placeholder="e.g. 07:30 AM - 09:30 AM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_breakfast_time_err"></small>
              </div>
            </div>
          </div>

          <!-- Lunch Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-lunch"><i class="fa-solid fa-bowl-rice"></i></span>
              Lunch Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="lunch" id="edit_lunch" class="form-control rounded-10" rows="2" placeholder="Enter afternoon lunch menu..."></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_lunch_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="lunch_time" id="edit_lunch_time" class="form-control rounded-10" placeholder="e.g. 12:30 PM - 02:30 PM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_lunch_time_err"></small>
              </div>
            </div>
          </div>

          <!-- Evening Tea Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-tea"><i class="fa-solid fa-cookie-bite"></i></span>
              Evening Tea & Snacks Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="evening_tea" id="edit_evening_tea" class="form-control rounded-10" rows="2" placeholder="Enter evening tea & snacks..."></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_evening_tea_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="evening_tea_time" id="edit_evening_tea_time" class="form-control rounded-10" placeholder="e.g. 05:00 PM - 06:00 PM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_evening_tea_time_err"></small>
              </div>
            </div>
          </div>

          <!-- Dinner Section -->
          <div class="form-section-divider">
            <div class="form-section-title">
              <span class="meal-icon-indicator icon-dinner"><i class="fa-solid fa-utensils"></i></span>
              Dinner Details
            </div>
            <div class="row">
              <div class="col-md-7 mb-2 mb-md-0">
                <label class="font-weight-600 text-dark mb-1">Menu Items <span class="text-danger">*</span></label>
                <textarea name="dinner" id="edit_dinner" class="form-control rounded-10" rows="2" placeholder="Enter night dinner menu..."></textarea>
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_dinner_err"></small>
              </div>
              <div class="col-md-5">
                <label class="font-weight-600 text-dark mb-1">Serving Time <span class="text-danger">*</span></label>
                <input type="text" name="dinner_time" id="edit_dinner_time" class="form-control rounded-10" placeholder="e.g. 07:30 PM - 09:30 PM">
                <small class="text-danger font-weight-bold mt-1 d-block" id="edit_dinner_time_err"></small>
              </div>
            </div>
          </div>

        </div>

        <div class="modal-footer bg-light px-4 py-3 rounded-bottom-16">
          <button type="button" class="btn btn-secondary px-4 rounded-10 font-weight-500" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 rounded-10 font-weight-bold shadow-sm">
            <i class="fa-solid fa-floppy-disk mr-1"></i> Update Menu
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // =========================================================
    // JavaScript Error Text Form Validation - ADD FORM
    // =========================================================
    $('#setMenuForm').on('submit', function(e) {
        let isValid = true;

        let menuDay = $('#add_menu_day').val().trim();
        let breakFast = $('#add_break_fast').val().trim();
        let breakfastTime = $('#add_breakfast_time').val().trim();
        let lunch = $('#add_lunch').val().trim();
        let lunchTime = $('#add_lunch_time').val().trim();
        let eveningTea = $('#add_evening_tea').val().trim();
        let eveningTeaTime = $('#add_evening_tea_time').val().trim();
        let dinner = $('#add_dinner').val().trim();
        let dinnerTime = $('#add_dinner_time').val().trim();

        // 1. Day Validation
        if (menuDay === '') {
            $('#add_day_err').text('Please select a day of the week');
            isValid = false;
        } else {
            $('#add_day_err').text('');
        }

        // 2. Breakfast Items Validation
        if (breakFast === '') {
            $('#add_break_fast_err').text('Breakfast menu items are required');
            isValid = false;
        } else {
            $('#add_break_fast_err').text('');
        }

        // 3. Breakfast Time Validation
        if (breakfastTime === '') {
            $('#add_breakfast_time_err').text('Breakfast timing is required');
            isValid = false;
        } else {
            $('#add_breakfast_time_err').text('');
        }

        // 4. Lunch Items Validation
        if (lunch === '') {
            $('#add_lunch_err').text('Lunch menu items are required');
            isValid = false;
        } else {
            $('#add_lunch_err').text('');
        }

        // 5. Lunch Time Validation
        if (lunchTime === '') {
            $('#add_lunch_time_err').text('Lunch timing is required');
            isValid = false;
        } else {
            $('#add_lunch_time_err').text('');
        }

        // 6. Evening Tea Items Validation
        if (eveningTea === '') {
            $('#add_evening_tea_err').text('Evening tea & snacks items are required');
            isValid = false;
        } else {
            $('#add_evening_tea_err').text('');
        }

        // 7. Evening Tea Time Validation
        if (eveningTeaTime === '') {
            $('#add_evening_tea_time_err').text('Evening tea timing is required');
            isValid = false;
        } else {
            $('#add_evening_tea_time_err').text('');
        }

        // 8. Dinner Items Validation
        if (dinner === '') {
            $('#add_dinner_err').text('Dinner menu items are required');
            isValid = false;
        } else {
            $('#add_dinner_err').text('');
        }

        // 9. Dinner Time Validation
        if (dinnerTime === '') {
            $('#add_dinner_time_err').text('Dinner timing is required');
            isValid = false;
        } else {
            $('#add_dinner_time_err').text('');
        }

        if (!isValid) {
            e.preventDefault();
        }
    });

    // Real-time error text clearing on Add Form inputs
    $('#add_menu_day').on('change', function() {
        if ($(this).val().trim() !== '') $('#add_day_err').text('');
    });
    $('#add_break_fast').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_break_fast_err').text('');
    });
    $('#add_breakfast_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_breakfast_time_err').text('');
    });
    $('#add_lunch').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_lunch_err').text('');
    });
    $('#add_lunch_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_lunch_time_err').text('');
    });
    $('#add_evening_tea').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_evening_tea_err').text('');
    });
    $('#add_evening_tea_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_evening_tea_time_err').text('');
    });
    $('#add_dinner').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_dinner_err').text('');
    });
    $('#add_dinner_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#add_dinner_time_err').text('');
    });


    // =========================================================
    // JavaScript Error Text Form Validation - EDIT FORM
    // =========================================================
    $('#editMenuForm').on('submit', function(e) {
        let isValid = true;

        let menuDay = $('#edit_menu_day').val().trim();
        let breakFast = $('#edit_break_fast').val().trim();
        let breakfastTime = $('#edit_breakfast_time').val().trim();
        let lunch = $('#edit_lunch').val().trim();
        let lunchTime = $('#edit_lunch_time').val().trim();
        let eveningTea = $('#edit_evening_tea').val().trim();
        let eveningTeaTime = $('#edit_evening_tea_time').val().trim();
        let dinner = $('#edit_dinner').val().trim();
        let dinnerTime = $('#edit_dinner_time').val().trim();

        // 1. Day Validation
        if (menuDay === '') {
            $('#edit_day_err').text('Please select a day of the week');
            isValid = false;
        } else {
            $('#edit_day_err').text('');
        }

        // 2. Breakfast Items Validation
        if (breakFast === '') {
            $('#edit_break_fast_err').text('Breakfast menu items are required');
            isValid = false;
        } else {
            $('#edit_break_fast_err').text('');
        }

        // 3. Breakfast Time Validation
        if (breakfastTime === '') {
            $('#edit_breakfast_time_err').text('Breakfast timing is required');
            isValid = false;
        } else {
            $('#edit_breakfast_time_err').text('');
        }

        // 4. Lunch Items Validation
        if (lunch === '') {
            $('#edit_lunch_err').text('Lunch menu items are required');
            isValid = false;
        } else {
            $('#edit_lunch_err').text('');
        }

        // 5. Lunch Time Validation
        if (lunchTime === '') {
            $('#edit_lunch_time_err').text('Lunch timing is required');
            isValid = false;
        } else {
            $('#edit_lunch_time_err').text('');
        }

        // 6. Evening Tea Items Validation
        if (eveningTea === '') {
            $('#edit_evening_tea_err').text('Evening tea & snacks items are required');
            isValid = false;
        } else {
            $('#edit_evening_tea_err').text('');
        }

        // 7. Evening Tea Time Validation
        if (eveningTeaTime === '') {
            $('#edit_evening_tea_time_err').text('Evening tea timing is required');
            isValid = false;
        } else {
            $('#edit_evening_tea_time_err').text('');
        }

        // 8. Dinner Items Validation
        if (dinner === '') {
            $('#edit_dinner_err').text('Dinner menu items are required');
            isValid = false;
        } else {
            $('#edit_dinner_err').text('');
        }

        // 9. Dinner Time Validation
        if (dinnerTime === '') {
            $('#edit_dinner_time_err').text('Dinner timing is required');
            isValid = false;
        } else {
            $('#edit_dinner_time_err').text('');
        }

        if (!isValid) {
            e.preventDefault();
        }
    });

    // Real-time error text clearing on Edit Form inputs
    $('#edit_menu_day').on('change', function() {
        if ($(this).val().trim() !== '') $('#edit_day_err').text('');
    });
    $('#edit_break_fast').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_break_fast_err').text('');
    });
    $('#edit_breakfast_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_breakfast_time_err').text('');
    });
    $('#edit_lunch').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_lunch_err').text('');
    });
    $('#edit_lunch_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_lunch_time_err').text('');
    });
    $('#edit_evening_tea').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_evening_tea_err').text('');
    });
    $('#edit_evening_tea_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_evening_tea_time_err').text('');
    });
    $('#edit_dinner').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_dinner_err').text('');
    });
    $('#edit_dinner_time').on('input', function() {
        if ($(this).val().trim() !== '') $('#edit_dinner_time_err').text('');
    });


    // =========================================================
    // Populate Edit Modal
    // =========================================================
    $(document).on('click', '.edit-menu-btn', function() {
        var id = $(this).data('id');
        var day = $(this).data('day');
        var breakfast = $(this).data('breakfast');
        var breakfastTime = $(this).data('breakfast-time');
        var lunch = $(this).data('lunch');
        var lunchTime = $(this).data('lunch-time');
        var tea = $(this).data('tea');
        var teaTime = $(this).data('tea-time');
        var dinner = $(this).data('dinner');
        var dinnerTime = $(this).data('dinner-time');

        $('#edit_menu_id').val(id);
        $('#edit_menu_day').val(day);
        $('#edit_day_display').text(day);
        $('#edit_break_fast').val(breakfast);
        $('#edit_breakfast_time').val(breakfastTime || '07:30 AM - 09:30 AM');
        $('#edit_lunch').val(lunch);
        $('#edit_lunch_time').val(lunchTime || '12:30 PM - 02:30 PM');
        $('#edit_evening_tea').val(tea);
        $('#edit_evening_tea_time').val(teaTime || '05:00 PM - 06:00 PM');
        $('#edit_dinner').val(dinner);
        $('#edit_dinner_time').val(dinnerTime || '07:30 PM - 09:30 PM');

        // Clear previous error messages
        $('#edit_day_err, #edit_break_fast_err, #edit_breakfast_time_err, #edit_lunch_err, #edit_lunch_time_err, #edit_evening_tea_err, #edit_evening_tea_time_err, #edit_dinner_err, #edit_dinner_time_err').text('');

        $('#editMenuModal').modal('show');
    });

    // Reset errors when opening add modal
    $('#setMenuModal').on('show.bs.modal', function() {
        $('#add_day_err, #add_break_fast_err, #add_breakfast_time_err, #add_lunch_err, #add_lunch_time_err, #add_evening_tea_err, #add_evening_tea_time_err, #add_dinner_err, #add_dinner_time_err').text('');
    });

    // Delete Confirmation with SweetAlert2
    $(document).on('click', '.delete-menu-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');
        var day = $(this).data('day');

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Delete ' + day + ' Menu?',
                text: "Are you sure you want to remove the menu for " + day + "?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                customClass: {
                    popup: 'rounded-16 shadow-lg'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        } else {
            if (confirm("Are you sure you want to delete the menu for " + day + "?")) {
                window.location.href = url;
            }
        }
    });

    // Clear All Confirmation with SweetAlert2
    $(document).on('click', '.clear-all-btn', function(e) {
        e.preventDefault();
        var url = $(this).data('url');

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Clear All Menus?',
                text: "This will remove all saved menu records from the database. Are you sure?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, clear all!',
                cancelButtonText: 'Cancel',
                customClass: {
                    popup: 'rounded-16 shadow-lg'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        } else {
            if (confirm("Are you sure you want to clear all menu records?")) {
                window.location.href = url;
            }
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>