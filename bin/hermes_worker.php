<?php
/**
 * پردازشگر پس‌زمینه ارزیابی هوشمند و تدوین اسناد هرمس (Hermes Background Worker)
 * اجرای غیراسنکرون (Async) و زمان‌بندی‌شده (Scheduled Queue)
 * 
 * نحوه اجرا از خط فرمان:
 * php bin/hermes_worker.php 12                (ارزیابی یک پیشنهاد خاص)
 * php bin/hermes_worker.php --task 45         (اجرای کار بازنگری و تدوین سند بر اساس شناسه تسک)
 * php bin/hermes_worker.php --queue           (پردازش صف موارد ارزیابی و بازنگری‌های معلق)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This worker script can only be executed via CLI.\n";
    exit(1);
}

// اطمینان از خروج بلادرنگ لاگ‌ها در ویندوز و لینوکس
@ini_set('output_buffering', 'off');
@ob_implicit_flush(true);
while (@ob_end_flush());

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/hermes.php';
require_once __DIR__ . '/../inc/files.php';
require_once __DIR__ . '/../inc/pdf_generator.php';
require_once __DIR__ . '/../inc/bale.php';
require_once __DIR__ . '/../inc/layout.php';

$arg1 = $argv[1] ?? '--queue';
$arg2 = $argv[2] ?? null;
$timeStart = microtime(true);

echo "[" . date('Y-m-d H:i:s') . "] Hermes Worker started with args: {$arg1} " . ($arg2 ? $arg2 : "") . "\n";

try {
    $db = app_db();
} catch (Throwable $e) {
    echo "-> FATAL: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * اجرای یک تسک بازنگری و تدوین سند فرآیند
 */
function worker_run_revision_task(mysqli $db, int $taskId): bool
{
    $st = $db->prepare("SELECT * FROM HermesTasks WHERE ID = ?");
    $st->bind_param('i', $taskId);
    $st->execute();
    $task = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$task) {
        echo "-> ERROR: HermesTask #{$taskId} not found.\n";
        return false;
    }

    $impId = (int)$task['RefID'];
    $teamId = (int)($task['TeamID'] ?? 0);
    $revisionData = !empty($task['Payload']) ? json_decode($task['Payload'], true) : null;

    echo "-> Running Revision Task #{$taskId} for Improvement #{$impId} (Team: {$teamId})...\n";

    // تغییر وضعیت تسک به running
    $st = $db->prepare("UPDATE HermesTasks SET Status = 'running', UpdatedAt = NOW() WHERE ID = ?");
    $st->bind_param('i', $taskId);
    $st->execute();
    $st->close();

    // اگر پیش‌نویس داده‌ها در payload نبود، خود ورکر پیش‌نویس را استخراج می‌کند
    if (!is_array($revisionData) || empty($revisionData['steps'])) {
        echo "   * Generating procedure draft with Hermes...\n";
        $draftRes = hermes_draft_procedure_revision($db, $impId);
        if ($draftRes['success']) {
            $revisionData = $draftRes['revision'];
        } else {
            $errMsg = $draftRes['error'] ?: 'خطا در تدوین پیش‌نویس هرمس';
            $st = $db->prepare("UPDATE HermesTasks SET Status = 'failed', ErrorMessage = ?, UpdatedAt = NOW() WHERE ID = ?");
            $st->bind_param('si', $errMsg, $taskId);
            $st->execute();
            $st->close();
            echo "-> FAILED to draft revision: {$errMsg}\n";
            return false;
        }
    }

    // اعمال بازنگری، بایگانی نسخه‌های قبل و تولید PDF
    echo "   * Compiling procedure PDF & archiving prior versions...\n";
    $applyRes = hermes_apply_procedure_revision($db, $impId, $teamId, $revisionData);

    if ($applyRes['success']) {
        $resJson = json_encode($applyRes, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("UPDATE HermesTasks SET Status = 'completed', Result = ?, UpdatedAt = NOW() WHERE ID = ?");
        $st->bind_param('si', $resJson, $taskId);
        $st->execute();
        $st->close();

        // دریافت اطلاعات فرآیند برای ارسال اعلان (Notification)
        $st = $db->prepare("SELECT p.ID AS Pid, p.Name AS ProcName, i.Title AS ImpTitle, i.requester_type, i.requester_id 
                            FROM Improvements i JOIN Processes p ON p.ID = i.ProcessID WHERE i.ID = ?");
        $st->bind_param('i', $impId);
        $st->execute();
        $info = $st->get_result()->fetch_assoc();
        $st->close();

        $procName = $info['ProcName'] ?? 'فرآیند';
        $impTitle = $info['ImpTitle'] ?? 'بهبود';
        $procId = (int)($info['Pid'] ?? 0);
        $newVer = $applyRes['new_version'];
        $pdfUrl = $applyRes['pdf_url'];
        $reqPersId = (($info['requester_type'] ?? '') === 'user') ? (int)($info['requester_id'] ?? 0) : null;

        // ثبت اعلان با اولویت بالا برای تیم مسئول
        $notifTitle = "سند مصوب فرآیند «{$procName}» (نسخه {$newVer}) توسط هرمس تدوین و منتشر شد";
        $notifBody = "عملیات بهینه‌سازی و تدوین سند رسمی PDF بر اساس پیشنهاد بهبود «{$impTitle}» با موفقیت پایان یافت و در پرونده ذخیره شد.";
        $notifLink = $pdfUrl;
        $fromId = $teamId;
        $st = $db->prepare("INSERT INTO Notifications (Title, Body, Link, FromType, FromID, ProcessID, TargetTeamID, Priority) VALUES (?, ?, ?, 'team', ?, ?, ?, 'high')");
        $st->bind_param('sssiii', $notifTitle, $notifBody, $notifLink, $fromId, $procId, $teamId);
        $st->execute();
        $st->close();

        // ارسال پیام خودکار به متولی در چارت سازمانی، مخاطبان اثرپذیر و کاربر پیشنهاددهنده در پیام‌رسان بله
        echo "   * Dispatching notifications to Org Chart owner, affected audience & requester via Bale...\n";
        $baleTitle = "تدوین و انتشار سند مصوب فرآیند «{$procName}» (نسخه {$newVer})";
        $baleDesc = "عملیات بهینه‌سازی و تدوین سند رسمی PDF بر اساس پیشنهاد بهبود «{$impTitle}» توسط هوش مصنوعی هرمس با موفقیت پایان یافت و در پرونده ذخیره شد.";
        $pdfRel = $applyRes['pdf_rel'] ?? '';
        $pdfFullPath = !empty($pdfRel) ? (APP_ROOT . DIRECTORY_SEPARATOR . 'processes' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pdfRel)) : null;
        $safeProcessLink = bale_sanitize_url($applyRes['pdf_url'] ?? '', $procId);
        $baleRes = bale_notify_process_change($db, $procId, $baleTitle, $baleDesc, $safeProcessLink, $teamId, $reqPersId, $pdfFullPath);
        $sentCount = $baleRes['sent'] ?? 0;
        $failedCount = $baleRes['failed'] ?? 0;
        echo "   -> Bale dispatched: {$sentCount} sent, {$failedCount} failed.\n";

        echo "-> SUCCESS: Revision Task #{$taskId} completed. Version: {$newVer}, PDF: {$applyRes['pdf_name']}\n";
        return true;
    } else {
        $errMsg = $applyRes['error'] ?: 'خطای نامشخص در اعمال بازنگری';
        $st = $db->prepare("UPDATE HermesTasks SET Status = 'failed', ErrorMessage = ?, UpdatedAt = NOW() WHERE ID = ?");
        $st->bind_param('si', $errMsg, $taskId);
        $st->execute();
        $st->close();

        // ثبت اعلان خطا برای آگاهی تیم
        $notifTitle = "خطا در تدوین سند فرآیند توسط هرمس";
        $notifBody = "در تدوین سند فرآیند برای بهبود #{$impId} خطایی رخ داد: " . $errMsg;
        $fromId = $teamId;
        $st = $db->prepare("INSERT INTO Notifications (Title, Body, FromType, FromID, TargetTeamID, Priority) VALUES (?, ?, 'team', ?, ?, 'high')");
        $st->bind_param('ssii', $notifTitle, $notifBody, $fromId, $teamId);
        $st->execute();
        $st->close();

        echo "-> FAILED: {$errMsg}\n";
        return false;
    }
}

/**
 * اجرای تسک پس‌زمینه پاسخ هرمس به منشن گفتگو (@Hermes)
 */
function worker_run_discussion_mention_task(mysqli $db, int $taskId): bool
{
    $timeStart = microtime(true);
    echo "-> [Task #{$taskId}] Starting Hermes Discussion Mention Worker...\n";

    $st = $db->prepare("SELECT * FROM HermesTasks WHERE ID = ? AND TaskType = 'discussion_mention'");
    $st->bind_param('i', $taskId);
    $st->execute();
    $task = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$task) {
        echo "-> ERROR: Task #{$taskId} not found or not discussion_mention.\n";
        return false;
    }

    if ($task['Status'] === 'completed') {
        echo "-> Task #{$taskId} is already completed.\n";
        return true;
    }

    $st = $db->prepare("UPDATE HermesTasks SET Status = 'running', UpdatedAt = NOW() WHERE ID = ?");
    $st->bind_param('i', $taskId);
    $st->execute();
    $st->close();

    $impId = (int)$task['RefID'];
    $payload = json_decode((string)$task['Payload'], true) ?: [];
    $prompt = (string)($payload['prompt'] ?? '');
    $senderName = (string)($payload['sender_name'] ?? 'کاربر');
    $senderType = (string)($payload['sender_type'] ?? 'user');

    echo "   * Generating Hermes AI response for Improvement #{$impId} ...\n";
    $answer = hermes_answer_discussion_mention($db, $impId, $prompt, $senderName, $senderType);

    if ($answer && trim($answer) !== '') {
        $aiType = 'hermes';
        $aiId = 0;
        $stAi = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement', ?, ?, ?, ?)");
        $stAi->bind_param('isis', $impId, $aiType, $aiId, $answer);
        $stAi->execute();
        $stAi->close();

        $resJson = json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("UPDATE HermesTasks SET Status = 'completed', Result = ?, UpdatedAt = NOW() WHERE ID = ?");
        $st->bind_param('si', $resJson, $taskId);
        $st->execute();
        $st->close();

        echo "   * Dispatching notifications for Hermes answer...\n";
        discussion_dispatch_notifications($db, $impId, 'hermes', 0, $answer, 'هرمس');

        $elapsed = round(microtime(true) - $timeStart, 2);
        echo "-> SUCCESS: Discussion Mention Task #{$taskId} completed in {$elapsed}s.\n";
        return true;
    } else {
        $errMsg = 'پاسخی از مدل هرمس دریافت نشد.';
        $st = $db->prepare("UPDATE HermesTasks SET Status = 'failed', ErrorMessage = ?, UpdatedAt = NOW() WHERE ID = ?");
        $st->bind_param('si', $errMsg, $taskId);
        $st->execute();
        $st->close();

        echo "-> FAILED: {$errMsg}\n";
        return false;
    }
}

/**
 * هدایت اجرای تسک بر اساس نوع TaskType
 */
function worker_route_task(mysqli $db, int $taskId): bool
{
    $st = $db->prepare("SELECT TaskType FROM HermesTasks WHERE ID = ?");
    $st->bind_param('i', $taskId);
    $st->execute();
    $task = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$task) {
        echo "-> Task #{$taskId} does not exist.\n";
        return false;
    }

    if ($task['TaskType'] === 'discussion_mention') {
        return worker_run_discussion_mention_task($db, $taskId);
    }

    return worker_run_revision_task($db, $taskId);
}

// شاخه‌بندی دستورات دریافتی
if ($arg1 === '--task' && is_numeric($arg2)) {
    // اجرای یک تسک خاص
    worker_route_task($db, (int)$arg2);
} elseif (str_starts_with($arg1, '--task=') && is_numeric(substr($arg1, 7))) {
    // اجرای یک تسک با فرمت --task=ID
    worker_route_task($db, (int)substr($arg1, 7));
} elseif (is_numeric($arg1) && (int)$arg1 > 0) {
    // ارزیابی یک پیشنهاد بهبود خاص
    $impId = (int)$arg1;
    $stCheck = $db->prepare("SELECT AiVerdict FROM Improvements WHERE ID = ?");
    $stCheck->bind_param('i', $impId);
    $stCheck->execute();
    $curRow = $stCheck->get_result()->fetch_assoc();
    $stCheck->close();

    if ($curRow && in_array($curRow['AiVerdict'], ['yes', 'no'], true)) {
        echo "-> SKIP: Improvement ID {$impId} is already evaluated (Verdict: {$curRow['AiVerdict']}).\n";
    } else {
        echo "-> Evaluating Improvement ID: {$impId} ...\n";
        $result = hermes_evaluate_improvement($db, $impId);
        if ($result['success']) {
            echo "-> SUCCESS: Verdict is [{$result['verdict']}]\n";
            echo "   Summary: {$result['summary']}\n";
        } else {
            echo "-> FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
        }
    }
} else {
    // پردازش صف همه‌جانبه (Queue Mode): ارزیابی‌ها و بازنگری‌ها
    echo "-> Checking for pending tasks in queue...\n";

    // ۱. پردازش تسک‌های معلق صف (HermesTasks)
    $stTasks = $db->query("SELECT ID FROM HermesTasks WHERE Status = 'queued' ORDER BY CreatedAt ASC LIMIT 5");
    if ($stTasks) {
        $taskRows = $stTasks->fetch_all(MYSQLI_ASSOC);
        if (!empty($taskRows)) {
            echo "-> Found " . count($taskRows) . " pending task(s).\n";
            foreach ($taskRows as $tr) {
                worker_route_task($db, (int)$tr['ID']);
                usleep(300000); // 0.3s
            }
        }
    }

    // ۲. پردازش بهبودهای در انتظار ارزیابی داور (Improvements pending AI)
    $limit = 5;
    $st = $db->prepare("SELECT ID, Title FROM Improvements WHERE AiVerdict = 'pending' ORDER BY CreatedAt ASC LIMIT ?");
    $st->bind_param('i', $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (empty($rows)) {
        echo "-> No pending improvement evaluations in queue.\n";
    } else {
        echo "-> Found " . count($rows) . " pending improvement(s) to evaluate.\n";
        foreach ($rows as $item) {
            $itemId = (int)$item['ID'];
            echo "   * Processing evaluation for #{$itemId} («{$item['Title']}»)... ";
            $res = hermes_evaluate_improvement($db, $itemId);
            if ($res['success']) {
                echo "DONE: Verdict = [{$res['verdict']}]\n";
            } else {
                echo "ERROR: " . ($res['error'] ?? 'Failed') . "\n";
            }
            usleep(500000); // 0.5s
        }
    }
}

$elapsed = round(microtime(true) - $timeStart, 2);
echo "[" . date('Y-m-d H:i:s') . "] Hermes Worker finished in {$elapsed}s.\n";
