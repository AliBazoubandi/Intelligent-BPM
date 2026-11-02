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

function fmt_date(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? farsi_num(date('Y/m/d H:i', $ts)) : farsi_num($dt);
}

function fmt_date_short(?string $dt): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? farsi_num(date('Y/m/d', $ts)) : farsi_num($dt);
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
