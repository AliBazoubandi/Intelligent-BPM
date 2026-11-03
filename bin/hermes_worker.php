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
        $st = $db->prepare("SELECT p.ID AS Pid, p.Name AS ProcName, i.Title AS ImpTitle 
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

        // ثبت اعلان با اولویت بالا برای تیم مسئول
        $notifTitle = "سند مصوب فرآیند «{$procName}» (نسخه {$newVer}) توسط هرمس تدوین و منتشر شد";
        $notifBody = "عملیات بهینه‌سازی و تدوین سند رسمی PDF بر اساس پیشنهاد بهبود «{$impTitle}» با موفقیت پایان یافت و در پرونده ذخیره شد.";
        $notifLink = $pdfUrl;
        $fromId = $teamId;
        $st = $db->prepare("INSERT INTO Notifications (Title, Body, Link, FromType, FromID, ProcessID, TargetTeamID, Priority) VALUES (?, ?, ?, 'team', ?, ?, ?, 'high')");
        $st->bind_param('sssiii', $notifTitle, $notifBody, $notifLink, $fromId, $procId, $teamId);
        $st->execute();
        $st->close();

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

// شاخه‌بندی دستورات دریافتی
if ($arg1 === '--task' && is_numeric($arg2)) {
    // اجرای یک تسک خاص
    worker_run_revision_task($db, (int)$arg2);
} elseif (str_starts_with($arg1, '--task=') && is_numeric(substr($arg1, 7))) {
    // اجرای یک تسک با فرمت --task=ID
    worker_run_revision_task($db, (int)substr($arg1, 7));
} elseif (is_numeric($arg1) && (int)$arg1 > 0) {
    // ارزیابی یک پیشنهاد بهبود خاص
    $impId = (int)$arg1;
    echo "-> Evaluating Improvement ID: {$impId} ...\n";
    $result = hermes_evaluate_improvement($db, $impId);
    if ($result['success']) {
        echo "-> SUCCESS: Verdict is [{$result['verdict']}]\n";
        echo "   Summary: {$result['summary']}\n";
    } else {
        echo "-> FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
} else {
    // پردازش صف همه‌جانبه (Queue Mode): ارزیابی‌ها و بازنگری‌ها
    echo "-> Checking for pending tasks in queue...\n";

    // ۱. پردازش تسک‌های بازنگری معلق (HermesTasks)
    $stTasks = $db->query("SELECT ID FROM HermesTasks WHERE Status = 'queued' ORDER BY CreatedAt ASC LIMIT 5");
    if ($stTasks) {
        $taskRows = $stTasks->fetch_all(MYSQLI_ASSOC);
        if (!empty($taskRows)) {
            echo "-> Found " . count($taskRows) . " pending revision task(s).\n";
            foreach ($taskRows as $tr) {
                worker_run_revision_task($db, (int)$tr['ID']);
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
