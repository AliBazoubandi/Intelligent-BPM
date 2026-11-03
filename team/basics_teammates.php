<?php
/**
 * اطلاعات پایه > هم‌تیمی (اعضای تیم فرآیند فعلی)
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$error = '';
$notice = '';
$needsConfirm = false;
$confirmCode = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $error = 'اعتبار صفحه منقضی شده است.';
    else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            $code = trim((string)($_POST['personnel_code'] ?? ''));
            $st = $db->prepare('SELECT p.ID, p.Name, p.Family, p.TeamID, t.TeamName FROM Personnel p LEFT JOIN ProcessTeams t ON t.ID = p.TeamID WHERE p.PersonnelCode = ? AND p.Active = 1');
            $st->bind_param('s', $code); $st->execute();
            $p = $st->get_result()->fetch_assoc(); $st->close();
            if (!$p) $error = 'پرسنلی با این کد یافت نشد.';
            elseif ((int)$p['TeamID'] === $tid) $error = 'این نفر عضو همین تیم است.';
            elseif ($p['TeamID'] !== null && empty($_POST['confirm_transfer'])) {
                $error = 'این کاربر عضو تیم «' . ($p['TeamName'] ?? 'دیگر') . '» است. برای تأیید انتقال به این تیم، مجدداً دکمه افزودن را بزنید.';
                $needsConfirm = true;
                $confirmCode = $code;
            } else {
                $st = $db->prepare('UPDATE Personnel SET TeamID = ? WHERE ID = ?');
                $st->bind_param('ii', $tid, $p['ID']); $st->execute(); $st->close();
                $notice = trim($p['Name'] . ' ' . $p['Family']) . ' به تیم افزوده شد.';
            }
        } elseif ($action === 'remove') {
            $pid = (int)($_POST['personnel_id'] ?? 0);
            $st = $db->prepare('UPDATE Personnel SET TeamID = NULL WHERE ID = ? AND TeamID = ?');
            $st->bind_param('ii', $pid, $tid); $st->execute(); $st->close();
            $st = $db->prepare('UPDATE ProcessTeams SET LeaderPersonnelID = NULL WHERE ID = ? AND LeaderPersonnelID = ?');
            $st->bind_param('ii', $tid, $pid); $st->execute(); $st->close();
            $notice = 'عضو از تیم حذف شد.';
        }
    }
}

$st = $db->prepare(
    'SELECT p.ID, p.PersonnelCode, p.Name, p.Family, p.CanApprove, p.CanImprove, c.Name AS CompanyName
     FROM Personnel p LEFT JOIN Companies c ON c.ID = p.CompanyID
     WHERE p.TeamID = ? AND p.Active = 1 ORDER BY p.Name'
);
$st->bind_param('i', $tid); $st->execute();
$members = []; $res = $st->get_result();
while ($r = $res->fetch_assoc()) $members[] = $r;
$st->close();
?>
<?php team_header($id, 'basics_teammates', 'اطلاعات پایه — هم‌تیمی'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:640px">
    <h2>افزودن هم‌تیمی</h2>
    <p style="color:var(--muted);font-size:13px;margin-top:0">با وارد کردن کد پرسنلی، آن نفر به تیم «<?= e($id['name']) ?>» ملحق می‌شود.</p>
    <form method="post" class="inline-form" style="width:100%">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <?php if ($needsConfirm): ?><input type="hidden" name="confirm_transfer" value="1"><?php endif; ?>
        <label class="field" style="flex:1;margin:0">
            <input type="text" name="personnel_code" required maxlength="20" value="<?= e($confirmCode) ?>" placeholder="کد پرسنلی، مثلاً P-1002">
        </label>
        <button class="btn"><?= $needsConfirm ? '⚠️ تأیید و انتقال' : '＋ افزودن' ?></button>
    </form>
</div>

<div class="card">
    <h2>اعضای تیم (<?= count($members) ?>)</h2>
    <?php if (!$members): ?>
        <div class="empty"><span class="ico">👥</span><p>تیم هنوز عضوی ندارد.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>نفر</th><th>کد پرسنلی</th><th>شرکت</th><th>اجازه‌ها</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><span class="title">👤 <?= e($m['Name'] . ' ' . $m['Family']) ?></span></td>
                    <td><span class="num-ltr"><?= e($m['PersonnelCode']) ?></span></td>
                    <td><?= e($m['CompanyName'] ?? '—') ?></td>
                    <td>
                        <?php if ((int)$m['CanApprove']): ?><span class="badge badge-user">تأیید</span><?php endif; ?>
                        <?php if ((int)$m['CanImprove']): ?><span class="badge badge-accepted">بهبود</span><?php endif; ?>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('حذف این نفر از تیم؟');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="personnel_id" value="<?= (int)$m['ID'] ?>">
                            <button class="btn btn-ghost btn-xs">خروج از تیم</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
