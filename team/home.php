<?php
/**
 * خانه تیم فرآیند — آمار و کارهای در انتظار بررسی
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$st = $db->prepare("SELECT COUNT(*) c FROM Processes WHERE ResponsibleTeamID = ? AND Status='active'");
$st->bind_param('i', $tid); $st->execute();
$myProcesses = (int)$st->get_result()->fetch_assoc()['c']; $st->close();

$st = $db->prepare(
    "SELECT COUNT(*) c FROM Improvements i
     JOIN Processes p ON p.ID = i.ProcessID
     WHERE p.ResponsibleTeamID = ? AND i.Status = 'pending'"
);
$st->bind_param('i', $tid); $st->execute();
$pending = (int)$st->get_result()->fetch_assoc()['c']; $st->close();

$st = $db->prepare(
    "SELECT i.ID, i.Title, i.Status, i.CreatedAt, p.Name AS ProcName,
            u.Name AS ReqName, u.Family AS ReqFamily,
            (SELECT r.Body FROM Responses r WHERE r.RefType='improvement' AND r.RefID=i.ID ORDER BY r.ID DESC LIMIT 1) AS LastReply
     FROM Improvements i
     JOIN Processes p ON p.ID = i.ProcessID
     LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type = 'user'
     WHERE p.ResponsibleTeamID = ?
     ORDER BY i.CreatedAt DESC LIMIT 15"
);
$st->bind_param('i', $tid); $st->execute();
$improvements = [];
$res = $st->get_result();
while ($r = $res->fetch_assoc()) $improvements[] = $r;
$st->close();
?>
<?php team_header($id, 'home', 'پنل تیم فرآیند'); ?>

<div class="stat-grid">
    <div class="stat">
        <span class="ico"><?= app_icon('processes', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($myProcesses) ?></span><br><span class="lbl">فرآیندهای مسئول تیم</span></span>
    </div>
    <div class="stat">
        <span class="ico"><?= app_icon('changes', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($pending) ?></span><br><span class="lbl">بهبود در انتظار بررسی</span></span>
    </div>
</div>

<div class="card">
    <h2>درخواست‌های بهبود فرآیندهای تیم</h2>
    <?php if (!$improvements): ?>
        <div class="empty"><span class="ico"><?= app_icon('improvements', '', 36) ?></span><p>هنوز درخواست بهبودی برای فرآیندهای این تیم ثبت نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>بهبود</th><th>فرآیند</th><th>درخواست‌کننده</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php foreach ($improvements as $m): ?>
                <tr>
                    <td><span class="title"><?= e($m['Title']) ?></span><?php if ($m['LastReply']): ?><br><span class="sub">آخرین پاسخ: <?= e(mb_strimwidth((string)$m['LastReply'], 0, 80, '…')) ?></span><?php endif; ?></td>
                    <td><?= e($m['ProcName']) ?></td>
                    <td><?= e(trim((string)$m['ReqName'] . ' ' . (string)$m['ReqFamily']) ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($m['Status']) ?>"><?= status_label($m['Status']) ?></span></td>
                    <td><?= e(fmt_date_short($m['CreatedAt'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="alert alert-info">بخش‌های رسیدگی به بهبودها، ثبت تغییرات و اطلاع‌رسانی در فازهای بعدی همین پنل فعال می‌شوند.</div>

<?php app_footer(); ?>
