<?php
/**
 * گفتگو در فرآیندها — رشته‌های گفتگو حول بهبودها (Responses)
 * فهرست: ?imp= ندارد | نمای رشته: discussion.php?imp=ID
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/hermes.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$notice = '';
$error = '';

/* پاسخ جدید یا ارزیابی با هرمس */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } else {
        $impId = (int)($_POST['imp_id'] ?? 0);
        $action = (string)($_POST['action'] ?? 'reply');

        // بررسی اینکه آیا این تیم مسئول فرآیند است
        $st = $db->prepare('SELECT p.ResponsibleTeamID FROM Improvements i JOIN Processes p ON p.ID = i.ProcessID WHERE i.ID = ?');
        $st->bind_param('i', $impId);
        $st->execute();
        $permRow = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$permRow) {
            $error = 'بهبود مورد نظر یافت نشد.';
        } elseif ((int)$permRow['ResponsibleTeamID'] !== $tid) {
            $error = 'فقط تیم مسئول فرآیند اجازه پاسخگویی یا ارزیابی هرمس را دارد.';
        } elseif ($action === 'hermes_eval') {
            @set_time_limit(90);
            $res = hermes_evaluate_improvement($db, $impId);
            if ($res['success']) {
                $notice = 'ارزیابی هرمس با موفقیت به‌روزرسانی شد: رأی داور «' . ($res['verdict'] === 'yes' ? 'تأیید (YES)' : 'عدم تأیید (NO)') . '» است.';
            } else {
                $error = 'خطا در ارزیابی هرمس: ' . ($res['error'] ?? 'خطا');
            }
        } else {
            $body = trim((string)($_POST['body'] ?? ''));
            if (mb_strlen($body) < 2) $error = 'متن پیام را وارد کنید.';
            else {
                $senderType = 'team';
                $st = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement',?,?,?,?)");
                $st->bind_param('isis', $impId, $senderType, $tid, $body);
                $st->execute(); $st->close();
                header('Location: discussion.php?imp=' . $impId);
                exit;
            }
        }
    }
}

/* ---------- نمای رشته ---------- */
$impId = (int)($_GET['imp'] ?? 0);
if ($impId > 0) {
    $st = $db->prepare(
        "SELECT i.*, p.Name AS ProcName, p.ID AS Pid, p.ResponsibleTeamID, cat.Title AS CatTitle,
                u.Name AS ReqName, u.Family AS ReqFamily, pt.TeamName AS RespTeamName
         FROM Improvements i
         JOIN Processes p ON p.ID = i.ProcessID
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN ProcessTeams pt ON pt.ID = p.ResponsibleTeamID
         LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type='user'
         WHERE i.ID = ?"
    );
    $st->bind_param('i', $impId); $st->execute();
    $imp = $st->get_result()->fetch_assoc(); $st->close();

    if (!$imp) {
        team_header($id, 'discussion', 'رشته یافت نشد');
        echo '<div class="card"><div class="empty"><span class="ico">' . app_icon('search', '', 36) . '</span><p>این گفتگو وجود ندارد.</p></div></div>';
        app_footer(); exit;
    }

    $isOwnerTeam = ((int)$imp['ResponsibleTeamID'] === $tid);

    $st = $db->prepare(
        "SELECT r.*, u.Name, u.Family, t.TeamName
         FROM Responses r
         LEFT JOIN Personnel u ON u.ID = r.SenderID AND r.SenderType='user'
         LEFT JOIN ProcessTeams t ON t.ID = r.SenderID AND r.SenderType='team'
         WHERE r.RefType='improvement' AND r.RefID = ? ORDER BY r.ID"
    );
    $st->bind_param('i', $impId); $st->execute();
    $msgs = []; $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $msgs[] = $r;
    $st->close();
    ?>
    <?php team_header($id, 'discussion', 'گفتگو: ' . $imp['Title']); ?>
    <div class="crumbs"><a href="discussion.php">گفتگو در فرآیندها</a> ← <?= e($imp['Title']) ?></div>
    <?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="page-head" style="margin-bottom:6px">
            <h2 style="margin:0"><?= e($imp['Title']) ?> <span class="badge badge-<?= e($imp['Status']) ?>"><?= status_label($imp['Status']) ?></span></h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?php if ($isOwnerTeam && $imp['Status'] !== 'implemented'): ?>
                    <a class="btn btn-brand btn-sm" href="revision.php?imp=<?= $impId ?>" style="display:inline-flex;align-items:center;gap:6px">
                        <?= app_icon('bot', '', 15) ?>
                        <span>اعمال بهبود و بازنویسی سند با هرمس</span>
                    </a>
                <?php elseif ($imp['Status'] === 'implemented'): ?>
                    <span class="badge" style="background:#dcfce7;color:#166534;font-size:12px;padding:6px 12px;border-radius:14px;display:inline-flex;align-items:center;gap:5px">
                        <?= app_icon('check', '', 13) ?>
                        <span>بهبود اعمال و مستند به‌روز شد</span>
                    </span>
                <?php endif; ?>
                <a class="btn btn-ghost btn-sm" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=improvements" style="display:inline-flex;align-items:center;gap:6px">
                    <?= app_icon('files', '', 15) ?>
                    <span>پرونده «<?= e($imp['ProcName']) ?>»</span>
                </a>
            </div>
        </div>
        <p class="sub" style="color:var(--muted);font-size:12.5px">
            درخواست <?= e(trim((string)$imp['ReqName'] . ' ' . (string)$imp['ReqFamily']) ?: '—') ?> · <?= e(fmt_date($imp['CreatedAt'])) ?>
        </p>
        <?php if (!$isOwnerTeam): ?>
            <div class="alert alert-info">تیم مسئول این فرآیند «<?= e($imp['RespTeamName'] ?? '—') ?>» است؛ نمایش فقط‌خواندنی.</div>
        <?php endif; ?>
        <?php if ($imp['Description']): ?><p style="background:var(--brand-soft);border-radius:12px;padding:12px 16px;font-size:13.5px"><?= nl2br(e($imp['Description'])) ?></p><?php endif; ?>

        <?= hermes_render_card($imp['AiVerdict'] ?? 'pending', $imp['AiAnalysis'] ?? null, $imp['AiEvaluatedAt'] ?? null, $impId, $isOwnerTeam) ?>

        <div class="thread">
            <?php if (!$msgs): ?><div class="empty"><span class="ico"><?= app_icon('discussion', '', 36) ?></span><p>هنوز پیامی در این گفتگو ثبت نشده است.</p></div><?php endif; ?>
            <?php foreach ($msgs as $m):
                $isTeam = $m['SenderType'] === 'team';
                $who = $isTeam ? ($m['TeamName'] ?? 'تیم فرآیند') : trim((string)$m['Name'] . ' ' . (string)$m['Family']); ?>
                <div class="msg <?= $isTeam ? 'from-team' : 'from-user' ?>">
                    <div class="m-head">
                        <span style="display:inline-flex;align-items:center;gap:6px">
                            <?= $isTeam ? app_icon('team', '', 14) : app_icon('users', '', 14) ?>
                            <b><?= e($who) ?></b>
                        </span>
                        <span><?= e(fmt_date($m['CreatedAt'])) ?></span>
                    </div>
                    <p class="m-body"><?= e($m['Body']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($isOwnerTeam): ?>
        <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="imp_id" value="<?= $impId ?>">
            <label class="field" style="flex:1;min-width:240px;margin:0">
                <span class="field-label">پاسخ تیم</span>
                <textarea name="body" required maxlength="2000" rows="2" placeholder="پاسخ خود را بنویسید…"></textarea>
            </label>
            <button class="btn">ارسال پاسخ</button>
        </form>
        <?php endif; ?>
    </div>
    <?php app_footer(); exit;
}

/* ---------- فهرست رشته‌ها ---------- */
$res = $db->query(
    "SELECT i.ID, i.Title, i.Status, i.CreatedAt, i.AiVerdict, p.Name AS ProcName,
            u.Name AS ReqName, u.Family AS ReqFamily,
            (SELECT COUNT(*) FROM Responses r WHERE r.RefType='improvement' AND r.RefID=i.ID) AS Replies
     FROM Improvements i
     JOIN Processes p ON p.ID = i.ProcessID
     LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type='user'
     ORDER BY (SELECT MAX(r2.ID) FROM Responses r2 WHERE r2.RefType='improvement' AND r2.RefID=i.ID) DESC, i.ID DESC
     LIMIT 100"
);
$threads = [];
while ($r = $res->fetch_assoc()) $threads[] = $r;
?>
<?php team_header($id, 'discussion', 'گفتگو در فرآیندها'); ?>

<div class="card">
    <h2>رشته‌های گفتگو (<?= count($threads) ?>)</h2>
    <?php if (!$threads): ?>
        <div class="empty"><span class="ico">💬</span><p>هنوز گفتگویی ثبت نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>موضوع</th><th>فرآیند</th><th>داور هرمس (AI)</th><th>شروع‌کننده</th><th>وضعیت بهبود</th><th>پاسخ‌ها</th><th>تاریخ</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($threads as $t): ?>
                <tr>
                    <td><span class="title"><?= e($t['Title']) ?></span></td>
                    <td><?= e($t['ProcName']) ?></td>
                    <td><?= hermes_badge($t['AiVerdict'] ?? 'pending') ?></td>
                    <td><?= e(trim((string)$t['ReqName'] . ' ' . (string)$t['ReqFamily']) ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($t['Status']) ?>"><?= status_label($t['Status']) ?></span></td>
                    <td><?= (int)$t['Replies'] ?></td>
                    <td><?= e(fmt_date_short($t['CreatedAt'])) ?></td>
                    <td><a class="btn btn-ghost btn-xs" href="discussion.php?imp=<?= (int)$t['ID'] ?>">ورود به گفتگو</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
