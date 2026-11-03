<?php
/**
 * لیست بهبودها — پنل تیم: فیلتر وضعیت، تعیین تکلیف و پاسخ
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/hermes.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } else {
        $impId = (int)($_POST['imp_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'hermes_queue') {
            hermes_dispatch_async_eval(0);
            $notice = 'صف ارزیابی هوشمند هرمس در پس‌زمینه فعال شد. موارد معلق به زودی ارزیابی خواهند شد.';
        } else {
            // آیا این تیم مسئول فرآیند است؟
            $st = $db->prepare(
                'SELECT i.ID, i.Status AS ist, p.ResponsibleTeamID FROM Improvements i
                 JOIN Processes p ON p.ID = i.ProcessID WHERE i.ID = ?'
            );
            $st->bind_param('i', $impId); $st->execute();
            $imp = $st->get_result()->fetch_assoc(); $st->close();

            if (!$imp) {
                $error = 'بهبود مورد نظر یافت نشد.';
            } elseif ((int)$imp['ResponsibleTeamID'] !== $tid) {
                $error = 'فقط تیم مسئول فرآیند می‌تواند درباره بهبود آن تصمیم بگیرد.';
            } elseif ($action === 'set_status' && in_array($_POST['status'] ?? '', ['pending','accepted','rejected','implemented'], true)) {
                $newSt = (string)$_POST['status'];
                $st = $db->prepare('UPDATE Improvements SET Status = ? WHERE ID = ?');
                $st->bind_param('si', $newSt, $impId); $st->execute(); $st->close();
                $notice = 'وضعیت بهبود به «' . status_label($newSt) . '» تغییر کرد.';
            } elseif ($action === 'hermes_eval') {
                @set_time_limit(90);
                $res = hermes_evaluate_improvement($db, $impId);
                if ($res['success']) {
                    $vText = ($res['verdict'] === 'yes') ? 'تأیید داور هرمس (YES)' : 'عدم تأیید داور هرمس (NO)';
                    $notice = 'ارزیابی هرمس با موفقیت ثبت شد: رأی داور «' . $vText . '» است.';
                } else {
                    $error = 'خطا در ارزیابی هرمس: ' . ($res['error'] ?? 'پاسخی از سرویس دریافت نشد.');
                }
            } elseif ($action === 'reply') {
                $body = trim((string)($_POST['body'] ?? ''));
                if (mb_strlen($body) < 2) {
                    $error = 'متن پاسخ را وارد کنید.';
                } else {
                    $refType = 'improvement'; $senderType = 'team';
                    $st = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement',?,?,?,?)");
                    $st->bind_param('isis', $impId, $senderType, $tid, $body);
                    $st->execute(); $st->close();
                    $notice = 'پاسخ ثبت شد.';
                }
            }
        }
    }
}

$statusFilter = (string)($_GET['status'] ?? '');
$sql = "SELECT i.*, p.Name AS ProcName, p.ID AS Pid, p.ResponsibleTeamID, cat.Title AS CatTitle,
               u.Name AS ReqName, u.Family AS ReqFamily, pt.TeamName AS ReqTeamName,
               (SELECT COUNT(*) FROM Responses r WHERE r.RefType='improvement' AND r.RefID=i.ID) AS Replies
        FROM Improvements i
        JOIN Processes p ON p.ID = i.ProcessID
        JOIN ProcessCategories cat ON cat.ID = p.CategoryID
        LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type='user'
        LEFT JOIN ProcessTeams pt ON pt.ID = i.requester_id AND i.requester_type='team'";
$types = '';
$params = [];
if (in_array($statusFilter, ['pending','accepted','rejected','implemented'], true)) {
    $sql .= ' WHERE i.Status = ?';
    $types = 's'; $params[] = $statusFilter;
}
$sql .= ' ORDER BY i.CreatedAt DESC LIMIT 200';
$st = $db->prepare($sql);
if ($types) $st->bind_param($types, ...$params);
$st->execute();
$imps = []; $res = $st->get_result();
while ($r = $res->fetch_assoc()) $imps[] = $r;
$st->close();
$csrf = csrf_token();
$pendingAiCount = 0;
$stCount = $db->query("SELECT COUNT(*) AS c FROM Improvements WHERE AiVerdict = 'pending'");
if ($stCount) {
    $pendingAiCount = (int)($stCount->fetch_assoc()['c'] ?? 0);
}
?>
<?php team_header($id, 'improvements', 'لیست بهبودها'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <div class="chips" style="margin:0">
        <?php
        $filters = ['' => 'همه', 'pending' => 'در انتظار بررسی', 'accepted' => 'تأییدشده', 'rejected' => 'ردشده', 'implemented' => 'اجرا‌شده'];
        foreach ($filters as $k => $label): ?>
            <a class="chip <?= $statusFilter === $k ? 'active' : '' ?>" href="improvements.php<?= $k ? '?status=' . $k : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($pendingAiCount > 0): ?>
    <form method="post" class="inline-form" style="margin:0">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="hermes_queue">
        <button class="btn btn-sm btn-ghost" title="پردازش خودکار موارد در صف در پس‌زمینه" style="display:inline-flex;align-items:center;gap:6px">
            <?= app_icon('bolt', '', 14) ?>
            <span>پردازش صف هوش مصنوعی (<?= farsi_num($pendingAiCount) ?> در صف)</span>
        </button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <?php if (!$imps): ?>
        <div class="empty"><span class="ico"><?= app_icon('improvements', '', 36) ?></span><p>بهبودی در این وضعیت یافت نشد.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th style="min-width:200px">بهبود</th><th>فرآیند</th><th>داور هرمس (AI)</th><th>درخواست‌کننده</th><th>وضعیت</th><th>تاریخ</th><th style="min-width:240px">تعیین تکلیف / ارزیابی / پاسخ</th></tr></thead>
            <tbody>
            <?php foreach ($imps as $m):
                $mine = (int)$m['ResponsibleTeamID'] === $tid; ?>
                <tr>
                    <td>
                        <span class="title"><?= e($m['Title']) ?></span>
                        <?php if ($m['Description']): ?><br><span class="sub"><?= e(mb_strimwidth((string)$m['Description'], 0, 130, '…')) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <a href="file.php?id=<?= (int)$m['Pid'] ?>&tab=improvements"><?= e($m['ProcName']) ?></a>
                        <br><span class="sub"><?= e($m['CatTitle']) ?><?= $mine ? '' : ' · تیم دیگر' ?></span>
                    </td>
                    <td>
                        <?= hermes_badge($m['AiVerdict'] ?? 'pending') ?>
                        <br><a class="sub" href="discussion.php?imp=<?= (int)$m['ID'] ?>" style="font-size:11.5px;display:inline-flex;align-items:center;gap:4px"><?= app_icon('search', '', 12) ?><span>مشاهده تحلیل هرمس</span></a>
                    </td>
                    <td><?= e(trim((string)$m['ReqName'] . ' ' . (string)$m['ReqFamily']) ?: ((string)$m['ReqTeamName'] ?: '—')) ?></td>
                    <td><span class="badge badge-<?= e($m['Status']) ?>"><?= status_label($m['Status']) ?></span></td>
                    <td>
                        <?= e(fmt_date_short($m['CreatedAt'])) ?>
                        <br><a class="sub" href="discussion.php?imp=<?= (int)$m['ID'] ?>" style="display:inline-flex;align-items:center;gap:4px"><?= app_icon('discussion', '', 12) ?><span><?= farsi_num((int)$m['Replies']) ?> پاسخ</span></a>
                    </td>
                    <td>
                        <?php if ($mine): ?>
                        <div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
                            <form method="post" class="inline-form" style="margin:0">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="imp_id" value="<?= (int)$m['ID'] ?>">
                                <select name="status" style="padding:5px 8px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:12.5px">
                                    <?php foreach (['pending','accepted','rejected','implemented'] as $sOpt): ?>
                                        <option value="<?= $sOpt ?>" <?= $m['Status'] === $sOpt ? 'selected' : '' ?>><?= status_label($sOpt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-xs">ثبت</button>
                            </form>
                            <form method="post" class="inline-form" style="margin:0">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="hermes_eval">
                                <input type="hidden" name="imp_id" value="<?= (int)$m['ID'] ?>">
                                <button class="btn btn-ghost btn-xs" title="ارزیابی تعارضات و پارادوکس‌ها با هوش مصنوعی هرمس" style="display:inline-flex;align-items:center;gap:5px">
                                    <?= app_icon('bot', '', 13) ?>
                                    <span><?= (!empty($m['AiVerdict']) && $m['AiVerdict'] !== 'pending') ? 'ارزیابی مجدد' : 'ارزیابی با هرمس' ?></span>
                                </button>
                            </form>
                            <?php if ($m['Status'] !== 'implemented'): ?>
                                <a class="btn btn-brand btn-xs" href="revision.php?imp=<?= (int)$m['ID'] ?>" title="اعمال بهبود و بازنویسی سند با هرمس" style="display:inline-flex;align-items:center;gap:4px">
                                    <?= app_icon('bot', '', 12) ?>
                                    <span>بازنویسی سند</span>
                                </a>
                            <?php else: ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;font-size:11px;padding:3px 8px;border-radius:10px">اجرا و مستند شد</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="reply">
                            <input type="hidden" name="imp_id" value="<?= (int)$m['ID'] ?>">
                            <input type="text" name="body" maxlength="1000" placeholder="پاسخ سریع…" required
                                   style="flex:1;min-width:130px;padding:6px 10px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:12.5px">
                            <button class="btn btn-ghost btn-xs" title="ارسال پاسخ"><?= app_icon('reply', '', 13) ?></button>
                        </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
