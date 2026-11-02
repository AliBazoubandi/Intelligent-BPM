<?php
/**
 * ورود کاربران سازمان — /user
 */
require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/icons.php';
auth_session_start();
redirect_if_logged_in('user');

$error = '';
$oldCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $oldCode = $code;

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده؛ دوباره تلاش کنید.';
    } elseif ($code === '' || $password === '') {
        $error = 'کد پرسنلی و رمز عبور را وارد کنید.';
    } else {
        $identity = attempt_user_login($code, $password);
        if ($identity) {
            log_login('user', $identity['id'], $code, true);
            session_regenerate_id(true);
            $_SESSION['op_identity'] = $identity;
            header('Location: home.php');
            exit;
        }
        log_login('user', null, $code, false);
        $error = 'کد پرسنلی یا رمز عبور اشتباه است.';
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ورود کاربران | پردازشگر فرآیند سازمان</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/mikhak.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="login-body">
<main class="login-card">
    <div class="login-brand">
        <div class="login-logo"><?= app_icon('brand', 'login-brand-icon', 38) ?></div>
        <h1>پردازشگر فرآیند سازمان</h1>
        <p>سامانه مدیریت و بهبود فرآیندها</p>
        <span class="login-area-tag">ورود کاربران سازمان</span>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label class="field">
            <span class="field-label">کد پرسنلی</span>
            <input type="text" name="identifier" placeholder="مثلاً P-1001" value="<?= e($oldCode) ?>" required maxlength="30" autofocus>
        </label>
        <label class="field">
            <span class="field-label">رمز عبور</span>
            <input type="password" name="password" placeholder="رمز عبور" required maxlength="100">
        </label>
        <button type="submit" class="btn-primary">ورود به سامانه</button>
    </form>

    <p class="login-alt">عضو تیم فرآیند هستید؟ <a href="<?= TEAM_URL ?>/login.php">ورود تیم فرآیند</a></p>
    <p class="login-hint">راهنمای تست: <code>P-1001</code> با رمز <code>123456</code></p>
</main>
</body>
</html>
