<?php
/**
 * ماژول تولید رسمی مستندات و شناسنامه فرآیندها در قالب PDF
 * با استفاده از موتور mPDF و پشتیبانی کامل از چینش راست‌به‌چپ (RTL)، زبان فارسی و استاندارد مستندسازی فرآیندها
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/files.php';

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * تولید سند رسمی دستورالعمل فرآیند با بهینه‌سازی‌های هرمس
 *
 * @param array $process اطلاعات فرآیند (ID, Name, Description, Version, CatTitle, RespTeam, CompanyName, OwnerName, etc.)
 * @param array $revisionData اطلاعات بازنگری (new_version, change_summary, purpose, roles, steps, optimization_notes, etc.)
 * @param string $outputPath مسیر ذخیره فایل روی دیسک (در صورت خالی بودن، خروجی رشته برمی‌گردد)
 * @return string مسیر فایل ذخیره‌شده یا محتوای باینری PDF
 * @throws Exception در صورت بروز خطای تولید
 */
function generate_process_procedure_pdf(array $process, array $revisionData, string $outputPath = ''): string
{
    // تنظیمات فونت و اندازه صفحه
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'dejavusans',
        'autoScriptToLang' => true,
        'autoLangToFont' => true,
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 26,
        'margin_bottom' => 22,
        'margin_header' => 8,
        'margin_footer' => 8,
    ]);

    $procName = htmlspecialchars($process['Name'] ?? 'فرآیند بدون نام', ENT_QUOTES, 'UTF-8');
    $catTitle = htmlspecialchars($process['CatTitle'] ?? 'دسته‌بندی عمومی', ENT_QUOTES, 'UTF-8');
    $companyName = htmlspecialchars($process['CompanyName'] ?? (defined('APP_NAME') ? APP_NAME : 'سازمان'), ENT_QUOTES, 'UTF-8');
    $respTeam = htmlspecialchars($process['RespTeam'] ?? ($process['TeamName'] ?? 'تیم فرآیند'), ENT_QUOTES, 'UTF-8');
    $ownerName = htmlspecialchars(trim((string)($process['OwnerName'] ?? '') . ' ' . (string)($process['OwnerFamily'] ?? '')) ?: 'تعیین نشده', ENT_QUOTES, 'UTF-8');
    $newVersion = htmlspecialchars((string)($revisionData['new_version'] ?? ($process['Version'] ?? '1.0')), ENT_QUOTES, 'UTF-8');
    $oldVersion = htmlspecialchars((string)($revisionData['old_version'] ?? ($process['Version'] ?? '1.0')), ENT_QUOTES, 'UTF-8');
    $docCode = 'PRC-' . str_pad((string)($process['ID'] ?? 1), 3, '0', STR_PAD_LEFT);
    $effectiveDate = htmlspecialchars((string)($revisionData['effective_date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8');
    $todayJalali = function_exists('fmt_date_short') ? fmt_date_short(date('Y-m-d H:i:s')) : $effectiveDate;

    // سربرگ رسمی سند
    $headerHtml = '
    <div style="direction: rtl; text-align: right; font-family: dejavusans; border-bottom: 2px solid #1e3a8a; padding-bottom: 6px; font-size: 10px; color: #334155;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 33%; text-align: right;">
                    <span style="font-weight: bold; color: #1e3a8a; font-size: 12px;">' . $companyName . '</span><br>
                    <span>سامانه مدیریت فرآیندهای کسب‌وکار</span>
                </td>
                <td style="width: 34%; text-align: center;">
                    <span style="font-size: 14px; font-weight: bold; color: #0f172a;">دستورالعمل اجرایی فرآیند</span><br>
                    <span style="color: #64748b; font-size: 10px;">شناسنامه و روش اجرایی مصوب</span>
                </td>
                <td style="width: 33%; text-align: left; direction: ltr; font-size: 10px; color: #475569;">
                    <b>کد سند:</b> ' . $docCode . '<br>
                    <b>نسخه:</b> ' . $newVersion . ' | <b>تاریخ:</b> ' . $todayJalali . '
                </td>
            </tr>
        </table>
    </div>';

    // پابرگ رسمی سند
    $footerHtml = '
    <div style="direction: rtl; text-align: center; font-family: dejavusans; border-top: 1px solid #cbd5e1; padding-top: 6px; font-size: 9px; color: #64748b;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 60%; text-align: right;">
                    <span>سند مصوب سازمانی — بهینه‌سازی و تدوین‌شده توسط موتور مهندسی فرآیند هرمس (Hermes AI)</span>
                </td>
                <td style="width: 40%; text-align: left; direction: ltr;">
                    صفحه {PAGENO} از {nbpg}
                </td>
            </tr>
        </table>
    </div>';

    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);

    // آماده‌سازی بخش‌های بدنه
    $purpose = trim((string)($revisionData['purpose'] ?? ''));
    if ($purpose === '') {
        $purpose = 'هدف از این دستورالعمل، تعیین ضوابط، مسئولیت‌ها و توالی اقدامات لازم جهت اجرای صحیح، استاندارد و شفاف فرآیند «' . $procName . '» در کلیه واحدهای ذی‌ربط می‌باشد.';
    }

    $changeSummary = trim((string)($revisionData['change_summary'] ?? 'بهینه‌سازی و بازنگری گام‌های فرآیند جهت بهبود سرعت و کیفیت اجرا.'));
    $optimizationImpact = trim((string)($revisionData['optimization_impact'] ?? ''));
    $impTitle = htmlspecialchars(trim((string)($revisionData['improvement_title'] ?? '')), ENT_QUOTES, 'UTF-8');
    $impId = (int)($revisionData['improvement_id'] ?? 0);

    // مراحل فرآیند
    $steps = $revisionData['steps'] ?? [];
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
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; text-align: center; font-weight: bold; color: #1e3a8a;">' . $stNum . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; font-weight: 500; color: #334155;">' . $stRole . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; line-height: 1.6;">' . $stAction . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 7px 8px; color: #475569; font-size: 10px; line-height: 1.5;">' . $stNotes . '</td>
        </tr>';
    }

    // سوابق تغییرات
    $history = $revisionData['revision_history'] ?? [];
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
            <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center; font-weight: bold;">' . $newVersion . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center;">' . $todayJalali . '</td>
            <td style="border: 1px solid #cbd5e1; padding: 6px 8px; text-align: center;">بهینه‌سازی هوشمند</td>
            <td style="border: 1px solid #cbd5e1; padding: 6px 8px;">' . htmlspecialchars($changeSummary, ENT_QUOTES, 'UTF-8') . '</td>
        </tr>';
    }

    // ساختار کامل HTML سند
    $bodyHtml = '
    <div style="direction: rtl; text-align: right; font-family: dejavusans; line-height: 1.7; color: #1e293b; font-size: 11px;">

        <!-- عنوان اصلی سند -->
        <div style="text-align: center; margin-bottom: 16px;">
            <h1 style="color: #1e3a8a; font-size: 18px; margin: 0 0 4px 0;">دستورالعمل اجرایی: ' . $procName . '</h1>
            <span style="font-size: 11px; color: #64748b;">حوزه: ' . $catTitle . ' | نسخه فعال: ' . $newVersion . ' | تاریخ تصویب: ' . $todayJalali . '</span>
        </div>

        <!-- ۱. شناسنامه فرآیند -->
        <div style="margin-bottom: 16px;">
            <div style="background-color: #1e3a8a; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 12px; border-radius: 3px 3px 0 0;">
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
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px; font-weight: bold; color: #0284c7;">' . $newVersion . ' (قبلی: ' . $oldVersion . ')</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">تیم مسئول:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px;">' . $respTeam . '</td>
                    <td style="border: 1px solid #cbd5e1; background: #f8fafc; padding: 6px 10px; font-weight: bold; color: #334155;">مالک فرآیند:</td>
                    <td style="border: 1px solid #cbd5e1; padding: 6px 10px;">' . $ownerName . '</td>
                </tr>
            </table>
        </div>

        <!-- ۲. هدف و دامنه کاربرد -->
        <div style="margin-bottom: 16px;">
            <div style="background-color: #1e3a8a; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 12px; border-radius: 3px 3px 0 0;">
                ۲. هدف و دامنه کاربرد
            </div>
            <div style="border: 1px solid #cbd5e1; border-top: none; padding: 10px 12px; background: #ffffff;">
                <p style="margin: 0; line-height: 1.8;">' . nl2br(htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')) . '</p>
            </div>
        </div>

        <!-- ۳. کادر اختصاصی بهینه‌سازی اعمال‌شده توسط هرمس -->
        <div style="margin-bottom: 16px;">
            <div style="background-color: #0d9488; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 12px; border-radius: 3px 3px 0 0;">
                ۳. بهینه‌سازی اعمال‌شده در نسخه ' . $newVersion . ' (موتور مهندسی هرمس)
            </div>
            <div style="border: 1px solid #0d9488; border-top: none; padding: 10px 14px; background: #f0fdfa;">
                ' . ($impTitle !== '' ? '<p style="margin: 0 0 5px 0;"><b>پیشنهاد بهبود مبنا:</b> شماره #' . $impId . ' — «' . $impTitle . '»</p>' : '') . '
                <p style="margin: 0 0 6px 0;"><b>خلاصه تغییرات و اصلاحات:</b> ' . htmlspecialchars($changeSummary, ENT_QUOTES, 'UTF-8') . '</p>
                ' . ($optimizationImpact !== '' ? '<p style="margin: 0; color: #0f766e;"><b>دستاورد عملیاتی:</b> ' . htmlspecialchars($optimizationImpact, ENT_QUOTES, 'UTF-8') . '</p>' : '') . '
            </div>
        </div>

        <!-- ۴. مراحل گام‌به‌گام اجرای فرآیند -->
        <div style="margin-bottom: 16px;">
            <div style="background-color: #1e3a8a; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 12px; border-radius: 3px 3px 0 0;">
                ۴. روش اجرایی و توالی گام‌ها (گردش کار بهینه‌شده)
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 10.5px;">
                <thead>
                    <tr style="background: #e2e8f0; color: #0f172a;">
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 6%; text-align: center;">گام</th>
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 22%; text-align: right;">نقش / واحد مسئول</th>
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 47%; text-align: right;">شرح اقدام اجرایی</th>
                        <th style="border: 1px solid #cbd5e1; padding: 7px; width: 25%; text-align: right;">خروجی / الزامات کنترلی</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $stepsHtml . '
                </tbody>
            </table>
        </div>

        <!-- ۵. جدول سوابق بازنگری و تغییرات -->
        <div style="margin-bottom: 10px;">
            <div style="background-color: #475569; color: #ffffff; padding: 5px 10px; font-weight: bold; font-size: 12px; border-radius: 3px 3px 0 0;">
                ۵. تاریخچه تغییرات و سوابق بازنگری سند
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
        </div>

    </div>';

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

