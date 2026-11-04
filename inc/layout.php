<?php
/**
 * پوسته مشترک — هدر حرفه‌ای با منوی مدرن و فونت میخک + فوتر
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/icons.php';

/**
 * هدر ناحیه کاربر: نوار بالا (برند + پروفایل) و نوار منوی برداری
 */
function user_header(array $id, string $active, string $pageTitle): void
{
    $badges = user_badges((int)$id['id']);
    $menu = [
        'home' => ['home.php', 'خانه', 'home', null],
        'processes' => ['processes.php', 'پیمایش فرآیندها', 'processes', null],
        'ask' => ['ask.php', 'پرسش از فرآیندها', 'bot', null],
        'improvements' => ['improvements.php', 'بهبودهای من', 'improvements', $badges['improvements']],
        'changes' => ['changes.php', 'تغییرات مرتبط با من', 'changes', $badges['changes']],
    ];
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<script>
(function(){try{var t=localStorage.getItem("theme-preference")||"auto";var d=t==="dark"||(t==="auto"&&window.matchMedia("(prefers-color-scheme: dark)").matches);if(d){document.documentElement.setAttribute("data-theme","dark");var m=document.querySelector('meta[name="color-scheme"]');if(m)m.content="dark";}else{document.documentElement.setAttribute("data-theme","light");var m=document.querySelector('meta[name="color-scheme"]');if(m)m.content="light";}}catch(e){}})();
</script>
<title><?= e($pageTitle) ?> | رابین‌فلو</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/mikhak.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="app-body">
<header class="app-header">
    <div class="header-inner">
        <div class="header-top">
            <a class="brand" href="home.php">
                <span class="brand-logo">
                    <img src="<?= BASE_URL ?>/assets/logo.png" alt="رابین‌فلو" class="brand-img">
                </span>
                <span class="brand-text">
                    <b>رابین‌فلو</b>
                    <small>سامانه مدیریت و بهبود فرآیندها</small>
                </span>
            </a>

            <div class="header-actions">
                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="تغییر پوسته تاریک و روشن" title="تغییر حالت شب و روز">
                    <span class="theme-icon theme-icon-moon"><?= app_icon('moon', '', 16) ?></span>
                    <span class="theme-icon theme-icon-sun"><?= app_icon('sun', '', 16) ?></span>
                </button>

                <div class="header-search-wrap">
                    <form class="header-search" method="get" action="search.php">
                        <span class="header-search-ico"><?= app_icon('search', '', 15) ?></span>
                        <input type="search" name="q" id="globalSearchInput" class="header-search-input" placeholder="جستجو در سامانه…" value="<?= (isset($_GET['q']) && $active === 'search') ? e($_GET['q']) : '' ?>" autocomplete="off">
                        <kbd class="search-kbd" title="کلید میانبر فوکوس جستجو">Ctrl K</kbd>
                    </form>
                </div>

                <div class="user-chip-compact" id="userChipCompact">
                    <button type="button" class="user-chip-btn" id="userMenuBtn" aria-label="مشخصات کاربر" aria-expanded="false">
                        <span class="avatar avatar-compact"><?= e(mb_substr($id['name'], 0, 1)) ?></span>
                        <span class="user-name-compact"><?= e($id['name']) ?></span>
                        <span class="user-chevron"><?= app_icon('chevron_down', '', 12) ?></span>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <div class="user-dd-profile">
                            <span class="avatar avatar-dd"><?= e(mb_substr($id['name'], 0, 1)) ?></span>
                            <div class="user-dd-info">
                                <strong><?= e($id['name']) ?></strong>
                                <span class="user-dd-sub">کد: <?= e($id['code']) ?><?= !empty($id['company']) ? ' · ' . e($id['company']) : '' ?></span>
                            </div>
                        </div>
                        <div class="user-dd-divider"></div>
                        <a href="my_changes.php" class="user-dd-link">
                            <?= app_icon('changes', '', 14) ?>
                            <span>تغییرات مرتبط با من</span>
                        </a>
                        <a href="improvements.php" class="user-dd-link">
                            <?= app_icon('improvements', '', 14) ?>
                            <span>بهبودهای من</span>
                        </a>
                        <div class="user-dd-divider"></div>
                        <a href="logout.php" class="user-dd-link user-dd-logout">
                            <?= app_icon('logout', '', 14) ?>
                            <span>خروج از حساب</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <nav class="header-nav" aria-label="منوی اصلی">
            <?php foreach ($menu as $key => [$href, $label, $iconName, $badge]): ?>
                <a class="nav-item <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>">
                    <?= app_icon($iconName, 'tab-icon', 17) ?>
                    <span class="tab-label"><?= e($label) ?></span>
                    <?php if ($badge): ?><span class="nav-badge"><?= farsi_num((int)$badge) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
<main class="app-main">
    <div class="page-head">
        <h1><?= e($pageTitle) ?></h1>
    </div>
<?php
}

/** فوتر مشترک */
function app_footer(): void
{
    ?>
</main>
<footer class="app-foot">✨ تمام حقوق اين وبسايت مربوط به تیم هوش تجاری رهباریان رابین است. ✨</footer>

<script>
(function() {
    // 1. Theme toggle handler (Dark / Light)
    function applyTheme(theme) {
        const isDark = theme === 'dark' || (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
        const meta = document.querySelector('meta[name="color-scheme"]');
        if (meta) meta.content = isDark ? 'dark' : 'light';
        localStorage.setItem('theme-preference', isDark ? 'dark' : 'light');
    }

    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
    }

    // React to system preference changes when user hasn't explicitly chosen
    try {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
            const saved = localStorage.getItem('theme-preference');
            if (!saved || saved === 'auto') {
                applyTheme(e.matches ? 'dark' : 'light');
            }
        });
    } catch(e) {}

    // Listen to storage events across tabs
    window.addEventListener('storage', function(e) {
        if (e.key === 'theme-preference' && e.newValue) {
            applyTheme(e.newValue);
        }
    });

    // 2. Global search shortcut (Ctrl+K or Cmd+K)
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            const searchInput = document.getElementById('globalSearchInput');
            if (searchInput) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    // 3. User compact menu dropdown toggle
    const userChip = document.getElementById('userChipCompact');
    const userBtn = document.getElementById('userMenuBtn');
    if (userChip) {
        userChip.addEventListener('click', function(e) {
            if (e.target.closest('#userDropdownMenu')) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            userChip.classList.toggle('open');
            if (userBtn) {
                userBtn.setAttribute('aria-expanded', userChip.classList.contains('open') ? 'true' : 'false');
            }
        });

        document.addEventListener('click', function(e) {
            if (!userChip.contains(e.target)) {
                userChip.classList.remove('open');
                if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                userChip.classList.remove('open');
                if (userBtn) userBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }
})();
</script>
</body>
</html>
<?php
}

/** هدر ناحیه تیم */
function team_header(array $id, string $active, string $pageTitle): void
{
    $basicsChildren = [
        'basics_bale' => ['basics_bale.php', 'پیام‌رسان بله'],
        'basics_companies' => ['basics_companies.php', 'ایجاد شرکت'],
        'basics_categories' => ['basics_categories.php', 'دسته فرآیند'],
        'basics_teammates' => ['basics_teammates.php', 'هم‌تیمی'],
        'basics_user_new' => ['basics_user_new.php', 'کاربر جدید'],
        'basics_users' => ['basics_users.php', 'لیست کاربران'],
    ];
    $basicsActive = isset($basicsChildren[$active]) || $active === 'basics';
    $tid = (int)($id['id'] ?? 0);
    $notifCount = 0;
    try {
        $db = app_db();
        $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetTeamID = ? OR TargetTeamID IS NULL) AND IsRead = 0");
        $cntSt->bind_param('i', $tid);
        $cntSt->execute();
        $notifCount = (int)($cntSt->get_result()->fetch_assoc()['c'] ?? 0);
        $cntSt->close();
    } catch (Throwable) {}
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<script>
(function(){try{var t=localStorage.getItem("theme-preference")||"auto";var d=t==="dark"||(t==="auto"&&window.matchMedia("(prefers-color-scheme: dark)").matches);if(d){document.documentElement.setAttribute("data-theme","dark");var m=document.querySelector('meta[name="color-scheme"]');if(m)m.content="dark";}else{document.documentElement.setAttribute("data-theme","light");var m=document.querySelector('meta[name="color-scheme"]');if(m)m.content="light";}}catch(e){}})();
</script>
<title><?= e($pageTitle) ?> | پنل تیم رابین‌فلو</title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/favicon.png">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/mikhak.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="app-body team-theme">
<header class="app-header">
    <div class="header-inner">
        <div class="header-top">
            <a class="brand" href="home.php">
                <span class="brand-logo">
                    <img src="<?= BASE_URL ?>/assets/logo.png" alt="رابین‌فلو" class="brand-img">
                </span>
                <span class="brand-text">
                    <b>رابین‌فلو</b>
                    <small>پنل تیم مهندسی فرآیند</small>
                </span>
            </a>

            <div class="header-actions">
                <a href="notifications.php" class="notif-bell-btn" title="اعلان‌های هرمس و رویدادها">
                    <?= app_icon('bot', '', 17) ?>
                    <span id="notifBadge" class="notif-badge" style="display:<?= $notifCount > 0 ? 'flex' : 'none' ?>;"><?= farsi_num($notifCount) ?></span>
                </a>

                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="تغییر پوسته تاریک و روشن" title="تغییر حالت شب و روز">
                    <span class="theme-icon theme-icon-moon"><?= app_icon('moon', '', 16) ?></span>
                    <span class="theme-icon theme-icon-sun"><?= app_icon('sun', '', 16) ?></span>
                </button>

                <div class="header-search-wrap">
                    <form class="header-search" method="get" action="processes.php">
                        <span class="header-search-ico"><?= app_icon('search', '', 15) ?></span>
                        <input type="search" name="q" id="globalSearchInput" class="header-search-input" placeholder="جستجو در پنل تیم…" value="<?= (isset($_GET['q']) && $active === 'processes') ? e($_GET['q']) : '' ?>" autocomplete="off">
                        <kbd class="search-kbd" title="کلید میانبر فوکوس جستجو">Ctrl K</kbd>
                    </form>
                </div>

                <div class="user-chip-compact" id="userChipCompact">
                    <button type="button" class="user-chip-btn" id="userMenuBtn" aria-label="مشخصات تیم" aria-expanded="false">
                        <span class="avatar avatar-compact avatar-team"><?= e(mb_substr($id['name'], 0, 1)) ?></span>
                        <span class="user-name-compact"><?= e($id['name']) ?></span>
                        <span class="user-chevron"><?= app_icon('chevron_down', '', 12) ?></span>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <div class="user-dd-profile">
                            <span class="avatar avatar-dd avatar-team"><?= e(mb_substr($id['name'], 0, 1)) ?></span>
                            <div class="user-dd-info">
                                <strong><?= e($id['name']) ?></strong>
                                <span class="user-dd-sub">کد تیم: <?= e($id['code']) ?><?= !empty($id['company']) ? ' · ' . e($id['company']) : '' ?></span>
                            </div>
                        </div>
                        <div class="user-dd-divider"></div>
                        <a href="notifications.php" class="user-dd-link">
                            <?= app_icon('bot', '', 14) ?>
                            <span>اعلان‌های هرمس</span>
                        </a>
                        <a href="improvements.php" class="user-dd-link">
                            <?= app_icon('improvements', '', 14) ?>
                            <span>لیست بهبودها</span>
                        </a>
                        <div class="user-dd-divider"></div>
                        <a href="logout.php" class="user-dd-link user-dd-logout">
                            <?= app_icon('logout', '', 14) ?>
                            <span>خروج از حساب</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <nav class="header-nav" aria-label="منوی اصلی">
            <a class="nav-item <?= $active === 'home' ? 'active' : '' ?>" href="home.php">
                <?= app_icon('home', 'tab-icon', 17) ?>
                <span class="tab-label">خانه</span>
            </a>
            <a class="nav-item <?= $active === 'processes' ? 'active' : '' ?>" href="processes.php">
                <?= app_icon('processes', 'tab-icon', 17) ?>
                <span class="tab-label">لیست فرآیندها</span>
            </a>
            <a class="nav-item <?= $active === 'process_new' ? 'active' : '' ?>" href="process_new.php">
                <?= app_icon('plus_circle', 'tab-icon', 17) ?>
                <span class="tab-label">فرآیند جدید</span>
            </a>
            <a class="nav-item <?= $active === 'ask' ? 'active' : '' ?>" href="ask.php">
                <?= app_icon('bot', 'tab-icon', 17) ?>
                <span class="tab-label">پرسش از فرآیندها</span>
            </a>
            <a class="nav-item <?= $active === 'improvements' ? 'active' : '' ?>" href="improvements.php">
                <?= app_icon('improvements', 'tab-icon', 17) ?>
                <span class="tab-label">لیست بهبودها</span>
            </a>
            <a class="nav-item <?= $active === 'discussion' ? 'active' : '' ?>" href="discussion.php">
                <?= app_icon('discussion', 'tab-icon', 17) ?>
                <span class="tab-label">گفتگو در فرآیندها</span>
            </a>
            <a class="nav-item <?= $active === 'files' ? 'active' : '' ?>" href="files.php">
                <?= app_icon('files', 'tab-icon', 17) ?>
                <span class="tab-label">مدیریت فایل‌ها</span>
            </a>
            <a class="nav-item <?= $active === 'notifications' ? 'active' : '' ?>" href="notifications.php">
                <?= app_icon('bot', 'tab-icon', 17) ?>
                <span class="tab-label">اعلان‌های هرمس</span>
                <?php if ($notifCount > 0): ?><span class="nav-badge"><?= farsi_num($notifCount) ?></span><?php endif; ?>
            </a>
            <div class="nav-item has-sub <?= $basicsActive ? 'active' : '' ?>" id="basicsMenu" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <?= app_icon('basics', 'tab-icon', 17) ?>
                <span class="tab-label">اطلاعات پایه</span>
                <span class="caret">▾</span>
                <div class="submenu" id="basicsSubmenu" role="menu">
                    <?php foreach ($basicsChildren as $key => [$href, $label]): ?>
                        <a class="sub-item <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>" role="menuitem"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>
    </div>
</header>
<script>
(function () {
    const menu = document.getElementById('basicsMenu');
    if (!menu) return;

    function toggleMenu(forceState) {
        const willOpen = typeof forceState === 'boolean' ? forceState : !menu.classList.contains('open');
        menu.classList.toggle('open', willOpen);
        menu.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    menu.addEventListener('click', function (ev) {
        if (ev.target.closest('.submenu a, .sub-item')) return;
        ev.preventDefault();
        toggleMenu();
    });

    menu.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
            if (ev.target.closest('.submenu a, .sub-item')) return;
            ev.preventDefault();
            toggleMenu();
        } else if (ev.key === 'Escape') {
            toggleMenu(false);
        }
    });

    document.addEventListener('click', function (ev) {
        if (!menu.contains(ev.target)) {
            toggleMenu(false);
        }
    });
})();
</script>
<div id="hermesToastContainer" style="position:fixed;bottom:24px;left:24px;z-index:9999;display:flex;flex-direction:column;gap:12px;max-width:380px;pointer-events:none"></div>
<script>
(function() {
    let notifiedIds = new Set();

    // Read dismissed notification IDs from localStorage so they persist across tabs and page navigation
    function getDismissedSet() {
        try {
            const raw = localStorage.getItem('robinflow_dismissed_notifs');
            if (raw) {
                const arr = JSON.parse(raw);
                if (Array.isArray(arr)) {
                    return new Set(arr.map(Number));
                }
            }
        } catch (e) {}
        return new Set();
    }

    let dismissedSet = getDismissedSet();

    window.dismissHermesNotif = function(notifId, toastEl) {
        const nid = parseInt(notifId);
        if (!nid) return;

        // Immediately add to sets so no navigation or polling can pop it up again
        dismissedSet.add(nid);
        notifiedIds.add(nid);
        try {
            const arr = Array.from(dismissedSet).slice(-200);
            localStorage.setItem('robinflow_dismissed_notifs', JSON.stringify(arr));
        } catch (e) {}

        // Smooth visual dismissal
        if (toastEl) {
            toastEl.style.opacity = '0';
            toastEl.style.transform = 'translateY(12px) scale(0.96)';
            setTimeout(() => {
                if (toastEl.parentElement) toastEl.remove();
            }, 250);
        }

        // Notify server to mark notification as read in database
        fetch('<?= BASE_URL ?>/team/notifications.php?mark_read_ajax=1&id=' + encodeURIComponent(nid))
            .then(r => r.json())
            .then(data => {
                if (data && typeof data.unread_count !== 'undefined') {
                    const badge = document.getElementById('notifBadge');
                    if (badge) {
                        if (data.unread_count > 0) {
                            badge.style.display = 'flex';
                            badge.textContent = data.unread_count;
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                }
            })
            .catch(() => {});
    };

    function pollHermesAlerts() {
        fetch('<?= BASE_URL ?>/team/notifications.php?check_unread=1')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('notifBadge');
                if (badge) {
                    if (data.unread_count > 0) {
                        badge.style.display = 'flex';
                        badge.textContent = data.unread_count;
                    } else {
                        badge.style.display = 'none';
                    }
                }
                if (data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(n => {
                        const nid = parseInt(n.ID);
                        if (!notifiedIds.has(nid) && !dismissedSet.has(nid)) {
                            notifiedIds.add(nid);
                            showHermesToast(n);
                        }
                    });
                }
            })
            .catch(() => {});
    }

    function showHermesToast(n) {
        const container = document.getElementById('hermesToastContainer');
        if (!container) return;
        const nid = parseInt(n.ID);
        const toast = document.createElement('div');
        toast.className = 'hermes-toast-item';
        toast.style.cssText = 'pointer-events:auto;background:#0f172a;color:#fff;border-radius:12px;padding:14px 18px;box-shadow:0 12px 28px -5px rgba(0,0,0,0.35);border:1px solid #334155;display:flex;flex-direction:column;gap:6px;font-family:inherit;font-size:13px;transition:opacity 0.25s ease, transform 0.25s ease;animation:slideIn 0.3s ease-out;';
        
        let linkHtml = '';
        if (n.Link) {
            linkHtml = `<a href="${n.Link}" target="_blank" onclick="window.dismissHermesNotif(${nid}, this.closest('.hermes-toast-item'))" style="display:inline-block;margin-top:6px;background:#2563eb;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:bold;text-align:center">مشاهده و دانلود سند PDF ←</a>`;
        }
        
        toast.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span style="font-weight:bold;color:#38bdf8;display:flex;align-items:center;gap:6px">🤖 ${n.Title}</span>
                <button type="button" onclick="window.dismissHermesNotif(${nid}, this.closest('.hermes-toast-item'))" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:18px;line-height:1;padding:2px 6px;border-radius:4px" title="بستن">×</button>
            </div>
            ${n.Body ? `<div style="color:#cbd5e1;font-size:12px;line-height:1.6">${n.Body}</div>` : ''}
            ${linkHtml}
        `;
        container.appendChild(toast);
        // Automatically hide visually after 16 seconds if user doesn't touch it
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(12px) scale(0.96)';
                setTimeout(() => { if (toast.parentElement) toast.remove(); }, 250);
            }
        }, 16000);
    }

    setInterval(pollHermesAlerts, 8000);
    setTimeout(pollHermesAlerts, 1500);
})();
</script>
<main class="app-main">
    <div class="page-head"><h1><?= e($pageTitle) ?></h1></div>
<?php
}
