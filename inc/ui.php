<?php
/**
 * برچسب‌ها و کمک‌توابع نمایشی مشترک
 */

function status_label(string $en): string
{
    return [
        // فرآیند
        'draft' => 'پیش‌نویس', 'active' => 'فعال', 'archived' => 'بایگانی',
        // بهبود
        'pending' => 'در انتظار بررسی', 'accepted' => 'تأییدشده', 'rejected' => 'ردشده', 'implemented' => 'اجرا‌شده',
        // تغییر
        'approved' => 'تأییدشده', 'applied' => 'اعمال‌شده',
        // نوع تغییر
        'revision' => 'اصلاح', 'replacement' => 'جایگزینی', 'addition' => 'افزودن', 'removal' => 'حذف',
    ][$en] ?? $en;
}

/** تبدیل اعداد انگلیسی به فارسی */
function farsi_num(string|int|float|null $input): string
{
    if ($input === null || $input === '') return '';
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($en, $fa, (string)$input);
}

/** تبدیل تاریخ میلادی به هجری شمسی (جلالی) */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100) + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int)($days / 12053));
    $days %= 12053;
    $jy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/**
 * فرمت تاریخ و زمان شمسی (تاریخ اول، سپس زمان بعد از آن)
 * مثال: ۱۴۰۵/۰۸/۱۲ - ۱۴:۱۵
 */
function fmt_date(string|int|null $dt): string
{
    if ($dt === null || $dt === '' || $dt === '—') return '—';
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    if (!$ts) return farsi_num((string)$dt);

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);

    $jDate = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    $time = date('H:i', $ts);

    // Left-to-Right Mark (\u{200E}) ensures that even in RTL tables/paragraphs,
    // the date is placed first on the left, followed by the hyphen, then the time on the right.
    return "\u{200E}" . farsi_num($jDate) . ' - ' . farsi_num($time) . "\u{200E}";
}

/**
 * فرمت تاریخ شمسی کوتاه بدون زمان
 * مثال: ۱۴۰۵/۰۸/۱۲
 */
function fmt_date_short(string|int|null $dt): string
{
    if ($dt === null || $dt === '' || $dt === '—') return '—';
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    if (!$ts) return farsi_num((string)$dt);

    $gy = (int)date('Y', $ts);
    $gm = (int)date('n', $ts);
    $gd = (int)date('j', $ts);
    [$jy, $jm, $jd] = gregorian_to_jalali($gy, $gm, $gd);

    $jDate = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    return farsi_num($jDate);
}

function fmt_size(int $bytes): string
{
    if ($bytes >= 1048576) return farsi_num(number_format($bytes / 1048576, 1)) . ' مگابایت';
    if ($bytes >= 1024) return farsi_num(number_format($bytes / 1024, 1)) . ' کیلوبایت';
    return farsi_num($bytes) . ' بایت';
}

/** دسته‌های فعال */
function active_categories(mysqli $db): array
{
    $out = [];
    $res = $db->query('SELECT ID, Title, FolderPath FROM ProcessCategories WHERE Active=1 ORDER BY SortOrder, Title');
    while ($r = $res->fetch_assoc()) $out[] = $r;
    return $out;
}

/** تنظیم پیام فلش در سشن */
function flash_set(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('auth_session_start')) {
            auth_session_start();
        } else {
            @session_start();
        }
    }
    $_SESSION['flash_message'] = [
        'type' => $type, // 'ok' or 'error'
        'message' => $message,
    ];
}

/** دریافت و پاکسازی پیام فلش از سشن */
function flash_get(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        if (function_exists('auth_session_start')) {
            auth_session_start();
        } else {
            @session_start();
        }
    }
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}

