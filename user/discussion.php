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

/* ارسال پاسخ جدید */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است؛ دوباره تلاش کنید.';
    } else {
        $impId = (int)($_POST['imp_id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));

        // بررسی اینکه بهبود متعلق به این کاربر باشد
        $st = $db->prepare("SELECT ID FROM Improvements WHERE ID = ? AND requester_type = 'user' AND requester_id = ?");
        $st->bind_param('ii', $impId, $uid);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_assoc();
        $st->close();

        if (!$ok) {
            $error = 'بهبود مورد نظر یافت نشد یا دسترسی به آن ندارید.';
        } elseif (mb_strlen($body) < 2) {
            $error = 'متن پیام را وارد کنید.';
        } else {
            $senderType = 'user';
            $st = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement',?,?,?,?)");
            $st->bind_param('isis', $impId, $senderType, $uid, $body);
            $st->execute();
            $st->close();
            header('Location: discussion.php?imp=' . $impId);
            exit;
        }
    }
}

$impId = (int)($_GET['imp'] ?? 0);
if ($impId <= 0) {
    header('Location: improvements.php');
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
    user_header($id, 'improvements', 'گفتگو یافت نشد');
    echo '<div class="card"><div class="empty"><span class="ico">' . app_icon('search', '', 36) . '</span><p>این گفتگو وجود ندارد یا متعلق به شما نیست.</p><p><a href="improvements.php">بازگشت به بهبودهای من</a></p></div></div>';
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
?>
<?php user_header($id, 'improvements', 'گفتگو: ' . $imp['Title']); ?>

<div class="crumbs">
    <a href="improvements.php">بهبودهای من</a> ← <?= e($imp['Title']) ?>
</div>

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

    <?= hermes_render_card($imp['AiVerdict'] ?? 'pending', $imp['AiAnalysis'] ?? null, $imp['AiEvaluatedAt'] ?? null, $impId, false) ?>

    <div class="thread">
        <?php if (!$msgs): ?>
            <div class="empty"><span class="ico"><?= app_icon('discussion', '', 36) ?></span><p>هنوز پاسخی برای این بهبود ثبت نشده است.</p></div>
        <?php endif; ?>
        <?php foreach ($msgs as $m):
            $isTeam = $m['SenderType'] === 'team';
            $who = $isTeam ? ($m['TeamName'] ?? 'تیم فرآیند') : 'شما (کاربر)'; ?>
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

    <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-top:16px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="imp_id" value="<?= $impId ?>">
        <label class="field" style="flex:1;min-width:240px;margin:0">
            <span class="field-label">پیام شما</span>
            <textarea name="body" required maxlength="2000" rows="2" placeholder="پاسخ یا توضیحات تکمیلی خود را بنویسید…"></textarea>
        </label>
        <button class="btn">ارسال پیام</button>
    </form>
</div>

<?php app_footer(); ?>

