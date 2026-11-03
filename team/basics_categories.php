<?php
/**
 * اطلاعات پایه > دسته فرآیند (ساخت فولدر در processes/ + رکورد)
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();

$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $error = 'اعتبار صفحه منقضی شده است.';
    else {
        $action = (string)($_POST['action'] ?? 'create');
        if ($action === 'sync') {
            // همگام‌سازی با فولدرهای روی دیسک
            $root = PROCESSES_ROOT;
            $folders = array_values(array_filter(scandir($root), fn($f) => $f !== '.' && $f !== '..' && is_dir($root . DIRECTORY_SEPARATOR . $f)));
            sort($folders, SORT_STRING);
            $order = 0;
            $st = $db->prepare(
                "INSERT INTO ProcessCategories (Title, FolderName, FolderPath, SortOrder, Active) VALUES (?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE Active=1, FolderPath=VALUES(FolderPath)"
            );
            foreach ($folders as $f) {
                $order++;
                $rel = $f . '/';
                $st->bind_param('sssi', $f, $f, $rel, $order);
                $st->execute();
            }
            $st->close();

            if ($folders) {
                $in = implode(',', array_map(fn($f) => "'" . $db->real_escape_string($f) . "'", $folders));
                $db->query("UPDATE ProcessCategories SET Active=0 WHERE FolderName NOT IN ($in)");
            } else {
                $db->query('UPDATE ProcessCategories SET Active=0');
            }
            $notice = 'فولدرهای روی دیسک با جدول دسته‌ها همگام شد.';
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            $folder = preg_replace('/[\\\\\/:*?"<>|]+/u', '', $title);
            $folder = trim(preg_replace('/\s+/u', ' ', $folder));
            if (mb_strlen($folder) < 2) {
                $error = 'نام دسته معتبر نیست.';
            } elseif (is_dir(PROCESSES_ROOT . DIRECTORY_SEPARATOR . $folder)) {
                $error = 'پوشه‌ای با این نام از قبل وجود دارد.';
            } else {
                $abs = PROCESSES_ROOT . DIRECTORY_SEPARATOR . $folder;
                mkdir($abs, 0775, true);
                file_put_contents($abs . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
                $order = (int)$db->query('SELECT COALESCE(MAX(SortOrder),0) m FROM ProcessCategories')->fetch_assoc()['m'];
                $order++;
                $rel = $folder . '/';
                $st = $db->prepare(
                    'INSERT INTO ProcessCategories (Title, FolderName, FolderPath, SortOrder, Active) VALUES (?,?,?,?,1)
                     ON DUPLICATE KEY UPDATE Title = VALUES(Title), FolderPath = VALUES(FolderPath), Active = 1'
                );
                $st->bind_param('sssi', $title, $folder, $rel, $order);
                $st->execute(); $st->close();
                $notice = 'دسته «' . $title . '» و پوشه آن ساخته شد.';
            }
        }
    }
}
$res = $db->query(
    'SELECT c.*, (SELECT COUNT(*) FROM Processes p WHERE p.CategoryID = c.ID) AS Procs
     FROM ProcessCategories c ORDER BY c.SortOrder, c.Title'
);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
?>
<?php team_header($id, 'basics_categories', 'اطلاعات پایه — دسته فرآیند'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:760px">
    <h2>ساخت دسته (پوشه) جدید</h2>
    <p style="color:var(--muted);font-size:13px;margin-top:0">هر دسته معادل یک پوشه در دایرکتوری <span class="num-ltr">processes/</span> است؛ با ثبت این فرم پوشه نیز ساخته می‌شود.</p>
    <form method="post" class="inline-form" style="width:100%">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <label class="field" style="flex:1;margin:0">
            <input type="text" name="title" required maxlength="120" placeholder="مثلاً حقوق و دستمزد">
        </label>
        <button class="btn">＋ ساخت دسته</button>
    </form>
    <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="sync">
        <button class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
            <?= app_icon('refresh', '', 14) ?>
            <span>همگام‌سازی با فولدرهای روی دیسک</span>
        </button>
    </form>
</div>

<div class="card">
    <h2>دسته‌های فرآیند (<?= farsi_num(count($rows)) ?>)</h2>
    <div class="table-wrap">
    <table class="data">
        <thead><tr><th>دسته</th><th>پوشه</th><th>فرآیندها</th><th>ترتیب</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="title" style="display:inline-flex;align-items:center;gap:6px"><?= app_icon('files', '', 14) ?><span><?= e($r['Title']) ?></span></span></td>
                <td><span class="num-ltr sub"><?= e($r['FolderPath']) ?></span></td>
                <td><?= farsi_num((int)$r['Procs']) ?></td>
                <td><?= farsi_num((int)$r['SortOrder']) ?></td>
                <td><span class="badge <?= (int)$r['Active'] ? 'badge-active' : 'badge-archived' ?>"><?= (int)$r['Active'] ? 'فعال' : 'غیرفعال' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php app_footer(); ?>
