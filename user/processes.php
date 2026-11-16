<?php
/**
 * پیمایش فرآیندها — دسته‌ها (فولدرها) + لیست فرآیندها + جزئیات و فایل‌ها
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/files.php';
$id = require_area('user');
$db = app_db();

$categories = active_categories($db);

$uid = (int)($id['id'] ?? 0);
$userCompanyId = !empty($id['company_id']) ? (int)$id['company_id'] : null;
$accessCtx = user_load_access_context($db, $uid);

/* ---------- نمای جزئیات ---------- */
$procId = (int)($_GET['id'] ?? 0);
if ($procId > 0) {
    $st = $db->prepare(
        "SELECT p.*, cat.Title AS CatTitle, cat.FolderName, c.Name AS CompanyName, t.TeamName,
                per.Name AS OwnerName, per.Family AS OwnerFamily
         FROM Processes p
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN Companies c ON c.ID = p.CompanyID
         LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID
         LEFT JOIN Personnel per ON per.ID = p.OwnerPersonnelID
         WHERE p.ID = ?"
    );
    $st->bind_param('i', $procId);
    $st->execute();
    $proc = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$proc || !process_user_can_access($proc, $uid, $userCompanyId, $accessCtx['pos_id'], $accessCtx['dept'], $accessCtx['aud_proc_ids'])) {
        user_header($id, 'processes', 'فرآیند یافت نشد');
        echo '<div class="card"><div class="empty"><span class="ico">' . app_icon('search', '', 36) . '</span><p>فرآیند مورد نظر وجود ندارد یا شما مجوز مشاهده آن را ندارید.</p><p><a href="processes.php">بازگشت به پیمایش فرآیندها</a></p></div></div>';
        app_footer();
        exit;
    }

    /* فایل‌های واقعی فرآیند در دایرکتوری processes */
    $relDir = fm_normalize_rel((string)$proc['FilePath']);
    if ($relDir === '') $relDir = fm_normalize_rel($proc['FolderName'] . '/' . $proc['Name']);
    $absDir = fm_safe_abs($relDir);
    $files = [];
    if ($absDir !== null && is_dir($absDir)) {
        foreach (scandir($absDir) as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess' || str_starts_with($f, '.')) continue;
            $fp = $absDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($fp)) $files[] = ['name' => $f, 'size' => (int)filesize($fp), 'time' => fmt_date((int)filemtime($fp))];
        }
    }
    ?>
    <?php user_header($id, 'processes', $proc['Name']); ?>
    <div class="crumbs"><a href="processes.php">پیمایش فرآیندها</a> ← <a href="processes.php?cat=<?= (int)$proc['CategoryID'] ?>"><?= e($proc['CatTitle']) ?></a> ← <?= e($proc['Name']) ?></div>

    <div class="card">
        <div class="page-head">
            <h2 style="margin:0"><?= e($proc['Name']) ?> <span class="badge badge-<?= e($proc['Status']) ?>"><?= status_label($proc['Status']) ?></span></h2>
            <a class="btn btn-ghost btn-sm" href="improvements.php?process=<?= (int)$proc['ID'] ?>" style="display:inline-flex;align-items:center;gap:6px">
                <?= app_icon('improvements', '', 14) ?>
                <span>پیشنهاد بهبود</span>
            </a>
        </div>
        <div class="table-wrap">
        <table class="data">
            <tbody>
                <tr><th style="width:180px">دسته (فولدر)</th><td><?= e($proc['CatTitle']) ?></td></tr>
                <tr><th>شرکت</th><td><?= e($proc['CompanyName'] ?? '—') ?></td></tr>
                <tr><th>تیم فرآیند مسئول</th><td><?= e($proc['TeamName'] ?? '—') ?></td></tr>
                <tr><th>مالک فرآیند</th><td><?= e(trim((string)$proc['OwnerName'] . ' ' . (string)$proc['OwnerFamily']) ?: '—') ?></td></tr>
                <tr><th>نسخه</th><td><span class="num-ltr"><?= e($proc['Version']) ?></span></td></tr>
                <tr><th>آخرین به‌روزرسانی</th><td><?= e(fmt_date($proc['UpdatedAt'])) ?></td></tr>
                <?php if (!empty($proc['Description'])): ?>
                    <tr><th>شرح فرآیند</th><td><?= nl2br(e($proc['Description'])) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card">
        <h2>فایل‌های فرآیند</h2>
        <?php if (!$files): ?>
            <div class="empty"><span class="ico"><?= app_icon('file', '', 28) ?></span><p>فایلی برای این فرآیند در دایرکتوری فرآیندها قرار ندارد.</p></div>
        <?php else: ?>
            <ul class="file-list">
                <?php foreach ($files as $f):
                    $url = fm_url($relDir === '' ? $f['name'] : $relDir . '/' . $f['name']);
                    $isHermesDoc = str_starts_with($f['name'], 'دستورالعمل-فرآیند');
                ?>
                    <li style="<?= $isHermesDoc ? 'background:#f0fdf4;border-right:3px solid #16a34a;padding-right:10px;border-radius:6px;' : '' ?>">
                        <span><?= app_icon(str_ends_with($f['name'], '.pdf') ? 'pdf' : 'file', '', 18) ?></span>
                        <a href="<?= e($url) ?>" target="_blank" rel="noopener" style="font-weight:<?= $isHermesDoc ? 'bold' : 'normal' ?>"><?= e($f['name']) ?></a>
                        <?php if ($isHermesDoc): ?>
                            <span class="badge" style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px">دستورالعمل مصوب هرمس</span>
                        <?php endif; ?>
                        <span class="fsize"><?= e(fmt_size($f['size'])) ?> · <?= e($f['time']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php app_footer(); exit;
}

/* ---------- نمای لیست ---------- */
$catId = (int)($_GET['cat'] ?? 0);

$sql = "SELECT p.*, cat.Title AS CatTitle
        FROM Processes p JOIN ProcessCategories cat ON cat.ID = p.CategoryID
        WHERE p.Status = 'active'
        ORDER BY cat.SortOrder, p.Name";

$st = $db->query($sql);
$allCandidateProcs = [];
if ($st) {
    while ($r = $st->fetch_assoc()) {
        if (process_user_can_access($r, $uid, $userCompanyId, $accessCtx['pos_id'], $accessCtx['dept'], $accessCtx['aud_proc_ids'])) {
            $allCandidateProcs[] = $r;
        }
    }
}

// شمارش فرآیندهای مجاز هر دسته بر اساس قوانین دامنه مخاطب
$cnt = [];
foreach ($allCandidateProcs as $p) {
    $cId = (int)$p['CategoryID'];
    $cnt[$cId] = ($cnt[$cId] ?? 0) + 1;
}

// فیلتر بر اساس دسته انتخابی
$procs = [];
foreach ($allCandidateProcs as $p) {
    if ($catId === 0 || (int)$p['CategoryID'] === $catId) {
        $procs[] = $p;
    }
}
?>
<?php user_header($id, 'processes', 'پیمایش فرآیندها'); ?>

<div class="chips">
    <a class="chip <?= $catId === 0 ? 'active' : '' ?>" href="processes.php">همه دسته‌ها <span class="cnt">(<?= farsi_num(count($procs)) ?>)</span></a>
    <?php foreach ($categories as $c): ?>
        <a class="chip <?= $catId === (int)$c['ID'] ? 'active' : '' ?>" href="processes.php?cat=<?= (int)$c['ID'] ?>" style="display:inline-flex;align-items:center;gap:6px">
            <?= app_icon('files', '', 14) ?>
            <span><?= e($c['Title']) ?></span>
            <span class="cnt">(<?= farsi_num($cnt[(int)$c['ID']] ?? 0) ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$procs): ?>
    <div class="card"><div class="empty"><span class="ico"><?= app_icon('processes', '', 36) ?></span><p>در این دسته هنوز فرآیندی ثبت نشده است.</p></div></div>
<?php else: ?>
    <div class="proc-list">
        <?php foreach ($procs as $p): ?>
            <div class="proc-card">
                <h3><a href="processes.php?id=<?= (int)$p['ID'] ?>"><?= e($p['Name']) ?></a></h3>
                <p class="desc"><?= e(mb_strimwidth(strip_tags((string)$p['Description']), 0, 110, '…')) ?: 'بدون شرح' ?></p>
                <div class="proc-meta">
                    <span style="display:inline-flex;align-items:center;gap:5px"><?= app_icon('files', '', 14) ?> <b><?= e($p['CatTitle']) ?></b></span>
                    <span>نسخه <b class="num-ltr"><?= farsi_num($p['Version']) ?></b></span>
                    <span class="badge badge-<?= e($p['Status']) ?>"><?= status_label($p['Status']) ?></span>
                </div>
                <div><a class="btn btn-sm" href="processes.php?id=<?= (int)$p['ID'] ?>">مشاهده فرآیند</a></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php app_footer(); ?>
