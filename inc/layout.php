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
        'search' => ['search.php', 'جستجو', 'search', null],
        'ask' => ['ask.php', 'پرسش از فرآیندها', 'bot', null],
        'improvements' => ['improvements.php', 'بهبودهای من', 'improvements', $badges['improvements']],
        'changes' => ['changes.php', 'تغییرات مرتبط با من', 'changes', $badges['changes']],
    ];
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | رابین‌فلو (RobinFlow)</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/mikhak.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="app-body">
<header class="app-header">
    <div class="header-inner">
        <div class="header-top">
            <a class="brand" href="home.php">
                <span class="brand-logo">
                    <?= app_icon('brand', 'brand-svg', 26) ?>
                </span>
                <span class="brand-text">
                    <b>رابین‌فلو <span style="font-size:12px;font-weight:400;opacity:.85">(RobinFlow)</span></b>
                    <small>سامانه مدیریت و بهبود فرآیندها</small>
                </span>
            </a>
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
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | پنل تیم رابین‌فلو (RobinFlow)</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/mikhak.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css">
</head>
<body class="app-body team-theme">
<header class="app-header">
    <div class="header-inner">
        <div class="header-top">
            <a class="brand" href="home.php">
                <span class="brand-logo">
                    <?= app_icon('brand', 'brand-svg', 26) ?>
                </span>
                <span class="brand-text">
                    <b>رابین‌فلو <span style="font-size:12px;font-weight:400;opacity:.85">(RobinFlow)</span></b>
                    <small>پنل تیم مهندسی فرآیند</small>
                </span>
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
<main class="app-main">
    <div class="page-head"><h1><?= e($pageTitle) ?></h1></div>
<?php
}
