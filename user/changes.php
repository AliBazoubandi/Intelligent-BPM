<?php
/**
 * تغییرات مرتبط با من — تغییرات فرآیندهایی که مالکشان هستم،
 * بهبود داده‌ام، یا خودم اعمال کرده‌ام
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('user');
$db = app_db();

$uid = (int)$id['id'];
$st = $db->prepare(
    "SELECT ch.*, p.Name AS ProcName, cat.Title AS CatTitle, imp.Title AS ImpTitle,
            t.TeamName AS AppliedTeam
     FROM Changes ch
     JOIN Processes p ON p.ID = ch.ProcessID
     JOIN ProcessCategories cat ON cat.ID = p.CategoryID
     LEFT JOIN Improvements imp ON imp.ID = ch.ImprovementID
     LEFT JOIN ProcessTeams t ON t.ID = ch.AppliedByID AND ch.AppliedByType = 'team'
     WHERE (ch.AppliedByType='user' AND ch.AppliedByID = ?)
        OR p.OwnerPersonnelID = ?
        OR (imp.requester_type='user' AND imp.requester_id = ?)
     ORDER BY ch.CreatedAt DESC"
);
$st->bind_param('iii', $uid, $uid, $uid);
$st->execute();
$rows = [];
$res = $st->get_result();
while ($r = $res->fetch_assoc()) $rows[] = $r;
$st->close();

/* دلیل ارتباط هر ردیف */
function relation_reason(array $row, int $uid): string
{
    if (($row['AppliedByType'] ?? '') === 'user' && (int)$row['AppliedByID'] === $uid) return 'اعمال‌کننده';
    if (!empty($row['ImpTitle'])) return 'از بهبود شما';
    return 'مالک فرآیند';
}
?>
<?php user_header($id, 'changes', 'تغییرات مرتبط با من'); ?>

<div class="card">
    <h2>فهرست تغییرات (<?= farsi_num(count($rows)) ?>)</h2>
    <?php if (!$rows): ?>
        <div class="empty"><span class="ico"><?= app_icon('changes', '', 36) ?></span><p>تغییری مرتبط با حساب شما ثبت نشده است.<br>با مالکیت فرآیند یا ثبت بهبود، تغییرات اینجا نمایش داده می‌شوند.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>فرآیند</th><th>نوع تغییر</th><th>شرح</th><th>نسخه</th><th>وضعیت</th><th>تاریخ</th><th>ارتباط با شما</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="title" href="processes.php?id=<?= (int)$r['ProcessID'] ?>"><?= e($r['ProcName']) ?></a><br><span class="sub"><?= e($r['CatTitle']) ?></span></td>
                    <td><span class="badge badge-<?= e($r['ChangeType']) ?>"><?= status_label($r['ChangeType']) ?></span></td>
                    <td style="min-width:170px">
                        <?= e($r['Description'] ?: '—') ?>
                        <?php if (!empty($r['ImpTitle'])): ?><br><span class="sub">بهبود: «<?= e($r['ImpTitle']) ?>»</span><?php endif; ?>
                    </td>
                    <td><span class="num-ltr"><?= e($r['OldVersion'] ?? '—') ?> ← <?= e($r['NewVersion'] ?? '—') ?></span></td>
                    <td><span class="badge badge-<?= e($r['Status']) ?>"><?= status_label($r['Status']) ?></span></td>
                    <td><?= e(fmt_date_short($r['CreatedAt'])) ?></td>
                    <td><span class="badge badge-team"><?= e(relation_reason($r, $uid)) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
