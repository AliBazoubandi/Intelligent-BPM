<?php
require __DIR__ . '/../inc/auth.php';
auth_session_start();
$id = current_identity('team');
header('Location: ' . ($id && $id['kind'] === 'team' ? 'home.php' : 'login.php'));
exit;
