<?php
/**
 * RabinFlow - Authenticated Process Document Download Endpoint
 * Serves files from the processes/ directory only to authenticated users and team members.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/files.php';

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

// Authorization check for regular users
if ($uid > 0 && !$tid) {
    $db = app_db();
    $userCompanyId = (int)($userIdentity['company_id'] ?? 0);

    // Check if the file belongs to an active process in the user's company or public
    $firstSeg = explode('/', $rel)[0] ?? '';
    $secondSeg = explode('/', $rel)[1] ?? '';
    $lookupPath = $firstSeg . '/' . $secondSeg;

    $st = $db->prepare("
        SELECT ID, CompanyID, Status, AudienceType, AudienceDepartment 
        FROM Processes 
        WHERE ? LIKE CONCAT(FilePath, '%') 
           OR FilePath LIKE CONCAT(?, '%')
        LIMIT 1
    ");
    $st->bind_param('ss', $rel, $lookupPath);
    $st->execute();
    $proc = $st->get_result()->fetch_assoc();
    $st->close();

    if ($proc) {
        if ($proc['Status'] !== 'active') {
            download_error(403, 'Process is not active.');
        }

        if ($proc['CompanyID'] !== null && (int)$proc['CompanyID'] !== $userCompanyId) {
            download_error(403, 'Process document belongs to a different company.');
        }
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

