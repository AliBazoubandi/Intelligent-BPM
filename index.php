<?php
/**
 * ریشه سامانه — هدایت به ورود کاربران سازمان
 * تیم فرآیند: /process/team/
 */
require __DIR__ . '/config.php';
header('Location: ' . USER_URL . '/login.php');
exit;
