<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['warden_id']);
unset($_SESSION['warden_name']);
unset($_SESSION['warden_code']);
unset($_SESSION['warden_email']);
unset($_SESSION['warden_phone']);
unset($_SESSION['warden_image']);
unset($_SESSION['login_success']);

header("Location: login.php");
exit();
?>
