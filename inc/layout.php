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
            <div class="header-search-wrap">
                <form class="header-search" method="get" action="search.php">
                    <span class="header-search-ico"><?= app_icon('search', '', 16) ?></span>
                    <input type="search" name="q" class="header-search-input" placeholder="جستجوی سریع فرآیندها…" value="<?= (isset($_GET['q']) && $active === 'search') ? e($_GET['q']) : '' ?>" autocomplete="off">
                    <button type="submit" class="header-search-btn" title="جستجو"><span>جستجو</span></button>
                </form>
            </div>
            <div class="user-chip">
                <div class="user-avatar-wrap">
                    <span class="avatar"><?= e(mb_substr($id['name'], 0, 1)) ?></span>
                </div>
                <div class="user-meta">
                    <span class="user-name"><?= e($id['name']) ?></span>
                    <span class="user-sub">
                        <span class="code-pill"><?= e($id['code']) ?></span>
                        <?php if (!empty($id['company'])): ?>
                            <span class="sep">·</span>
                            <span class="company-text"><?= e($id['company']) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <a class="btn-exit" href="logout.php" title="خروج از سامانه">
                    <?= app_icon('logout', 'exit-icon', 15) ?>
                    <span>خروج</span>
                </a>
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
</body>
</html>
<?php
}

/** هدر ناحیه تیم */
function team_header(array $id, string $active, string $pageTitle): void
{
    $basicsChildren = [
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
            <div class="header-search-wrap">
                <form class="header-search" method="get" action="processes.php">
                    <span class="header-search-ico"><?= app_icon('search', '', 16) ?></span>
                    <input type="search" name="q" class="header-search-input" placeholder="جستجوی فرآیندها در پنل تیم…" value="<?= (isset($_GET['q']) && $active === 'processes') ? e($_GET['q']) : '' ?>" autocomplete="off">
                    <button type="submit" class="header-search-btn" title="جستجو"><span>جستجو</span></button>
                </form>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <a href="notifications.php" class="notif-bell-btn" title="اعلان‌های هرمس و رویدادها" style="position:relative;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,0.85);border:1px solid var(--line);color:var(--text);text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,0.05);transition:all .2s ease">
                    <?= app_icon('bot', '', 18) ?>
                    <span id="notifBadge" class="notif-badge" style="display:<?= $notifCount > 0 ? 'flex' : 'none' ?>;position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:11px;font-weight:bold;min-width:18px;height:18px;border-radius:9px;align-items:center;justify-content:center;padding:0 4px"><?= farsi_num($notifCount) ?></span>
                </a>
                <div class="user-chip">
                    <div class="user-avatar-wrap">
                        <span class="avatar avatar-team"><?= app_icon('team', '', 18) ?></span>
                    </div>
                    <div class="user-meta">
                        <span class="user-name"><?= e($id['name']) ?></span>
                        <span class="user-sub">
                            <span class="code-pill"><?= e($id['code']) ?></span>
                            <?php if (!empty($id['company'])): ?>
                                <span class="sep">·</span>
                                <span class="company-text"><?= e($id['company']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <span class="badge badge-team">تیم فرآیند</span>
                    <a class="btn-exit" href="logout.php" title="خروج از سامانه">
                        <?= app_icon('logout', 'exit-icon', 15) ?>
                        <span>خروج</span>
                    </a>
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
            <div class="nav-item has-sub <?= $basicsActive ? 'active' : '' ?>" tabindex="0" id="basicsMenu">
                <a href="#" onclick="return false;" style="display:inline-flex;align-items:center;gap:7px">
                    <?= app_icon('basics', 'tab-icon', 17) ?>
                    <span class="tab-label">اطلاعات پایه</span>
                    <span class="caret">▾</span>
                </a>
                <div class="submenu">
                    <?php foreach ($basicsChildren as $key => [$href, $label]): ?>
                        <a class="sub-item <?= $active === $key ? 'active' : '' ?>" href="<?= $href ?>"><?= e($label) ?></a>
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
    menu.addEventListener('click', function (ev) {
        if (ev.target.closest('.submenu')) return;
        ev.preventDefault();
        menu.classList.toggle('open');
    });
    document.addEventListener('click', function (ev) {
        if (!menu.contains(ev.target)) menu.classList.remove('open');
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') menu.classList.remove('open');
    });
})();
</script>
<div id="hermesToastContainer" style="position:fixed;bottom:24px;left:24px;z-index:9999;display:flex;flex-direction:column;gap:12px;max-width:380px;pointer-events:none"></div>
<script>
(function() {
    let notifiedIds = new Set();
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
                        if (!notifiedIds.has(nid)) {
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
        const toast = document.createElement('div');
        toast.style.cssText = 'pointer-events:auto;background:#0f172a;color:#fff;border-radius:12px;padding:14px 18px;box-shadow:0 12px 28px -5px rgba(0,0,0,0.35);border:1px solid #334155;display:flex;flex-direction:column;gap:6px;font-family:inherit;font-size:13px;transition:all 0.3s ease;animation:slideIn 0.3s ease-out;';
        
        let linkHtml = '';
        if (n.Link) {
            linkHtml = `<a href="${n.Link}" target="_blank" style="display:inline-block;margin-top:6px;background:#2563eb;color:#fff;padding:5px 12px;border-radius:6px;text-decoration:none;font-size:11.5px;font-weight:bold">مشاهده و دانلود سند PDF ←</a>`;
        }
        
        toast.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span style="font-weight:bold;color:#38bdf8;display:flex;align-items:center;gap:6px">🤖 ${n.Title}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;line-height:1">×</button>
            </div>
            ${n.Body ? `<div style="color:#cbd5e1;font-size:12px;line-height:1.6">${n.Body}</div>` : ''}
            ${linkHtml}
        `;
        container.appendChild(toast);
        setTimeout(() => { if (toast.parentElement) toast.remove(); }, 14000);
    }

    setInterval(pollHermesAlerts, 8000);
    setTimeout(pollHermesAlerts, 2000);
})();
</script>
<main class="app-main">
    <div class="page-head"><h1><?= e($pageTitle) ?></h1></div>
<?php
}
