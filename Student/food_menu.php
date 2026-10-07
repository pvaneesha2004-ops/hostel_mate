<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

require_once '../db.php';

// Standard 7 days list in sequence
$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Fetch all menus from database
$menus_raw = $conn->query("SELECT * FROM `food_menus`");
$menus_by_day = [];
$all_menus_list = [];

if ($menus_raw && $menus_raw->num_rows > 0) {
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
  /* Food Menu Custom Aesthetics matching Student Section (Indigo / Violet Theme) */
  .food-hero-card {
    background: linear-gradient(135deg, #4338ca 0%, #6366f1 50%, #8b5cf6 100%);
    border-radius: 18px;
    color: #ffffff;
    padding: 2rem 2.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 28px rgba(99, 102, 241, 0.22);
    margin-bottom: 2rem;
  }

  .food-hero-card::after {
    content: "\f2e7";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 25px;
    bottom: -20px;
    font-size: 140px;
    color: rgba(255, 255, 255, 0.08);
    pointer-events: none;
  }

  .today-badge-pulse {
    display: inline-flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.35rem 0.85rem;
    border-radius: 50px;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4);
    animation: pulse-indigo 2s infinite;
  }

  @keyframes pulse-indigo {
    0% {
      transform: scale(0.96);
      box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.5);
    }
    70% {
      transform: scale(1);
      box-shadow: 0 0 0 10px rgba(255, 255, 255, 0);
    }
    100% {
      transform: scale(0.96);
      box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
    }
  }

  .meal-preview-box {
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 14px;
    padding: 1.15rem;
    height: 100%;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }

  .meal-preview-box:hover {
    background: rgba(255, 255, 255, 0.18);
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
  }

  .meal-tag {
    font-size: 0.74rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-bottom: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
  }

  .meal-time-pill {
    font-size: 0.68rem;
    background: rgba(255, 255, 255, 0.22);
    padding: 0.18rem 0.55rem;
    border-radius: 20px;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
    white-space: nowrap;
    border: 1px solid rgba(255, 255, 255, 0.25);
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
    background: #e0e7ff;
    color: #3730a3;
    border: 1.5px solid #a5b4fc;
    box-shadow: 0 2px 6px rgba(99, 102, 241, 0.12);
  }

  .day-pill-regular {
    background: #f1f5f9;
    color: #334155;
    border: 1.5px solid #e2e8f0;
  }

  /* Food Menu Table Layout & Cell Alignment */
  #foodMenuTable {
    min-width: 1200px !important;
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
    background-color: #eef2ff !important;
    border-bottom: 2px solid #c7d2fe !important;
    color: #3730a3 !important;
    font-size: 0.78rem !important;
    letter-spacing: 0.6px;
    white-space: nowrap !important;
  }

  #foodMenuTable td {
    white-space: normal !important; /* Allows meal text to wrap cleanly */
  }

  .meal-cell {
    min-width: 210px !important;
    max-width: 300px !important;
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
    margin-bottom: 6px;
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
    border: 1px solid #e2e8f0;
    white-space: nowrap !important;
  }

  .meal-icon-indicator {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    margin-right: 12px;
    flex-shrink: 0;
    margin-top: 2px;
  }

  .icon-breakfast { background: #fef3c7; color: #d97706; }
  .icon-lunch { background: #dcfce7; color: #15803d; }
  .icon-tea { background: #ffedd5; color: #ea580c; }
  .icon-dinner { background: #ede9fe; color: #6d28d9; }

  .btn-student-primary {
    background: #6366f1;
    border-color: #6366f1;
    color: #ffffff;
    font-weight: 600;
    border-radius: 8px;
    padding: 0.45rem 0.85rem;
    font-size: 0.85rem;
    transition: all 0.2s ease;
  }
  .btn-student-primary:hover {
    background: #4f46e5;
    border-color: #4f46e5;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
  }

  /* DataTables Custom Controls Styling */
  .dataTables_wrapper .row:first-child {
    margin-bottom: 1.25rem !important;
    align-items: center;
  }
  .dataTables_wrapper .row:last-child {
    margin-top: 1.25rem !important;
    align-items: center;
  }
  .dataTables_wrapper .dataTables_filter input {
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    padding: 0.4rem 0.75rem !important;
    font-size: 0.875rem !important;
    margin-left: 0.5rem !important;
    outline: none !important;
  }
  .dataTables_wrapper .dataTables_filter input:focus {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important;
  }
  .dataTables_wrapper .dataTables_length select {
    border: 1px solid #cbd5e1 !important;
    border-radius: 8px !important;
    padding: 0.35rem 0.6rem !important;
    font-size: 0.875rem !important;
    outline: none !important;
  }
  .dataTables_wrapper .page-item.active .page-link {
    background-color: #6366f1 !important;
    border-color: #6366f1 !important;
    color: #ffffff !important;
    border-radius: 6px;
  }
  .dataTables_wrapper .page-link {
    color: #475569 !important;
    border-radius: 6px;
    margin: 0 2px;
    font-weight: 600;
    font-size: 0.85rem;
  }
</style>

<!-- Top Hero Card: Today's Menu Highlight -->
<div class="food-hero-card">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div>
      <div class="d-flex align-items-center mb-2">
        <span class="today-badge-pulse mr-2">
          <i class="fa-solid fa-calendar-day mr-1"></i> Today's Status
        </span>
        <h3 class="mb-0 font-weight-bold text-white"><?= htmlspecialchars($current_day_name) ?>'s Mess Menu</h3>
      </div>
      <p class="text-white-50 mb-0" style="font-size: 0.92rem; max-width: 680px;">
        Daily timetable and menu preview for breakfast, lunch, evening snacks, and night dinner.
      </p>
    </div>
  </div>

  <?php if ($todays_menu): ?>
    <div class="row mt-4">
      <!-- Breakfast -->
      <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
        <div class="meal-preview-box">
          <div class="meal-tag" style="color: #fde68a !important;">
            <span><i class="fa-solid fa-mug-hot mr-1"></i> Breakfast</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['breakfast_time']) ? htmlspecialchars($todays_menu['breakfast_time']) : '7:30 - 9:30 AM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.45;">
            <?= !empty($todays_menu['break_fast']) ? nl2br(htmlspecialchars($todays_menu['break_fast'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
      <!-- Lunch -->
      <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
        <div class="meal-preview-box">
          <div class="meal-tag" style="color: #a7f3d0 !important;">
            <span><i class="fa-solid fa-bowl-rice mr-1"></i> Lunch</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['lunch_time']) ? htmlspecialchars($todays_menu['lunch_time']) : '12:30 - 2:30 PM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.45;">
            <?= !empty($todays_menu['lunch']) ? nl2br(htmlspecialchars($todays_menu['lunch'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
      <!-- Evening Snacks -->
      <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
        <div class="meal-preview-box">
          <div class="meal-tag" style="color: #fed7aa !important;">
            <span><i class="fa-solid fa-cookie-bite mr-1"></i> Evening Tea</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['evening_tea_time']) ? htmlspecialchars($todays_menu['evening_tea_time']) : '5:00 - 6:00 PM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.45;">
            <?= !empty($todays_menu['evening_tea']) ? nl2br(htmlspecialchars($todays_menu['evening_tea'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
      <!-- Dinner -->
      <div class="col-lg-3 col-md-6 col-sm-6 mb-3 mb-lg-0">
        <div class="meal-preview-box">
          <div class="meal-tag" style="color: #ddd6fe !important;">
            <span><i class="fa-solid fa-utensils mr-1"></i> Dinner</span>
            <span class="meal-time-pill"><?= !empty($todays_menu['dinner_time']) ? htmlspecialchars($todays_menu['dinner_time']) : '7:30 - 9:30 PM' ?></span>
          </div>
          <div class="font-weight-500 text-white" style="font-size: 0.88rem; line-height: 1.45;">
            <?= !empty($todays_menu['dinner']) ? nl2br(htmlspecialchars($todays_menu['dinner'])) : '<span class="text-white-50 font-italic">Not Specified</span>' ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="alert mb-0 mt-3 d-flex align-items-center" style="background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.25); color: #ffffff; border-radius: 12px; padding: 1rem 1.25rem;">
      <i class="fa-solid fa-circle-info mr-3 font-weight-bold" style="font-size: 1.25rem;"></i>
      <div>
        Food menu details for <strong><?= htmlspecialchars($current_day_name) ?></strong> have not been configured yet.
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Weekly Menu Master Card -->
<div class="row">
  <div class="col-12">
    <div class="card shadow-sm border-0 rounded-16">
      <div class="card-body p-4">
        
        <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
          <div>
            <h4 class="card-title font-weight-bold mb-1" style="font-size: 1.2rem; color: #0f172a;">
              <i class="fa-solid fa-utensils mr-2" style="color: var(--student-primary) !important;"></i>Weekly Mess Timetable & Menu
            </h4>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
              Comprehensive schedule for breakfast, lunch, evening tea & snacks, and night dinner.
            </p>
          </div>
        </div>

        <?php if (empty($all_menus_list)): ?>
          <div class="text-center py-5">
            <div class="mb-3">
              <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 75px; height: 75px; background: rgba(99, 102, 241, 0.08); color: #6366f1; font-size: 2rem;">
                <i class="fa-solid fa-bowl-food"></i>
              </span>
            </div>
            <h5 class="font-weight-bold text-dark mb-1">No Food Menu Records Found</h5>
            <p class="text-muted mb-0" style="max-width: 480px; margin: 0 auto; font-size: 0.9rem;">
              The mess food menu has not been set by the administration yet.
            </p>
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
                  <th style="width: 90px; min-width: 90px;" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sl = 1;
                foreach ($all_menus_list as $row): 
                  $is_today = (strcasecmp($row['menu_day'], $current_day_name) === 0);
                ?>
                  <tr class="<?= $is_today ? 'table-primary-light' : '' ?>" style="<?= $is_today ? 'background-color: rgba(99, 102, 241, 0.04);' : '' ?>">
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
                      <button type="button" class="btn btn-sm btn-student-primary view-menu-btn shadow-sm" 
                              title="View Full Day Menu"
                              data-id="<?= $row['id'] ?>"
                              data-day="<?= htmlspecialchars($row['menu_day']) ?>"
                              data-breakfast="<?= htmlspecialchars($row['break_fast']) ?>"
                              data-breakfast-time="<?= htmlspecialchars($row['breakfast_time'] ?? '') ?>"
                              data-lunch="<?= htmlspecialchars($row['lunch']) ?>"
                              data-lunch-time="<?= htmlspecialchars($row['lunch_time'] ?? '') ?>"
                              data-tea="<?= htmlspecialchars($row['evening_tea']) ?>"
                              data-tea-time="<?= htmlspecialchars($row['evening_tea_time'] ?? '') ?>"
                              data-dinner="<?= htmlspecialchars($row['dinner']) ?>"
                              data-dinner-time="<?= htmlspecialchars($row['dinner_time'] ?? '') ?>"
                              data-updated="<?= !empty($row['updated_at']) ? date('d M Y, h:i A', strtotime($row['updated_at'])) : 'N/A' ?>">
                        <i class="fa-solid fa-eye mr-1"></i> View
                      </button>
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

<!-- View Day Menu Modal -->
<div class="modal fade" id="viewMenuModal" tabindex="-1" role="dialog" aria-labelledby="viewMenuModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg rounded-16 overflow-hidden">
        <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);">
          <div class="d-flex align-items-center">
            <div class="rounded-circle bg-white d-flex align-items-center justify-content-center mr-3 font-weight-bold" style="width: 38px; height: 38px; font-size: 1.1rem; color: #6366f1 !important;">
              <i class="fa-solid fa-utensils"></i>
            </div>
            <div>
              <h5 class="modal-title font-weight-bold mb-0 text-white" id="viewMenuModalLabel">
                Day Menu: <span id="view_day_title"></span>
              </h5>
              <small class="text-white-50">Meal breakdown and serving schedule</small>
            </div>
          </div>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

        <div class="modal-body p-4" style="background: #f8fafc;">
          <div class="row">
            <!-- Breakfast Card -->
            <div class="col-md-6 mb-3">
              <div class="card border rounded-12 shadow-sm h-100 bg-white">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center border-bottom text-dark py-2 px-3">
                  <span class="d-flex align-items-center">
                    <span class="meal-icon-indicator icon-breakfast"><i class="fa-solid fa-mug-hot"></i></span>
                    Breakfast
                  </span>
                  <span class="badge badge-light border text-muted" id="view_breakfast_time"></span>
                </div>
                <div class="card-body p-3">
                  <p class="mb-0 text-dark" id="view_breakfast_content" style="white-space: pre-line; line-height: 1.5; font-size: 0.9rem;"></p>
                </div>
              </div>
            </div>

            <!-- Lunch Card -->
            <div class="col-md-6 mb-3">
              <div class="card border rounded-12 shadow-sm h-100 bg-white">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center border-bottom text-dark py-2 px-3">
                  <span class="d-flex align-items-center">
                    <span class="meal-icon-indicator icon-lunch"><i class="fa-solid fa-bowl-rice"></i></span>
                    Lunch
                  </span>
                  <span class="badge badge-light border text-muted" id="view_lunch_time"></span>
                </div>
                <div class="card-body p-3">
                  <p class="mb-0 text-dark" id="view_lunch_content" style="white-space: pre-line; line-height: 1.5; font-size: 0.9rem;"></p>
                </div>
              </div>
            </div>

            <!-- Evening Tea Card -->
            <div class="col-md-6 mb-3">
              <div class="card border rounded-12 shadow-sm h-100 bg-white">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center border-bottom text-dark py-2 px-3">
                  <span class="d-flex align-items-center">
                    <span class="meal-icon-indicator icon-tea"><i class="fa-solid fa-cookie-bite"></i></span>
                    Evening Tea & Snacks
                  </span>
                  <span class="badge badge-light border text-muted" id="view_tea_time"></span>
                </div>
                <div class="card-body p-3">
                  <p class="mb-0 text-dark" id="view_tea_content" style="white-space: pre-line; line-height: 1.5; font-size: 0.9rem;"></p>
                </div>
              </div>
            </div>

            <!-- Dinner Card -->
            <div class="col-md-6 mb-3">
              <div class="card border rounded-12 shadow-sm h-100 bg-white">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center border-bottom text-dark py-2 px-3">
                  <span class="d-flex align-items-center">
                    <span class="meal-icon-indicator icon-dinner"><i class="fa-solid fa-utensils"></i></span>
                    Dinner
                  </span>
                  <span class="badge badge-light border text-muted" id="view_dinner_time"></span>
                </div>
                <div class="card-body p-3">
                  <p class="mb-0 text-dark" id="view_dinner_content" style="white-space: pre-line; line-height: 1.5; font-size: 0.9rem;"></p>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-2 text-right">
            <small class="text-muted"><i class="fa-regular fa-clock mr-1"></i>Last Updated: <span id="view_updated_at"></span></small>
          </div>
        </div>

        <div class="modal-footer bg-white border-top py-2 px-4">
          <button type="button" class="btn btn-secondary px-4 rounded-10 font-weight-500" data-dismiss="modal">Close</button>
        </div>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Initialize DataTables
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
        $('.datatable').DataTable({
            "order": [],
            "paging": false,
            "info": false,
            "searching": false,
            "columnDefs": [
                { "orderable": false, "targets": [2, 3, 4, 5, 7] }
            ]
        });
    }

    // View Menu Details Click Event
    $(document).on('click', '.view-menu-btn', function() {
        var day = $(this).data('day');
        var bf = $(this).data('breakfast');
        var bfTime = $(this).data('breakfast-time');
        var lunch = $(this).data('lunch');
        var lunchTime = $(this).data('lunch-time');
        var tea = $(this).data('tea');
        var teaTime = $(this).data('tea-time');
        var dinner = $(this).data('dinner');
        var dinnerTime = $(this).data('dinner-time');
        var updated = $(this).data('updated');

        $('#view_day_title').text(day);

        $('#view_breakfast_content').text(bf ? bf : 'Not Specified');
        $('#view_breakfast_time').text(bfTime ? bfTime : '7:30 - 9:30 AM');

        $('#view_lunch_content').text(lunch ? lunch : 'Not Specified');
        $('#view_lunch_time').text(lunchTime ? lunchTime : '12:30 - 2:30 PM');

        $('#view_tea_content').text(tea ? tea : 'Not Specified');
        $('#view_tea_time').text(teaTime ? teaTime : '5:00 - 6:00 PM');

        $('#view_dinner_content').text(dinner ? dinner : 'Not Specified');
        $('#view_dinner_time').text(dinnerTime ? dinnerTime : '7:30 - 9:30 PM');

        $('#view_updated_at').text(updated ? updated : 'N/A');

        $('#viewMenuModal').modal('show');
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
