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

<div class="stat-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 320px)); justify-content: start;">
    <a class="stat" href="processes.php">
        <span class="ico"><?= app_icon('processes', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($myProcesses) ?></span><br><span class="lbl">فرآیندهای مسئول تیم</span></span>
    </a>
    <a class="stat" href="improvements.php">
        <span class="ico"><?= app_icon('changes', '', 26) ?></span>
        <span><span class="num"><?= farsi_num($pending) ?></span><br><span class="lbl">بهبود در انتظار بررسی</span></span>
    </a>
</div>

<div class="card">
    <h2>درخواست‌های بهبود فرآیندهای تیم</h2>
    <?php if (!$improvements): ?>
        <div class="empty"><span class="ico"><?= app_icon('improvements', '', 36) ?></span><p>هنوز درخواست بهبودی برای فرآیندهای این تیم ثبت نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th>بهبود</th>
                    <th>فرآیند</th>
                    <th>درخواست‌کننده</th>
                    <th>وضعیت</th>
                    <th>تاریخ</th>
                    <th style="width: 140px; text-align: center; white-space: nowrap;">عملیات</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($improvements as $m): ?>
                <?php
                $cleanReply = preg_replace('/[#*`|_-]+/', ' ', (string)$m['LastReply']);
                $cleanReply = trim(preg_replace('/\s+/', ' ', $cleanReply));
                ?>
                <tr>
                    <td>
                        <a href="discussion.php?imp=<?= (int)$m['ID'] ?>" class="title" style="color:var(--text);font-weight:600;text-decoration:none">
                            <?= e($m['Title']) ?>
                        </a>
                        <?php if ($cleanReply): ?>
                            <br><span class="sub">آخرین پاسخ: <?= e(mb_strimwidth($cleanReply, 0, 75, '…')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($m['ProcName']) ?></td>
                    <td><?= e(trim((string)$m['ReqName'] . ' ' . (string)$m['ReqFamily']) ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($m['Status']) ?>"><?= status_label($m['Status']) ?></span></td>
                    <td><?= e(fmt_date_short($m['CreatedAt'])) ?></td>
                    <td style="text-align: center; white-space: nowrap;">
                        <a class="btn btn-sm btn-brand" href="discussion.php?imp=<?= (int)$m['ID'] ?>" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:6px 14px;font-size:12.5px;white-space:nowrap">
                            <?= app_icon('discussion', '', 14) ?>
                            <span>گفتگو / بررسی</span>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="alert alert-info" style="display:flex;align-items:center;gap:10px;margin-top:16px">
    <?= app_icon('info', '', 18) ?>
    <span>جهت مدیریت جامع فرآیندها، تدوین فرآیند جدید با هرمس، و بررسی پیشنهادات به بخش‌های «لیست فرآیندها» و «لیست بهبودها» مراجعه فرمایید.</span>
</div>

<?php app_footer(); ?>
