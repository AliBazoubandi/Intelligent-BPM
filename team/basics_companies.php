<?php
/**
 * اطلاعات پایه > ایجاد شرکت
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
        $name = trim((string)($_POST['name'] ?? ''));
        if (mb_strlen($name) < 2) $error = 'نام شرکت را وارد کنید.';
        else {
            $st = $db->prepare('INSERT INTO Companies (Name) VALUES (?)');
            try { $st->bind_param('s', $name); $st->execute(); $notice = 'شرکت «' . $name . '» ثبت شد.'; }
            catch (Throwable) { $error = 'این شرکت قبلاً ثبت شده است.'; }
            $st->close();
        }
    }
}
$res = $db->query(
    'SELECT c.ID, c.Name, c.Active,
            (SELECT COUNT(*) FROM Personnel p WHERE p.CompanyID = c.ID) AS Staff,
            (SELECT COUNT(*) FROM Processes pr WHERE pr.CompanyID = c.ID) AS Procs
     FROM Companies c ORDER BY c.Name'
);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
?>
<?php team_header($id, 'basics_companies', 'اطلاعات پایه — ایجاد شرکت'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px">
    <h2>ثبت شرکت جدید</h2>
    <form method="post" class="inline-form" style="width:100%">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label class="field" style="flex:1;margin:0">
            <input type="text" name="name" required maxlength="120" placeholder="نام کامل شرکت…">
        </label>
        <button class="btn">＋ ایجاد</button>
    </form>
</div>

<div class="card">
    <h2>شرکت‌های ثبت‌شده (<?= count($rows) ?>)</h2>
    <div class="table-wrap">
    <table class="data">
        <thead><tr><th>نام شرکت</th><th>پرسنل</th><th>فرآیندها</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="title"><?= e($r['Name']) ?></span></td>
                <td><?= (int)$r['Staff'] ?></td>
                <td><?= (int)$r['Procs'] ?></td>
                <td><span class="badge <?= (int)$r['Active'] ? 'badge-active' : 'badge-archived' ?>"><?= (int)$r['Active'] ? 'فعال' : 'غیرفعال' ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php app_footer(); ?>
