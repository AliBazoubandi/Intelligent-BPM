<?php
/**
 * ماژول ارتباط با پیام‌رسان بله (Bale Messenger Integration)
 * سامانه مدیریت فرآیندهای رابین‌فلو (RobinFlow)
 *
 * قابلیت‌ها:
 *  - ارسال پیام به متولی فرآیند (استخراج خودکار از چارت سازمانی)
 *  - ارسال پیام به مخاطبان و افراد اثرپذیر (تک‌تک افراد یا تمام پرسنل شرکت)
 *  - پشتیبانی از وب‌سرویس سفارشی کاربر و ربات استاندارد بله
 *  - ثبت تاریخچه پیام‌ها در جدول BaleMessageLog
 */

require_once __DIR__ . '/../config.php';

/**
 * ارسال یک پیام به بله با استفاده از API
 *
 * مشخصات وب‌سرویس اختصاصی بر اساس cURL ارائه‌شده:
 * POST https://rbb.palood.ir/api_send.php
 * Header: X-API-Key: <key>
 * Multipart Form Data:
 *   - phone: شماره تماس دریافت‌کننده
 *   - message: متن پیام
 *   - link: پیوند مستندات فرآیند
 *   - attachment: فایل پیوست (اختیاری)
 *   - attachment_type: نوع فایل (پیش‌فرض 'document')
 *
 * @param string $phoneOrChatId شماره تماس یا شناسه کاربر در بله
 * @param string $text متن پیام ارسالی
 * @param array $meta اطلاعات تکمیلی جهت ثبت در لاگ (ProcessID, RecipientID, ...)
 * @param string|null $link پیوند صفحه یا سند فرآیند
 * @param string|null $attachmentPath مسیر فایل پیوست روی سرور
 * @param string $attachmentType نوع پیوست (پیش‌فرض document)
 * @return array نتیجه ارسال ['success' => bool, 'http_code' => int, 'response' => string, 'error' => string|null]
 */
function bale_send_message(
    string $phoneOrChatId,
    string $text,
    array $meta = [],
    ?string $link = null,
    ?string $attachmentPath = null,
    string $attachmentType = 'document'
): array {
    $phone = trim($meta['phone'] ?? $phoneOrChatId);
    if ($phone === '') {
        return [
            'success'   => false,
            'http_code' => 0,
            'response'  => null,
            'error'     => 'شماره تماس یا شناسه برای ارسال به وب‌سرویس بله مشخص نشده است.',
        ];
    }

    $apiUrl = (string)BALE_API_URL;
    $apiKey = defined('BALE_API_KEY') ? trim((string)BALE_API_KEY) : '';
    $linkUrl = $link ?? ($meta['link'] ?? '');
    $attachFile = $attachmentPath ?? ($meta['attachment'] ?? null);
    $attachType = $attachmentType ?? ($meta['attachment_type'] ?? 'document');

    $postFields = [
        'phone'           => $phone,
        'message'         => $text,
        'link'            => (string)$linkUrl,
        'attachment_type' => (string)$attachType,
    ];

    if (!empty($attachFile) && file_exists($attachFile)) {
        $postFields['attachment'] = new CURLFile($attachFile);
    }

    $processId = !empty($meta['process_id']) ? (int)$meta['process_id'] : null;
    $persId    = !empty($meta['personnel_id']) ? (int)$meta['personnel_id'] : null;
    $recName   = $meta['recipient_name'] ?? null;
    $recPos    = $meta['recipient_position'] ?? null;

    // در صورتی که ارسال به بله موقتاً غیرفعال باشد، لاگ شبیه‌سازی ثبت می‌شود
    if (!BALE_API_ENABLED) {
        bale_log_message($processId, $persId, $recName, $recPos, $phone, $text, 'sent', 'Simulated: BALE_API_ENABLED is false');
        return [
            'success'   => true,
            'http_code' => 200,
            'response'  => 'Simulated success (BALE_API_ENABLED=false)',
            'error'     => null,
        ];
    }

    $headers = [];
    if ($apiKey !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)BALE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $rawResponse = curl_exec($ch);
    $curlError   = curl_error($ch);
    $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $isOk = ($curlError === '' && $httpCode >= 200 && $httpCode < 300);
    $status = $isOk ? 'sent' : 'failed';
    $logResponse = $curlError ? "cURL Error: {$curlError}" : "HTTP {$httpCode}: {$rawResponse}";

    bale_log_message($processId, $persId, $recName, $recPos, $phone, $text, $status, $logResponse);

    return [
        'success'   => $isOk,
        'http_code' => $httpCode,
        'response'  => $rawResponse,
        'error'     => $isOk ? null : ($curlError ?: "HTTP Error {$httpCode}"),
    ];
}

/**
 * ذخیره رویداد ارسال پیام در جدول لاگ
 */
function bale_log_message(?int $procId, ?int $persId, ?string $name, ?string $pos, string $chatId, string $text, string $status, ?string $apiRes): void
{
    try {
        $db = app_db();
        $st = $db->prepare(
            "INSERT INTO BaleMessageLog (ProcessID, RecipientPersonnelID, RecipientName, RecipientPosition, BaleChatID, MessageText, Status, ApiResponse)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->bind_param('iissssss', $procId, $persId, $name, $pos, $chatId, $text, $status, $apiRes);
        $st->execute();
        $st->close();
    } catch (Throwable) {}
}

/**
 * استخراج متولی و فهرست مخاطبان اثرپذیر یک فرآیند
 *
 * @param mysqli $db
 * @param int $processId
 * @return array اطلاعات فرآیند، متولی چارت، و لیست اشخاص اثرپذیر
 */
function bale_get_process_recipients(mysqli $db, int $processId): array
{
    // دریافت اطلاعات فرآیند و متولی
    $st = $db->prepare(
        "SELECT p.*, cat.Title AS CatTitle, c.Name AS CompanyName,
                op.Title AS OwnerPositionTitle, op.Department AS OwnerDepartment,
                per.ID AS OwnerPersID, per.Name AS OwnerName, per.Family AS OwnerFamily,
                per.BaleChatID AS OwnerBale, per.Phone AS OwnerPhone
         FROM Processes p
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN Companies c ON c.ID = p.CompanyID
         LEFT JOIN OrgPositions op ON op.ID = p.OwnerPositionID
         LEFT JOIN Personnel per ON per.ID = op.CurrentPersonnelID
         WHERE p.ID = ?"
    );
    $st->bind_param('i', $processId);
    $st->execute();
    $proc = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$proc) {
        return ['found' => false];
    }

    $ownerInfo = null;
    if (!empty($proc['OwnerPositionTitle'])) {
        $ownerFullName = trim(($proc['OwnerName'] ?? '') . ' ' . ($proc['OwnerFamily'] ?? ''));
        $ownerInfo = [
            'position_id'    => (int)$proc['OwnerPositionID'],
            'position_title' => $proc['OwnerPositionTitle'],
            'department'     => $proc['OwnerDepartment'],
            'personnel_id'   => !empty($proc['OwnerPersID']) ? (int)$proc['OwnerPersID'] : null,
            'name'           => $ownerFullName ?: 'بدون شاغل فعلی',
            'bale_chat_id'   => $proc['OwnerBale'] ?: $proc['OwnerPhone'] ?: '',
            'phone'          => $proc['OwnerPhone'] ?? '',
        ];
    }

    $audienceType = $proc['AudienceType'] ?? 'all_company';
    $audienceRecipients = [];

    if ($audienceType === 'all_company') {
        // تمام پرسنل شرکت (مثلاً برای فرآیند مرخصی یا آیین‌نامه‌های سراسری)
        $compId = (int)($proc['CompanyID'] ?? 0);
        $sql = "SELECT p.ID, p.PersonnelCode, p.Name, p.Family, p.BaleChatID, p.Phone,
                       op.Title AS PositionTitle
                FROM Personnel p
                LEFT JOIN OrgPositions op ON op.ID = p.OrgPositionID
                WHERE p.Active = 1";
        if ($compId > 0) {
            $sql .= " AND (p.CompanyID = {$compId} OR p.CompanyID IS NULL)";
        }
        $sql .= " ORDER BY p.PersonnelCode";
        $res = $db->query($sql);
        while ($r = $res->fetch_assoc()) {
            $fullName = trim($r['Name'] . ' ' . $r['Family']);
            $baleId = $r['BaleChatID'] ?: $r['Phone'] ?: '';
            $audienceRecipients[] = [
                'personnel_id' => (int)$r['ID'],
                'code'         => $r['PersonnelCode'],
                'name'         => $fullName,
                'position'     => $r['PositionTitle'] ?: 'پرسنل سازمان',
                'bale_chat_id' => $baleId,
                'phone'        => $r['Phone'] ?? '',
                'reason'       => 'تمام پرسنل شرکت (عمومی)',
            ];
        }
    } elseif ($audienceType === 'specific_positions') {
        // سمت‌های مشخص از چارت سازمانی (مثلاً مدیر بازرگانی، کارشناس فروش و ...)
        $stAud = $db->prepare(
            "SELECT pa.TargetID, pa.TargetName, op.Title AS PosTitle, op.Department,
                    p.ID AS PersID, p.PersonnelCode, p.Name, p.Family, p.BaleChatID, p.Phone
             FROM ProcessAudience pa
             JOIN OrgPositions op ON op.ID = pa.TargetID
             LEFT JOIN Personnel p ON p.ID = op.CurrentPersonnelID
             WHERE pa.ProcessID = ? AND pa.TargetType = 'position' AND op.Active = 1"
        );
        $stAud->bind_param('i', $processId);
        $stAud->execute();
        $res = $stAud->get_result();
        while ($r = $res->fetch_assoc()) {
            $fullName = trim(($r['Name'] ?? '') . ' ' . ($r['Family'] ?? ''));
            $baleId = $r['BaleChatID'] ?: $r['Phone'] ?: '';
            $audienceRecipients[] = [
                'personnel_id' => !empty($r['PersID']) ? (int)$r['PersID'] : null,
                'code'         => $r['PersonnelCode'] ?? '',
                'name'         => $fullName ?: 'فاقد شاغل فعلی',
                'position'     => $r['PosTitle'] ?: ($r['TargetName'] ?: 'سمت منتخب'),
                'bale_chat_id' => $baleId,
                'phone'        => $r['Phone'] ?? '',
                'reason'       => 'سمت اثرپذیر: ' . ($r['PosTitle'] ?? ''),
            ];
        }
        $stAud->close();
    } elseif ($audienceType === 'specific_personnel') {
        // افراد منتخب مشخص (مثلاً ۵ یا ۱۰ نفر خاص)
        $stAud = $db->prepare(
            "SELECT pa.TargetID, pa.TargetName, p.PersonnelCode, p.Name, p.Family, p.BaleChatID, p.Phone,
                    op.Title AS PosTitle
             FROM ProcessAudience pa
             JOIN Personnel p ON p.ID = pa.TargetID
             LEFT JOIN OrgPositions op ON op.ID = p.OrgPositionID
             WHERE pa.ProcessID = ? AND pa.TargetType = 'personnel' AND p.Active = 1"
        );
        $stAud->bind_param('i', $processId);
        $stAud->execute();
        $res = $stAud->get_result();
        while ($r = $res->fetch_assoc()) {
            $fullName = trim($r['Name'] . ' ' . $r['Family']);
            $baleId = $r['BaleChatID'] ?: $r['Phone'] ?: '';
            $audienceRecipients[] = [
                'personnel_id' => (int)$r['TargetID'],
                'code'         => $r['PersonnelCode'],
                'name'         => $fullName,
                'position'     => $r['PosTitle'] ?: 'پرسنل منتخب',
                'bale_chat_id' => $baleId,
                'phone'        => $r['Phone'] ?? '',
                'reason'       => 'فرد منتخب اثرپذیر',
            ];
        }
        $stAud->close();
    } elseif ($audienceType === 'department') {
        // تمام پرسنل یک دپارتمان خاص
        $dept = trim((string)($proc['AudienceDepartment'] ?? ''));
        if ($dept !== '') {
            $stAud = $db->prepare(
                "SELECT p.ID, p.PersonnelCode, p.Name, p.Family, p.BaleChatID, p.Phone,
                        op.Title AS PosTitle, op.Department
                 FROM Personnel p
                 JOIN OrgPositions op ON op.CurrentPersonnelID = p.ID
                 WHERE op.Department = ? AND p.Active = 1"
            );
            $stAud->bind_param('s', $dept);
            $stAud->execute();
            $res = $stAud->get_result();
            while ($r = $res->fetch_assoc()) {
                $fullName = trim($r['Name'] . ' ' . $r['Family']);
                $baleId = $r['BaleChatID'] ?: $r['Phone'] ?: '';
                $audienceRecipients[] = [
                    'personnel_id' => (int)$r['ID'],
                    'code'         => $r['PersonnelCode'],
                    'name'         => $fullName,
                    'position'     => $r['PosTitle'] ?: 'عضو واحد ' . $dept,
                    'bale_chat_id' => $baleId,
                    'phone'        => $r['Phone'] ?? '',
                    'reason'       => 'عضو دپارتمان ' . $dept,
                ];
            }
            $stAud->close();
        }
    }

    // ادغام متولی و مخاطبان بدون افراد تکراری
    $merged = [];
    $seenPersonnel = [];

    // ابتدا متولی فرآیند (Process Owner)
    if ($ownerInfo && !empty($ownerInfo['personnel_id'])) {
        $pId = $ownerInfo['personnel_id'];
        $seenPersonnel[$pId] = true;
        $merged[] = [
            'personnel_id' => $pId,
            'name'         => $ownerInfo['name'],
            'position'     => $ownerInfo['position_title'],
            'bale_chat_id' => $ownerInfo['bale_chat_id'],
            'phone'        => $ownerInfo['phone'],
            'role'         => 'owner',
            'role_label'   => 'متولی فرآیند (مسئول اصلی)',
        ];
    }

    // سپس مخاطبان اثرپذیر (Affected Audience)
    foreach ($audienceRecipients as $aud) {
        $pId = $aud['personnel_id'] ?? null;
        if ($pId && isset($seenPersonnel[$pId])) {
            continue; // جلوگیری از ارسال پیام تکراری به متولی اگر در لیست مخاطبان نیز حضور داشت
        }
        if ($pId) {
            $seenPersonnel[$pId] = true;
        }
        $merged[] = [
            'personnel_id' => $pId,
            'name'         => $aud['name'],
            'position'     => $aud['position'],
            'bale_chat_id' => $aud['bale_chat_id'],
            'phone'        => $aud['phone'],
            'role'         => 'audience',
            'role_label'   => $aud['reason'],
        ];
    }

    return [
        'found'               => true,
        'process'             => $proc,
        'owner_info'          => $ownerInfo,
        'audience_type'       => $audienceType,
        'audience_recipients' => $audienceRecipients,
        'all_recipients'      => $merged,
    ];
}

/**
 * ارسال پیام تغییر فرآیند به متولی و تمام مخاطبان اثرپذیر در پیام‌رسان بله
 *
 * @param mysqli $db
 * @param int $processId شناسه فرآیند
 * @param string $changeTitle عنوان یا دلیل تغییر
 * @param string $changeDescription شرح تفصیلی تغییرات اعمال‌شده
 * @param string|null $processLink لینک صفحه فرآیند جهت دسترسی سریع
 * @param int|null $fromTeamId شناسه تیم ارسال‌کننده
 * @return array آمار و وضعیت ارسال
 */
function bale_notify_process_change(
    mysqli $db,
    int $processId,
    string $changeTitle,
    string $changeDescription,
    ?string $processLink = null,
    ?int $fromTeamId = null
): array {
    $info = bale_get_process_recipients($db, $processId);
    if (empty($info['found'])) {
        return ['success' => false, 'error' => 'فرآیند یافت نشد.'];
    }

    $proc = $info['process'];
    $procName = $proc['Name'];
    $version  = $proc['Version'] ?? '1.0';
    $catTitle = $proc['CatTitle'] ?? 'دسته‌بندی';
    $owner    = $info['owner_info'];

    // لینک مستقیم
    $link = $processLink;
    if (!$link) {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $link = $base . '/user/processes.php?id=' . $processId;
    }

    $ownerSummary = $owner ? "{$owner['position_title']} ({$owner['name']})" : 'تعیین‌نشده';
    $audienceLabel = match ($info['audience_type']) {
        'all_company'        => 'تمام پرسنل شرکت (عمومی)',
        'specific_positions' => 'سمت‌های اثرپذیر از چارت',
        'specific_personnel' => 'پرسنل منتخب اثرپذیر',
        'department'         => 'واحد ' . ($proc['AudienceDepartment'] ?? ''),
        default              => 'سازمان',
    };

    // آماده‌سازی قالب استاندارد پیام بله
    $template = "🔔 رابین‌فلو | اطلاع‌رسانی تغییر فرآیند\n";
    $template .= "━━━━━━━━━━━━━━━━━━━━━\n";
    $template .= "📋 نام فرآیند: {$procName}\n";
    $template .= "📂 دسته‌بندی: {$catTitle}\n";
    $template .= "🔢 نسخه جدید: {$version}\n";
    $template .= "👤 متولی فرآیند: {$ownerSummary}\n";
    $template .= "👥 دامنه مخاطبان: {$audienceLabel}\n\n";
    $template .= "📌 موضوع تغییر: {$changeTitle}\n";
    if (trim($changeDescription) !== '') {
        $template .= "📝 شرح تغییرات:\n" . trim($changeDescription) . "\n\n";
    }
    $template .= "🔗 جهت مشاهده مستندات و بررسی روی پیوند زیر بزنید:\n{$link}\n";
    $template .= "━━━━━━━━━━━━━━━━━━━━━\n";
    $template .= "سامانه هوشمند مدیریت و بهبود فرآیندهای رابین‌فلو";

    // بررسی وجود فایل مستندات فرآیند جهت پیوست کردن در پیام بله (-F attachment=@... -F attachment_type=document)
    $attachmentPath = null;
    if (!empty($proc['FilePath'])) {
        $candidate = APP_ROOT . DIRECTORY_SEPARATOR . 'processes' . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $proc['FilePath']), DIRECTORY_SEPARATOR);
        if (is_file($candidate)) {
            $attachmentPath = $candidate;
        }
    }

    $recipients = $info['all_recipients'];
    $total = count($recipients);
    $sentCount = 0;
    $failedCount = 0;
    $dispatched = [];

    foreach ($recipients as $rec) {
        $phone = trim($rec['phone'] ?: $rec['bale_chat_id']);
        if ($phone === '') {
            // شخص شماره تماس یا شناسه بله ثبت نکرده است
            $dispatched[] = [
                'recipient' => $rec['name'],
                'position'  => $rec['position'],
                'chat_id'   => '',
                'status'    => 'skipped',
                'message'   => 'شماره تلفن یا شناسه بله ثبت نشده است.',
            ];
            continue;
        }

        // پیام اختصاصی برای متولی یا مخاطب
        $isOwner = ($rec['role'] ?? '') === 'owner';
        $personalHeader = $isOwner
            ? "⚠️ همکار گرامی جناب/سرکار {$rec['name']}، شما به عنوان «{$rec['position']}» متولی این فرآیند می‌باشید. لطفاً تغییرات جدید را بررسی نمایید.\n\n"
            : "همکار گرامی جناب/سرکار {$rec['name']} ({$rec['position']})، با توجه به اثرپذیری از این فرآیند، تغییرات جهت آگاهی شما ارسال می‌گردد:\n\n";

        $finalMsg = $personalHeader . $template;

        $meta = [
            'process_id'         => $processId,
            'personnel_id'       => $rec['personnel_id'],
            'recipient_name'     => $rec['name'],
            'recipient_position' => $rec['position'],
            'phone'              => $phone,
            'link'               => $link,
            'attachment'         => $attachmentPath,
            'attachment_type'    => 'document',
        ];

        $res = bale_send_message($phone, $finalMsg, $meta, $link, $attachmentPath, 'document');
        if ($res['success']) {
            $sentCount++;
        } else {
            $failedCount++;
        }

        $dispatched[] = [
            'recipient' => $rec['name'],
            'position'  => $rec['position'],
            'chat_id'   => $phone,
            'status'    => $res['success'] ? 'sent' : 'failed',
            'error'     => $res['error'],
        ];

        // ثبت اعلان درون‌برنامه‌ای رابین‌فلو نیز برای شخص
        if (!empty($rec['personnel_id'])) {
            try {
                $notifTitle = "تغییر در فرآیند «{$procName}» (نسخه {$version})";
                $notifBody = "تغییر جدید: {$changeTitle}" . (trim($changeDescription) !== '' ? " — " . mb_substr($changeDescription, 0, 100) : '');
                $stN = $db->prepare(
                    "INSERT INTO Notifications (Title, Body, Link, FromType, FromID, ProcessID, TargetPersonnelID, Priority)
                     VALUES (?, ?, ?, 'team', ?, ?, ?, 'high')"
                );
                $fromId = $fromTeamId ?: 1;
                $stN->bind_param('sssiii', $notifTitle, $notifBody, $link, $fromId, $processId, $rec['personnel_id']);
                $stN->execute();
                $stN->close();
            } catch (Throwable) {}
        }
    }

    return [
        'success'      => ($sentCount > 0 || $total === 0),
        'total'        => $total,
        'sent'         => $sentCount,
        'failed'       => $failedCount,
        'proc_name'    => $procName,
        'version'      => $version,
        'dispatched'   => $dispatched,
    ];
}

