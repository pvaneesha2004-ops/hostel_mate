<?php
ob_start();
?>

<?php
$content = ob_get_clean();
include 'main_master.php';
?>
