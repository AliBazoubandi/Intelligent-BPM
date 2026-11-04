<?php
/**
 * اطلاعات پایه > تنظیمات و لاگ پیام‌رسان بله (Bale Messenger Console)
 * تنظیمات وب‌سرویس، تست ارسال زنده، و مشاهده تاریخچه اعلان‌های پیام‌رسان بله
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/bale.php';

$id = require_area('team');
$db = app_db();

$notice = '';
$error = '';
$testResult = null;

$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'ok') {
        $notice = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

/* ---------- پردازش اکشن‌ها (ارسال تست، ارسال مجدد) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'اعتبار صفحه منقضی شده است.');
        header('Location: basics_bale.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'test_send') {
        $testChatId = trim((string)($_POST['test_chat_id'] ?? ''));
        $testText = trim((string)($_POST['test_text'] ?? ''));

        if ($testChatId === '' || $testText === '') {
            $error = 'شناسه/شماره تماس بله و متن پیام تست الزامی است.';
        } else {
            $res = bale_send_message($testChatId, $testText, [
                'recipient_name'     => 'تست کاربری',
                'recipient_position' => 'آزمایش وب‌سرویس',
            ]);
            $testResult = $res;
            if ($res['success']) {
                $notice = 'پیام آزمایشی با موفقیت به وب‌سرویس بله ارسال شد (کد پاسخ: ' . $res['http_code'] . ').';
            } else {
                $error = 'خطا در ارسال پیام به وب‌سرویس بله: ' . ($res['error'] ?: 'کد خطای HTTP ' . $res['http_code']);
            }
        }
    } elseif ($action === 'retry_log') {
        $logId = (int)($_POST['log_id'] ?? 0);
        $st = $db->prepare("SELECT * FROM BaleMessageLog WHERE ID = ?");
        $st->bind_param('i', $logId);
        $st->execute();
        $logRow = $st->get_result()->fetch_assoc();
        $st->close();

        if ($logRow) {
            $meta = [
                'process_id'         => $logRow['ProcessID'],
                'personnel_id'       => $logRow['RecipientPersonnelID'],
                'recipient_name'     => $logRow['RecipientName'],
                'recipient_position' => $logRow['RecipientPosition'],
            ];
            $res = bale_send_message($logRow['BaleChatID'], $logRow['MessageText'], $meta);
            if ($res['success']) {
                flash_set('ok', 'پیام مجدداً با موفقیت به ' . e($logRow['RecipientName']) . ' در بله ارسال شد.');
            } else {
                flash_set('error', 'خطا در ارسال مجدد: ' . ($res['error'] ?: 'کد ' . $res['http_code']));
            }
        }
        header('Location: basics_bale.php');
        exit;
    }
}

/* ---------- استخراج لاگ پیام‌های بله ---------- */
$logsQuery = "
    SELECT bml.*, p.Name AS ProcName
    FROM BaleMessageLog bml
    LEFT JOIN Processes p ON p.ID = bml.ProcessID
    ORDER BY bml.ID DESC
    LIMIT 100
";
$resLogs = $db->query($logsQuery);
$logs = [];
while ($r = $resLogs->fetch_assoc()) {
    $logs[] = $r;
}

$totalSent = count(array_filter($logs, fn($l) => $l['Status'] === 'sent'));
$totalFailed = count(array_filter($logs, fn($l) => $l['Status'] === 'failed'));
$csrf = csrf_token();
?>
<?php team_header($id, 'basics_bale', 'تنظیمات پیام‌رسان بله'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="page-head" style="margin-bottom:16px">
    <div>
        <h1 style="font-size:18px;margin:0 0 4px">تنظیمات و مانیتورینگ پیام‌رسان بله (Bale)</h1>
        <div class="sub">ارسال خودکار اعلان‌های تغییر فرآیند به متولیان و مخاطبان اثرپذیر از طریق وب‌سرویس فعال بله</div>
    </div>
    <div>
        <a class="btn btn-sm btn-ghost" href="basics_chart.php">
            <?= app_icon('basics', '', 14) ?> بازگشت به چارت سازمانی
        </a>
    </div>
</div>

<!-- کارت وضعیت و راهنمای اتصال -->
<div class="form-row" style="margin-bottom:18px">
    <div class="card" style="flex:1;margin-bottom:0">
        <h2 style="font-size:15px;margin-bottom:10px">📡 وضعیت وب‌سرویس بله</h2>
        <table class="detail-table" style="font-size:13px;width:100%">
            <tr>
                <td style="color:var(--muted);width:140px">آدرس وب‌سرویس (API):</td>
                <td><code class="num-ltr" style="font-size:12.5px;background:var(--bg);padding:3px 7px;border-radius:6px"><?= e(BALE_API_URL) ?></code></td>
            </tr>
            <tr>
                <td style="color:var(--muted)">وضعیت ماژول:</td>
                <td>
                    <?php if (BALE_API_ENABLED): ?>
                        <span class="badge badge-active">فعال (آماده ارسال پیام)</span>
                    <?php else: ?>
                        <span class="badge badge-archived">غیرفعال (حالت شبیه‌سازی)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="color:var(--muted)">مهلت پاسخ (Timeout):</td>
                <td><span class="num-ltr"><?= farsi_num(BALE_TIMEOUT) ?></span> ثانیه</td>
            </tr>
            <tr>
                <td style="color:var(--muted)">قالب بدنه پیام:</td>
                <td><span style="font-size:12px;color:var(--muted)">سازگار با انواع فیلدهای <code>chat_id</code>، <code>text</code>، <code>to</code>، <code>message</code> و <code>phone</code></span></td>
            </tr>
        </table>
    </div>

    <!-- فرم تست آنی ارسال پیام به بله -->
    <div class="card" style="flex:1;margin-bottom:0">
        <h2 style="font-size:15px;margin-bottom:8px">🧪 تست ارسال زنده پیام به بله</h2>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="test_send">

            <label class="field" style="margin-bottom:10px">
                <span class="field-label">شناسه کاربری، چت‌آیدی یا شماره تماس در بله</span>
                <input type="text" name="test_chat_id" required placeholder="مثلاً 123456789 یا 0912..." class="num-ltr" value="<?= e($_POST['test_chat_id'] ?? '') ?>" style="text-align:left">
            </label>

            <label class="field" style="margin-bottom:12px">
                <span class="field-label">متن پیام تست</span>
                <textarea name="test_text" rows="2" required placeholder="سلام، این یک پیام آزمایشی از سامانه رابین‌فلو است."><?= e($_POST['test_text'] ?? 'سلام، این یک پیام آزمایشی اتصال موفق سامانه رابین‌فلو به پیام‌رسان بله است.') ?></textarea>
            </label>

            <button type="submit" class="btn btn-sm">
                <?= app_icon('bot', '', 14) ?> ارسال پیام تست
            </button>
        </form>

        <?php if ($testResult): ?>
            <div style="margin-top:12px;padding:10px;border-radius:8px;background:var(--bg);font-size:12px">
                <strong>نتیجه درخواست:</strong> HTTP <?= (int)$testResult['http_code'] ?>
                <?php if ($testResult['error']): ?>
                    <div style="color:var(--danger);margin-top:4px"><?= e($testResult['error']) ?></div>
                <?php endif; ?>
                <?php if ($testResult['response']): ?>
                    <div style="margin-top:4px;word-break:break-all"><code class="num-ltr"><?= e($testResult['response']) ?></code></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- جدول لاگ پیام‌های ارسال شده -->
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap">
        <h2 style="margin:0">تاریخچه پیام‌های ارسال‌شده در بله (<?= farsi_num(count($logs)) ?>)</h2>
        <div style="display:flex;gap:6px">
            <span class="badge badge-active">ارسال موفق: <?= farsi_num($totalSent) ?></span>
            <span class="badge badge-archived" style="background:#fee2e2;color:#b91c1c">ناموفق: <?= farsi_num($totalFailed) ?></span>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>گیرنده و سمت سازمانی</th>
                    <th>شناسه / شماره بله</th>
                    <th>فرآیند مرتبط</th>
                    <th>متن پیام</th>
                    <th>وضعیت</th>
                    <th>زمان ارسال</th>
                    <th style="text-align:left">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted)">هنوز پیامی از سامانه به بله ارسال نشده است.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="num-ltr" style="color:var(--muted);font-size:12px"><?= farsi_num($l['ID']) ?></td>
                            <td>
                                <strong><?= e($l['RecipientName'] ?: 'نامشخص') ?></strong>
                                <?php if (!empty($l['RecipientPosition'])): ?>
                                    <div style="font-size:11.5px;color:var(--muted)"><?= e($l['RecipientPosition']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="num-ltr" style="font-size:12px;background:var(--bg);padding:2px 6px;border-radius:4px"><?= e($l['BaleChatID']) ?></code>
                            </td>
                            <td>
                                <?= !empty($l['ProcName']) ? e($l['ProcName']) : '<span style="color:var(--muted)">—</span>' ?>
                            </td>
                            <td style="max-width:280px">
                                <div style="font-size:12px;line-height:1.4;white-space:pre-wrap;max-height:60px;overflow:hidden;text-overflow:ellipsis" title="<?= e($l['MessageText']) ?>">
                                    <?= e(mb_substr($l['MessageText'], 0, 120)) ?><?= mb_strlen($l['MessageText']) > 120 ? '…' : '' ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($l['Status'] === 'sent'): ?>
                                    <span class="badge badge-active" title="<?= e($l['ApiResponse'] ?? '') ?>">✓ ارسال شد</span>
                                <?php elseif ($l['Status'] === 'failed'): ?>
                                    <span class="badge" style="background:#fee2e2;color:#b91c1c" title="<?= e($l['ApiResponse'] ?? '') ?>">✕ خطا</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#fef3c7;color:#92400e">در صف</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= fmt_date($l['SentAt']) ?>
                            </td>
                            <td style="text-align:left">
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="retry_log">
                                    <input type="hidden" name="log_id" value="<?= (int)$l['ID'] ?>">
                                    <button type="submit" class="btn btn-xs btn-ghost" title="ارسال دوباره این پیام به بله">
                                        ارسال مجدد
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php app_footer(); ?>
