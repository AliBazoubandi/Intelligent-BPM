<?php
/**
 * خانه کاربر — نمای کلی فرآیندها و وضعیت شخصی
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('user');
$db = app_db();

$stats = [];
$stats['categories'] = (int)$db->query('SELECT COUNT(*) c FROM ProcessCategories WHERE Active=1')->fetch_assoc()['c'];
$stats['processes'] = (int)$db->query("SELECT COUNT(*) c FROM Processes WHERE Status='active'")->fetch_assoc()['c'];
$badges = user_badges((int)$id['id']);

// آخرین فرآیندهای فعال
$latest = [];
$res = $db->query(
    "SELECT p.ID, p.Name, p.Version, p.UpdatedAt, cat.Title AS CatTitle
     FROM Processes p
     JOIN ProcessCategories cat ON cat.ID = p.CategoryID
     WHERE p.Status = 'active'
     ORDER BY p.UpdatedAt DESC LIMIT 6"
);
while ($r = $res->fetch_assoc()) $latest[] = $r;
?>
<?php user_header($id, 'home', 'خوش آمدید، ' . $id['name']); ?>

<div class="stat-grid">
    <a class="stat" href="processes.php">
        <span class="ico"><?= app_icon('files', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($stats['categories']) ?></span><br><span class="lbl">دسته فرآیند</span></span>
    </a>
    <a class="stat" href="processes.php">
        <span class="ico"><?= app_icon('processes', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($stats['processes']) ?></span><br><span class="lbl">فرآیند فعال</span></span>
    </a>
    <a class="stat" href="improvements.php">
        <span class="ico"><?= app_icon('improvements', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($badges['improvements']) ?></span><br><span class="lbl">بهبودهای من</span></span>
    </a>
    <a class="stat" href="changes.php">
        <span class="ico"><?= app_icon('changes', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($badges['changes']) ?></span><br><span class="lbl">تغییرات مرتبط با من</span></span>
    </a>
</div>

<div class="card">
    <h2>تازه‌ترین فرآیندهای فعال</h2>
    <?php if (!$latest): ?>
        <div class="empty"><span class="ico"><?= app_icon('processes', '', 36) ?></span><p>هنوز فرآیندی ثبت نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>فرآیند</th><th>دسته</th><th>نسخه</th><th>آخرین به‌روزرسانی</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($latest as $p): ?>
                <tr>
                    <td><span class="title"><?= e($p['Name']) ?></span></td>
                    <td><?= e($p['CatTitle']) ?></td>
                    <td><span class="num-ltr"><?= farsi_num($p['Version']) ?></span></td>
                    <td><?= e(fmt_date($p['UpdatedAt'])) ?></td>
                    <td><a class="btn btn-sm" href="processes.php?id=<?= (int)$p['ID'] ?>">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>اجازه‌های دسترسی شما</h2>
    <div class="chips">
        <?php
        $perms = ['request' => 'ثبت درخواست', 'search' => 'جستجو', 'improve' => 'ثبت بهبود', 'approve' => 'تأیید'];
        foreach ($perms as $k => $label): $on = !empty($id['perms'][$k]); ?>
            <span class="chip <?= $on ? 'active' : 'chip-off' ?>"><?= $label ?>: <?= $on ? 'دارد' : 'ندارد' ?></span>
        <?php endforeach; ?>
    </div>
</div>

<?php app_footer(); ?>
