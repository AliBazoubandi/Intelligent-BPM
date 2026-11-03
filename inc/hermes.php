<?php
/**
 * ارتباط با دستیار هوشمند هرمس (Hermes Agent)
 * ارزیابی هوشمند پیشنهادات بهبود، بررسی تعارضات و پارادوکس‌ها با سایر فرآیندها
 * پشتیبانی از اجرای غیراسنکرون (Async) و صف زمان‌بندی‌شده (Queue)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/files.php';
require_once __DIR__ . '/pdf_generator.php';

/**
 * بررسی در دسترس بودن سرویس هرمس
 */
function hermes_is_available(): bool
{
    if (!defined('HERMES_URL') || !HERMES_URL) return false;
    $modelsUrl = preg_replace('#/chat/completions/?$#i', '/models', HERMES_URL);
    if ($modelsUrl === HERMES_URL) {
        $parts = parse_url(HERMES_URL);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $modelsUrl = "{$scheme}://{$host}{$port}/v1/models";
    }
    $ch = curl_init($modelsUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (defined('HERMES_KEY') ? HERMES_KEY : '')
        ],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && is_string($res));
}

/**
 * ارسال پیام به هرمس از طریق OpenAI-compatible completions API
 */
function hermes_chat(array $messages, float $temperature = 0.2, int $maxTokens = 1200, ?int $timeout = null): ?string
{
    if (!defined('HERMES_URL') || !HERMES_URL) return null;
    $timeout = $timeout ?? (defined('HERMES_TIMEOUT') ? HERMES_TIMEOUT : 120);

    $payload = [
        'model' => defined('HERMES_MODEL') ? HERMES_MODEL : 'hermes-agent',
        'messages' => $messages,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
    ];

    $ch = curl_init(HERMES_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (defined('HERMES_KEY') ? HERMES_KEY : ''),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($response)) {
        return null;
    }

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

/**
 * پیدا کردن مسیر باینری PHP برای اجرای کارهای پس‌زمینه (Worker)
 */
function hermes_find_php_binary(): string
{
    if (defined('PHP_BIN') && PHP_BIN !== '' && file_exists(PHP_BIN)) {
        return PHP_BIN;
    }
    if (PHP_BINARY && file_exists(PHP_BINARY)) {
        $base = strtolower(basename(PHP_BINARY));
        if (str_contains($base, 'php') && !str_ends_with($base, 'php-cgi.exe') && !str_contains($base, 'httpd') && !str_contains($base, 'apache')) {
            return PHP_BINARY;
        }
    }
    $candidates = [
        'C:\\xampp\\php\\php.exe',
        'C:\\tools\\php\\php.exe',
        'C:\\php\\php.exe',
        '/usr/bin/php',
        '/usr/local/bin/php',
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand)) return $cand;
    }
    return 'php';
}

/**
 * ارسال غیراسنکرون (Async) کار ارزیابی به ورکر پس‌زمینه
 * این تابع بلافاصله و در کسری از ثانیه بازمی‌گردد بدون اینکه فرآیند وب متوقف شود.
 *
 * @param int $improvementId شناسه بهبود (یا 0 برای پردازش صف)
 */
function hermes_dispatch_async_eval(int $improvementId = 0): bool
{
    if ($improvementId > 0) {
        try {
            $db = app_db();
            $st = $db->prepare("SELECT AiVerdict FROM Improvements WHERE ID = ?");
            $st->bind_param('i', $improvementId);
            $st->execute();
            $cur = $st->get_result()->fetch_assoc();
            $st->close();
            if ($cur && in_array($cur['AiVerdict'], ['yes', 'no'], true)) {
                return true; // قبلاً ارزیابی شده؛ از ارسال تکراری جلوگیری شد
            }
        } catch (Throwable $e) {
            // در صورت بروز خطا در بررسی، روند عادی ادامه می‌یابد
        }
    }

    $phpBin = hermes_find_php_binary();
    $workerPath = realpath(__DIR__ . '/../bin/hermes_worker.php');
    if (!$workerPath || !file_exists($workerPath)) {
        return false;
    }

    $arg = ($improvementId > 0) ? (string)$improvementId : '--queue';
    $logFile = defined('APP_ROOT') ? (APP_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker.log') : (__DIR__ . '/../bin/worker.log');

    if (stripos(PHP_OS, 'WIN') === 0) {
        // ویندوز: استفاده از cmd /c start /B تا خروجی پردازه فرزند به فایل لاگ ارسال شود
        $cmd = sprintf('start /B "" cmd /c ""%s" "%s" %s >> "%s" 2>&1"', $phpBin, $workerPath, escapeshellarg($arg), $logFile);
        $handle = @popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
            return true;
        }
    } else {
        // لینوکس / یونیکس
        $cmd = sprintf('"%s" "%s" %s >> "%s" 2>&1 &', $phpBin, $workerPath, escapeshellarg($arg), $logFile);
        @exec($cmd);
        return true;
    }

    return false;
}

/**
 * ارسال غیراسنکرون (Async) عملیات بازنگری، بایگانی و تدوین سند فرآیند به ورکر پس‌زمینه هرمس
 * بلافاصله تسک را در دیتابیس ثبت کرده و کنترل را در چند میلی‌ثانیه به کاربر بازمی‌گرداند.
 *
 * @param mysqli $db
 * @param int $improvementId شناسه پیشنهاد بهبود
 * @param int $teamId شناسه تیم انجام‌دهنده
 * @param array|null $revisionData اطلاعات بازنگری (اختیاری)
 * @return int شناسه تسک ایجادشده در جدول HermesTasks (یا 0 در صورت خطا)
 */
function hermes_dispatch_async_revision(mysqli $db, int $improvementId, int $teamId, ?array $revisionData = null): int
{
    $payload = ($revisionData !== null) ? json_encode($revisionData, JSON_UNESCAPED_UNICODE) : null;
    $st = $db->prepare("INSERT INTO HermesTasks (TaskType, RefID, TeamID, Status, Payload) VALUES ('revision', ?, ?, 'queued', ?)");
    $st->bind_param('iis', $improvementId, $teamId, $payload);
    $st->execute();
    $taskId = (int)$db->insert_id;
    $st->close();

    if ($taskId <= 0) {
        return 0;
    }

    $phpBin = hermes_find_php_binary();
    $workerPath = realpath(__DIR__ . '/../bin/hermes_worker.php');
    if (!$workerPath || !file_exists($workerPath)) {
        return $taskId;
    }

    $arg = '--task ' . $taskId;
    $logFile = defined('APP_ROOT') ? (APP_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker.log') : (__DIR__ . '/../bin/worker.log');

    if (stripos(PHP_OS, 'WIN') === 0) {
        $cmd = sprintf('start /B "" cmd /c ""%s" "%s" %s >> "%s" 2>&1"', $phpBin, $workerPath, $arg, $logFile);
        $handle = @popen($cmd, 'r');
        if ($handle) {
            pclose($handle);
        }
    } else {
        $cmd = sprintf('"%s" "%s" %s >> "%s" 2>&1 &', $phpBin, $workerPath, $arg, $logFile);
        @exec($cmd);
    }

    return $taskId;
}

/**
 * ارزیابی تخصصی یک پیشنهاد بهبود توسط هرمس
 * بررسی پارادوکس‌ها و عوارض جانبی بر سایر فرآیندهای سازمان
 *
 * @return array{success:bool, verdict:string, summary:string, analysis:string, raw:?string, error:?string}
 */
function hermes_evaluate_improvement(mysqli $db, int $improvementId): array
{
    // دریافت اطلاعات بهبود و فرآیند هدف
    $st = $db->prepare(
        "SELECT i.*, p.Name AS ProcName, p.Description AS ProcDesc, cat.Title AS CatTitle, p.Version, p.Status AS ProcStatus
         FROM Improvements i
         JOIN Processes p ON p.ID = i.ProcessID
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         WHERE i.ID = ?"
    );
    $st->bind_param('i', $improvementId);
    $st->execute();
    $imp = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$imp) {
        return ['success' => false, 'verdict' => 'error', 'summary' => '', 'analysis' => '', 'raw' => null, 'error' => 'بهبود یافت نشد.'];
    }

    // دریافت فهرست سایر فرآیندهای سازمان برای بررسی تعارض و پارادوکس
    $otherProcs = [];
    $procId = (int)$imp['ProcessID'];
    $st = $db->prepare(
        "SELECT p.Name, p.Description, cat.Title AS CatTitle, p.Version, p.Status
         FROM Processes p
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         WHERE p.ID != ? AND p.Status != 'archived'
         ORDER BY cat.SortOrder, p.Name"
    );
    $st->bind_param('i', $procId);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $otherProcs[] = $r;
    }
    $st->close();

    // ساخت لیست سایر فرآیندها برای پرامپت
    $procsListText = "";
    foreach ($otherProcs as $idx => $op) {
        $num = $idx + 1;
        $desc = trim((string)$op['Description']) ?: 'بدون شرح تفصیلی';
        $procsListText .= "{$num}. «{$op['Name']}» (دسته: {$op['CatTitle']}) — شرح: {$desc}\n";
    }

    $systemPrompt = "شما «هرمس»، مشاور ارشد و داور هوشمند مهندسی فرآیندهای سازمانی هستید.\n"
        . "وظیفه شما: یک کاربر سازمانی پیشنهادی برای بهبود یکی از فرآیندها ارسال کرده است. شما باید این پیشنهاد را در بافت کلان سازمان تحلیل کنید:\n"
        . "1. آیا این بهبود منطقی، مفید و کارآمد است یا خیر؟\n"
        . "2. آیا با سایر فرآیندهای سازمان دچار تناقض، تداخل یا پارادوکس (Paradox/Conflict) می‌شود؟\n"
        . "3. چه عوارض جانبی منفی (Side Effects) نظیر خلأهای نظارتی، گلوگاه‌های کاری، نقض یکپارچگی داده‌ها یا ریسک‌های اجرایی ممکن است ایجاد کند؟\n"
        . "4. رأی نهایی شما به عنوان داور چیست: آیا تیم فرآیند این بهبود را تأیید کند (YES) یا رد کند (NO)؟\n"
        . "توجه مهم: هیچ‌گونه ابزار سیستمی یا دستور خارجی اجرا نکنید؛ پاسخ تحلیلی خود را مستقیماً در قالب بلوک JSON خالص ارائه دهید.";

    $userPrompt = "اطلاعات فرآیند هدف:\n"
        . "نام فرآیند: {$imp['ProcName']}\n"
        . "دسته فرآیند: {$imp['CatTitle']}\n"
        . "شرح فرآیند: " . ($imp['ProcDesc'] ?: 'ثبت نشده') . "\n\n"
        . "پیشنهاد بهبود ارائه شده توسط کاربر:\n"
        . "عنوان بهبود: {$imp['Title']}\n"
        . "شرح بهبود: " . ($imp['Description'] ?: 'توضیحی ارائه نشده است') . "\n\n"
        . "سایر فرآیندهای فعال در سازمان برای سنجش تناقض و پارادوکس:\n"
        . ($procsListText ?: "فرآیند فعال دیگری ثبت نشده است.") . "\n\n"
        . "لطفاً تحلیل کامل خود را به زبان فارسی و در ساختار JSON زیر ارسال فرمایید:\n"
        . "{\n"
        . '  "verdict": "YES" یا "NO",' . "\n"
        . '  "summary": "خلاصه نظر داور در ۱ الی ۲ جمله",' . "\n"
        . '  "paradox_check": "تحلیل تعارض و پارادوکس با سایر فرآیندها (اگر تعارضی نیست، بنویسید بدون تعارض)",' . "\n"
        . '  "side_effects": "عوارض جانبی و ریسک‌های احتمالی این پیشنهاد",' . "\n"
        . '  "recommendation": "توصیه راهبردی و راهنمای تصمیم‌گیری برای عضو تیم فرآیند"' . "\n"
        . "}";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];

    $raw = hermes_chat($messages, 0.2, 1000);

    if (!$raw) {
        $st = $db->prepare("UPDATE Improvements SET AiVerdict = 'error', AiAnalysis = 'خطا در ارتباط با سرویس هوش مصنوعی هرمس (تایم‌اوت یا عدم پاسخگویی).', AiEvaluatedAt = NOW() WHERE ID = ?");
        $st->bind_param('i', $improvementId);
        $st->execute();
        $st->close();
        return ['success' => false, 'verdict' => 'error', 'summary' => '', 'analysis' => 'خطا در برقراری ارتباط با هرمس.', 'raw' => null, 'error' => 'عدم پاسخگویی سرویس هرمس'];
    }

    // بررسی خطاهای موقت پرووایدر بالادست
    if (str_contains($raw, "didn't respond in time") || str_contains($raw, "Request timed out") || str_contains($raw, "temporarily unavailable")) {
        $st = $db->prepare("UPDATE Improvements SET AiVerdict = 'error', AiAnalysis = ?, AiEvaluatedAt = NOW() WHERE ID = ?");
        $errMsg = 'سرویس بالادست هرمس موقتاً پاسخ نداد (تایم‌اوت شبکه). لطفاً پس از چند لحظه دکمه «ارزیابی مجدد» را بزنید.';
        $st->bind_param('si', $errMsg, $improvementId);
        $st->execute();
        $st->close();
        return ['success' => false, 'verdict' => 'error', 'summary' => '', 'analysis' => $errMsg, 'raw' => $raw, 'error' => $errMsg];
    }

    // استخراج JSON از پاسخ
    $cleanJson = $raw;
    if (preg_match('/\{[\s\S]*\}/u', $raw, $m)) {
        $cleanJson = $m[0];
    }

    $parsed = json_decode($cleanJson, true);
    $verdict = 'pending';
    $analysisText = '';
    $summary = '';

    if (is_array($parsed) && isset($parsed['verdict'])) {
        $vUpper = strtoupper(trim((string)$parsed['verdict']));
        $verdict = ($vUpper === 'YES') ? 'yes' : 'no';
        $summary = trim((string)($parsed['summary'] ?? ''));
        $analysisText = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        // Fallback: check negative verdicts first to prevent false approvals on phrases like "عدم تأیید" or "مورد تأیید نیست"
        if (preg_match('/\b(NO|رد|مخالفت|عدم\s+تأیید|عدم\s+تایید|تأیید\s+نمی‌شود|تایید\s+نمی‌شود|مورد\s+تأیید\s+نیست|مورد\s+تایید\s+نیست)\b/ui', $raw)) {
            $verdict = 'no';
        } elseif (preg_match('/\b(YES|تأیید|تایید|موافقت)\b/ui', $raw)) {
            $verdict = 'yes';
        }
        $analysisText = $raw;
        $summary = mb_strimwidth(strip_tags($raw), 0, 150, '…');
    }

    // ذخیره در دیتابیس
    $st = $db->prepare("UPDATE Improvements SET AiVerdict = ?, AiAnalysis = ?, AiEvaluatedAt = NOW() WHERE ID = ?");
    $st->bind_param('ssi', $verdict, $analysisText, $improvementId);
    $st->execute();
    $st->close();

    return [
        'success' => true,
        'verdict' => $verdict,
        'summary' => $summary,
        'analysis' => $analysisText,
        'raw' => $raw,
        'error' => null,
    ];
}

/**
 * ایجاد نشان بصری (Badge) برای وضعیت رأی داور هرمس
 */
function hermes_badge(?string $verdict): string
{
    return match ($verdict) {
        'yes' => '<span class="badge badge-ai-yes" title="داور هوش مصنوعی هرمس: پیشنهاد مفید و بدون تعارض تشخیص داده شد">' . app_icon('check', '', 13) . ' <span>هرمس: تأیید (YES)</span></span>',
        'no' => '<span class="badge badge-ai-no" title="داور هوش مصنوعی هرمس: دارای تعارض، پارادوکس یا عوارض جانبی">' . app_icon('x', '', 13) . ' <span>هرمس: عدم تأیید (NO)</span></span>',
        'error' => '<span class="badge badge-ai-error" title="خطا در ارتباط با سرویس هرمس">' . app_icon('refresh', '', 13) . ' <span>هرمس: نیاز به بررسی</span></span>',
        default => '<span class="badge badge-ai-pending" title="در صف پردازش داور هوشمند هرمس">' . app_icon('refresh', 'ai-spin', 13) . ' <span>هرمس: در صف ارزیابی...</span></span>',
    };
}

/**
 * تبدیل تحلیل JSON هرمس به یک جعبه نمایش زیبا و خوانا
 */
function hermes_render_card(?string $aiVerdict, ?string $aiAnalysis, ?string $aiEvaluatedAt, int $impId, bool $canReevaluate = true): string
{
    if (!$aiVerdict || $aiVerdict === 'pending') {
        $html = '<div class="ai-box ai-box-pending">';
        $html .= '<div class="ai-box-head"><span style="display:inline-flex;align-items:center;gap:7px">' . app_icon('bot', '', 18) . '<b>داور فرآیند: هوش مصنوعی هرمس</b></span>' . hermes_badge($aiVerdict) . '</div>';
        $html .= '<p class="ai-box-msg" style="margin:8px 0;font-size:13px;color:var(--muted)">این پیشنهاد در صف ارزیابی خودکار هرمس قرار دارد. به محض اتمام تحلیل، بررسی تعارض با سایر فرآیندها و نظر داور در اینجا نمایش داده خواهد شد.</p>';
        if ($canReevaluate) {
            $html .= '<form method="post" class="inline-form" style="margin-top:8px">';
            $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
            $html .= '<input type="hidden" name="action" value="hermes_eval">';
            $html .= '<input type="hidden" name="imp_id" value="' . $impId . '">';
            $html .= '<button class="btn btn-sm btn-ai" style="display:inline-flex;align-items:center;gap:6px">' . app_icon('bolt', '', 14) . '<span>اجرای فوری ارزیابی هرمس</span></button>';
            $html .= '</form>';
        }
        $html .= '</div>';
        return $html;
    }

    $isYes = ($aiVerdict === 'yes');
    $isNo = ($aiVerdict === 'no');
    $boxClass = $isYes ? 'ai-box-yes' : ($isNo ? 'ai-box-no' : 'ai-box-error');

    $parsed = json_decode((string)$aiAnalysis, true);

    $html = '<div class="ai-box ' . $boxClass . '">';
    $html .= '<div class="ai-box-head">';
    $html .= '<span class="ai-title" style="display:inline-flex;align-items:center;gap:7px">' . app_icon('bot', '', 19) . '<b>نظر داور هرمس (Hermes Agent)</b>';
    if ($aiEvaluatedAt) {
        $html .= ' <small class="sub" style="margin-inline-start:8px">(' . e(fmt_date($aiEvaluatedAt)) . ')</small>';
    }
    $html .= '</span>';
    $html .= '<div style="display:flex;gap:8px;align-items:center">' . hermes_badge($aiVerdict);
    if ($canReevaluate) {
        $html .= '<form method="post" class="inline-form" style="margin:0">';
        $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        $html .= '<input type="hidden" name="action" value="hermes_eval">';
        $html .= '<input type="hidden" name="imp_id" value="' . $impId . '">';
        $html .= '<button class="btn btn-xs btn-ghost" title="ارزیابی مجدد با آخرین تغییرات فرآیندها" style="display:inline-flex;align-items:center;gap:5px">' . app_icon('refresh', '', 13) . '<span>ارزیابی مجدد</span></button>';
        $html .= '</form>';
    }
    $html .= '</div>';
    $html .= '</div>';

    if (is_array($parsed)) {
        if (!empty($parsed['summary'])) {
            $html .= '<div class="ai-sec ai-summary"><b>خلاصه داور:</b> ' . e($parsed['summary']) . '</div>';
        }
        if (!empty($parsed['paradox_check'])) {
            $html .= '<div class="ai-sec"><b style="display:inline-flex;align-items:center;gap:6px">' . app_icon('search', '', 15) . 'بررسی تعارض و پارادوکس با سایر فرآیندها:</b><br>' . nl2br(e($parsed['paradox_check'])) . '</div>';
        }
        if (!empty($parsed['side_effects'])) {
            $html .= '<div class="ai-sec"><b style="display:inline-flex;align-items:center;gap:6px">' . app_icon('basics', '', 15) . 'عوارض جانبی و تبعات احتمالی:</b><br>' . nl2br(e($parsed['side_effects'])) . '</div>';
        }
        if (!empty($parsed['recommendation'])) {
            $html .= '<div class="ai-sec ai-rec"><b style="display:inline-flex;align-items:center;gap:6px">' . app_icon('improvements', '', 15) . 'توصیه راهبردی برای تصمیم‌گیرنده:</b><br>' . nl2br(e($parsed['recommendation'])) . '</div>';
        }
    } else {
        $html .= '<div class="ai-sec">' . nl2br(e((string)$aiAnalysis)) . '</div>';
    }

    if ($isYes && $canReevaluate) {
        $html .= '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed rgba(22,163,74,0.3);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">';
        $html .= '<span style="font-size:12.5px;color:var(--muted)">داور هرمس این بهینه‌سازی را مفید و بدون تعارض تشخیص داده است؛ می‌توانید دستورالعمل و فایل PDF فرآیند را به‌صورت خودکار به‌روزرسانی کنید.</span>';
        $html .= '<a href="revision.php?imp=' . $impId . '" class="btn btn-sm btn-brand" style="display:inline-flex;align-items:center;gap:6px">' . app_icon('bot', '', 14) . '<span>اعمال بهینه‌سازی و بازنویسی سند با هرمس</span></a>';
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}


/**
 * نرمال‌سازی متون فارسی (یکسان‌سازی حروف عربی/فارسی، نیم‌فاصله‌ها و علائم)
 */
function hermes_normalize_persian(string $text): string
{
    $text = mb_strtolower($text);
    // یکسان‌سازی حروف کاف و یای عربی با فارسی
    $text = str_replace(['ي', 'ك', 'ك', 'ى', 'ئ', 'ة'], ['ی', 'ک', 'ک', 'ی', 'ی', 'ه'], $text);
    // تبدیل نیم‌فاصله، فاصله‌های باریک و اعراب به فاصله عادی
    $text = preg_replace('/[\x{200C}\x{200B}\x{200D}\x{FEFF}\p{M}\p{P}\p{S}]+/u', ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * سیستم بازیابی و پاسخگویی به فرآیندهای سازمان (Process RAG System)
 * جستجوی نزدیک‌ترین فرآیندها، استخراج مستندات و ارسال به هرمس
 *
 * @param mysqli $db
 * @param string $userQuery پرسش کاربر
 * @param array $chatHistory سابقه پیام‌های قبلی [['role' => 'user'|'assistant', 'content' => '...']]
 * @return array{success:bool, answer:string, related_processes:array, error:?string}
 */
function hermes_process_rag_query(mysqli $db, string $userQuery, array $chatHistory = []): array
{
    $q = trim($userQuery);
    if ($q === '') {
        return ['success' => false, 'answer' => '', 'related_processes' => [], 'error' => 'لطفاً پرسش خود را مطرح کنید.'];
    }

    // 1. دریافت تمام فرآیندهای فعال سازمان
    $st = $db->query(
        "SELECT p.ID, p.Name, p.Description, p.Version, p.Status, p.FilePath,
                cat.FolderName, cat.Title AS CatTitle, cat.FolderPath AS CatFolder,
                t.TeamName,
                u.Name AS OwnerName, u.Family AS OwnerFamily
         FROM Processes p
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID
         LEFT JOIN Personnel u ON u.ID = p.OwnerPersonnelID
         WHERE p.Status != 'archived'
         ORDER BY cat.SortOrder, p.Name"
    );

    $allProcs = [];
    while ($r = $st->fetch_assoc()) {
        $allProcs[] = $r;
    }

    if (empty($allProcs)) {
        return [
            'success' => true,
            'answer' => 'در حال حاضر هیچ فرآیند فعالی در سامانه ثبت نشده است.',
            'related_processes' => [],
            'error' => null
        ];
    }

    // 2. کلمه‌بندی و پاکسازی پرسمان برای سنجش شباهت و امتیازدهی
    $cleanQuery = hermes_normalize_persian($q);
    $words = array_filter(explode(' ', $cleanQuery), fn($w) => mb_strlen($w) >= 2);

    $stopwords = ['است', 'برای', 'چگونه', 'چطور', 'آیا', 'هایی', 'های', 'می', 'کند', 'شود', 'باید', 'کدام', 'این', 'آن', 'با', 'در', 'از', 'به', 'رو', 'که', 'یک', 'تا'];
    $meaningfulWords = array_diff($words, $stopwords);
    if (empty($meaningfulWords)) {
        $meaningfulWords = $words;
    }

    // 3. امتیازدهی به هر فرآیند (Scoring & Ranking)
    $scored = [];
    foreach ($allProcs as $proc) {
        $score = 0;
        $nameNorm = hermes_normalize_persian($proc['Name']);
        $descNorm = hermes_normalize_persian((string)$proc['Description']);
        $catNorm = hermes_normalize_persian((string)$proc['CatTitle']);
        $teamNorm = hermes_normalize_persian((string)$proc['TeamName']);

        // تطابق کامل نام در پرسمان
        if ($nameNorm !== '' && str_contains($cleanQuery, $nameNorm)) {
            $score += 60;
        }

        // تطابق تک‌تک کلمات
        foreach ($meaningfulWords as $w) {
            if (str_contains($nameNorm, $w)) $score += 20;
            if (str_contains($descNorm, $w)) $score += 10;
            if (str_contains($catNorm, $w)) $score += 8;
            if (str_contains($teamNorm, $w)) $score += 6;
        }

        // بررسی فایل‌های متنی روی دیسک (پیوست‌ها و دستورالعمل‌ها)
        $docExcerpt = '';
        $relDir = trim((string)$proc['FilePath'], '/');
        if ($relDir === '') {
            $relDir = $proc['FolderName'] . '/' . $proc['Name'];
        }
        $absDir = PROCESSES_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (is_dir($absDir)) {
            foreach (scandir($absDir) as $f) {
                if (str_ends_with($f, '.txt') || str_ends_with($f, '.md')) {
                    $docContent = @file_get_contents($absDir . DIRECTORY_SEPARATOR . $f);
                    if ($docContent) {
                        $docNorm = hermes_normalize_persian($docContent);
                        foreach ($meaningfulWords as $w) {
                            if (str_contains($docNorm, $w)) $score += 6;
                        }
                        if (mb_strlen($docExcerpt) < 500) {
                            $docExcerpt .= "\n[فایل " . $f . "]: " . mb_strimwidth(trim($docContent), 0, 400, '…');
                        }
                    }
                }
            }
        }

        $proc['doc_excerpt'] = $docExcerpt;
        $proc['rag_score'] = $score;
        $scored[] = $proc;
    }

    // مرتب‌سازی بر اساس امتیاز
    usort($scored, fn($a, $b) => $b['rag_score'] <=> $a['rag_score']);

    // فرآیندهای برتر با ارتباط معنادار
    $topMatches = array_filter($scored, fn($p) => $p['rag_score'] > 0);
    if (empty($topMatches)) {
        $topMatches = array_slice($scored, 0, 3);
    } else {
        $topMatches = array_slice($topMatches, 0, 4);
    }

    $relatedProcesses = array_map(fn($p) => [
        'id' => (int)$p['ID'],
        'name' => $p['Name'],
        'cat' => $p['CatTitle'],
        'team' => $p['TeamName'] ?? '—',
        'version' => $p['Version']
    ], $topMatches);

    // 4. ساخت بلوک کانتکست RAG برای هرمس
    $ragContext = "اطلاعات استخراج‌شده از فرآیندهای سازمان (مستندات و ضوابط):\n\n";
    foreach ($topMatches as $idx => $tp) {
        $num = $idx + 1;
        $owner = trim((string)$tp['OwnerName'] . ' ' . (string)$tp['OwnerFamily']) ?: 'تعیین نشده';
        $ragContext .= "### فرآیند شماره {$num}: «{$tp['Name']}» (شناسه: {$tp['ID']})\n";
        $ragContext .= "- دسته‌بندی: {$tp['CatTitle']}\n";
        $ragContext .= "- تیم مسئول پاسخگویی: " . ($tp['TeamName'] ?: 'تیم فرآیند') . "\n";
        $ragContext .= "- مالک فرآیند: {$owner}\n";
        $ragContext .= "- نسخه فعال: {$tp['Version']}\n";
        $ragContext .= "- شرح و هدف فرآیند: " . ($tp['Description'] ?: 'ثبت نشده') . "\n";
        if (!empty($tp['doc_excerpt'])) {
            $ragContext .= "- خلاصه مستندات و آیین‌نامه‌ها: {$tp['doc_excerpt']}\n";
        }
        $ragContext .= "\n";
    }

    $ragContext .= "فهرست سایر فرآیندهای سازمان برای آگاهی و ارجاع احتمالی:\n";
    foreach ($allProcs as $ap) {
        if (!in_array((int)$ap['ID'], array_column($relatedProcesses, 'id'), true)) {
            $ragContext .= "• «{$ap['Name']}» (دسته: {$ap['CatTitle']})\n";
        }
    }

    // 5. ارسال به مدل هرمس با پرامپت راهنمای فرآیند
    $systemPrompt = "شما «دستیار هوشمند فرآیندهای سازمان» (Process AI Assistant) هستید که با نام «هرمس» فعالیت می‌کنید.\n"
        . "وظیفه شما: پاسخ دقیق، مستند، گام‌به‌گام و سازمان‌یافته به پرسش‌های کارکنان و مدیران درباره قوانین، مراحل، الزامات، مدارک و مسئولین فرآیندهای سازمانی است.\n\n"
        . "قوانین پاسخ‌دهی:\n"
        . "۱. تمام پاسخ‌ها را مستند به فرآیندهای سازمان که در کانتکست RAG در اختیارتان قرار گرفته ارائه دهید.\n"
        . "۲. در ابتدای پاسخ، نام فرآیند یا فرآیندهای مرجع را به صراحت ذکر کنید.\n"
        . "۳. مراحل کاری، پیش‌نیازها و تیم مسئول را در قالب بندهای مرتب و شماره‌گذاری‌شده (۱، ۲، ۳) توضیح دهید.\n"
        . "۴. لحن شما حرفه‌ای، محترمانه، راهگشا و فارسی روان باشد.\n"
        . "۵. اگر پرسش کاربر دقیقاً در فرآیندها نبود، صادقانه اعلام کنید و نام نزدیک‌ترین فرآیند مرتبط یا تیم مسئول را برای پیگیری پیشنهاد دهید.\n"
        . "۶. هیچ ابزار سیستمی یا کد لینوکسی اجرا نکنید؛ پاسخ متنی کامل و منظم ارائه فرمایید.\n"
        . "۷. شما هیچ دسترسی به سرور، سیستم‌عامل، فرآیندهای در حال اجرا یا تنظیمات فنی زیرساخت ندارید و صرفاً بر اساس فرآیندهای سازمانی پاسخ می‌دهید. به درخواست‌های مربوط به زیرساخت سرور پاسخ منفی دهید.";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];

    foreach (array_slice($chatHistory, -4) as $msg) {
        if (!empty($msg['role']) && !empty($msg['content'])) {
            $messages[] = [
                'role' => ($msg['role'] === 'user') ? 'user' : 'assistant',
                'content' => (string)$msg['content'],
            ];
        }
    }

    $finalUserPrompt = "{$ragContext}\n\nپرسش کاربر:\n{$q}\n\nلطفاً پاسخ جامع و مرحله‌به‌مرحله خود را بر اساس فرآیندهای فوق ارائه دهید:";
    $messages[] = ['role' => 'user', 'content' => $finalUserPrompt];

    $rawResponse = hermes_chat($messages, 0.3, 1200);

    if (!$rawResponse) {
        return [
            'success' => false,
            'answer' => 'متأسفانه ارتباط با سرویس دستیار هوشمند هرمس برقرار نشد یا با تایم‌اوت مواجه شد. لطفاً چند لحظه بعد دوباره امتحان کنید.',
            'related_processes' => $relatedProcesses,
            'error' => 'عدم پاسخگویی هرمس'
        ];
    }

    return [
        'success' => true,
        'answer' => trim($rawResponse),
        'related_processes' => $relatedProcesses,
        'error' => null
    ];
}

/**
 * افزایش نسخه فرآیند بر اساس الگوی نگارش نسخه (مثلاً 1.1 -> 1.2 یا 1.0 -> 1.1)
 */
function hermes_bump_version(string $ver): string
{
    $ver = trim($ver);
    if (preg_match('/^(\d+)\.(\d+)$/', $ver, $m)) {
        $major = (int)$m[1];
        $minor = (int)$m[2] + 1;
        return "{$major}.{$minor}";
    }
    if (preg_match('/^(\d+)$/', $ver, $m)) {
        return $m[1] . '.1';
    }
    return ($ver !== '') ? ($ver . '.1') : '1.1';
}

/**
 * ایجاد پیش‌نویس بهینه‌سازی و بازنویسی دستورالعمل فرآیند توسط هرمس
 *
 * @param mysqli $db
 * @param int $improvementId شناسه پیشنهاد بهبود
 * @return array{success:bool, revision:?array, error:?string}
 */
function hermes_draft_procedure_revision(mysqli $db, int $improvementId): array
{
    // دریافت اطلاعات بهبود و فرآیند
    $st = $db->prepare(
        "SELECT i.*, p.ID AS Pid, p.Name AS ProcName, p.Description AS ProcDesc, p.Version AS CurrentVersion,
                p.FilePath, p.ResponsibleTeamID, p.OwnerPersonnelID,
                cat.Title AS CatTitle, cat.FolderName,
                comp.Name AS CompanyName,
                team.TeamName AS RespTeamName,
                u.Name AS ReqName, u.Family AS ReqFamily
         FROM Improvements i
         JOIN Processes p ON p.ID = i.ProcessID
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN Companies comp ON comp.ID = p.CompanyID
         LEFT JOIN ProcessTeams team ON team.ID = p.ResponsibleTeamID
         LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type = 'user'
         WHERE i.ID = ?"
    );
    $st->bind_param('i', $improvementId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        return ['success' => false, 'revision' => null, 'error' => 'پیشنهاد بهبود یافت نشد.'];
    }

    $currentVersion = trim((string)$row['CurrentVersion']) ?: '1.0';
    $suggestedVersion = hermes_bump_version($currentVersion);

    // دریافت نقش‌های مجری فرآیند
    $executors = [];
    $st = $db->prepare("SELECT pe.*, p.Name AS PersName, p.Family AS PersFamily, t.TeamName
                        FROM ProcessExecutors pe
                        LEFT JOIN Personnel p ON p.ID = pe.PersonnelID
                        LEFT JOIN ProcessTeams t ON t.ID = pe.TeamID
                        WHERE pe.ProcessID = ?");
    $st->bind_param('i', $row['Pid']);
    $st->execute();
    $res = $st->get_result();
    while ($ex = $res->fetch_assoc()) {
        $executors[] = $ex;
    }
    $st->close();

    // دریافت سوابق تغییرات قبلی
    $history = [];
    $st = $db->prepare("SELECT * FROM Changes WHERE ProcessID = ? ORDER BY ID DESC LIMIT 5");
    $st->bind_param('i', $row['Pid']);
    $st->execute();
    $res = $st->get_result();
    while ($ch = $res->fetch_assoc()) {
        $history[] = $ch;
    }
    $st->close();

    // خواندن فایل‌های متنی یا دستورالعمل‌های فعلی فرآیند
    $relDir = fm_normalize_rel((string)$row['FilePath']);
    if ($relDir === '') {
        $relDir = fm_normalize_rel($row['FolderName'] . '/' . $row['ProcName']);
    }
    $absDir = PROCESSES_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
    $existingDocsText = '';
    if (is_dir($absDir)) {
        foreach (scandir($absDir) as $f) {
            if (str_starts_with($f, '.')) continue;
            if (str_ends_with($f, '.txt') || str_ends_with($f, '.md')) {
                $content = @file_get_contents($absDir . DIRECTORY_SEPARATOR . $f);
                if ($content) {
                    $existingDocsText .= "\n[سند فعلی: {$f}]\n" . trim($content) . "\n";
                }
            }
        }
    }
    if ($existingDocsText === '') {
        $existingDocsText = trim((string)$row['ProcDesc']) ?: 'دستورالعمل اولیه ثبت نشده است.';
    }

    // ساخت پرامپت اختصاصی برای بازنویسی دستورالعمل توسط هرمس
    $systemPrompt = "شما «هرمس»، مشاور ارشد مهندسی فرآیندهای سازمانی و متخصص تدوین مستندات ایزو و BPMN هستید.\n"
        . "وظیفه شما: یک پیشنهاد بهبود برای فرآیند مورد نظر تصویب شده است. شما باید دستورالعمل اجرایی فرآیند را بازنویسی کنید تا این بهبود به صورت ساختاریافته، شفاف و کاربردی در گام‌های فرآیند تلفیق شود.\n"
        . "خروجی شما باید منحصراً یک ساختار JSON معتبر و بدون هیچ متن اضافی قبل یا بعد از آن باشد.";

    $userPrompt = "مشخصات فرآیند سازمانی:\n"
        . "- نام فرآیند: {$row['ProcName']}\n"
        . "- دسته‌بندی: {$row['CatTitle']}\n"
        . "- نسخه فعلی: {$currentVersion}\n"
        . "- تیم مسئول: " . ($row['RespTeamName'] ?: 'تیم فرآیند') . "\n"
        . "- مستندات و ضوابط فعلی فرآیند:\n{$existingDocsText}\n\n"
        . "مشخصات پیشنهاد بهبود مصوب:\n"
        . "- عنوان بهبود: {$row['Title']}\n"
        . "- شرح بهبود ارائه شده: " . ($row['Description'] ?: '—') . "\n"
        . "- تحلیل و ارزیابی پیشین هرمس: " . ($row['AiAnalysis'] ?: 'پیشنهاد مفید و بدون تعارض تشخیص داده شده است') . "\n\n"
        . "لطفاً پیش‌نویس بازنگری دستورالعمل را به زبان فارسی و در قالب ساختار JSON زیر تدوین نمایید:\n"
        . "{\n"
        . '  "suggested_version": "' . $suggestedVersion . '",' . "\n"
        . '  "change_summary": "شرح خلاصه تغییرات و اصلاحات اعمال‌شده در این نسخه",' . "\n"
        . '  "purpose": "هدف و دامنه کاربرد دستورالعمل بازنگری‌شده",' . "\n"
        . '  "steps": [' . "\n"
        . '    {"step": 1, "role": "عنوان نقش مسئول اقدام", "action": "شرح شفاف و گام‌به‌گام اقدام اجرایی", "notes": "الزامات، فرم‌ها و نکات کنترلی"}' . "\n"
        . '  ],' . "\n"
        . '  "optimization_impact": "تاثیر و دستاورد عملیاتی این بهینه‌سازی بر سرعت، کیفیت و کارایی فرآیند"' . "\n"
        . "}";

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];

    $raw = hermes_chat($messages, 0.2, 1600, 45);

    $parsed = null;
    if ($raw && preg_match('/\{[\s\S]*\}/u', $raw, $m)) {
        $parsed = json_decode($m[0], true);
    }

    // در صورت در دسترس نبودن هوش مصنوعی یا خطا، ایجاد پیش‌نویس هوشمند جایگزین (Fallback)
    if (!is_array($parsed) || empty($parsed['steps'])) {
        $changeDesc = "اعمال پیشنهاد بهبود «{$row['Title']}»؛ بهینه‌سازی گام‌های اجرایی و رفع موانع کاری.";
        $parsed = [
            'suggested_version' => $suggestedVersion,
            'change_summary' => $changeDesc,
            'purpose' => "هدف از این دستورالعمل، استانداردسازی و تسریع در اجرای فرآیند «{$row['ProcName']}» با لحاظ آخرین بهینه‌سازی‌های سازمانی است.",
            'steps' => [
                [
                    'step' => 1,
                    'role' => 'متقاضی / آغازگر',
                    'action' => 'ثبت الکترونیکی درخواست و بارگذاری کلیه مستندات مربوطه در پرتال سازمانی.',
                    'notes' => 'بررسی اولیه صحت مندرجات پیش از ارسال نهایی.'
                ],
                [
                    'step' => 2,
                    'role' => ($row['RespTeamName'] ?: 'تیم مسئول فرآیند'),
                    'action' => 'بررسی سیستمی، اعتبارسنجی مدارک و کنترل عدم تعارض با سایر فرآیندها طبق بهینه‌سازی اخیر.',
                    'notes' => 'انجام بررسی در کمترین زمان ممکن بر اساس شاخص‌های SLA.'
                ],
                [
                    'step' => 3,
                    'role' => 'واحد اجرایی ذی‌ربط',
                    'action' => 'اجرای اقدام فنی/اداری و ثبت وضعیت خاتمه یافته در پرونده الکترونیکی.',
                    'notes' => 'صدور تأییدیه و ثبت در تاریخچه تغییرات فرآیند.'
                ]
            ],
            'optimization_impact' => "بهینه‌سازی بر اساس پیشنهاد «{$row['Title']}» موجب کاهش زمان گردش کار و بهبود دقت اجرای فرآیند گردیده است."
        ];
    }

    // تکمیل متادیتای مورد نیاز برای نمایش و تولید PDF
    $revision = [
        'improvement_id' => (int)$row['ID'],
        'improvement_title' => $row['Title'],
        'process_id' => (int)$row['Pid'],
        'process_name' => $row['ProcName'],
        'category_title' => $row['CatTitle'],
        'old_version' => $currentVersion,
        'new_version' => trim((string)($parsed['suggested_version'] ?? $suggestedVersion)),
        'change_summary' => trim((string)($parsed['change_summary'] ?? '')),
        'purpose' => trim((string)($parsed['purpose'] ?? '')),
        'steps' => $parsed['steps'] ?? [],
        'optimization_impact' => trim((string)($parsed['optimization_impact'] ?? '')),
        'resp_team' => $row['RespTeamName'] ?: 'تیم فرآیند',
        'company_name' => $row['CompanyName'] ?? (defined('APP_NAME') ? APP_NAME : 'سازمان'),
        'owner_name' => trim((string)$row['OwnerPersonnelID']),
        'revision_history' => $history,
    ];

    return ['success' => true, 'revision' => $revision, 'error' => null];
}

/**
 * اعمال قطعی بهینه‌سازی در پرونده فرآیند:
 * ۱. بایگانی نسخه‌های قبلی مستندات در پوشه history/v{old_version}/
 * ۲. تدوین و تولید نسخه جدید فایل PDF دستورالعمل اجرایی با هرمس
 * ۳. ایجاد فایل متنی همگام برای به‌روزرسانی سیستم جستجو و RAG
 * ۴. به‌روزرسانی نسخه در جدول Processes
 * ۵. تغییر وضعیت پیشنهاد بهبود به implemented در جدول Improvements
 * ۶. ثبت رکورد تغییر در جدول Changes
 * ۷. ارسال پیام رسمی در رشته گفتگوی فرآیند
 *
 * @param mysqli $db
 * @param int $improvementId شناسه پیشنهاد بهبود
 * @param int $teamId شناسه تیم انجام‌دهنده
 * @param array $revisionData اطلاعات بازنگری تاییدشده
 * @return array{success:bool, new_version:string, pdf_name:string, pdf_url:string, error:?string}
 */
function hermes_apply_procedure_revision(mysqli $db, int $improvementId, int $teamId, array $revisionData): array
{
    require_once __DIR__ . '/files.php';
    require_once __DIR__ . '/pdf_generator.php';

    // دریافت اطلاعات بهبود و فرآیند
    $st = $db->prepare(
        "SELECT i.*, p.ID AS Pid, p.Name AS ProcName, p.Description AS ProcDesc, p.Version AS CurrentVersion,
                p.FilePath, p.ResponsibleTeamID, p.OwnerPersonnelID,
                cat.Title AS CatTitle, cat.FolderName,
                comp.Name AS CompanyName,
                team.TeamName AS RespTeamName
         FROM Improvements i
         JOIN Processes p ON p.ID = i.ProcessID
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN Companies comp ON comp.ID = p.CompanyID
         LEFT JOIN ProcessTeams team ON team.ID = p.ResponsibleTeamID
         WHERE i.ID = ?"
    );
    $st->bind_param('i', $improvementId);
    $st->execute();
    $proc = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$proc) {
        return ['success' => false, 'new_version' => '', 'pdf_name' => '', 'pdf_url' => '', 'error' => 'پیشنهاد بهبود یافت نشد.'];
    }

    if ((int)$proc['ResponsibleTeamID'] !== $teamId) {
        return ['success' => false, 'new_version' => '', 'pdf_name' => '', 'pdf_url' => '', 'error' => 'فقط تیم مسئول فرآیند اجازه اعمال تغییرات و به‌روزرسانی مستندات را دارد.'];
    }

    $oldVersion = trim((string)$proc['CurrentVersion']) ?: '1.0';
    $newVersion = trim((string)($revisionData['new_version'] ?? ''));
    if ($newVersion === '') {
        $newVersion = hermes_bump_version($oldVersion);
    }

    $changeSummary = trim((string)($revisionData['change_summary'] ?? ''));
    if ($changeSummary === '') {
        $changeSummary = "اعمال و یکپارچه‌سازی بهبود «{$proc['Title']}» در گردش کار فرآیند توسط هرمس.";
    }

    // آماده‌سازی مسیر فیزیکی پوشه فرآیند
    $relDir = fm_normalize_rel((string)$proc['FilePath']);
    if ($relDir === '') {
        $relDir = fm_normalize_rel($proc['FolderName'] . '/' . $proc['ProcName']);
    }
    $absDir = PROCESSES_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
    if (!is_dir($absDir)) {
        @mkdir($absDir, 0775, true);
        @file_put_contents($absDir . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
    }

    // ۱. بایگانی مستندات پیشین در پوشه history/v{old_version}/
    $historyDir = $absDir . DIRECTORY_SEPARATOR . 'history' . DIRECTORY_SEPARATOR . 'v' . preg_replace('/[^\w.-]/', '_', $oldVersion);
    if (!is_dir($historyDir)) {
        @mkdir($historyDir, 0775, true);
        @file_put_contents($historyDir . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
    }

    // کپی اسناد متنی و PDF فعلی به پوشه آرشیو
    if (is_dir($absDir)) {
        foreach (scandir($absDir) as $f) {
            if ($f === '.' || $f === '..' || $f === 'history' || str_starts_with($f, '.')) continue;
            $sourcePath = $absDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($sourcePath)) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['pdf', 'txt', 'md', 'docx'], true)) {
                    @copy($sourcePath, $historyDir . DIRECTORY_SEPARATOR . $f);
                }
            }
        }
    }

    // ۲. تولید PDF جدید با موتور mPDF
    $pdfFileName = 'دستورالعمل-فرآیند-v' . $newVersion . '.pdf';
    $targetPdfPath = $absDir . DIRECTORY_SEPARATOR . $pdfFileName;
    $canonicalPdfPath = $absDir . DIRECTORY_SEPARATOR . 'دستورالعمل-فرآیند.pdf';

    $revisionData['old_version'] = $oldVersion;
    $revisionData['new_version'] = $newVersion;
    $revisionData['improvement_id'] = (int)$proc['ID'];
    $revisionData['improvement_title'] = $proc['Title'];

    try {
        generate_process_procedure_pdf($proc, $revisionData, $targetPdfPath);
        // کپی به عنوان نسخه جاری
        @copy($targetPdfPath, $canonicalPdfPath);
    } catch (Throwable $e) {
        return ['success' => false, 'new_version' => $newVersion, 'pdf_name' => '', 'pdf_url' => '', 'error' => 'خطا در ایجاد فایل PDF: ' . $e->getMessage()];
    }

    // ۳. نگارش فایل متنی دستورالعمل جهت بازیابی آنی در چت RAG هرمس
    $txtFileName = 'دستورالعمل-فرآیند.txt';
    $txtContent = "فرآیند: {$proc['ProcName']}\n"
        . "دسته‌بندی: {$proc['CatTitle']}\n"
        . "نسخه: {$newVersion}\n"
        . "تاریخ بازنگری: " . date('Y-m-d') . "\n"
        . "وضعیت: تصویب‌شده (اعمال بهینه‌سازی توسط هرمس)\n\n"
        . "هدف و دامنه کاربرد:\n" . ($revisionData['purpose'] ?? '') . "\n\n"
        . "شرح تغییرات این نسخه:\n{$changeSummary}\n\n"
        . "مراحل اجرایی فرآیند:\n";

    if (!empty($revisionData['steps']) && is_array($revisionData['steps'])) {
        foreach ($revisionData['steps'] as $st) {
            $txtContent .= "گام {$st['step']} [مسئول: {$st['role']}]: {$st['action']}\n";
            if (!empty($st['notes']) && $st['notes'] !== '—') {
                $txtContent .= "  نکات و الزامات: {$st['notes']}\n";
            }
        }
    }
    @file_put_contents($absDir . DIRECTORY_SEPARATOR . $txtFileName, $txtContent);

    // ۴. به‌روزرسانی تراکنشی دیتابیس
    $db->begin_transaction();
    try {
        // الف) ثبت در جدول Changes
        $procId = (int)$proc['Pid'];
        $impId = (int)$proc['ID'];
        $chType = 'revision';
        $appliedByType = 'team';
        $chStatus = 'applied';
        $st = $db->prepare(
            "INSERT INTO Changes (ProcessID, ImprovementID, ChangeType, Description, OldVersion, NewVersion, AppliedByType, AppliedByID, Status, EffectiveDate)
             VALUES (?,?,?,?,?,?,?,?,?,CURDATE())"
        );
        $st->bind_param('iisssssis', $procId, $impId, $chType, $changeSummary, $oldVersion, $newVersion, $appliedByType, $teamId, $chStatus);
        $st->execute();
        $st->close();

        // ب) به‌روزرسانی نسخه در جدول Processes
        $st = $db->prepare("UPDATE Processes SET Version = ?, UpdatedAt = NOW() WHERE ID = ?");
        $st->bind_param('si', $newVersion, $procId);
        $st->execute();
        $st->close();

        // ج) به‌روزرسانی وضعیت بهبود به implemented
        $st = $db->prepare("UPDATE Improvements SET Status = 'implemented', ReviewedByTeamID = ?, UpdatedAt = NOW() WHERE ID = ?");
        $st->bind_param('ii', $teamId, $impId);
        $st->execute();
        $st->close();

        // د) ثبت پیام در رشته گفتگو
        $refType = 'improvement';
        $senderType = 'team';
        $pdfRelPath = $relDir . '/' . $pdfFileName;
        $pdfUrl = fm_url($pdfRelPath);
        $msgBody = "✅ پیشنهاد بهبود توسط تیم مسئول تصویب و اعمال شد.\n"
            . "مستند دستورالعمل فرآیند با شماره نسخه جدید «{$newVersion}» توسط موتور مهندسی هرمس بازنویسی و فایل رسمی PDF آن در پرونده فرآیند منتشر گردید.\n"
            . "خلاصه تغییرات: {$changeSummary}";

        $st = $db->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES (?,?,?,?,?)");
        $st->bind_param('sisis', $refType, $impId, $senderType, $teamId, $msgBody);
        $st->execute();
        $st->close();

        $db->commit();
    } catch (Throwable $dbErr) {
        $db->rollback();
        return ['success' => false, 'new_version' => $newVersion, 'pdf_name' => '', 'pdf_url' => '', 'error' => 'خطای دیتابیس در ذخیره تغییرات: ' . $dbErr->getMessage()];
    }

    return [
        'success' => true,
        'new_version' => $newVersion,
        'pdf_name' => $pdfFileName,
        'pdf_rel' => $pdfRelPath,
        'pdf_url' => fm_url($pdfRelPath),
        'error' => null
    ];
}

