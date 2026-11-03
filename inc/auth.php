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
        session_name('OPSESS');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function current_identity(?string $area = null): ?array
{
    auth_session_start();
    if ($area === 'user') {
        return $_SESSION['op_user'] ?? (isset($_SESSION['op_identity']) && ($_SESSION['op_identity']['kind'] ?? '') === 'user' ? $_SESSION['op_identity'] : null);
    }
    if ($area === 'team') {
        return $_SESSION['op_team'] ?? (isset($_SESSION['op_identity']) && ($_SESSION['op_identity']['kind'] ?? '') === 'team' ? $_SESSION['op_identity'] : null);
    }
    return $_SESSION['op_user'] ?? $_SESSION['op_team'] ?? $_SESSION['op_identity'] ?? null;
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
 */
function require_area(string $area): array
{
    $id = current_identity($area);
    if (!$id || $id['kind'] !== $area) {
        header('Location: ' . area_login_url($area));
        exit;
    }
    return $id;
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
        'company' => $row['CompanyName'],
        'team' => $row['TeamName'],
        'perms' => [
            'request' => (int)$row['CanRequest'],
            'search' => (int)$row['CanSearch'],
            'improve' => (int)$row['CanImprove'],
            'approve' => (int)$row['CanApprove'],
        ],
    ];
}

/** ورود تیم فرآیند با کد تیم + رمز */
function attempt_team_login(string $code, string $password): ?array
{
    $db = app_db();
    $st = $db->prepare(
        'SELECT t.ID, t.TeamCode, t.TeamName, t.PasswordHash, c.Name AS CompanyName
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
        'company' => $row['CompanyName'],
    ];
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
