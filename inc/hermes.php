<?php
/**
 * ارتباط با دستیار هوشمند هرمس (Hermes Agent)
 * ارزیابی هوشمند پیشنهادات بهبود، بررسی تعارضات و پارادوکس‌ها با سایر فرآیندها
 * پشتیبانی از اجرای غیراسنکرون (Async) و صف زمان‌بندی‌شده (Queue)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/icons.php';

/**
 * بررسی در دسترس بودن سرویس هرمس
 */
function hermes_is_available(): bool
{
    if (!defined('HERMES_URL') || !HERMES_URL) return false;
    $ch = curl_init('http://127.0.0.1:8642/v1/models');
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
        if (!str_ends_with(strtolower(PHP_BINARY), 'php-cgi.exe')) {
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
    $phpBin = hermes_find_php_binary();
    $workerPath = realpath(__DIR__ . '/../bin/hermes_worker.php');
    if (!$workerPath || !file_exists($workerPath)) {
        return false;
    }

    $arg = ($improvementId > 0) ? (string)$improvementId : '--queue';
    $logFile = defined('APP_ROOT') ? (APP_ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker.log') : (__DIR__ . '/../bin/worker.log');

    if (stripos(PHP_OS, 'WIN') === 0) {
        // ویندوز: استفاده از start /B برای فرآیند پس‌زمینه مستقل و بدون مسدودسازی وب
        $cmd = sprintf('start /B "" "%s" "%s" %s >> "%s" 2>&1', $phpBin, $workerPath, escapeshellarg($arg), $logFile);
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
        if (preg_match('/\b(YES|تأیید|موافقت)\b/ui', $raw)) {
            $verdict = 'yes';
        } elseif (preg_match('/\b(NO|رد|مخالفت)\b/ui', $raw)) {
            $verdict = 'no';
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

    $html .= '</div>';
    return $html;
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
    $cleanQuery = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($q));
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
        $nameLower = mb_strtolower($proc['Name']);
        $descLower = mb_strtolower((string)$proc['Description']);
        $catLower = mb_strtolower((string)$proc['CatTitle']);
        $teamLower = mb_strtolower((string)$proc['TeamName']);

        // تطابق کامل نام در پرسمان
        if (str_contains($cleanQuery, $nameLower)) {
            $score += 60;
        }

        // تطابق تک‌تک کلمات
        foreach ($meaningfulWords as $w) {
            if (str_contains($nameLower, $w)) $score += 20;
            if (str_contains($descLower, $w)) $score += 10;
            if (str_contains($catLower, $w)) $score += 8;
            if (str_contains($teamLower, $w)) $score += 6;
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
                        $docContentLower = mb_strtolower($docContent);
                        foreach ($meaningfulWords as $w) {
                            if (str_contains($docContentLower, $w)) $score += 6;
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
        . "۶. هیچ ابزار سیستمی یا کد لینوکسی اجرا نکنید؛ پاسخ متنی کامل و منظم ارائه فرمایید.";

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
