<?php
ob_start();
?>

<div class="row">
    <div class="col-12">
        <h4 class="font-weight-bold text-dark mb-4">Dashboard</h4>
    </div>
</div>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
