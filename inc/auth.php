<?php
/**
 * لایه احراز هویت — دو ناحیه کاملاً جدا:
 *  /user → کاربران سازمان (پرسنل)
 *  /team → تیم‌های فرآیند
 */
require_once __DIR__ . '/../config.php';

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        @ini_set('session.use_strict_mode', '1');
        session_name('OPSESS');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure'   => $isHttps,
            'use_strict_mode' => 1,
        ]);
    }
}

function current_identity(?string $area = null): ?array
{
    auth_session_start();
    $user = $_SESSION['op_user'] ?? (isset($_SESSION['op_identity']) && ($_SESSION['op_identity']['kind'] ?? '') === 'user' ? $_SESSION['op_identity'] : null);
    if ($user !== null) {
        // All users currently have permission to suggest optimizations
        $user['perms']['improve'] = 1;
        if (isset($_SESSION['op_user'])) {
            $_SESSION['op_user']['perms']['improve'] = 1;
        }
        if (isset($_SESSION['op_identity']) && ($_SESSION['op_identity']['kind'] ?? '') === 'user') {
            $_SESSION['op_identity']['perms']['improve'] = 1;
        }
    }
    if ($area === 'user') {
        return $user;
    }
    if ($area === 'team') {
        return $_SESSION['op_team'] ?? (isset($_SESSION['op_identity']) && ($_SESSION['op_identity']['kind'] ?? '') === 'team' ? $_SESSION['op_identity'] : null);
    }
    return $user ?? $_SESSION['op_team'] ?? $_SESSION['op_identity'] ?? null;
}

function current_user(): ?int
{
    $id = current_identity('user');
    return ($id && !empty($id['id'])) ? (int)$id['id'] : null;
}

function current_team(): ?int
{
    $id = current_identity('team');
    return ($id && !empty($id['id'])) ? (int)$id['id'] : null;
}

function csrf_token(): string
{
    auth_session_start();
    if (empty($_SESSION['op_csrf'])) {
        $_SESSION['op_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['op_csrf'];
}

function csrf_check(?string $token): bool
{
    auth_session_start();
    return is_string($token) && !empty($_SESSION['op_csrf']) && hash_equals($_SESSION['op_csrf'], $token);
}

/** مسیر ورود هر ناحیه */
function area_login_url(string $area): string
{
    return ($area === 'team' ? TEAM_URL : USER_URL) . '/login.php';
}

/**
 * اجبار به ورود در ناحیه مشخص؛ اگر نشست نبود یا ناحیه اشتباه بود
 * به صفحه ورود همان ناحیه ریدایرکت می‌شود.
 * اعتبارسنجی مجدد وضعیت فعال بودن اکانت (Active=1) با کش ۶۰ ثانیه‌ای
 */
function require_area(string $area): array
{
    $id = current_identity($area);
    if (!$id || $id['kind'] !== $area) {
        header('Location: ' . area_login_url($area));
        exit;
    }

    $now = time();
    $cacheKey = 'active_check_' . $area;
    $lastCheck = $_SESSION[$cacheKey] ?? 0;
    if ($now - $lastCheck > 60) {
        try {
            $db = app_db();
            $targetId = (int)($id['id'] ?? 0);
            $isActive = false;
            if ($area === 'user') {
                $st = $db->prepare('SELECT Active FROM Personnel WHERE ID = ?');
                if ($st) {
                    $st->bind_param('i', $targetId);
                    $st->execute();
                    $res = $st->get_result()->fetch_assoc();
                    $isActive = !empty($res['Active']);
                    $st->close();
                }
            } elseif ($area === 'team') {
                $st = $db->prepare('SELECT Active FROM ProcessTeams WHERE ID = ?');
                if ($st) {
                    $st->bind_param('i', $targetId);
                    $st->execute();
                    $res = $st->get_result()->fetch_assoc();
                    $isActive = !empty($res['Active']);
                    $st->close();
                }
            }
            if (!$isActive) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();
                header('Location: ' . area_login_url($area));
                exit;
            }
            $_SESSION[$cacheKey] = $now;
        } catch (Throwable) {}
    }

    return $id;
}

/**
 * بررسی محدودیت دفعات ورود ناموفق (Login Rate Limiter)
 * حداکثر ۱۰ تلاش ناموفق در ۱۰ دقیقه گذشته؛ در غیر این صورت مسدودی موقت
 *
 * @param string $loginType ('user'|'team')
 * @param string $identifier کد کاربری یا تیم
 * @param int $maxAttempts حداکثر تلاش‌های مجاز (پیش‌فرض ۱۰)
 * @param int $windowMinutes بازه زمانی به دقیقه (پیش‌فرض ۱۰)
 * @return array ['locked' => bool, 'remaining' => int, 'wait_minutes' => int]
 */
function auth_check_login_rate_limit(string $loginType, string $identifier, int $maxAttempts = 10, int $windowMinutes = 10): array
{
    try {
        $db = app_db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $db->prepare(
            "SELECT COUNT(*) AS fail_count 
             FROM LoginLog 
             WHERE LoginType = ? 
               AND (Identifier = ? OR Ip = ?)
               AND Success = 0 
               AND LoggedAt >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        if (!$st) {
            return ['locked' => false, 'remaining' => $maxAttempts, 'wait_minutes' => 0];
        }
        $st->bind_param('sssi', $loginType, $identifier, $ip, $windowMinutes);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        $failCount = (int)($row['fail_count'] ?? 0);
        if ($failCount >= $maxAttempts) {
            return [
                'locked' => true,
                'remaining' => 0,
                'wait_minutes' => $windowMinutes,
            ];
        }
        return [
            'locked' => false,
            'remaining' => max(0, $maxAttempts - $failCount),
            'wait_minutes' => 0,
        ];
    } catch (Throwable) {
        return ['locked' => false, 'remaining' => $maxAttempts, 'wait_minutes' => 0];
    }
}

/** اگر کاربر/تیم وارد شده بود، مستقیم به خانه همان ناحیه برود */
function redirect_if_logged_in(string $area): void
{
    $id = current_identity($area);
    if ($id && $id['kind'] === $area) {
        header('Location: ' . ($area === 'team' ? TEAM_URL : USER_URL) . '/home.php');
        exit;
    }
}

/** ورود پرسنل با کد پرسنلی + رمز */
function attempt_user_login(string $code, string $password): ?array
{
    $db = app_db();
    $st = $db->prepare(
        'SELECT p.ID, p.PersonnelCode, p.Name, p.Family, p.CanRequest, p.CanSearch, p.CanImprove, p.CanApprove,
                p.PasswordHash, p.TeamID, p.CompanyID, c.Name AS CompanyName, t.TeamName
         FROM Personnel p
         LEFT JOIN Companies c ON c.ID = p.CompanyID
         LEFT JOIN ProcessTeams t ON t.ID = p.TeamID
         WHERE p.PersonnelCode = ? AND p.Active = 1'
    );
    $st->bind_param('s', $code);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['PasswordHash']) || !password_verify($password, $row['PasswordHash'])) {
        return null;
    }
    return [
        'kind' => 'user',
        'id' => (int)$row['ID'],
        'code' => $row['PersonnelCode'],
        'name' => trim($row['Name'] . ' ' . $row['Family']),
        'company_id' => !empty($row['CompanyID']) ? (int)$row['CompanyID'] : null,
        'company' => $row['CompanyName'],
        'team' => $row['TeamName'],
        'perms' => [
            'request' => (int)$row['CanRequest'],
            'search' => (int)$row['CanSearch'],
            'improve' => 1, // All users currently have permission to suggest optimizations
            'approve' => (int)$row['CanApprove'],
        ],
    ];
}

/** ورود تیم فرآیند با کد تیم + رمز */
function attempt_team_login(string $code, string $password): ?array
{
    $db = app_db();
    $st = $db->prepare(
        'SELECT t.ID, t.TeamCode, t.TeamName, t.PasswordHash, t.CompanyID, t.IsAdmin, c.Name AS CompanyName
         FROM ProcessTeams t
         LEFT JOIN Companies c ON c.ID = t.CompanyID
         WHERE t.TeamCode = ? AND t.Active = 1'
    );
    $st->bind_param('s', $code);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || !password_verify($password, $row['PasswordHash'])) {
        return null;
    }
    return [
        'kind' => 'team',
        'id' => (int)$row['ID'],
        'code' => $row['TeamCode'],
        'name' => $row['TeamName'],
        'company_id' => !empty($row['CompanyID']) ? (int)$row['CompanyID'] : null,
        'company' => $row['CompanyName'],
        'is_admin' => (int)($row['IsAdmin'] ?? 0),
    ];
}

/** آیا تیم جاری دارای دسترسی مدیر ارشد (IsAdmin) است؟ */
function team_is_admin(?array $id = null): bool
{
    if ($id === null) {
        $id = current_identity('team');
    }
    return !empty($id['is_admin']);
}

/** ثبت رویداد ورود در LoginLog */
function log_login(string $type, ?int $refId, string $identifier, bool $success): void
{
    try {
        $db = app_db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $db->prepare('INSERT INTO LoginLog (LoginType, RefID, Identifier, Success, Ip) VALUES (?,?,?,?,?)');
        $ok = $success ? 1 : 0;
        $st->bind_param('sisis', $type, $refId, $identifier, $ok, $ip);
        $st->execute();
        $st->close();
    } catch (Throwable) {
        // لاگ نباید ورود را بشکند
    }
}

/** شمارش اعلان‌های مرتبط با کاربر (برای بج منو) */
function user_badges(int $personnelId): array
{
    $db = app_db();
    $out = [];
    $st = $db->prepare("SELECT COUNT(*) c FROM Improvements WHERE requester_type='user' AND requester_id=?");
    $st->bind_param('i', $personnelId);
    $st->execute();
    $out['improvements'] = (int)$st->get_result()->fetch_assoc()['c'];
    $st->close();

    $st = $db->prepare(
        "SELECT COUNT(*) c FROM Changes ch
         LEFT JOIN Processes p ON p.ID = ch.ProcessID
         LEFT JOIN Improvements imp ON imp.ID = ch.ImprovementID
         WHERE (ch.AppliedByType='user' AND ch.AppliedByID=?)
            OR p.OwnerPersonnelID = ?
            OR (imp.requester_type='user' AND imp.requester_id=?)"
    );
    $st->bind_param('iii', $personnelId, $personnelId, $personnelId);
    $st->execute();
    $out['changes'] = (int)$st->get_result()->fetch_assoc()['c'];
    $st->close();
    return $out;
}
