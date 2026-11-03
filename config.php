<?php
/**
 * تنظیمات پایه سامانه رابین‌فلو (RobinFlow)
 * سامانه مدیریت و بهبود فرآیندهای سازمان — هوش تجاری رهباریان رابین
 * ساختار: /user (کاربران) | /team (تیم‌های فرآیند) | /processes (فایل‌های فرآیند)
 */

define('APP_ROOT', __DIR__);
define('PROCESSES_ROOT', APP_ROOT . DIRECTORY_SEPARATOR . 'processes');

/**
 * بارگذاری فایل متغیرهای محیطی (.env) به صورت Native
 */
function load_env(string $path): void
{
    if (!file_exists($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            // حذف کوتیشن‌های احتمالی اطراف مقدار
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

/**
 * دریافت مقدار متغیر محیطی همراه با مقدار پیش‌فرض
 */
function env(string $key, mixed $default = null): mixed
{
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') {
        return $default;
    }
    return $val;
}

// بارگذاری تنظیمات از .env
load_env(APP_ROOT . DIRECTORY_SEPARATOR . '.env');

define('APP_NAME', (string)env('APP_NAME', 'RobinFlow'));
define('APP_TITLE_FA', 'رابین‌فلو');

// دیتابیس
define('DB_HOST', (string)env('DB_HOST', 'localhost'));
define('DB_USER', (string)env('DB_USER', 'root'));
define('DB_PASS', (string)env('DB_PASS', ''));
define('DB_NAME', (string)env('DB_NAME', 'org_process'));

// تنظیمات ایجنت هوش مصنوعی هرمس (Hermes Agent)
define('HERMES_URL', (string)env('HERMES_URL', 'http://127.0.0.1:8642/v1/chat/completions'));
define('HERMES_KEY', (string)env('HERMES_KEY', ''));
define('HERMES_MODEL', (string)env('HERMES_MODEL', 'hermes-agent'));
define('HERMES_TIMEOUT', (int)env('HERMES_TIMEOUT', 120));

// مسیر باینری PHP برای پردازش پس‌زمینه (Worker)
define('PHP_BIN', (string)env('PHP_BIN', ''));

if (!defined('BASE_URL')) {
    if (PHP_SAPI === 'cli') {
        define('BASE_URL', (string)env('BASE_URL', ''));
    } else {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        $base = trim(preg_replace('#(^|/)(user|team|inc|bin)$#', '', $dir), '/');
        define('BASE_URL', ($base === '' || $base === '.') ? '' : '/' . $base);
    }
}
define('USER_URL', BASE_URL . '/user');
define('TEAM_URL', BASE_URL . '/team');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/** اتصال بدون دیتابیس برنامه (برای نصب) */
function db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** اتصال به دیتابیس برنامه */
function app_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/** escape برای خروجی HTML */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
