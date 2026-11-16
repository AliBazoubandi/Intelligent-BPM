<?php
require __DIR__ . '/../inc/auth.php';
auth_session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) {
    header('Location: home.php');
    exit;
}

unset($_SESSION['op_user']);
if (isset($_SESSION['op_identity']) && ($_SESSION['op_identity']['kind'] ?? '') === 'user') {
    unset($_SESSION['op_identity']);
}
if (empty($_SESSION['op_team'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
header('Location: login.php');
exit;
