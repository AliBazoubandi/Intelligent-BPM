<?php
/**
 * RabinFlow - Development Server Router Script for PHP Built-in Server (php -S)
 *
 * Enforces strict access control:
 *  - Explicitly 403s .env, *.sql, *.log, *.md, bin/, db/, inc/, vendor/, config.php,
 *    install.php (unless intentionally allowed), dotfiles, and directory traversal.
 *  - Restricts executable PHP scripts to allowed public endpoints (/index.php, /user/*.php, /team/*.php).
 *  - Denies execution of any PHP file inside /processes/ or uploaded assets.
 *  - Returns false ONLY for allowed static assets so php -S serves them with native MIME types.
 */

require_once dirname(__DIR__) . '/config.php';

// 1. Extract requested URI and path
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath = parse_url($rawUri, PHP_URL_PATH);
$path = rawurldecode($parsedPath ?? '/');

// Helper: render beautiful standard error page
$renderError = function (int $code, string $reason = ''): void {
    http_response_code($code);
    $errorScript = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'error.php';
    if (file_exists($errorScript)) {
        $_GET['code'] = $code;
        if ($reason !== '') $_GET['msg'] = $reason;
        include $errorScript;
        exit;
    }
    header('Content-Type: text/plain; charset=UTF-8');
    echo "{$code} " . ($code === 403 ? 'Forbidden' : ($code === 404 ? 'Not Found' : 'Error')) . ($reason !== '' ? ": {$reason}" : '') . "\n";
    exit;
};

// Helper: send 403 Forbidden
$deny = function (string $reason = 'دسترسی به این بخش یا منبع امکان‌پذیر نیست') use ($renderError): void {
    $renderError(403, $reason);
};

// Helper: send 404 Not Found
$notFound = function (string $reason = 'صفحه یا فایل مورد نظر در سرور یافت نشد') use ($renderError): void {
    $renderError(404, $reason);
};

// 2. Reject directory traversal, backslashes, and null bytes immediately
if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\')) {
    $deny('Invalid path traversal attempt');
}

// 3. Reject dotfiles (e.g. /.env, /.git, /.htaccess, /.installed, or any hidden file)
if (preg_match('#(^|/)\.[^/]#', $path)) {
    $deny('Dotfiles access forbidden');
}

// 4. Explicitly block sensitive file extensions and sensitive files
if (preg_match('#\.(sql|log|md|env|json|bak|old|tmp|lock|ini|sh|bat|cmd|py|pyc|htaccess)$#i', $path)) {
    $deny('Direct access to this file type is forbidden');
}

// 5. Explicitly block internal source and data directories
if (preg_match('#^/(bin|db|inc|vendor)(/|$)#i', $path)) {
    $deny('Access to internal or protected directory is forbidden');
}

// 6. Handle /processes/... requests: securely route all document requests through authenticated download.php
if (preg_match('#^/processes/(.+)$#i', $path, $pMatches)) {
    $relFile = trim($pMatches[1], '/');
    $ext = strtolower(pathinfo($relFile, PATHINFO_EXTENSION));
    $blockedExts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'inc', 'htaccess', 'env', 'sql'];
    if (in_array($ext, $blockedExts, true) || str_contains($relFile, '.php.')) {
        $deny('Direct execution or access to executable files is forbidden');
    }
    // Redirect securely to authenticated download endpoint
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . $baseUrl . '/download.php?path=' . rawurlencode($relFile));
    exit;
}
if (preg_match('#^/processes(/|$)#i', $path)) {
    $deny('Direct listing of processes directory is forbidden');
}

// 7. Explicitly block root config.php and org_process.sql
if (preg_match('#^/(config\.php|org_process\.sql)$#i', $path)) {
    $deny('Access to configuration and database dump is forbidden');
}

// 8. Check install.php: only allow if intentionally enabled via ALLOW_INSTALL=1
if (preg_match('#^/install\.php$#i', $path)) {
    $allowInstall = (getenv('ALLOW_INSTALL') === '1') || (isset($_SERVER['ALLOW_INSTALL']) && $_SERVER['ALLOW_INSTALL'] === '1');
    if (!$allowInstall) {
        $deny('Installer is disabled. Set ALLOW_INSTALL=1 in environment to enable.');
    }
}

// 8. Resolve filesystem path within Document Root
$docRoot = realpath(dirname(__DIR__));
if ($docRoot === false) {
    $deny('Server configuration error');
}

$targetPath = $docRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);

// Handle directory requests (e.g. /, /user, /user/, /team, /team/)
if (is_dir($targetPath)) {
    if (!str_ends_with($path, '/')) {
        $query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
        header('Location: ' . $path . '/' . $query, true, 301);
        exit;
    }
    $targetPath = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
    $path = rtrim($path, '/') . '/index.php';
}

$realTarget = realpath($targetPath);

// Target file existence and containment
if ($realTarget === false || !is_file($realTarget)) {
    // If outside the allowed public URL patterns, 403; otherwise 404
    if (!preg_match('#^/(index\.php|download\.php|error\.php|user/|team/|assets/|favicon\.(ico|png)|robots\.txt)#iu', $path)) {
        $deny('Outside public surface');
    }
    $notFound();
}

$realDocRoot = str_replace('\\', '/', $docRoot);
$normalizedTarget = str_replace('\\', '/', $realTarget);
if (!str_starts_with($normalizedTarget, $realDocRoot . '/')) {
    $deny('Path containment violation');
}

$relPath = substr($normalizedTarget, strlen($realDocRoot));

// 9. Enforce Public Surface Boundaries:

// A. Allowed PHP Scripts:
if (str_ends_with($realTarget, '.php')) {
    $isAllowedPhp = false;
    if ($relPath === '/index.php' || $relPath === '/download.php' || $relPath === '/error.php') {
        $isAllowedPhp = true;
    } elseif ($relPath === '/install.php' && ((getenv('ALLOW_INSTALL') === '1') || (isset($_SERVER['ALLOW_INSTALL']) && $_SERVER['ALLOW_INSTALL'] === '1'))) {
        $isAllowedPhp = true;
    } elseif (preg_match('#^/user/[a-zA-Z0-9_\-]+\.php$#', $relPath)) {
        $isAllowedPhp = true;
    } elseif (preg_match('#^/team/[a-zA-Z0-9_\-]+\.php$#', $relPath)) {
        $isAllowedPhp = true;
    }

    if (!$isAllowedPhp) {
        $deny('Unauthorized PHP script execution');
    }

    // Execute allowed PHP script
    $_SERVER['SCRIPT_FILENAME'] = $realTarget;
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['PHP_SELF'] = $path;
    chdir(dirname($realTarget));
    require $realTarget;
    exit;
}

// B. Allowed Static Assets:
$staticAllowedExts = [
    'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
    'woff', 'woff2', 'ttf', 'eot',
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'vsd', 'vsdx',
    'txt', 'csv', 'zip', 'rar', '7z', 'mp4'
];

$ext = strtolower(pathinfo($realTarget, PATHINFO_EXTENSION));
if (!in_array($ext, $staticAllowedExts, true)) {
    $deny('File extension not allowed');
}

// Static assets must only reside in /assets/ or root favicon/robots
$isAllowedStaticArea = preg_match('#^/(assets/|favicon\.(ico|png)|robots\.txt)#iu', $relPath);
if (!$isAllowedStaticArea) {
    $deny('Static asset outside public directories');
}

// Valid public static asset: return false so PHP built-in web server handles it
return false;

