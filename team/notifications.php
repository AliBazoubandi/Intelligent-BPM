<?php
/**
 * مرکز اعلان‌ها و رویدادهای هرمس و گفتگوها — پنل تیم
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ui.php';

/* ---------- خروجی AJAX برای بررسی اعلان‌های خوانده‌نشده ---------- */
if (isset($_GET['check_unread'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = current_identity('team');
    if (!$id || empty($id['id'])) {
        echo json_encode([
            'unread_count' => 0,
            'hermes_unread_count' => 0,
            'msg_unread_count' => 0,
            'hermes_notifications' => [],
            'message_notifications' => [],
            'notifications' => []
        ]);
        exit;
    }
    $db = app_db();
    $tid = (int)$id['id'];
    $compId = !empty($id['company_id']) ? (int)$id['company_id'] : null;

    $counts = notification_get_unread_counts($db, 'team', $tid, $compId);
    $hermesUnread = $counts['hermes'];
    $msgUnread = $counts['message'];

    // اعلان‌های خوانده‌نشده هرمس
    $stH = $db->prepare(
        "SELECT n.ID, n.Title, n.Body, n.Link, n.Priority, n.SentAt, n.Type 
         FROM Notifications n 
         WHERE (n.TargetTeamID = ? OR (n.TargetTeamID IS NULL AND n.TargetPersonnelID IS NULL)) 
           AND (? IS NULL OR n.TargetCompanyID IS NULL OR n.TargetCompanyID = ?)
           AND NOT EXISTS (
               SELECT 1 FROM NotificationReads nr 
               WHERE nr.NotificationID = n.ID AND nr.ReaderType = 'team' AND nr.ReaderID = ?
           )
           AND n.Type = 'hermes' 
         ORDER BY n.ID DESC LIMIT 5"
    );
    $stH->bind_param('iiii', $tid, $compId, $compId, $tid);
    $stH->execute();
    $hermesRows = $stH->get_result()->fetch_all(MYSQLI_ASSOC);
    $stH->close();

    // پیام‌های گفتگو خوانده‌نشده
    $stM = $db->prepare(
        "SELECT n.ID, n.Title, n.Body, n.Link, n.Priority, n.SentAt, n.Type 
         FROM Notifications n 
         WHERE (n.TargetTeamID = ? OR (n.TargetTeamID IS NULL AND n.TargetPersonnelID IS NULL)) 
           AND (? IS NULL OR n.TargetCompanyID IS NULL OR n.TargetCompanyID = ?)
           AND NOT EXISTS (
               SELECT 1 FROM NotificationReads nr 
               WHERE nr.NotificationID = n.ID AND nr.ReaderType = 'team' AND nr.ReaderID = ?
           )
           AND n.Type = 'message' 
         ORDER BY n.ID DESC LIMIT 5"
    );
    $stM->bind_param('iiii', $tid, $compId, $compId, $tid);
    $stM->execute();
    $msgRows = $stM->get_result()->fetch_all(MYSQLI_ASSOC);
    $stM->close();

    echo json_encode([
        'unread_count' => ($hermesUnread + $msgUnread),
        'hermes_unread_count' => $hermesUnread,
        'msg_unread_count' => $msgUnread,
        'hermes_notifications' => $hermesRows,
        'message_notifications' => $msgRows,
        'notifications' => $hermesRows
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- خروجی AJAX برای علامت‌گذاری اعلان به عنوان خوانده‌شده ---------- */
if (isset($_REQUEST['mark_read_ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = current_identity('team');
    if (!$id || empty($id['id'])) {
        echo json_encode(['ok' => false]);
        exit;
    }
    // اعتبارسنجی CSRF در صورت ارسال به روش POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_check($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF error']);
        exit;
    }

    $db = app_db();
    $tid = (int)$id['id'];
    $compId = !empty($id['company_id']) ? (int)$id['company_id'] : null;
    $notifId = (int)($_REQUEST['id'] ?? 0);
    if ($notifId > 0) {
        notification_mark_read($db, 'team', $tid, $compId, $notifId);
    }

    $counts = notification_get_unread_counts($db, 'team', $tid, $compId);
    $hermesUnread = $counts['hermes'];
    $msgUnread = $counts['message'];

    echo json_encode([
        'ok' => true,
        'unread_count' => ($hermesUnread + $msgUnread),
        'hermes_unread_count' => $hermesUnread,
        'msg_unread_count' => $msgUnread
    ]);
    exit;
}

$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];
$compId = !empty($id['company_id']) ? (int)$id['company_id'] : null;
$tab = (string)($_GET['tab'] ?? 'hermes');
if (!in_array($tab, ['hermes', 'messages'], true)) {
    $tab = 'hermes';
}

/* ---------- علامت‌گذاری به عنوان خوانده‌شده ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (csrf_check($_POST['csrf'] ?? null)) {
        $action = (string)($_POST['action'] ?? '');
        $postTab = (string)($_POST['tab'] ?? $tab);
        if ($action === 'mark_all_read') {
            $typeFilter = ($postTab === 'messages') ? 'message' : 'hermes';
            notification_mark_all_read($db, 'team', $tid, $compId, $typeFilter);
            header('Location: notifications.php?tab=' . urlencode($postTab));
            exit;
        } elseif ($action === 'mark_read') {
            $notifId = (int)($_POST['notif_id'] ?? 0);
            notification_mark_read($db, 'team', $tid, $compId, $notifId);
            header('Location: notifications.php?tab=' . urlencode($postTab));
            exit;
        }
    }
}

// دریافت تعداد خوانده‌نشده‌های هر بخش جهت نمایش روی تب‌ها
$counts = notification_get_unread_counts($db, 'team', $tid, $compId);
$unreadHermesCount = $counts['hermes'];
$unreadMsgCount = $counts['message'];

// دریافت لیست اعلان‌های تب جاری
$targetType = ($tab === 'messages') ? 'message' : 'hermes';
$st = $db->prepare(
    "SELECT n.*, p.Name AS ProcName,
            (CASE WHEN nr.ReadAt IS NOT NULL THEN 1 ELSE 0 END) AS IsRead
     FROM Notifications n
     LEFT JOIN Processes p ON p.ID = n.ProcessID
     LEFT JOIN NotificationReads nr ON nr.NotificationID = n.ID AND nr.ReaderType = 'team' AND nr.ReaderID = ?
     WHERE (n.TargetTeamID = ? OR (n.TargetTeamID IS NULL AND n.TargetPersonnelID IS NULL))
       AND (? IS NULL OR n.TargetCompanyID IS NULL OR n.TargetCompanyID = ?)
       AND n.Type = ?
     ORDER BY n.ID DESC LIMIT 100"
);
$st->bind_param('iiiis', $tid, $tid, $compId, $compId, $targetType);
$st->execute();
$notifications = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$csrf = csrf_token();
team_header($id, 'notifications', 'مرکز اعلان‌ها و پیام‌ها');
?>

<div class="card">
    <div class="page-head" style="margin-bottom:14px">
        <div>
            <h2 style="margin:0;display:flex;align-items:center;gap:8px">
                <?= app_icon('bell', '', 22) ?>
                <span>مرکز اعلان‌ها و پیام‌ها</span>
            </h2>
            <p class="sub" style="margin:4px 0 0 0;color:var(--muted);font-size:13px">
                <?= $tab === 'messages' ? 'پیام‌ها و پاسخ‌های رد و بدل شده در گفتگوهای بهبود فرآیندها' : 'پیام‌های تغییرات مصوب فرآیندها، تدوین اسناد PDF و نتایج داوری هوشمند داور هرمس' ?>
            </p>
        </div>
        <div>
            <form method="post" class="inline-form" style="margin:0">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <button class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                    <?= app_icon('check', '', 14) ?>
                    <span>علامت‌گذاری همه این بخش به عنوان خوانده‌شده</span>
                </button>
            </form>
        </div>
    </div>

    <!-- دکمه‌های ناوبری تب‌های تفکیک‌شده -->
    <div style="display:flex;gap:10px;margin-bottom:18px;border-bottom:1px solid var(--border);padding-bottom:12px;flex-wrap:wrap">
        <a href="notifications.php?tab=hermes" class="btn <?= $tab === 'hermes' ? 'btn-brand' : 'btn-ghost' ?>" style="display:inline-flex;align-items:center;gap:8px">
            <?= app_icon('bot', '', 16) ?>
            <span>اعلان‌های هرمس</span>
            <?php if ($unreadHermesCount > 0): ?>
                <span style="background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px"><?= farsi_num($unreadHermesCount) ?></span>
            <?php endif; ?>
        </a>
        <a href="notifications.php?tab=messages" class="btn <?= $tab === 'messages' ? 'btn-brand' : 'btn-ghost' ?>" style="display:inline-flex;align-items:center;gap:8px">
            <?= app_icon('discussion', '', 16) ?>
            <span>پیام‌های گفتگو</span>
            <?php if ($unreadMsgCount > 0): ?>
                <span style="background:#0284c7;color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:999px"><?= farsi_num($unreadMsgCount) ?></span>
            <?php endif; ?>
        </a>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="empty">
            <span class="ico"><?= $tab === 'messages' ? '💬' : '🔔' ?></span>
            <p><?= $tab === 'messages' ? 'هیچ پیام جدیدی در گفتگوها برای تیم شما ثبت نشده است.' : 'هیچ اعلانی از سوی هرمس برای تیم شما ثبت نشده است.' ?></p>
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($notifications as $n):
                $isUnread = ((int)$n['IsRead'] === 0);
            ?>
            <div style="background:<?= $isUnread ? 'var(--brand-soft)' : 'var(--card-bg)' ?>;border:1px solid <?= $isUnread ? 'var(--brand)' : 'var(--border)' ?>;border-radius:12px;padding:14px 18px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
                <div style="flex:1;min-width:260px">
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
                        <?php if ($isUnread): ?>
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $tab === 'messages' ? '#0284c7' : 'var(--brand)' ?>"></span>
                        <?php endif; ?>
                        <b style="font-size:14px;color:var(--text)"><?= e($n['Title']) ?></b>
                        <small class="sub" style="color:var(--muted);font-size:11.5px"><?= e(fmt_date($n['SentAt'])) ?></small>
                    </div>
                    <?php if ($n['Body']): ?>
                        <p style="margin:0 0 8px 0;font-size:13px;color:var(--muted);line-height:1.6"><?= nl2br(e($n['Body'])) ?></p>
                    <?php endif; ?>
                    <?php 
                        $teamLink = trim((string)($n['Link'] ?? ''));
                        if ($teamLink !== '') {
                            if (str_starts_with($teamLink, '/processes/')) {
                                $teamLink = BASE_URL . '/download.php?path=' . rawurlencode(ltrim(substr($teamLink, strlen('/processes/')), '/'));
                            } elseif (str_starts_with($teamLink, 'processes/')) {
                                $teamLink = BASE_URL . '/download.php?path=' . rawurlencode(ltrim(substr($teamLink, strlen('processes/')), '/'));
                            }
                        }
                        if (!empty($teamLink) && is_safe_app_url($teamLink)): 
                            $isPdf = (stripos($teamLink, '.pdf') !== false || stripos($teamLink, 'download.php') !== false);
                            $btnLabel = $isPdf ? 'مشاهده و دریافت سند PDF پیوست' : 'مشاهده و ورود به صفحه گفتگو';
                            $btnIcon = $isPdf ? 'pdf' : 'discussion';
                    ?>
                        <div style="margin-top:8px">
                            <a class="btn <?= $isPdf ? 'btn-brand' : 'btn-ghost' ?> btn-xs" href="<?= e($teamLink) ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:5px">
                                <?= app_icon($btnIcon, '', 13) ?>
                                <span><?= e($btnLabel) ?></span>
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
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
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
