<?php
/**
 * مرکز اعلان‌ها و رویدادهای هرمس — پنل تیم
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';

/* ---------- خروجی AJAX برای بررسی اعلان‌های خوانده‌نشده ---------- */
if (isset($_GET['check_unread'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = current_identity('team');
    if (!$id || empty($id['id'])) {
        echo json_encode(['unread_count' => 0, 'notifications' => []]);
        exit;
    }
    $db = app_db();
    $tid = (int)$id['id'];

    $st = $db->prepare(
        "SELECT ID, Title, Body, Link, Priority, SentAt 
         FROM Notifications 
         WHERE (TargetTeamID = ? OR (TargetTeamID IS NULL AND TargetPersonnelID IS NULL)) AND IsRead = 0 
         ORDER BY ID DESC LIMIT 5"
    );
    $st->bind_param('i', $tid);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetTeamID = ? OR (TargetTeamID IS NULL AND TargetPersonnelID IS NULL)) AND IsRead = 0");
    $cntSt->bind_param('i', $tid);
    $cntSt->execute();
    $unreadCount = (int)($cntSt->get_result()->fetch_assoc()['c'] ?? 0);
    $cntSt->close();

    echo json_encode([
        'unread_count' => $unreadCount,
        'notifications' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- خروجی AJAX برای علامت‌گذاری اعلان به عنوان خوانده‌شده هنگام بستن یا کلیک روی PDF ---------- */
if (isset($_REQUEST['mark_read_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = current_identity('team') ?? current_identity('user');
    if (!$id || empty($id['id'])) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $db = app_db();
    $tid = (int)$id['id'];
    $notifId = (int)($_REQUEST['id'] ?? 0);
    if ($notifId > 0) {
        $st = $db->prepare("UPDATE Notifications SET IsRead = 1 WHERE ID = ? AND (TargetTeamID = ? OR TargetTeamID IS NULL OR TargetPersonnelID = ?)");
        $st->bind_param('iii', $notifId, $tid, $tid);
        $st->execute();
        $st->close();
    }

    $cntSt = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetTeamID = ? OR (TargetTeamID IS NULL AND TargetPersonnelID IS NULL)) AND IsRead = 0");
    $cntSt->bind_param('i', $tid);
    $cntSt->execute();
    $unreadCount = (int)($cntSt->get_result()->fetch_assoc()['c'] ?? 0);
    $cntSt->close();

    echo json_encode(['ok' => true, 'unread_count' => $unreadCount]);
    exit;
}

$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

/* ---------- علامت‌گذاری به عنوان خوانده‌شده ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'mark_all_read') {
            $st = $db->prepare("UPDATE Notifications SET IsRead = 1 WHERE (TargetTeamID = ? OR (TargetTeamID IS NULL AND TargetPersonnelID IS NULL)) AND IsRead = 0");
            $st->bind_param('i', $tid);
            $st->execute();
            $st->close();
            header('Location: notifications.php');
            exit;
        } elseif ($action === 'mark_read') {
            $notifId = (int)($_POST['notif_id'] ?? 0);
            $st = $db->prepare("UPDATE Notifications SET IsRead = 1 WHERE ID = ? AND (TargetTeamID = ? OR (TargetTeamID IS NULL AND TargetPersonnelID IS NULL))");
            $st->bind_param('ii', $notifId, $tid);
            $st->execute();
            $st->close();
            header('Location: notifications.php');
            exit;
        }
    }
}

// دریافت لیست کامل اعلان‌ها
$st = $db->prepare(
    "SELECT n.*, p.Name AS ProcName 
     FROM Notifications n
     LEFT JOIN Processes p ON p.ID = n.ProcessID
     WHERE n.TargetTeamID = ? OR (n.TargetTeamID IS NULL AND n.TargetPersonnelID IS NULL)
     ORDER BY n.ID DESC LIMIT 100"
);
$st->bind_param('i', $tid);
$st->execute();
$notifications = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$csrf = csrf_token();
team_header($id, 'notifications', 'مرکز اعلان‌ها و پیام‌های هرمس');
?>

<div class="card">
    <div class="page-head" style="margin-bottom:12px">
        <div>
            <h2 style="margin:0;display:flex;align-items:center;gap:8px">
                <?= app_icon('bot', '', 22) ?>
                <span>اعلان‌های هوشمند هرمس و فرآیندها (<?= count($notifications) ?>)</span>
            </h2>
            <p class="sub" style="margin:4px 0 0 0;color:var(--muted);font-size:13px">
                پیام‌های تکمیل خودکار اسناد، نتایج پردازش‌های پس‌زمینه و رویدادهای سازمانی
            </p>
        </div>
        <div>
            <form method="post" class="inline-form" style="margin:0">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                    <?= app_icon('check', '', 14) ?>
                    <span>علامت‌گذاری همه به عنوان خوانده‌شده</span>
                </button>
            </form>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty"><span class="ico">🔔</span><p>در حال حاضر هیچ اعلانی برای تیم شما ثبت نشده است.</p></div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($notifications as $n):
                $isUnread = ((int)$n['IsRead'] === 0);
            ?>
            <div style="background:<?= $isUnread ? 'var(--brand-soft)' : 'var(--card-bg)' ?>;border:1px solid <?= $isUnread ? 'var(--brand)' : 'var(--border)' ?>;border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
                <div style="flex:1;min-width:260px">
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                        <?php if ($isUnread): ?>
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--brand)"></span>
                        <?php endif; ?>
                        <b style="font-size:14px;color:var(--text)"><?= e($n['Title']) ?></b>
                        <small class="sub" style="color:var(--muted);font-size:11.5px"><?= e(fmt_date($n['SentAt'])) ?></small>
                    </div>
                    <?php if ($n['Body']): ?>
                        <p style="margin:0 0 8px 0;font-size:13px;color:var(--muted);line-height:1.6"><?= nl2br(e($n['Body'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($n['Link'])): ?>
                        <div style="margin-top:8px">
                            <a class="btn btn-brand btn-xs" href="<?= e($n['Link']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:5px">
                                <?= app_icon('files', '', 13) ?>
                                <span>مشاهده و دریافت سند PDF پیوست</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($isUnread): ?>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notif_id" value="<?= (int)$n['ID'] ?>">
                        <button class="btn btn-ghost btn-xs" title="علامت‌گذاری به عنوان خوانده‌شده"><?= app_icon('check', '', 13) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
