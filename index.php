<?php
/**
 * ریشه سامانه — هدایت به ورود کاربران سازمان
 * تیم فرآیند: /team/
 */
require __DIR__ . '/config.php';
header('Location: ' . USER_URL . '/login.php');
exit;
