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

$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'ok') {
        $notice = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

/* پاسخ جدید یا ارزیابی با هرمس */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $impId = (int)($_POST['imp_id'] ?? 0);
    $redirectUrl = 'discussion.php' . ($impId > 0 ? '?imp=' . $impId : '');

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'اعتبار صفحه منقضی شده است.');
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        $action = (string)($_POST['action'] ?? 'reply');

        // بررسی اینکه آیا این تیم مسئول فرآیند است
        $st = $db->prepare('SELECT p.ResponsibleTeamID FROM Improvements i JOIN Processes p ON p.ID = i.ProcessID WHERE i.ID = ?');
        $st->bind_param('i', $impId);
        $st->execute();
        $permRow = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$permRow) {
            flash_set('error', 'بهبود مورد نظر یافت نشد.');
            header('Location: discussion.php');
            exit;
        } elseif ((int)$permRow['ResponsibleTeamID'] !== $tid) {
            flash_set('error', 'فقط تیم مسئول فرآیند اجازه پاسخگویی یا ارزیابی هرمس را دارد.');
            header('Location: ' . $redirectUrl);
            exit;
        } elseif ($action === 'retry_hermes') {
            $st = $db->prepare(
                "UPDATE Improvements 
                 SET AiVerdict = 'pending', 
                     AiAnalysis = NULL, 
                     AiEvaluatedAt = NULL, 
                     CreatedAt = NOW(), 
                     UpdatedAt = NOW() 
                 WHERE ID = ?"
            );
            $st->bind_param('i', $impId);
            $st->execute();
            $st->close();

            hermes_dispatch_async_eval($impId);
            flash_set('ok', 'ارزیابی مجدد هرمس برای این پیشنهاد ارسال شد.');
            header('Location: ' . $redirectUrl);
            exit;
        } elseif ($action === 'hermes_eval') {
            @set_time_limit(90);
            $res = hermes_evaluate_improvement($db, $impId);
            if ($res['success']) {
                flash_set('ok', 'ارزیابی هرمس با موفقیت به‌روزرسانی شد: رأی داور «' . ($res['verdict'] === 'yes' ? 'تأیید (YES)' : 'عدم تأیید (NO)') . '» است.');
            } else {
                flash_set('error', 'خطا در ارزیابی هرمس: ' . ($res['error'] ?? 'خطا'));
            }
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $body = trim((string)($_POST['body'] ?? ''));
            if (mb_strlen($body) < 2) {
                flash_set('error', 'متن پیام را وارد کنید.');
            } else {
                $senderType = 'team';
                $st = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement',?,?,?,?)");
                $st->bind_param('isis', $impId, $senderType, $tid, $body);
                $st->execute(); $st->close();

                $teamLabel = !empty($id['name']) ? $id['name'] : 'تیم فرآیند';

                // ۱. ارسال اعلان درون‌برنامه‌ای و بله به کاربر پیشنهاددهنده
                discussion_dispatch_notifications($db, $impId, 'team', $tid, $body, $teamLabel);

                // ۲. بررسی منشن شدن هرمس (@Hermes یا @هرمس) - اجرای کاملاً غیرهمگام (Async)
                if (preg_match('/@(?:hermes|هرمس)/iu', $body)) {
                    hermes_dispatch_async_discussion_mention($db, $impId, $body, $teamLabel, 'team', $tid);
                    flash_set('ok', 'پاسخ تیم ثبت شد. هرمس در حال پردازش و نگارش پاسخ در پس‌زمینه است.');
                } else {
                    flash_set('ok', 'پاسخ شما با موفقیت ثبت شد.');
                }
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}

/* ---------- نمای رشته ---------- */
$impId = (int)($_GET['imp'] ?? 0);
if ($impId > 0) {
    /* ---------- بررسی وضعیت تسک هرمس از طریق AJAX ---------- */
    if (isset($_GET['poll_task_status']) && isset($_GET['task_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        $checkTaskId = (int)$_GET['task_id'];
        $stT = $db->prepare("SELECT Status FROM HermesTasks WHERE ID = ? AND RefID = ?");
        $stT->bind_param('ii', $checkTaskId, $impId);
        $stT->execute();
        $tRow = $stT->get_result()->fetch_assoc();
        $stT->close();
        $tStatus = $tRow['Status'] ?? 'none';
        echo json_encode([
            'status' => $tStatus,
            'completed' => ($tStatus === 'completed'),
            'failed' => ($tStatus === 'failed')
        ]);
        exit;
    }

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

    // بررسی اینکه آیا تسک بازنگری در پس‌زمینه در حال اجرا است
    $stTask = $db->prepare("SELECT Status FROM HermesTasks WHERE TaskType = 'revision' AND RefID = ? ORDER BY ID DESC LIMIT 1");
    $stTask->bind_param('i', $impId);
    $stTask->execute();
    $taskRow = $stTask->get_result()->fetch_assoc();
    $stTask->close();
    $isRevising = ($taskRow && in_array($taskRow['Status'], ['queued', 'running'], true));

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

    $stMention = $db->prepare("SELECT ID, Status FROM HermesTasks WHERE TaskType = 'discussion_mention' AND RefID = ? AND Status IN ('queued', 'running', 'pending') ORDER BY ID DESC LIMIT 1");
    $stMention->bind_param('i', $impId);
    $stMention->execute();
    $activeHermesTask = $stMention->get_result()->fetch_assoc();
    $stMention->close();
    ?>
    <?php team_header($id, 'discussion', 'گفتگو: ' . $imp['Title']); ?>
    <div class="crumbs"><a href="discussion.php">گفتگو در فرآیندها</a> ← <?= e($imp['Title']) ?></div>
    <?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="page-head" style="margin-bottom:6px">
            <h2 style="margin:0"><?= e($imp['Title']) ?> <span class="badge badge-<?= e($imp['Status']) ?>"><?= status_label($imp['Status']) ?></span></h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?php if ($isRevising): ?>
                    <a class="btn btn-sm" href="revision.php?imp=<?= $impId ?>" style="background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;display:inline-flex;align-items:center;gap:6px">
                        <?= app_icon('refresh', 'ai-spin', 14) ?>
                        <span>هرمس در حال بازنویسی در پس‌زمینه... (مشاهده وضعیت)</span>
                    </a>
                <?php elseif ($isOwnerTeam && $imp['Status'] !== 'implemented'): ?>
                    <a class="btn btn-brand btn-sm" href="revision.php?imp=<?= $impId ?>&action=start_async" style="display:inline-flex;align-items:center;gap:6px">
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

        <?= hermes_render_card($imp['AiVerdict'] ?? 'pending', $imp['AiAnalysis'] ?? null, $imp['AiEvaluatedAt'] ?? null, $impId, $isOwnerTeam, $imp['CreatedAt'] ?? null) ?>

        <div class="thread">
            <?php if (!$msgs): ?><div class="empty"><span class="ico"><?= app_icon('discussion', '', 36) ?></span><p>هنوز پیامی در این گفتگو ثبت نشده است.</p></div><?php endif; ?>
            <?php foreach ($msgs as $m):
                $isHermes = in_array($m['SenderType'], ['hermes', 'ai'], true);
                $isTeam = $m['SenderType'] === 'team';
                if ($isHermes) {
                    $who = 'داور هوش مصنوعی هرمس (Hermes AI)';
                    $msgClass = 'from-hermes';
                    $iconHtml = app_icon('hermes', 'chat-avatar-img', 18);
                } elseif ($isTeam) {
                    $who = $m['TeamName'] ?? 'تیم فرآیند';
                    $msgClass = 'from-team';
                    $iconHtml = app_icon('team', '', 14);
                } else {
                    $who = trim((string)$m['Name'] . ' ' . (string)$m['Family']) ?: 'کاربر پیشنهاددهنده';
                    $msgClass = 'from-user';
                    $iconHtml = app_icon('users', '', 14);
                }
                $bodyHtml = preg_replace('/(@(?:hermes|هرمس))/iu', '<span class="mention-tag">$1</span>', nl2br(e($m['Body'])));
            ?>
                <div class="msg <?= $msgClass ?>">
                    <div class="m-head">
                        <span style="display:inline-flex;align-items:center;gap:6px">
                            <?= $iconHtml ?>
                            <b><?= e($who) ?></b>
                        </span>
                        <span><?= e(fmt_date($m['CreatedAt'])) ?></span>
                    </div>
                    <div class="m-body"><?= $bodyHtml ?></div>
                </div>
            <?php endforeach; ?>

            <?php if ($activeHermesTask): ?>
                <div id="hermesThinkingBox" class="msg from-hermes thinking-pulse" data-task-id="<?= (int)$activeHermesTask['ID'] ?>">
                    <div class="m-head">
                        <span style="display:inline-flex;align-items:center;gap:6px">
                            <?= app_icon('hermes', 'chat-avatar-img', 18) ?>
                            <b>داور هوش مصنوعی هرمس (Hermes AI)</b>
                        </span>
                        <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11px">در حال پردازش...</span>
                    </div>
                    <div class="m-body" style="display:flex;align-items:center;gap:10px;padding:8px 0">
                        <div class="typing-dots"><span></span><span></span><span></span></div>
                        <span style="color:var(--text);font-weight:600">هرمس در حال نگارش پاسخ تخصصی به گفتگو است…</span>
                    </div>
                    <small style="color:var(--muted);font-size:11.5px;display:block;margin-top:2px">
                        می‌توانید آزادانه این صفحه را ترک کرده و به کارهای دیگر برسید؛ پاسخ به محض آماده شدن در گفتگو درج خواهد شد.
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isOwnerTeam): ?>
        <form method="post" style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="imp_id" value="<?= $impId ?>">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                <span class="field-label" style="margin:0;font-weight:700">پاسخ تیم فرآیند:</span>
                <button type="button" class="btn-mention-hermes" onclick="insertHermesMention('replyBodyTeam')">
                    <?= app_icon('bot', '', 14) ?>
                    <span>پرسش از هرمس (@Hermes)</span>
                </button>
            </div>
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
                <label class="field" style="flex:1;min-width:240px;margin:0">
                    <textarea id="replyBodyTeam" name="body" required maxlength="2000" rows="2" placeholder="پاسخ تیم را بنویسید… (برای استعلام و مشورت با هوش مصنوعی هرمس، از @Hermes استفاده کنید)"></textarea>
                </label>
                <button class="btn" style="height:44px">ارسال پاسخ</button>
            </div>
        </form>
        <script>
        function insertHermesMention(textareaId) {
            const el = document.getElementById(textareaId);
            if (!el) return;
            const tag = '@Hermes ';
            if (!el.value.includes('@Hermes') && !el.value.includes('@هرمس')) {
                el.value = tag + el.value;
            }
            el.focus();
        }

        (function() {
            const thinkingBox = document.getElementById('hermesThinkingBox');
            if (thinkingBox) {
                const taskId = thinkingBox.getAttribute('data-task-id');
                const pollTimer = setInterval(() => {
                    fetch('discussion.php?imp=<?= $impId ?>&poll_task_status=1&task_id=' + encodeURIComponent(taskId))
                        .then(r => r.json())
                        .then(data => {
                            if (data && (data.completed || data.failed)) {
                                clearInterval(pollTimer);
                                window.location.reload();
                            }
                        })
                        .catch(() => {});
                }, 2500);
            }
        })();
        </script>
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
                    <td><?= hermes_badge($t['AiVerdict'] ?? 'pending', $t['CreatedAt'] ?? null, (int)$t['ID'], false) ?></td>
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
