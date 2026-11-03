<?php
require __DIR__ . '/../inc/auth.php';
auth_session_start();
$id = current_identity('user');
header('Location: ' . ($id && $id['kind'] === 'user' ? 'home.php' : 'login.php'));
exit;
