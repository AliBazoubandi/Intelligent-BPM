<?php
/**
 * گفتگو در فرآیندها — پنل کاربر
 * مشاهده پیام‌ها و ارسال پاسخ در مورد یک پیشنهاد بهبود
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/hermes.php';
$id = require_area('user');
$db = app_db();
$uid = (int)$id['id'];

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

/* ارسال پاسخ جدید */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $impId = (int)($_POST['imp_id'] ?? 0);
    $redirectUrl = 'discussion.php' . ($impId > 0 ? '?imp=' . $impId : '');

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'اعتبار صفحه منقضی شده است؛ دوباره تلاش کنید.');
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        $action = (string)($_POST['action'] ?? 'reply');
        $body = trim((string)($_POST['body'] ?? ''));

        // بررسی اینکه بهبود متعلق به این کاربر باشد
        $st = $db->prepare("SELECT ID FROM Improvements WHERE ID = ? AND requester_type = 'user' AND requester_id = ?");
        $st->bind_param('ii', $impId, $uid);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_assoc();
        $st->close();

        if (!$ok) {
            flash_set('error', 'بهبود مورد نظر یافت نشد یا دسترسی به آن ندارید.');
            header('Location: improvements.php');
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
            flash_set('ok', 'درخواست ارزیابی مجدد هرمس با موفقیت ارسال شد.');
            header('Location: discussion.php?imp=' . $impId);
            exit;
        } elseif (mb_strlen($body) < 2) {
            flash_set('error', 'متن پیام را وارد کنید.');
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $senderType = 'user';
            $st = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement',?,?,?,?)");
            $st->bind_param('isis', $impId, $senderType, $uid, $body);
            $st->execute();
            $st->close();

            $userName = trim((string)($id['name'] ?? 'کاربر'));

            // ۱. ارسال اعلان درون‌برنامه‌ای و بله به تیم مسئول فرآیند
            discussion_dispatch_notifications($db, $impId, 'user', $uid, $body, $userName);

            // ۲. بررسی منشن شدن هرمس (@Hermes یا @هرمس) - اجرای کاملاً غیرهمگام (Async)
            if (preg_match('/@(?:hermes|هرمس)/iu', $body)) {
                hermes_dispatch_async_discussion_mention($db, $impId, $body, $userName, 'user', $uid);
                flash_set('ok', 'پیام شما ثبت شد. هرمس در حال پردازش و نگارش پاسخ در پس‌زمینه است.');
            } else {
                flash_set('ok', 'پاسخ شما با موفقیت ثبت شد.');
            }

            header('Location: discussion.php?imp=' . $impId);
            exit;
        }
    }
}

$impId = (int)($_GET['imp'] ?? 0);

/* ---------- ۱. اگر پارامتر imp مشخص شده بود: نمای رشته گفتگو ---------- */
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
        "SELECT i.*, p.Name AS ProcName, p.ID AS Pid, cat.Title AS CatTitle
         FROM Improvements i
         JOIN Processes p ON p.ID = i.ProcessID
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         WHERE i.ID = ? AND i.requester_type = 'user' AND i.requester_id = ?"
    );
    $st->bind_param('ii', $impId, $uid);
    $st->execute();
    $imp = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$imp) {
        user_header($id, 'discussion', 'گفتگو یافت نشد');
        echo '<div class="crumbs"><a href="discussion.php">گفتگو در فرآیندها</a> ← خطا</div>';
        echo '<div class="card"><div class="empty"><span class="ico">' . app_icon('search', '', 36) . '</span><p>این گفتگو وجود ندارد یا متعلق به شما نیست.</p><p><a href="discussion.php" class="btn btn-brand btn-sm">بازگشت به لیست گفتگوها</a></p></div></div>';
        app_footer();
        exit;
    }

    $st = $db->prepare(
        "SELECT r.*, u.Name, u.Family, t.TeamName
         FROM Responses r
         LEFT JOIN Personnel u ON u.ID = r.SenderID AND r.SenderType='user'
         LEFT JOIN ProcessTeams t ON t.ID = r.SenderID AND r.SenderType='team'
         WHERE r.RefType='improvement' AND r.RefID = ? ORDER BY r.ID"
    );
    $st->bind_param('i', $impId);
    $st->execute();
    $msgs = [];
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $msgs[] = $r;
    $st->close();

    $activeTaskSt = $db->prepare("SELECT ID, Status FROM HermesTasks WHERE RefID = ? AND TaskType = 'discussion_mention' AND Status IN ('queued', 'running', 'pending') ORDER BY ID DESC LIMIT 1");
    $activeTaskSt->bind_param('i', $impId);
    $activeTaskSt->execute();
    $activeHermesTask = $activeTaskSt->get_result()->fetch_assoc();
    $activeTaskSt->close();
    ?>
    <?php user_header($id, 'discussion', 'گفتگو: ' . $imp['Title']); ?>

    <div class="crumbs">
        <a href="discussion.php">گفتگو در فرآیندها</a> ← <?= e($imp['Title']) ?>
    </div>

    <?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <div class="card">
        <div class="page-head" style="margin-bottom:6px">
            <h2 style="margin:0"><?= e($imp['Title']) ?> <span class="badge badge-<?= e($imp['Status']) ?>"><?= status_label($imp['Status']) ?></span></h2>
            <a class="btn btn-ghost btn-sm" href="processes.php?id=<?= (int)$imp['Pid'] ?>" style="display:inline-flex;align-items:center;gap:6px">
                <?= app_icon('processes', '', 15) ?>
                <span>مشاهده پرونده فرآیند</span>
            </a>
        </div>
        <p class="sub" style="color:var(--muted);font-size:12.5px">
            فرآیند: <b><?= e($imp['ProcName']) ?></b> (دسته: <?= e($imp['CatTitle']) ?>) · تاریخ ثبت: <?= e(fmt_date($imp['CreatedAt'])) ?>
        </p>
        <?php if ($imp['Description']): ?>
            <p style="background:var(--brand-soft);border-radius:12px;padding:12px 16px;font-size:13.5px"><?= nl2br(e($imp['Description'])) ?></p>
        <?php endif; ?>

        <?= hermes_render_card($imp['AiVerdict'] ?? 'pending', $imp['AiAnalysis'] ?? null, $imp['AiEvaluatedAt'] ?? null, $impId, true, $imp['CreatedAt'] ?? null) ?>

        <div class="thread">
            <?php if (!$msgs): ?>
                <div class="empty"><span class="ico"><?= app_icon('discussion', '', 36) ?></span><p>هنوز پاسخی برای این بهبود ثبت نشده است.</p></div>
            <?php endif; ?>
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
                    $who = 'شما (کاربر)';
                    $msgClass = 'from-user';
                    $iconHtml = app_icon('users', '', 14);
                }
                // هایلایت کردن منشن‌های @Hermes یا @هرمس
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

        <form method="post" style="display:flex;flex-direction:column;gap:8px;margin-top:16px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="imp_id" value="<?= $impId ?>">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
                <span class="field-label" style="margin:0;font-weight:700">پیام شما در گفتگو:</span>
                <button type="button" class="btn-mention-hermes" onclick="insertHermesMention('replyBodyUser')">
                    <?= app_icon('bot', '', 14) ?>
                    <span>پرسش از هرمس (@Hermes)</span>
                </button>
            </div>
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
                <label class="field" style="flex:1;min-width:240px;margin:0">
                    <textarea id="replyBodyUser" name="body" required maxlength="2000" rows="2" placeholder="پاسخ خود را بنویسید… (برای پرسش از هوش مصنوعی هرمس درباره رأی داوری یا فرآیند، از @Hermes استفاده کنید)"></textarea>
                </label>
                <button class="btn" style="height:44px">ارسال پیام</button>
            </div>
        </form>
    </div>

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

    <?php 
    app_footer(); 
    exit;
}

/* ---------- ۲. اگر پارامتر imp مشخص نبود: نمایش فهرست گفتگوهای کاربر ---------- */
$st = $db->prepare(
    "SELECT i.ID, i.Title, i.Status, i.CreatedAt, i.AiVerdict, p.Name AS ProcName, p.ID AS Pid,
            pt.TeamName AS RespTeamName,
            (SELECT COUNT(*) FROM Responses r WHERE r.RefType='improvement' AND r.RefID=i.ID) AS Replies,
            (SELECT MAX(r3.CreatedAt) FROM Responses r3 WHERE r3.RefType='improvement' AND r3.RefID=i.ID) AS LastReplyAt
     FROM Improvements i
     JOIN Processes p ON p.ID = i.ProcessID
     LEFT JOIN ProcessTeams pt ON pt.ID = p.ResponsibleTeamID
     WHERE i.requester_type = 'user' AND i.requester_id = ?
     ORDER BY (SELECT MAX(r2.ID) FROM Responses r2 WHERE r2.RefType='improvement' AND r2.RefID=i.ID) DESC, i.ID DESC"
);
$st->bind_param('i', $uid);
$st->execute();
$threads = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
?>
<?php user_header($id, 'discussion', 'گفتگو در فرآیندها'); ?>

<div class="crumbs">
    <a href="home.php">خانه</a> ← گفتگو در فرآیندها
</div>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card">
    <div class="page-head" style="margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
            <h2 style="margin:0;display:flex;align-items:center;gap:8px">
                <?= app_icon('discussion', '', 22) ?>
                <span>گفتگو در فرآیندها (<?= count($threads) ?>)</span>
            </h2>
            <p class="sub" style="margin:4px 0 0 0;color:var(--muted);font-size:13px">
                کانال ارتباط مستقیم شما با تیم‌های مهندسی فرآیند و داور هوشمند هرمس (Hermes AI) حول پیشنهادات بهبود
            </p>
        </div>
        <div>
            <a href="improvements.php" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                <?= app_icon('improvements', '', 14) ?>
                <span>لیست بهبودهای من</span>
            </a>
        </div>
    </div>

    <?php if (!$threads): ?>
        <div class="empty">
            <span class="ico"><?= app_icon('discussion', '', 40) ?></span>
            <p style="font-weight:600">هنوز گفتگویی برای پیشنهادهای بهبود شما ثبت نشده است.</p>
            <p style="color:var(--muted);font-size:13px">با ثبت هر پیشنهاد بهبود، یک کانال گفتگوی تعاملی اختصاصی با تیم فرآیند و هرمس تشکیل می‌شود.</p>
            <p style="margin-top:12px"><a href="processes.php" class="btn btn-brand btn-sm">مشاهده فرآیندها و ثبت پیشنهاد بهبود</a></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>موضوع بهبود</th>
                        <th>فرآیند مرتبط</th>
                        <th>تیم مسئول</th>
                        <th>داور هرمس (AI)</th>
                        <th>وضعیت</th>
                        <th>پیام‌ها</th>
                        <th>تاریخ / آخرین پیام</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($threads as $t): 
                    $repCount = (int)$t['Replies'];
                ?>
                    <tr>
                        <td>
                            <a href="discussion.php?imp=<?= (int)$t['ID'] ?>" style="font-weight:700;color:var(--ink);text-decoration:none">
                                <?= e($t['Title']) ?>
                            </a>
                        </td>
                        <td>
                            <a href="processes.php?id=<?= (int)$t['Pid'] ?>" class="sub" style="color:var(--muted);text-decoration:none">
                                <?= e($t['ProcName']) ?>
                            </a>
                        </td>
                        <td><?= e($t['RespTeamName'] ?: 'تیم فرآیند') ?></td>
                        <td><?= hermes_badge($t['AiVerdict'] ?? 'pending', $t['CreatedAt'] ?? null, (int)$t['ID'], false) ?></td>
                        <td><span class="badge badge-<?= e($t['Status']) ?>"><?= status_label($t['Status']) ?></span></td>
                        <td>
                            <span class="badge" style="background:<?= $repCount > 0 ? 'var(--brand-soft)' : 'var(--line)' ?>;color:<?= $repCount > 0 ? 'var(--brand)' : 'var(--muted)' ?>;font-weight:700">
                                <?= farsi_num($repCount) ?> پیام
                            </span>
                        </td>
                        <td><small style="color:var(--muted);font-size:12px"><?= e(fmt_date_short($t['LastReplyAt'] ?: $t['CreatedAt'])) ?></small></td>
                        <td>
                            <a class="btn btn-brand btn-xs" href="discussion.php?imp=<?= (int)$t['ID'] ?>" style="display:inline-flex;align-items:center;gap:4px">
                                <?= app_icon('discussion', '', 12) ?>
                                <span>ورود به گفتگو</span>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>

