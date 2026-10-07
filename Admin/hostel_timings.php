<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../db.php';

// Auto-create hostel_timings table if it doesn't exist yet
$create_table_sql = "CREATE TABLE IF NOT EXISTS `hostel_timings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `attendance_start_time` TIME NOT NULL,
    `attendance_end_time` TIME NOT NULL,
    `outing_start_time` TIME NOT NULL,
    `outing_end_time` TIME NOT NULL,
    `latitude` VARCHAR(50) DEFAULT NULL,
    `longitude` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$conn->query($create_table_sql);

// Alter table to add latitude and longitude columns if missing
$conn->query("ALTER TABLE `hostel_timings` ADD COLUMN IF NOT EXISTS `latitude` VARCHAR(50) DEFAULT NULL AFTER `outing_end_time`");
$conn->query("ALTER TABLE `hostel_timings` ADD COLUMN IF NOT EXISTS `longitude` VARCHAR(50) DEFAULT NULL AFTER `latitude`");

// Strict Enforcer: Clean up empty rows and keep ONLY ONE single row in table
$conn->query("DELETE FROM `hostel_timings` WHERE `attendance_start_time` IS NULL OR `attendance_start_time` = '' OR `attendance_start_time` = '00:00:00'");
$conn->query("DELETE FROM `hostel_timings` WHERE `id` NOT IN (SELECT max_id FROM (SELECT MAX(`id`) AS max_id FROM `hostel_timings`) AS temp)");

// Check if single timing record exists
$fetch_existing = $conn->query("SELECT * FROM `hostel_timings` ORDER BY `id` DESC LIMIT 1");
$existing_timing = ($fetch_existing && $fetch_existing->num_rows > 0) ? $fetch_existing->fetch_assoc() : null;

// Handle Add or Update Submission (Single Record System)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $attendance_start_time = isset($_POST['attendance_start_time']) ? trim($_POST['attendance_start_time']) : '';
    $attendance_end_time = isset($_POST['attendance_end_time']) ? trim($_POST['attendance_end_time']) : '';
    $outing_start_time = isset($_POST['outing_start_time']) ? trim($_POST['outing_start_time']) : '';
    $outing_end_time = isset($_POST['outing_end_time']) ? trim($_POST['outing_end_time']) : '';
    $latitude = isset($_POST['latitude']) ? trim($_POST['latitude']) : '';
    $longitude = isset($_POST['longitude']) ? trim($_POST['longitude']) : '';

    // Validate required fields
    if (empty($attendance_start_time) || empty($attendance_end_time) || empty($outing_start_time) || empty($outing_end_time) || empty($latitude) || empty($longitude)) {
        $_SESSION['error'] = "All fields are mandatory. Please fill in all details.";
        header("Location: hostel_timings.php");
        exit();
    }

    if ($existing_timing) {
        // Update the single existing record
        $target_id = intval($existing_timing['id']);
        $stmt = $conn->prepare("UPDATE `hostel_timings` SET `attendance_start_time` = ?, `attendance_end_time` = ?, `outing_start_time` = ?, `outing_end_time` = ?, `latitude` = ?, `longitude` = ?, `updated_at` = NOW() WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $attendance_start_time, $attendance_end_time, $outing_start_time, $outing_end_time, $latitude, $longitude, $target_id);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Hostel timings and location updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update hostel timings: " . $conn->error;
            }
            $stmt->close();
        }
    } else {
        // Insert initial single timing record
        $stmt = $conn->prepare("INSERT INTO `hostel_timings` (`attendance_start_time`, `attendance_end_time`, `outing_start_time`, `outing_end_time`, `latitude`, `longitude`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
        if ($stmt) {
            $stmt->bind_param("ssssss", $attendance_start_time, $attendance_end_time, $outing_start_time, $outing_end_time, $latitude, $longitude);
            if ($stmt->execute()) {
                $_SESSION['msg'] = "Hostel timings and location configured successfully.";
            } else {
                $_SESSION['error'] = "Failed to save hostel timings: " . $conn->error;
            }
            $stmt->close();
        }
    }

    header("Location: hostel_timings.php");
    exit();
}

ob_start(); 
?>

<div class="row">
    <div class="col-lg-12 grid-margin stretch-card">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="card-title mb-1">Hostel Timings & Geofence Location</h4>
                        <p class="text-muted mb-0 style-subtext" style="font-size: 0.85rem;">Global configuration for daily attendance windows, student outing timings, and hostel coordinates.</p>
                    </div>
                    <?php if (!$existing_timing): ?>
                        <button class="btn btn-primary btn-sm font-weight-bold" data-toggle="modal" data-target="#timingModal">
                            <i class="ti-plus mr-1"></i> Set Hostel Timings
                        </button>
                    <?php endif; ?>
                </div>
                
                <?php if(isset($_SESSION['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="ti-check mr-2"></i><?= htmlspecialchars($_SESSION['msg']) ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['msg']); endif; ?>

                <?php if(isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="ti-alert mr-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php unset($_SESSION['error']); endif; ?>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 70px;">SlNo</th>
                                <th>Attendance Time</th>
                                <th>Outing Time</th>
                                <th>Hostel Coordinates (Lat, Long)</th>
                                <th>Last Updated</th>
                                <th style="width: 160px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($existing_timing): 
                                $att_start_fmt = !empty($existing_timing['attendance_start_time']) ? date('h : i A', strtotime($existing_timing['attendance_start_time'])) : 'N/A';
                                $att_end_fmt   = !empty($existing_timing['attendance_end_time']) ? date('h : i A', strtotime($existing_timing['attendance_end_time'])) : 'N/A';
                                $out_start_fmt = !empty($existing_timing['outing_start_time']) ? date('h : i A', strtotime($existing_timing['outing_start_time'])) : 'N/A';
                                $out_end_fmt   = !empty($existing_timing['outing_end_time']) ? date('h : i A', strtotime($existing_timing['outing_end_time'])) : 'N/A';

                                $att_time_range = ($att_start_fmt !== 'N/A' && $att_end_fmt !== 'N/A') ? "{$att_start_fmt} - {$att_end_fmt}" : 'N/A';
                                $out_time_range = ($out_start_fmt !== 'N/A' && $out_end_fmt !== 'N/A') ? "{$out_start_fmt} - {$out_end_fmt}" : 'N/A';
                                
                                $lat_val = !empty($existing_timing['latitude']) ? htmlspecialchars($existing_timing['latitude']) : '';
                                $long_val = !empty($existing_timing['longitude']) ? htmlspecialchars($existing_timing['longitude']) : '';
                            ?>
                            <tr>
                                <td class="font-weight-bold">1</td>
                                <td>
                                    <span class="badge badge-success font-weight-600 px-3 py-2" style="font-size: 0.85rem; border-radius: 8px;">
                                        <i class="ti-alarm-clock mr-1"></i><?= $att_time_range ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-info font-weight-600 px-3 py-2" style="font-size: 0.85rem; border-radius: 8px;">
                                        <i class="ti-time mr-1"></i><?= $out_time_range ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($lat_val) && !empty($long_val)): ?>
                                        <span class="badge badge-light border text-dark font-weight-600 px-2 py-1" style="font-size: 0.82rem;">
                                            <i class="ti-location-pin text-primary mr-1"></i><?= $lat_val ?>, <?= $long_val ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted font-italic" style="font-size: 0.82rem;">Coordinates not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted font-weight-500">
                                        <?= !empty($existing_timing['updated_at']) ? date('d M, Y h:i A', strtotime($existing_timing['updated_at'])) : 'N/A' ?>
                                    </small>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm edit-btn font-weight-600" title="Update Timings" 
                                        data-id="<?= $existing_timing['id'] ?>"
                                        data-att-start="<?= htmlspecialchars($existing_timing['attendance_start_time']) ?>"
                                        data-att-end="<?= htmlspecialchars($existing_timing['attendance_end_time']) ?>"
                                        data-out-start="<?= htmlspecialchars($existing_timing['outing_start_time']) ?>"
                                        data-out-end="<?= htmlspecialchars($existing_timing['outing_end_time']) ?>"
                                        data-latitude="<?= $lat_val ?>"
                                        data-longitude="<?= $long_val ?>"
                                        data-toggle="modal" data-target="#timingModal">
                                        <i class="ti-pencil mr-1"></i> Update Timings
                                    </button>
                                </td>
                            </tr>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4 font-weight-500">
                                    <i class="ti-info-alt mr-1"></i> No hostel timings configured. Click <strong>"Set Hostel Timings"</strong> to initialize settings.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Add or Update Timings -->
<div class="modal fade" id="timingModal" tabindex="-1" role="dialog" aria-labelledby="timingModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" action="" id="timingForm">
          <div class="modal-header">
            <h5 class="modal-title font-weight-bold" id="timingModalLabel">
                <?= $existing_timing ? 'Update Hostel Timings' : 'Set Hostel Timings' ?>
            </h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="action" value="<?= $existing_timing ? 'edit' : 'add' ?>">
            <?php if ($existing_timing): ?>
                <input type="hidden" name="id" id="modal_id" value="<?= $existing_timing['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label class="font-weight-600">Attendance Start Time <span class="text-danger">*</span></label>
                <input type="time" name="attendance_start_time" id="modal_att_start" class="form-control" value="<?= $existing_timing ? htmlspecialchars($existing_timing['attendance_start_time']) : '20:00' ?>">
                <small class="text-danger" id="att_start_err"></small>
            </div>

            <div class="form-group">
                <label class="font-weight-600">Attendance End Time <span class="text-danger">*</span></label>
                <input type="time" name="attendance_end_time" id="modal_att_end" class="form-control" value="<?= $existing_timing ? htmlspecialchars($existing_timing['attendance_end_time']) : '21:30' ?>">
                <small class="text-danger" id="att_end_err"></small>
            </div>

            <div class="form-group">
                <label class="font-weight-600">Outing Start Time <span class="text-danger">*</span></label>
                <input type="time" name="outing_start_time" id="modal_out_start" class="form-control" value="<?= $existing_timing ? htmlspecialchars($existing_timing['outing_start_time']) : '06:00' ?>">
                <small class="text-danger" id="out_start_err"></small>
            </div>

            <div class="form-group">
                <label class="font-weight-600">Outing End Time <span class="text-danger">*</span></label>
                <input type="time" name="outing_end_time" id="modal_out_end" class="form-control" value="<?= $existing_timing ? htmlspecialchars($existing_timing['outing_end_time']) : '20:00' ?>">
                <small class="text-danger" id="out_end_err"></small>
            </div>

            <div class="form-row">
                <div class="col-md-6 form-group">
                    <label class="font-weight-600">Hostel Latitude <span class="text-danger">*</span></label>
                    <input type="text" name="latitude" id="modal_latitude" class="form-control" value="<?= $existing_timing ? htmlspecialchars($existing_timing['latitude'] ?? '') : '' ?>" placeholder="e.g. 12.9715987">
                    <small class="text-danger" id="latitude_err"></small>
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-600">Hostel Longitude <span class="text-danger">*</span></label>
                    <input type="text" name="longitude" id="modal_longitude" class="form-control" value="<?= $existing_timing ? htmlspecialchars($existing_timing['longitude'] ?? '') : '' ?>" placeholder="e.g. 77.5945627">
                    <small class="text-danger" id="longitude_err"></small>
                </div>
            </div>

          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">
                <?= $existing_timing ? 'Update Timings' : 'Save Timings' ?>
            </button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Fill timing modal when Edit/Update button is clicked
    $(document).on('click', '.edit-btn', function() {
        var id = $(this).data('id');
        var attStart = $(this).data('att-start');
        var attEnd = $(this).data('att-end');
        var outStart = $(this).data('out-start');
        var outEnd = $(this).data('out-end');
        var lat = $(this).data('latitude');
        var long = $(this).data('longitude');
        
        if ($('#modal_id').length) {
            $('#modal_id').val(id);
        }
        $('#modal_att_start').val(attStart);
        $('#modal_att_end').val(attEnd);
        $('#modal_out_start').val(outStart);
        $('#modal_out_end').val(outEnd);
        $('#modal_latitude').val(lat);
        $('#modal_longitude').val(long);
        
        $('#att_start_err').text('');
        $('#att_end_err').text('');
        $('#out_start_err').text('');
        $('#out_end_err').text('');
        $('#latitude_err').text('');
        $('#longitude_err').text('');
    });

    // Clear modal error messages when modal opens
    $('#timingModal').on('show.bs.modal', function () {
        $('#att_start_err').text('');
        $('#att_end_err').text('');
        $('#out_start_err').text('');
        $('#out_end_err').text('');
        $('#latitude_err').text('');
        $('#longitude_err').text('');
    });

    // Form submission validation
    $('#timingForm').on('submit', function(e) {
        let valid = true;
        let attStart = $('#modal_att_start').val().trim();
        let attEnd = $('#modal_att_end').val().trim();
        let outStart = $('#modal_out_start').val().trim();
        let outEnd = $('#modal_out_end').val().trim();
        let latitude = $('#modal_latitude').val().trim();
        let longitude = $('#modal_longitude').val().trim();

        if (attStart === '') {
            $('#att_start_err').text('Attendance Start Time is mandatory');
            valid = false;
        } else {
            $('#att_start_err').text('');
        }

        if (attEnd === '') {
            $('#att_end_err').text('Attendance End Time is mandatory');
            valid = false;
        } else {
            $('#att_end_err').text('');
        }

        if (outStart === '') {
            $('#out_start_err').text('Outing Start Time is mandatory');
            valid = false;
        } else {
            $('#out_start_err').text('');
        }

        if (outEnd === '') {
            $('#out_end_err').text('Outing End Time is mandatory');
            valid = false;
        } else {
            $('#out_end_err').text('');
        }

        if (latitude === '') {
            $('#latitude_err').text('Hostel Latitude is mandatory');
            valid = false;
        } else {
            $('#latitude_err').text('');
        }

        if (longitude === '') {
            $('#longitude_err').text('Hostel Longitude is mandatory');
            valid = false;
        } else {
            $('#longitude_err').text('');
        }

        if (!valid) {
            e.preventDefault();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
