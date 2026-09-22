<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['student_id']);
unset($_SESSION['student_name']);
unset($_SESSION['student_email']);
unset($_SESSION['student_phone']);
unset($_SESSION['student_role']);
unset($_SESSION['student_image']);
unset($_SESSION['student_room']);

header("Location: login.php");
exit();
?>
