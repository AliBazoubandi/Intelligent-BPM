<?php
/**
 * ماژول تولید رسمی مستندات و شناسنامه فرآیندها در قالب PDF
 * با استفاده از موتور mPDF، قلم استاندارد فارسی وزیرمتن (Vazirmatn TTF) و پشتیبانی کامل از RTL و استانداردهای مستندسازی ISO
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/files.php';

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

/**
 * ایجاد نمونه mPDF پیکربندی‌شده با فونت فارسی وزیرمتن و تنظیمات RTL
 */
function rf_create_mpdf_instance(): Mpdf
{
    $defaultConfig = (new ConfigVariables())->getDefaults();
    $defaultFontConfig = (new FontVariables())->getDefaults();
    $fontsDir = defined('APP_ROOT') ? (APP_ROOT . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts') : (__DIR__ . '/../assets/fonts');

    return new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'fontDir' => array_merge($defaultConfig['fontDir'], [$fontsDir]),
        'fontdata' => $defaultFontConfig['fontdata'] + [
            'vazirmatn' => [
                'R' => 'Vazirmatn-Regular.ttf',
                'B' => 'Vazirmatn-Bold.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ]
        ],
        'default_font' => 'vazirmatn',
        'autoScriptToLang' => true,
        'autoLangToFont' => true,
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 26,
        'margin_bottom' => 22,
        'margin_header' => 8,
        'margin_footer' => 8,
    ]);
}

/**
 * تابع جامع و یکپارچه برای تولید اسناد و شناسنامه‌های فرآیند در قالب PDF
 *
 * @param string $mode 'revision' برای بازنگری‌های فرآیند یا 'design' برای طراحی فرآیند جدید
 * @param array $process اطلاعات فرآیند (ID, Name, Description, Version, CatTitle, RespTeam, CompanyName, OwnerName, etc.)
 * @param array $data اطلاعات بازنگری یا طراحی (purpose, steps, change_summary, impacted_roles, etc.)
 * @param string $outputPath مسیر فایل خروجی؛ در صورت خالی بودن، رشته باینری برمی‌گردد
 * @return string مسیر فایل ذخیره‌شده یا محتوای باینری PDF
 * @throws Exception
 */
function rf_generate_procedure_pdf(string $mode, array $process, array $data, string $outputPath = ''): string
{
    $mpdf = rf_create_mpdf_instance();

    $isDesign = ($mode === 'design' || $mode === 'new_process');
    $accentColor = $isDesign ? '#0f766e' : '#1e3a8a';
    $accentBg = $isDesign ? '#f0fdfa' : '#f8fafc';

    // استخراج و امن‌سازی متادیتای فرآیند با پشتیبانی از نام‌های کلیدی مختلف
    $procName = htmlspecialchars($process['Name'] ?? ($process['ProcName'] ?? 'فرآیند بدون نام'), ENT_QUOTES, 'UTF-8');
    $catTitle = htmlspecialchars($process['CatTitle'] ?? 'دسته‌بندی عمومی', ENT_QUOTES, 'UTF-8');
    $companyName = htmlspecialchars($process['CompanyName'] ?? ($process['Company'] ?? (defined('APP_NAME') ? APP_NAME : 'سازمان')), ENT_QUOTES, 'UTF-8');
    $respTeam = htmlspecialchars($process['RespTeam'] ?? ($process['RespTeamName'] ?? ($process['TeamName'] ?? 'تیم فرآیند')), ENT_QUOTES, 'UTF-8');
    
    // حل نام متولی (پرسنلی یا سمتی)
    $ownerCandidate = trim((string)($process['OwnerName'] ?? ($process['OwnerPositionTitle'] ?? ($data['owner_role_title'] ?? ''))));
    if (!empty($process['OwnerFamily'])) {
        $ownerCandidate = trim((string)($process['OwnerName'] ?? '') . ' ' . (string)$process['OwnerFamily']);
    }
    $ownerName = htmlspecialchars($ownerCandidate ?: 'تعیین نشده', ENT_QUOTES, 'UTF-8');

    $version = htmlspecialchars((string)($data['new_version'] ?? ($data['version'] ?? ($process['Version'] ?? '1.0'))), ENT_QUOTES, 'UTF-8');
    $oldVersion = htmlspecialchars((string)($data['old_version'] ?? ($process['Version'] ?? '1.0')), ENT_QUOTES, 'UTF-8');
    $audienceLabel = htmlspecialchars($process['AudienceLabel'] ?? ($data['audience_label'] ?? 'عموم پرسنل سازمان'), ENT_QUOTES, 'UTF-8');

    $docCode = !empty($process['ID']) ? ('PRC-' . str_pad((string)$process['ID'], 3, '0', STR_PAD_LEFT)) : ('PRC-N' . date('ymd'));
    $todayJalali = function_exists('fmt_date_short') ? fmt_date_short(date('Y-m-d H:i:s')) : date('Y/m/d');

    $headerSub = $isDesign ? 'سامانه مدیریت و بهینه‌سازی فرآیندها (رابین‌فلو)' : 'سامانه مدیریت فرآیندهای کسب‌وکار';
    $headerCenterTitle = $isDesign ? 'شناسنامه و دستورالعمل اجرایی فرآیند' : 'دستورالعمل اجرایی فرآیند';
    $headerCenterSub = $isDesign ? 'طراحی و تدوین استاندارد توسط هرمس (AI)' : 'شناسنامه و روش اجرایی مصوب';
    $footerNote = $isDesign
        ? 'سند مصوب فرآیند سازمانی — تحلیل مصاحبه و معماری گردش‌کار توسط دستیار هوشمند هرمس (Hermes AI)'
        : 'سند مصوب سازمانی — بهینه‌سازی و تدوین‌شده توسط موتور مهندسی فرآیند هرمس (Hermes AI)';

    // سربرگ رسمی سند
    $headerHtml = '
    <div style="direction: rtl; text-align: right; font-family: vazirmatn, sans-serif; border-bottom: 2px solid ' . $accentColor . '; padding-bottom: 6px; font-size: 10px; color: #334155;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 33%; text-align: right;">
                    <span style="font-weight: bold; color: ' . $accentColor . '; font-size: 12px;">' . $companyName . '</span><br>
                    <span>' . $headerSub . '</span>
                </td>
                <td style="width: 34%; text-align: center;">
                    <span style="font-size: 14px; font-weight: bold; color: #0f172a;">' . $headerCenterTitle . '</span><br>
                    <span style="color: #64748b; font-size: 10px;">' . $headerCenterSub . '</span>
                </td>
                <td style="width: 33%; text-align: left; direction: ltr; font-size: 10px; color: #475569;">
                    <b>کد سند:</b> ' . $docCode . '<br>
                    <b>نسخه:</b> ' . $version . ' | <b>تاریخ:</b> ' . $todayJalali . '
                </td>
            </tr>
        </table>
    </div>';

    // پابرگ رسمی سند
    $footerHtml = '
    <div style="direction: rtl; text-align: center; font-family: vazirmatn, sans-serif; border-top: 1px solid #cbd5e1; padding-top: 6px; font-size: 9px; color: #64748b;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 65%; text-align: right;">
                    <span>' . $footerNote . '</span>
                </td>
                <td style="width: 35%; text-align: left; direction: ltr;">
                    صفحه {PAGENO} از {nbpg}
                </td>
            </tr>
        </table>
    </div>';

    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);

    // آماده‌سازی فیلدهای محتوایی
    $purpose = trim((string)($data['purpose'] ?? ''));
    if ($purpose === '') {
        $purpose = 'هدف از این دستورالعمل، تعیین استانداردها، گام‌ها، مسئولیت‌ها و توالی اقدامات لازم جهت اجرای شفاف و یکپارچه فرآیند «' . $procName . '» در کلیه واحدهای سازمان می‌باشد.';
    }

    $scope = trim((string)($data['scope'] ?? ''));
    $kpis = trim((string)($data['kpis_and_slas'] ?? ''));
    $optNotes = trim((string)($data['optimization_notes'] ?? ''));
    $changeSummary = trim((string)($data['change_summary'] ?? 'بهینه‌سازی و بازنگری گام‌های فرآیند جهت بهبود سرعت و کیفیت اجرا.'));
    $optimizationImpact = trim((string)($data['optimization_impact'] ?? ''));
    $impTitle = htmlspecialchars(trim((string)($data['improvement_title'] ?? '')), ENT_QUOTES, 'UTF-8');
    $impId = (int)($data['improvement_id'] ?? 0);

    // گام‌های اجرایی فرآیند
    $steps = $data['steps'] ?? [];
    if (!is_array($steps) || empty($steps)) {
        $steps = [
            [
                'step' => 1,
                'role' => 'درخواست‌کننده / مجری',
                'action' => 'ثبت تقاضا یا آغاز عملیات طبق نیازمندی‌های مصوب در پرتال سازمانی.',
                'notes' => 'ورود اطلاعات کامل و پیوست مدارک الزامی است.'
            ],
            [
                'step' => 2,
                'role' => $respTeam,
                'action' => 'بررسی انطباق، ارزیابی کارشناسی و صدور مجوز ادامه فرآیند.',
                'notes' => 'حداکثر مهلت بررسی مطابق SLA فرآیند.'
            ],
            [
                'step' => 3,
                'role' => 'واحد ذی‌ربط / مسئول اجرایی',
                'action' => 'اجرای اقدام عملیاتی و ثبت نتایج در سوابق الکترونیکی پرونده.',
                'notes' => 'تأیید نهایی و بایگانی در پرونده فرآیند.'
            ]
        ];
    }

    $stepsHtml = '';
    foreach ($steps as $idx => $st) {
        $stNum = htmlspecialchars((string)($st['step'] ?? ($idx + 1)), ENT_QUOTES, 'UTF-8');
        $stRole = htmlspecialchars((string)($st['role'] ?? 'مجری'), ENT_QUOTES, 'UTF-8');
        $stAction = nl2br(htmlspecialchars((string)($st['action'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $stNotes = nl2br(htmlspecialchars((string)($st['notes'] ?? '—'), ENT_QUOTES, 'UTF-8'));
        $bg = ($idx % 2 === 1) ? 'background-color: #f8fafc;' : 'background-color: #ffffff;';

        $stepsHtml .= '
        <tr style="' . $bg . '">
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; text-align: center; font-weight: bold; color: ' . $accentColor . ';">' . $stNum . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; font-weight: 500; color: #1e293b;">' . $stRole . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; line-height: 1.6;">' . $stAction . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; color: #475569; font-size: 10px; line-height: 1.5;">' . $stNotes . '</td>
        </tr>';
    }

    // ساخت بدنه HTML
    $bodyHtml = '
    <div style="direction: rtl; text-align: right; font-family: vazirmatn, sans-serif; line-height: 1.7; color: #1e293b; font-size: 11px;">

        <!-- عنوان اصلی سند -->
        <div style="text-align: center; margin-bottom: 16px;">
            <h1 style="color: ' . $accentColor . '; font-size: 18px; margin: 0 0 4px 0;">' . ($isDesign ? 'شناسنامه و دستورالعمل اجرایی: ' : 'دستورالعمل اجرایی: ') . $procName . '</h1>
            <span style="font-size: 11px; color: #64748b;">حوزه: ' . $catTitle . ' | نسخه: ' . $version . ' | تاریخ تصویب: ' . $todayJalali . '</span>
        </div>

        <!-- ۱. شناسنامه فرآیند -->
        <div style="margin-bottom: 15px;">
            <div style="background-color: ' . $accentColor . '; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                ۱. شناسنامه و مشخصات کنترلی فرآیند
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 10.5px;">
                <tr>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; width: 18%; font-weight: bold; color: #334155;">نام فرآیند:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px; width: 32%;">' . $procName . '</td>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; width: 18%; font-weight: bold; color: #334155;">کد فرآیند:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px; width: 32%;">' . $docCode . '</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">دسته‌بندی:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px;">' . $catTitle . '</td>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">نسخه سند:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px; font-weight: bold; color: ' . $accentColor . ';">' . $version . ($isDesign ? ' (نسخه مصوب اولیه)' : (' (قبلی: ' . $oldVersion . ')')) . '</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">تیم مسئول:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px;">' . $respTeam . '</td>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">متولی فرآیند:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px;">' . $ownerName . '</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">دامنه مخاطبان:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px;" colspan="3">' . $audienceLabel . '</td>
                </tr>
            </table>
        </div>

        <!-- ۲. هدف و مأموریت فرآیند -->
        <div style="margin-bottom: 15px;">
            <div style="background-color: ' . $accentColor . '; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                ۲. هدف و مأموریت فرآیند
            </div>
            <div style="border: 1px solid #cbd5e1; border-top: none; padding: 9px 12px; background: #ffffff;">
                <p style="margin: 0; line-height: 1.8;">' . nl2br(htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')) . '</p>
            </div>
        </div>';

    // ۳. دامنه کاربرد (در صورت وجود در داده‌ها)
    if ($scope !== '') {
        $bodyHtml .= '
        <div style="margin-bottom: 15px;">
            <div style="background-color: ' . $accentColor . '; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                ۳. دامنه کاربرد و پیش‌نیازهای اجرایی
            </div>
            <div style="border: 1px solid #cbd5e1; border-top: none; padding: 9px 12px; background: #ffffff;">
                <p style="margin: 0; line-height: 1.8;">' . nl2br(htmlspecialchars($scope, ENT_QUOTES, 'UTF-8')) . '</p>
            </div>
        </div>';
    }

    // نقش‌های درگیر (مختص طراحی هرمس یا در صورت وجود)
    $impactedRoles = $data['impacted_roles'] ?? [];
    if (is_array($impactedRoles) && !empty($impactedRoles)) {
        $rolesRows = '';
        foreach ($impactedRoles as $ir) {
            $rTitle = htmlspecialchars(is_array($ir) ? ($ir['role'] ?? 'نقش سازمانی') : (string)$ir, ENT_QUOTES, 'UTF-8');
            $rInv = htmlspecialchars(is_array($ir) ? ($ir['involvement'] ?? 'اجرا یا نظارت بر فرآیند') : 'مشارکت در فرآیند', ENT_QUOTES, 'UTF-8');
            $rolesRows .= '
            <tr>
                <td style="border: 1px solid #cbd5e1; padding: 6px 8px; font-weight: bold; color: ' . $accentColor . '; width: 35%;">' . $rTitle . '</td>
                <td style="border: 1px solid #cbd5e1; padding: 6px 8px; color: #334155;">' . $rInv . '</td>
            </tr>';
        }
        $bodyHtml .= '
        <div style="margin-bottom: 15px;">
            <div style="background-color: ' . $accentColor . '; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                ۴. ماتریس نقش‌ها و مسئولیت‌های سازمانی
            </div>
            <div style="border: 1px solid #cbd5e1; border-top: none; padding: 8px 12px; background: #ffffff;">
                <table style="width: 100%; border-collapse: collapse; font-size: 10.5px;">
                    <thead>
                        <tr style="background: #f1f5f9; color: #334155;">
                            <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: right;">سمت / نقش</th>
                            <th style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: right;">مسئولیت در فرآیند</th>
                        </tr>
                    </thead>
                    <tbody>' . $rolesRows . '</tbody>
                </table>
            </div>
        </div>';
    }

    // بهینه‌سازی اعمال‌شده (در حالت بازنگری)
    if (!$isDesign) {
        $bodyHtml .= '
        <div style="margin-bottom: 15px;">
            <div style="background-color: #0d9488; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                بهینه‌سازی اعمال‌شده در نسخه ' . $version . ' (موتور مهندسی هرمس)
            </div>
            <div style="border: 1px solid #0d9488; border-top: none; padding: 10px 14px; background: #f0fdfa;">
                ' . ($impTitle !== '' ? '<p style="margin: 0 0 5px 0;"><b>پیشنهاد بهبود مبنا:</b> شماره #' . $impId . ' — «' . $impTitle . '»</p>' : '') . '
                <p style="margin: 0 0 6px 0;"><b>خلاصه تغییرات و اصلاحات:</b> ' . htmlspecialchars($changeSummary, ENT_QUOTES, 'UTF-8') . '</p>
                ' . ($optimizationImpact !== '' ? '<p style="margin: 0; color: #0f766e;"><b>دستاورد عملیاتی:</b> ' . htmlspecialchars($optimizationImpact, ENT_QUOTES, 'UTF-8') . '</p>' : '') . '
            </div>
        </div>';
    }

    // شاخص‌های کلیدی SLA / KPI (در حالت طراحی)
    if ($kpis !== '') {
        $bodyHtml .= '
        <div style="margin-bottom: 15px;">
            <div style="background-color: #0369a1; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                شاخص‌های کلیدی عملکرد و توافق‌نامه سطح خدمت (KPI / SLA)
            </div>
            <div style="border: 1px solid #cbd5e1; border-top: none; padding: 9px 12px; background: #f0f9ff;">
                <p style="margin: 0; line-height: 1.8; color: #0c4a6e;">' . nl2br(htmlspecialchars($kpis, ENT_QUOTES, 'UTF-8')) . '</p>
            </div>
        </div>';
    }

    // جدول توالی گام‌های اجرایی
    $bodyHtml .= '
        <div style="margin-bottom: 15px;">
            <div style="background-color: ' . $accentColor . '; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                روش اجرایی و توالی گام‌ها (گردش کار استاندارد)
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 10.5px;">
                <thead>
                    <tr style="background: #e2e8f0; color: #0f172a;">
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 6%; text-align: center;">گام</th>
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 23%; text-align: right;">سمت / نقش مسئول</th>
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 45%; text-align: right;">شرح اقدام اجرایی</th>
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 26%; text-align: right;">الزامات، مهلت و خروجی</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $stepsHtml . '
                </tbody>
            </table>
        </div>';

    // یادداشت‌های معماری هرمس (در حالت طراحی)
    if ($optNotes !== '') {
        $bodyHtml .= '
        <div style="margin-bottom: 15px;">
            <div style="background-color: #334155; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                ملاحظات معماری فرآیند و رفع گلوگاه‌ها (تحلیل هرمس)
            </div>
            <div style="border: 1px solid #cbd5e1; border-top: none; padding: 9px 12px; background: #f8fafc;">
                <p style="margin: 0; line-height: 1.8; color: #475569;">' . nl2br(htmlspecialchars($optNotes, ENT_QUOTES, 'UTF-8')) . '</p>
            </div>
        </div>';
    }

    // تاریخچه تغییرات و سوابق بازنگری (در حالت بازنگری)
    if (!$isDesign) {
        $history = $data['revision_history'] ?? [];
        $historyHtml = '';
        if (is_array($history) && !empty($history)) {
            foreach ($history as $h) {
                $hVer = htmlspecialchars((string)($h['NewVersion'] ?? $h['Version'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $hDate = htmlspecialchars((string)($h['EffectiveDate'] ?? $h['CreatedAt'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $hDesc = htmlspecialchars((string)($h['Description'] ?? '—'), ENT_QUOTES, 'UTF-8');
                $hType = htmlspecialchars((string)($h['ChangeType'] ?? 'بازنگری'), ENT_QUOTES, 'UTF-8');
                $historyHtml .= '
                <tr>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; font-weight: bold;">' . $hVer . '</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center;">' . $hDate . '</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center;">' . $hType . '</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 8px;">' . $hDesc . '</td>
                </tr>';
            }
        } else {
            $historyHtml = '
            <tr>
                <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; font-weight: bold;">' . $version . '</td>
                <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center;">' . $todayJalali . '</td>
                <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center;">بهینه‌سازی هوشمند</td>
                <td style="border: 1px solid #cbd5e1; padding: 6px 8px;">' . htmlspecialchars($changeSummary, ENT_QUOTES, 'UTF-8') . '</td>
            </tr>';
        }

        $bodyHtml .= '
        <div style="margin-bottom: 10px;">
            <div style="background-color: #475569; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 11.5px; border-radius: 3px 3px 0 0;">
                تاریخچه تغییرات و سوابق بازنگری سند
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                <thead>
                    <tr style="background: #f1f5f9; color: #334155;">
                        <th style="border: 1px solid #cbd5e1; padding: 6px; width: 10%; text-align: center;">نسخه</th>
                        <th style="border: 1px solid #cbd5e1; padding: 6px; width: 15%; text-align: center;">تاریخ تصویب</th>
                        <th style="border: 1px solid #cbd5e1; padding: 6px; width: 18%; text-align: center;">نوع تغییر</th>
                        <th style="border: 1px solid #cbd5e1; padding: 6px; text-align: right;">شرح و منشأ بازنگری</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $historyHtml . '
                </tbody>
            </table>
        </div>';
    }

    $bodyHtml .= '</div>';

    $mpdf->WriteHTML($bodyHtml);

    if ($outputPath !== '') {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $mpdf->Output($outputPath, Destination::FILE);
        return $outputPath;
    }

    return $mpdf->Output('', Destination::STRING_RETURN);
}

/**
 * تولید سند رسمی دستورالعمل فرآیند با بهینه‌سازی‌های هرمس
 */
function generate_process_procedure_pdf(array $process, array $revisionData, string $outputPath = ''): string
{
    return rf_generate_procedure_pdf('revision', $process, $revisionData, $outputPath);
}

/**
 * تولید سند رسمی و شناسنامه فرآیند جدید تدوین‌شده توسط هرمس
 */
function generate_new_process_procedure_pdf(array $processData, array $designData, string $outputPath = ''): string
{
    return rf_generate_procedure_pdf('design', $processData, $designData, $outputPath);
}
