<?php
/**
 * مرکز اعلان‌ها و پیام‌ها — پنل کاربر
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';

/* ---------- خروجی AJAX برای بررسی اعلان‌های خوانده‌نشده ---------- */
if (isset($_GET['check_unread'])) {
    header('Content-Type: application/json; charset=utf-8');
    $id = current_identity('user');
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
    $uid = (int)$id['id'];

    // اعلان‌های خوانده‌نشده هرمس
    $stH = $db->prepare(
        "SELECT ID, Title, Body, Link, Priority, SentAt, Type 
         FROM Notifications 
         WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) 
           AND IsRead = 0 
           AND Type = 'hermes' 
         ORDER BY ID DESC LIMIT 5"
    );
    $stH->bind_param('i', $uid);
    $stH->execute();
    $hermesRows = $stH->get_result()->fetch_all(MYSQLI_ASSOC);
    $stH->close();

    $cntH = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 AND Type = 'hermes'");
    $cntH->bind_param('i', $uid);
    $cntH->execute();
    $hermesUnread = (int)($cntH->get_result()->fetch_assoc()['c'] ?? 0);
    $cntH->close();

    // پیام‌های گفتگو خوانده‌نشده
    $stM = $db->prepare(
        "SELECT ID, Title, Body, Link, Priority, SentAt, Type 
         FROM Notifications 
         WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) 
           AND IsRead = 0 
           AND Type = 'message' 
         ORDER BY ID DESC LIMIT 5"
    );
    $stM->bind_param('i', $uid);
    $stM->execute();
    $msgRows = $stM->get_result()->fetch_all(MYSQLI_ASSOC);
    $stM->close();

    $cntM = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 AND Type = 'message'");
    $cntM->bind_param('i', $uid);
    $cntM->execute();
    $msgUnread = (int)($cntM->get_result()->fetch_assoc()['c'] ?? 0);
    $cntM->close();

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
    $id = current_identity('user');
    if (!$id || empty($id['id'])) {
        echo json_encode(['ok' => false]);
        exit;
    }
    $db = app_db();
    $uid = (int)$id['id'];
    $notifId = (int)($_REQUEST['id'] ?? 0);
    if ($notifId > 0) {
        $st = $db->prepare("UPDATE Notifications SET IsRead = 1 WHERE ID = ? AND (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL))");
        $st->bind_param('ii', $notifId, $uid);
        $st->execute();
        $st->close();
    }

    $cntH = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 AND Type = 'hermes'");
    $cntH->bind_param('i', $uid);
    $cntH->execute();
    $hermesUnread = (int)($cntH->get_result()->fetch_assoc()['c'] ?? 0);
    $cntH->close();

    $cntM = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 AND Type = 'message'");
    $cntM->bind_param('i', $uid);
    $cntM->execute();
    $msgUnread = (int)($cntM->get_result()->fetch_assoc()['c'] ?? 0);
    $cntM->close();

    echo json_encode([
        'ok' => true,
        'unread_count' => ($hermesUnread + $msgUnread),
        'hermes_unread_count' => $hermesUnread,
        'msg_unread_count' => $msgUnread
    ]);
    exit;
}

$id = require_area('user');
$db = app_db();
$uid = (int)$id['id'];
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
            $typeCondition = ($postTab === 'messages') ? "AND Type = 'message'" : "AND Type = 'hermes'";
            $st = $db->prepare("UPDATE Notifications SET IsRead = 1 WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 {$typeCondition}");
            $st->bind_param('i', $uid);
            $st->execute();
            $st->close();
            header('Location: notifications.php?tab=' . urlencode($postTab));
            exit;
        } elseif ($action === 'mark_read') {
            $notifId = (int)($_POST['notif_id'] ?? 0);
            $st = $db->prepare("UPDATE Notifications SET IsRead = 1 WHERE ID = ? AND (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL))");
            $st->bind_param('ii', $notifId, $uid);
            $st->execute();
            $st->close();
            header('Location: notifications.php?tab=' . urlencode($postTab));
            exit;
        }
    }
}

// تعداد اعلان‌های خوانده‌نشده جهت نمایش روی تب‌ها
$cntH = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 AND Type = 'hermes'");
$cntH->bind_param('i', $uid);
$cntH->execute();
$unreadHermesCount = (int)($cntH->get_result()->fetch_assoc()['c'] ?? 0);
$cntH->close();

$cntM = $db->prepare("SELECT COUNT(*) AS c FROM Notifications WHERE (TargetPersonnelID = ? OR (TargetPersonnelID IS NULL AND TargetTeamID IS NULL)) AND IsRead = 0 AND Type = 'message'");
$cntM->bind_param('i', $uid);
$cntM->execute();
$unreadMsgCount = (int)($cntM->get_result()->fetch_assoc()['c'] ?? 0);
$cntM->close();

// دریافت لیست اعلان‌های تب جاری
$targetType = ($tab === 'messages') ? 'message' : 'hermes';
$st = $db->prepare(
    "SELECT n.*, p.Name AS ProcName 
     FROM Notifications n
     LEFT JOIN Processes p ON p.ID = n.ProcessID
     WHERE (n.TargetPersonnelID = ? OR (n.TargetPersonnelID IS NULL AND n.TargetTeamID IS NULL))
       AND n.Type = ?
     ORDER BY n.ID DESC LIMIT 100"
);
$st->bind_param('is', $uid, $targetType);
$st->execute();
$notifications = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$currentUnreadCount = ($tab === 'messages') ? $unreadMsgCount : $unreadHermesCount;

user_header($id, 'notifications', 'مرکز اعلان‌ها و پیام‌ها');
?>

<div class="card">
    <div class="page-head" style="margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center">
                <?= app_icon('bell', '', 22) ?>
            </div>
            <div>
                <h2 style="margin:0;font-size:18px">مرکز اعلان‌ها و پیام‌ها</h2>
                <p style="margin:2px 0 0;font-size:12.5px;color:var(--muted)">
                    مدیریت یکپارچه اعلان‌های هوشمند داور هرمس و پیام‌های گفتگو با تیم‌های فرآیندی
                </p>
            </div>
        </div>

        <?php if ($currentUnreadCount > 0): ?>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <button class="btn btn-sm btn-ghost" style="display:inline-flex;align-items:center;gap:6px">
                    <?= app_icon('check', '', 14) ?>
                    <span>علامت‌گذاری همه این بخش به عنوان خوانده‌شده</span>
                </button>
            </form>
        <?php endif; ?>
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
            <span class="ico"><?= $tab === 'messages' ? app_icon('discussion', '', 40) : app_icon('bot', '', 40) ?></span>
            <p style="font-weight:600"><?= $tab === 'messages' ? 'هنوز پیامی در گفتگوها برای شما ثبت نشده است.' : 'هنوز اعلانی از سوی هرمس برای شما ثبت نشده است.' ?></p>
            <p style="color:var(--muted);font-size:13px"><?= $tab === 'messages' ? 'هنگام پاسخ تیم به پیشنهادات یا گفتگو با @Hermes، پیام‌ها اینجا نمایش داده می‌شوند.' : 'با تصویب پیشنهادات و تغییر نسخه رسمی فرآیندها، رویدادها در این بخش قرار می‌گیرند.' ?></p>
        </div>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
            <?php foreach ($notifications as $n): 
                $isRead = (bool)$n['IsRead'];
                $isHigh = ($n['Priority'] === 'high');
            ?>
                <div class="card" style="margin:0;padding:16px;border-radius:12px;border:1px solid <?= $isRead ? 'var(--line)' : ($tab === 'messages' ? '#0284c7' : 'var(--brand)') ?>;background:<?= $isRead ? 'var(--card)' : ($tab === 'messages' ? 'rgba(2, 132, 199, 0.05)' : 'rgba(37, 99, 235, 0.03)') ?>;transition:all .2s ease;box-shadow:<?= $isRead ? 'none' : '0 4px 14px rgba(0,0,0,0.06)' ?>">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
                        <div style="display:flex;align-items:flex-start;gap:10px;flex:1;min-width:260px">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-top:6px;background:<?= $isRead ? 'var(--muted)' : ($tab === 'messages' ? '#0284c7' : 'var(--brand)') ?>;flex-shrink:0"></span>
                            <div>
                                <h3 style="margin:0 0 4px;font-size:14.5px;font-weight:700;color:<?= $isRead ? 'inherit' : ($tab === 'messages' ? '#0284c7' : 'var(--brand)') ?>">
                                    <?= e($n['Title']) ?>
                                    <?php if ($isHigh): ?>
                                        <span class="badge badge-ai-yes" style="font-size:11px;padding:1px 6px;margin-right:6px">مهم</span>
                                    <?php endif; ?>
                                </h3>
                                <p style="margin:0;font-size:13px;color:var(--ink);opacity:.85;line-height:1.7"><?= nl2br(e($n['Body'])) ?></p>
                                
                                <?php if (!empty($n['Link'])): ?>
                                    <div style="margin-top:10px">
                                        <?php 
                                            $link = $n['Link'];
                                            $btnLabel = 'مشاهده پیوند مرتبط';
                                            $btnIcon = 'files';
                                            if (str_contains($link, '.pdf')) {
                                                $btnLabel = 'مشاهده و دریافت سند PDF پیوست';
                                                $btnIcon = 'files';
                                            } elseif (str_contains($link, 'discussion.php')) {
                                                $btnLabel = 'ورود به گفتگو و ارسال پاسخ';
                                                $btnIcon = 'discussion';
                                            } elseif (str_contains($link, 'processes.php')) {
                                                $btnLabel = 'مشاهده پرونده فرآیند';
                                                $btnIcon = 'processes';
                                            }
                                        ?>
                                        <a href="<?= e($link) ?>" class="btn btn-sm <?= str_contains($link, '.pdf') ? 'btn-brand' : 'btn-ghost' ?>" style="display:inline-flex;align-items:center;gap:6px">
                                            <?= app_icon($btnIcon, '', 14) ?>
                                            <span><?= e($btnLabel) ?></span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="font-size:11.5px;color:var(--muted)"><?= e(fmt_date($n['SentAt'])) ?></span>
                            <?php if (!$isRead): ?>
                                <form method="post" style="margin:0">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notif_id" value="<?= (int)$n['ID'] ?>">
                                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                                    <button class="btn btn-ghost btn-xs" title="علامت‌گذاری به عنوان خوانده‌شده" style="padding:4px 8px">
                                        <?= app_icon('check', '', 13) ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
