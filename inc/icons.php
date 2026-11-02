<?php
/**
 * کتابخانه آیکون‌های برداری SVG سیستم (Sharp & Modern Vector Icons)
 * جایگزین ایموجی‌های نامتعارف با آیکون‌های استاندارد و هماهنگ رابط کاربری
 */

function app_icon(string $name, string $class = '', int $size = 18): string
{
    $cls = 'app-svg-ico ' . trim($class);
    $s = (int)$size;

    return match ($name) {
        // نشان برند (Brand Logo)
        'brand' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>'
            . '<circle cx="12" cy="12" r="4.5" fill="currentColor" fill-opacity="0.18"/>'
            . '<circle cx="12" cy="12" r="1.8" fill="currentColor"/>'
            . '</svg>',

        // خانه (Home)
        'home' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1V9.5z"/>'
            . '</svg>',

        // لیست فرآیندها / پیمایش (Processes)
        'processes' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
            . '<rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>'
            . '<path d="M10 6.5h4M17.5 10v4M10 17.5h4M6.5 10v4"/>'
            . '</svg>',

        // فرآیند جدید (New Process)
        'plus_circle' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<circle cx="12" cy="12" r="9"/>'
            . '<path d="M12 8v8M8 12h8"/>'
            . '</svg>',

        // جستجو (Search)
        'search' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4.2-4.2"/>'
            . '</svg>',

        // بهبودها / ایده‌ها (Improvements / Sparkles)
        'improvements' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M9 18h6M10 22h4"/>'
            . '<path d="M12 2a7 7 0 0 0-7 7c0 2.5 1.5 4.5 3 6h8c1.5-1.5 3-3.5 3-6a7 7 0 0 0-7-7z"/>'
            . '</svg>',

        // تغییرات / تاریخچه (Changes / Clock)
        'changes' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/>'
            . '</svg>',

        // گفتگو (Discussion / Chat)
        'discussion' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
            . '<path d="M8 10h.01M12 10h.01M16 10h.01" stroke-width="2.5"/>'
            . '</svg>',

        // مدیریت فایل‌ها (Files / Folder)
        'files' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
            . '</svg>',

        // اطلاعات پایه (Basics / Settings)
        'basics' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>'
            . '</svg>',

        // خروج (Logout)
        'logout' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>'
            . '</svg>',

        // ربات هوش مصنوعی (AI Bot)
        'bot' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="4" y="8" width="16" height="12" rx="3"/><circle cx="9" cy="13" r="1.5" fill="currentColor"/><circle cx="15" cy="13" r="1.5" fill="currentColor"/>'
            . '<path d="M12 2v4M8 2h8M2 14h2M20 14h2M9 17h6"/>'
            . '</svg>',

        // رفرش / همگام‌سازی (Refresh / Sync)
        'refresh' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>'
            . '</svg>',

        // رعدوبرق (Lightning / Fast)
        'bolt' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'
            . '</svg>',

        // بازگشت / فلش (Arrow / Reply)
        'reply' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>'
            . '</svg>',

        // تیک / تأیید (Check)
        'check' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
            . '<polyline points="20 6 9 17 4 12"/>'
            . '</svg>',

        // ضربدر / لغو (X)
        'x' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
            . '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'
            . '</svg>',

        // کاربران / تیم (Users)
        'users' => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
            . '<path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'
            . '</svg>',

        // پیش‌فرض
        default => '<svg class="' . $cls . '" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
            . '<circle cx="12" cy="12" r="9"/>'
            . '</svg>',
    };
}

