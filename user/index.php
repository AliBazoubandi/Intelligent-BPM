<?php
require __DIR__ . '/../inc/auth.php';
auth_session_start();
header('Location: ' . (current_identity() && current_identity()['kind'] === 'user' ? 'home.php' : 'login.php'));
exit;
