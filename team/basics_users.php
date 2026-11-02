<?php
/**
 * اطلاعات پایه > لیست کاربران
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();

$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $error = 'اعتبار صفحه منقضی شده است.';
    else {
        $pid = (int)($_POST['personnel_id'] ?? 0);
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'toggle') {
            $st = $db->prepare('UPDATE Personnel SET Active = 1 - Active WHERE ID = ?');
            $st->bind_param('i', $pid); $st->execute(); $st->close();
            $notice = 'وضعیت کاربر تغییر کرد.';
        }
    }
}
if (!empty($_GET['created'])) $notice = 'کاربر «' . $_GET['created'] . '» با موفقیت ثبت شد.';

$filter = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT p.*, c.Name AS CompanyName, t.TeamName
        FROM Personnel p
        LEFT JOIN Companies c ON c.ID = p.CompanyID
        LEFT JOIN ProcessTeams t ON t.ID = p.TeamID";
$types = '';
if ($filter !== '') {
    $sql .= ' WHERE p.Name LIKE ? OR p.Family LIKE ? OR p.PersonnelCode LIKE ?';
    $types = 'sss';
}
$sql .= ' ORDER BY p.PersonnelCode';
$st = $db->prepare($sql);
if ($types) { $like = '%' . $filter . '%'; $st->bind_param($types, $like, $like, $like); }
$st->execute();
$rows = []; $res = $st->get_result();
while ($r = $res->fetch_assoc()) $rows[] = $r;
$st->close();
$csrf = csrf_token();
?>
<?php team_header($id, 'basics_users', 'اطلاعات پایه — لیست کاربران'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="page-head">
    <form method="get" class="inline-form">
        <input type="text" name="q" value="<?= e($filter) ?>" placeholder="جستجوی نام یا کد پرسنلی…"
               style="padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit">
        <button class="btn btn-sm">فیلتر</button>
    </form>
    <a class="btn btn-sm" href="basics_user_new.php">＋ کاربر جدید</a>
</div>

<div class="card">
    <h2>کاربران سازمان (<?= count($rows) ?>)</h2>
    <div class="table-wrap">
    <table class="data">
        <thead><tr><th>کد پرسنلی</th><th>نام</th><th>شرکت</th><th>تیم فرآیند</th><th>اجازه‌ها</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="num-ltr title"><?= e($r['PersonnelCode']) ?></span></td>
                <td><?= e($r['Name'] . ' ' . $r['Family']) ?></td>
                <td><?= e($r['CompanyName'] ?? '—') ?></td>
                <td><?= e($r['TeamName'] ?? '—') ?></td>
                <td>
                    <?php
                    $permMap = [['CanRequest', 'درخواست'], ['CanSearch', 'جستجو'], ['CanImprove', 'بهبود'], ['CanApprove', 'تأیید']];
                    foreach ($permMap as [$col, $lbl]):
                        if ((int)$r[$col]): ?><span class="badge badge-user" style="margin-inline-end:4px"><?= $lbl ?></span><?php endif;
                    endforeach;
                    if (!$r['CanRequest'] && !$r['CanSearch'] && !$r['CanImprove'] && !$r['CanApprove']) echo '—';
                    ?>
                </td>
                <td><span class="badge <?= (int)$r['Active'] ? 'badge-active' : 'badge-archived' ?>"><?= (int)$r['Active'] ? 'فعال' : 'غیرفعال' ?></span></td>
                <td>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="personnel_id" value="<?= (int)$r['ID'] ?>">
                        <button class="btn btn-ghost btn-xs"><?= (int)$r['Active'] ? 'غیرفعال' : 'فعال' ?> کردن</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php app_footer(); ?>
