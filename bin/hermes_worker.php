<?php
/**
 * پردازشگر پس‌زمینه ارزیابی هوشمند هرمس (Hermes Background Worker)
 * اجرای غیراسنکرون (Async) و زمان‌بندی‌شده (Scheduled Queue)
 * 
 * نحوه اجرا از خط فرمان:
 * php bin/hermes_worker.php 12        (ارزیابی یک پیشنهاد خاص)
 * php bin/hermes_worker.php --queue   (ارزیابی صف موارد معلق)
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

$db = app_db();

$arg = $argv[1] ?? '--queue';
$timeStart = microtime(true);

echo "[" . date('Y-m-d H:i:s') . "] Hermes Worker started with argument: {$arg}\n";

if (is_numeric($arg) && (int)$arg > 0) {
    // ارزیابی یک بهبود خاص
    $impId = (int)$arg;
    echo "-> Evaluating Improvement ID: {$impId} ...\n";
    $result = hermes_evaluate_improvement($db, $impId);
    if ($result['success']) {
        echo "-> SUCCESS: Verdict is [{$result['verdict']}]\n";
        echo "   Summary: {$result['summary']}\n";
    } else {
        echo "-> FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
} else {
    // پردازش صف (Queue Mode)
    echo "-> Checking for pending improvements in queue...\n";
    
    $limit = 5; // پردازش دسته‌ای برای مدیریت بار (Load Balancing)
    $st = $db->prepare("SELECT ID, Title FROM Improvements WHERE AiVerdict = 'pending' ORDER BY CreatedAt ASC LIMIT ?");
    $st->bind_param('i', $limit);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    if (empty($rows)) {
        echo "-> No pending improvements found in queue.\n";
    } else {
        echo "-> Found " . count($rows) . " pending item(s) to evaluate.\n";
        foreach ($rows as $item) {
            $itemId = (int)$item['ID'];
            echo "   * Processing #{$itemId} («{$item['Title']}»)... ";
            $res = hermes_evaluate_improvement($db, $itemId);
            if ($res['success']) {
                echo "DONE: Verdict = [{$res['verdict']}]\n";
            } else {
                echo "ERROR: " . ($res['error'] ?? 'Failed') . "\n";
            }
            // مکث کوتاه برای جلوگیری از فشار ناگهانی روی مدل
            usleep(500000); // 0.5s
        }
    }
}

$elapsed = round(microtime(true) - $timeStart, 2);
echo "[" . date('Y-m-d H:i:s') . "] Hermes Worker finished in {$elapsed}s.\n";

