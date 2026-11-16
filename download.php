<?php
/**
 * RabinFlow - Authenticated Process Document Download Endpoint
 * Serves files from the processes/ directory only to authenticated users and team members.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/files.php';
require_once __DIR__ . '/inc/access.php';

auth_session_start();

$userIdentity = current_identity('user');
$teamIdentity = current_identity('team');
$uid = ($userIdentity && !empty($userIdentity['id'])) ? (int)$userIdentity['id'] : null;
$tid = ($teamIdentity && !empty($teamIdentity['id'])) ? (int)$teamIdentity['id'] : null;

function download_error(int $code, string $msg): void {
    http_response_code($code);
    $_GET['code'] = $code;
    $_GET['msg'] = $msg;
    include __DIR__ . '/error.php';
    exit;
}

if (!$uid && !$tid) {
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'text/html')) {
        header('Location: ' . BASE_URL . '/user/login.php');
        exit;
    }
    download_error(401, 'Authentication required to access process documents.');
}

$rel = fm_normalize_rel($_GET['path'] ?? '');
if ($rel === '') {
    download_error(400, 'Path parameter is missing.');
}

$abs = fm_safe_abs($rel);
if ($abs === null || !is_file($abs)) {
    download_error(404, 'Requested document does not exist.');
}

$baseName = basename($abs);
if (!fm_allowed_ext($baseName)) {
    download_error(403, 'Direct access to this file type is forbidden.');
}

$db = app_db();

// 1. تطابق قطعی مسیر فایل با فرآیندهای ثبت‌شده در دیتابیس (Fail-Closed همراه با کش درون‌درخواستی)
static $downloadProcessListCache = null;
if ($downloadProcessListCache === null) {
    $st = $db->query("
        SELECT p.ID, p.Name, p.CompanyID, p.ResponsibleTeamID, p.OwnerPersonnelID, p.OwnerPositionID,
               p.FilePath, p.Status, p.AudienceType, p.AudienceDepartment, cat.FolderName
        FROM Processes p
        JOIN ProcessCategories cat ON cat.ID = p.CategoryID
        WHERE p.Status != 'archived'
    ");
    $downloadProcessListCache = $st ? $st->fetch_all(MYSQLI_ASSOC) : [];
}
$allProcs = $downloadProcessListCache;

$proc = null;
$longestMatchLen = -1;
$normRel = trim(str_replace('\\', '/', $rel), '/');

foreach ($allProcs as $p) {
    $fp1 = trim(str_replace('\\', '/', (string)$p['FilePath']), '/');
    $fp2 = trim(str_replace('\\', '/', (string)$p['FolderName'] . '/' . (string)$p['Name']), '/');
    foreach ([$fp1, $fp2] as $fp) {
        if ($fp !== '') {
            if ($normRel === $fp || str_starts_with($normRel, $fp . '/')) {
                $len = strlen($fp);
                if ($len > $longestMatchLen) {
                    $longestMatchLen = $len;
                    $proc = $p;
                }
            }
        }
    }
}

// سیاست Fail-Closed: اگر فایل به هیچ فرآیند ثبت‌شده‌ای تعلق نداشته باشد، دسترسی مسدود می‌شود
if (!$proc) {
    download_error(404, 'Requested document does not match any registered process.');
}

$procCompanyId = !empty($proc['CompanyID']) ? (int)$proc['CompanyID'] : null;
$respTeamId = !empty($proc['ResponsibleTeamID']) ? (int)$proc['ResponsibleTeamID'] : 0;

// 2. بررسی دسترسی تیم‌ها (Team Authorization)
if ($tid > 0) {
    $teamCompanyId = !empty($teamIdentity['company_id']) ? (int)$teamIdentity['company_id'] : 0;
    $isAdmin = !empty($teamIdentity['is_admin']);

    if (!$isAdmin) {
        if ($teamCompanyId > 0) {
            // تفکیک چندمستأجری: تیم شرکت‌دار فقط مجاز به دانلود فرآیندهای شرکت خود یا فرآیندهای عمومی است
            if ($procCompanyId !== null && $procCompanyId !== $teamCompanyId) {
                download_error(403, 'Process document belongs to a different company.');
            }
        } else {
            // تیم سراسری بدون شرکت (غیرادمین): فقط مجاز به فرآیندهای بدون شرکت یا فرآیندی که تیم مسئول آن است
            if ($procCompanyId !== null && $respTeamId !== $tid) {
                download_error(403, 'Process document belongs to a different company.');
            }
        }

        // فرآیندهای غیرفعال فقط برای تیم مسئول همان فرآیند در دسترس است
        if ($proc['Status'] !== 'active' && $respTeamId !== $tid) {
            download_error(403, 'Process is not active.');
        }
    }
}

// 3. بررسی دسترسی کاربران عادی (User Authorization - FIX R1)
if ($uid > 0 && !$tid) {
    $userCompanyId = !empty($userIdentity['company_id']) ? (int)$userIdentity['company_id'] : null;

    if (!process_user_has_access($db, $proc, $uid, $userCompanyId)) {
        download_error(403, 'You do not have permission to view or download this process document.');
    }
}

// MIME types mapping
$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf' => 'application/pdf',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/markdown; charset=utf-8',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'doc' => 'application/msword',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'vsdx' => 'application/vnd.ms-visio.drawing',
    'vsd' => 'application/vnd.visio',
    'zip' => 'application/zip',
];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

$safeFilename = preg_replace('/[^\w\.\-\x{0600}-\x{06FF}]/u', '_', $baseName);
$fallbackAscii = preg_replace('/[^0-9A-Za-z.\-]/', '_', $baseName) ?: ('document.' . $ext);

header('Content-Type: ' . $contentType);
header('Content-Length: ' . (string)filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . $fallbackAscii . '"; filename*=UTF-8\'\'' . rawurlencode($safeFilename));

readfile($abs);
exit;

