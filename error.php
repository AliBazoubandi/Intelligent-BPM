<?php
/**
 * RabinFlow - صفحه خطاهای سازمانی و استاندارد سیستم (Enterprise HTTP Error Pages)
 * طراحی مینیمال، رسمی و متناسب با هویت سازمانی سامانه
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
if (!defined('BASE_URL')) {
    $envBase = getenv('BASE_URL') ?: (getenv('APP_URL') ? (string)parse_url((string)getenv('APP_URL'), PHP_URL_PATH) : '');
    define('BASE_URL', rtrim($envBase, '/'));
}

$code = isset($_GET['code']) ? (int)$_GET['code'] : (http_response_code() ?: 404);
if (!in_array($code, [400, 401, 403, 404, 500, 502, 503], true)) {
    $code = 404;
}

http_response_code($code);

$customMsg = isset($_GET['msg']) ? trim((string)$_GET['msg']) : '';
$customMsg = htmlspecialchars(strip_tags($customMsg), ENT_QUOTES, 'UTF-8');

$traceSeed = ($_SERVER['REQUEST_URI'] ?? '') . '|' . microtime(true);
$traceId = 'RBN-' . $code . '-' . strtoupper(substr(md5($traceSeed), 0, 6));

$configs = [
    401 => [
        'title'         => 'نیاز به احراز هویت',
        'subtitle'      => 'برای دسترسی به این پرونده یا بخش، ابتدا باید وارد حساب کاربری خود شوید.',
        'desc'          => 'نشست کاری شما منقضی شده است یا دسترسی به این سند به مجوزهای کاربری فعال در سامانه رابین‌فلو نیاز دارد.',
        'badge'         => 'خطای ۴۰۱ · احراز هویت',
        'color'         => '#d97706',
        'color_rgb'     => '217, 119, 6',
        'icon'          => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1.5"/></svg>',
        'primary_btn'   => ['url' => BASE_URL . '/user/login.php', 'text' => 'ورود به پورتال کاربران'],
        'secondary_btn' => ['url' => BASE_URL . '/team/login.php', 'text' => 'ورود اعضای تیم فرآیند'],
    ],
    403 => [
        'title'         => 'عدم دسترسی به منبع درخواستی',
        'subtitle'      => 'مجوزهای کاربری شما برای مشاهده این فرآیند یا پرونده سازمانی کافی نیست.',
        'desc'          => 'این سند در سطح دسترسی مشخصی تعریف شده و تنها پرسنل مجاز، متولیان سازمانی یا تیم‌های ذی‌ربط امکان دسترسی به آن را دارند.',
        'badge'         => 'خطای ۴۰۳ · دسترسی محدود',
        'color'         => '#dc2626',
        'color_rgb'     => '220, 38, 38',
        'icon'          => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'primary_btn'   => ['url' => BASE_URL . '/user/home.php', 'text' => 'بازگشت به خانه'],
        'secondary_btn' => ['url' => BASE_URL . '/user/processes.php', 'text' => 'پیمایش فرآیندهای مجاز'],
    ],
    404 => [
        'title'         => 'صفحه یا سند مورد نظر یافت نشد',
        'subtitle'      => 'آدرس درخواستی در سامانه وجود ندارد یا به مسیر دیگری منتقل شده است.',
        'desc'          => 'لطفاً نشانی وارد شده را مجدداً بررسی فرمایید یا با استفاده از گزینه‌های زیر به بخش‌های فعال سامانه هدایت شوید.',
        'badge'         => 'خطای ۴۰۴ · یافت نشد',
        'color'         => '#2563eb',
        'color_rgb'     => '37, 99, 235',
        'icon'          => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M16 16s-1.5-2-4-2-4 2-4 2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
        'primary_btn'   => ['url' => BASE_URL . '/', 'text' => 'صفحه اصلی سامانه'],
        'secondary_btn' => ['url' => BASE_URL . '/user/home.php', 'text' => 'داشبورد کاربری'],
    ],
    500 => [
        'title'         => 'خطای موقت در سرور سامانه',
        'subtitle'      => 'متأسفانه در حین پردازش درخواست شما خطای غیرمنتظره‌ای رخ داده است.',
        'desc'          => 'لاگ این رویداد در سرور ثبت گردید. لطفاً چند لحظه بعد مجدداً تلاش فرمایید یا با راهبر سامانه در ارتباط باشید.',
        'badge'         => 'خطای ۵۰۰ · خطای سرور',
        'color'         => '#b91c1c',
        'color_rgb'     => '185, 28, 28',
        'icon'          => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
        'primary_btn'   => ['url' => 'javascript:location.reload()', 'text' => 'تلاش مجدد بارگذاری'],
        'secondary_btn' => ['url' => BASE_URL . '/', 'text' => 'بازگشت به صفحه اصلی'],
    ],
];

$err = $configs[$code] ?? $configs[404];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= htmlspecialchars($err['title'], ENT_QUOTES, 'UTF-8') ?> (<?= $code ?>) | رابین‌فلو</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/mikhak.css">
    <script>
    (function(){
        function syncTheme() {
            try {
                var t = localStorage.getItem("theme-preference") || "auto";
                var isDark = t === "dark" || (t === "auto" && window.matchMedia("(prefers-color-scheme: dark)").matches);
                document.documentElement.setAttribute("data-theme", isDark ? "dark" : "light");
                var meta = document.querySelector('meta[name="color-scheme"]');
                if (meta) meta.content = isDark ? "dark" : "light";
            } catch(e) {}
        }
        syncTheme();

        // دنبال کردن لحظه‌ای تم فعال سایت در صورت تغییر در تب‌های دیگر
        window.addEventListener('storage', function(e) {
            if (e.key === 'theme-preference') {
                syncTheme();
            }
        });

        // هماهنگی با تم پیش‌فرض سیستم‌عامل
        try {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
                var saved = localStorage.getItem('theme-preference');
                if (!saved || saved === 'auto') {
                    syncTheme();
                }
            });
        } catch(e) {}
    })();
    </script>
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --ink: #0f172a;
            --muted: #475569;
            --subtle: #94a3b8;
            --line: #e2e8f0;
            --brand: #2563eb;
            --brand-hover: #1d4ed8;
            --accent: <?= $err['color'] ?>;
            --accent-rgb: <?= $err['color_rgb'] ?>;
            --shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.08), 0 4px 6px -2px rgba(15, 23, 42, 0.03);
        }
        [data-theme="dark"] {
            --bg: #090d16;
            --card: #111827;
            --ink: #f8fafc;
            --muted: #cbd5e1;
            --subtle: #64748b;
            --line: #1e293b;
            --brand: #3b82f6;
            --brand-hover: #60a5fa;
            --shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.5), 0 4px 6px -2px rgba(0, 0, 0, 0.3);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        /* اعمال یکدست قلم روی تمام عناصر */
        html, body, button, input, select, textarea, .btn, .btn-back, .btn-primary, .btn-secondary, span, p, h1, h2, h3, h4, h5, div {
            font-family: Mikhak, 'Vazirmatn', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, sans-serif !important;
        }

        body {
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            direction: rtl;
            text-align: right;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .error-container {
            max-width: 540px;
            width: 100%;
        }

        .error-card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 36px 32px;
            position: relative;
            animation: fadeIn .25s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* سربرگ هویت سامانه */
        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }
        .brand-link {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--ink);
        }
        .brand-logo {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            object-fit: cover;
        }
        .brand-text h2 {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.2;
        }
        .brand-text span {
            font-size: 11px;
            color: var(--subtle);
        }



        /* بلوک آیکون و بج */
        .status-section {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
        }
        .icon-tile {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            background: rgba(var(--accent-rgb), 0.08);
            border: 1px solid rgba(var(--accent-rgb), 0.18);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            background: rgba(var(--accent-rgb), 0.08);
            padding: 4px 12px;
            border-radius: 999px;
            margin-bottom: 4px;
        }
        .badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
        }

        /* متون پیام خطا */
        .error-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--ink);
            margin-bottom: 8px;
            line-height: 1.4;
        }
        .error-subtitle {
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 12px;
            line-height: 1.6;
        }
        .error-desc {
            font-size: 13px;
            color: var(--subtle);
            line-height: 1.7;
            margin-bottom: 24px;
        }

        /* پیام سفارشی سیستم در صورت وجود */
        .custom-detail {
            background: rgba(239, 68, 68, 0.06);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: #ef4444;
            margin-bottom: 22px;
            direction: ltr;
            text-align: left;
            word-break: break-all;
        }

        /* دکمه‌های عملیات */
        .actions-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            transition: all .15s ease;
            cursor: pointer;
            border: 1px solid transparent;
            user-select: none;
        }

        .btn-primary {
            background: var(--brand);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: var(--brand-hover);
            color: #ffffff;
        }

        .btn-secondary {
            background: transparent;
            color: var(--ink);
            border-color: var(--line);
        }
        .btn-secondary:hover {
            background: rgba(100, 116, 139, 0.06);
            border-color: var(--subtle);
        }

        .btn-back {
            background: transparent;
            color: var(--muted);
            border-color: transparent;
        }
        .btn-back:hover {
            color: var(--ink);
            background: rgba(100, 116, 139, 0.06);
        }
        .btn-arrow-icon {
            width: 15px;
            height: 15px;
            stroke: currentColor;
            stroke-width: 2.2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin="round";
            transition: transform .15s ease;
        }
        .btn-back:hover .btn-arrow-icon {
            transform: translateX(-3px);
        }

        /* پانوشت رسمی کارت */
        .card-footer {
            padding-top: 16px;
            border-top: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11.5px;
            color: var(--subtle);
        }
        .trace-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(100, 116, 139, 0.06);
            border: 1px solid var(--line);
            padding: 3px 8px;
            border-radius: 6px;
            font-family: monospace, Tahoma, sans-serif !important;
            font-size: 11px;
            color: var(--muted);
            cursor: pointer;
            transition: all .15s ease;
        }
        .trace-tag:hover {
            color: var(--ink);
            border-color: var(--subtle);
        }

        .toast-notify {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(40px);
            background: #0f172a;
            color: #f8fafc;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            pointer-events: none;
            opacity: 0;
            transition: all .2s ease;
            z-index: 1000;
        }
        .toast-notify.visible {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <main class="error-card">
            <header class="card-header">
                <a href="<?= BASE_URL ?>/" class="brand-link">
                    <img src="<?= BASE_URL ?>/assets/logo.png" alt="رابین‌فلو" class="brand-logo">
                    <div class="brand-text">
                        <h2>رابین‌فلو</h2>
                        <span>سامانه مدیریت و بهبود فرآیندهای کسب‌وکار</span>
                    </div>
                </a>
            </header>

            <div class="status-section">
                <div class="icon-tile">
                    <?= $err['icon'] ?>
                </div>
                <div>
                    <span class="badge-pill">
                        <span class="badge-dot"></span>
                        <?= htmlspecialchars($err['badge'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <h1 class="error-title"><?= htmlspecialchars($err['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                </div>
            </div>

            <p class="error-subtitle"><?= htmlspecialchars($err['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="error-desc"><?= htmlspecialchars($err['desc'], ENT_QUOTES, 'UTF-8') ?></p>

            <?php if (!empty($customMsg)): ?>
                <div class="custom-detail">
                    <strong>Technical Info:</strong> <?= $customMsg ?>
                </div>
            <?php endif; ?>

            <div class="actions-group">
                <?php if (!empty($err['primary_btn'])): ?>
                    <a href="<?= htmlspecialchars($err['primary_btn']['url'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
                        <?= htmlspecialchars($err['primary_btn']['text'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endif; ?>

                <?php if (!empty($err['secondary_btn'])): ?>
                    <a href="<?= htmlspecialchars($err['secondary_btn']['url'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                        <?= htmlspecialchars($err['secondary_btn']['text'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endif; ?>

                <button type="button" onclick="handleBack()" class="btn btn-back">
                    <svg class="btn-arrow-icon" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    <span>بازگشت به صفحه قبل</span>
                </button>
            </div>

            <footer class="card-footer">
                <div class="trace-tag" id="traceTag" title="کلیک برای کپی شناسه پیگیری">
                    <span>کد خطا:</span>
                    <strong id="traceCode"><?= $traceId ?></strong>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </div>
                <span>هوش تجاری رهیاران رابین</span>
            </footer>
        </main>
    </div>

    <div class="toast-notify" id="toastNotify">کد پیگیری با موفقیت کپی شد</div>

    <script>
    function handleBack() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '<?= BASE_URL ?>/';
        }
    }



    var traceTag = document.getElementById('traceTag');
    var toast = document.getElementById('toastNotify');
    traceTag.addEventListener('click', function(){
        var code = document.getElementById('traceCode').textContent;
        navigator.clipboard.writeText(code).then(function(){
            toast.classList.add('visible');
            setTimeout(function(){ toast.classList.remove('visible'); }, 2200);
        });
    });
    </script>
</body>
</html>
