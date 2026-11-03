<?php
/**
 * بازنویسی و اعمال بهینه‌سازی در مستندات فرآیند توسط هوش مصنوعی هرمس — پنل تیم
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/files.php';
require_once __DIR__ . '/../inc/hermes.php';
require_once __DIR__ . '/../inc/pdf_generator.php';

$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$impId = (int)($_GET['imp'] ?? ($_POST['imp_id'] ?? 0));
if ($impId <= 0) {
    header('Location: discussion.php');
    exit;
}

// بررسی دسترسی تیم به فرآیند
$st = $db->prepare(
    "SELECT i.*, p.ID AS Pid, p.Name AS ProcName, p.Description AS ProcDesc, p.Version AS CurrentVersion,
            p.FilePath, p.ResponsibleTeamID, cat.Title AS CatTitle, cat.FolderName,
            pt.TeamName AS RespTeamName,
            u.Name AS ReqName, u.Family AS ReqFamily
     FROM Improvements i
     JOIN Processes p ON p.ID = i.ProcessID
     JOIN ProcessCategories cat ON cat.ID = p.CategoryID
     LEFT JOIN ProcessTeams pt ON pt.ID = p.ResponsibleTeamID
     LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type = 'user'
     WHERE i.ID = ?"
);
$st->bind_param('i', $impId);
$st->execute();
$imp = $st->get_result()->fetch_assoc();
$st->close();

if (!$imp) {
    team_header($id, 'discussion', 'پیشنهاد یافت نشد');
    echo '<div class="card"><div class="empty"><span class="ico">🔍</span><p>پیشنهاد بهبود مورد نظر یافت نشد.</p></div></div>';
    app_footer();
    exit;
}

$isOwner = ((int)$imp['ResponsibleTeamID'] === $tid);
if (!$isOwner) {
    team_header($id, 'discussion', 'عدم دسترسی');
    echo '<div class="card"><div class="alert alert-error">فقط تیم مسئول این فرآیند («' . e($imp['RespTeamName'] ?? '—') . '») اجازه بهینه‌سازی و بازنویسی مستندات را دارد.</div></div>';
    app_footer();
    exit;
}

$notice = '';
$error = '';
$appliedResult = null;

/* ---------- پردازش درخواست‌ها ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است. لطفاً صفحه را مجدداً بارگذاری نمایید.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        // دریافت فیلدهای فرم
        $newVersion = trim((string)($_POST['new_version'] ?? ''));
        $changeSummary = trim((string)($_POST['change_summary'] ?? ''));
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $optImpact = trim((string)($_POST['optimization_impact'] ?? ''));

        // بازسازی گام‌ها
        $steps = [];
        $postedSteps = $_POST['steps'] ?? [];
        if (is_array($postedSteps)) {
            foreach ($postedSteps as $idx => $s) {
                $role = trim((string)($s['role'] ?? ''));
                $act = trim((string)($s['action'] ?? ''));
                $notes = trim((string)($s['notes'] ?? '—'));
                if ($act !== '') {
                    $steps[] = [
                        'step' => $idx + 1,
                        'role' => $role ?: 'مسئول فرآیند',
                        'action' => $act,
                        'notes' => $notes ?: '—'
                    ];
                }
            }
        }

        $revisionData = [
            'new_version' => $newVersion,
            'change_summary' => $changeSummary,
            'purpose' => $purpose,
            'optimization_impact' => $optImpact,
            'steps' => $steps,
        ];

        if ($action === 'preview_pdf') {
            // پیش‌نمایش آنی فایل PDF بدون ذخیره نهایی
            try {
                $pdfBinary = generate_process_procedure_pdf($imp, $revisionData);
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="preview-procedure-v' . $newVersion . '.pdf"');
                header('Content-Length: ' . strlen($pdfBinary));
                echo $pdfBinary;
                exit;
            } catch (Throwable $e) {
                $error = 'خطا در ایجاد پیش‌نمایش PDF: ' . $e->getMessage();
            }
        } elseif ($action === 'apply_revision') {
            @set_time_limit(120);
            $res = hermes_apply_procedure_revision($db, $impId, $tid, $revisionData);
            if ($res['success']) {
                $notice = 'بهینه‌سازی با موفقیت در پرونده فرآیند اعمال شد. فایل PDF رسمی در نسخه ' . $res['new_version'] . ' تدوین و منتشر گردید.';
                $appliedResult = $res;
                // به‌روزرسانی اطلاعات بارگذاری‌شده
                $imp['Status'] = 'implemented';
                $imp['CurrentVersion'] = $res['new_version'];
            } else {
                $error = 'خطا در اعمال بهینه‌سازی: ' . ($res['error'] ?? 'خطای نامشخص');
            }
        }
    }
}

// در صورت باز شدن صفحه برای بار اول (یا عدم ارسال فرم)، پیش‌نویس از هرمس دریافت می‌شود
$draft = null;
if (!$appliedResult) {
    @set_time_limit(60);
    $draftRes = hermes_draft_procedure_revision($db, $impId);
    if ($draftRes['success']) {
        $draft = $draftRes['revision'];
    }
}

$pageTitle = 'بازنویسی مستند فرآیند با هرمس: ' . $imp['ProcName'];
team_header($id, 'discussion', $pageTitle);
?>

<div class="crumbs">
    <a href="processes.php">فرآیندها</a> ←
    <a href="file.php?id=<?= (int)$imp['Pid'] ?>"><?= e($imp['ProcName']) ?></a> ←
    <a href="discussion.php?imp=<?= (int)$imp['ID'] ?>">گفتگوی بهبود #<?= (int)$imp['ID'] ?></a> ←
    <span>بازنویسی مستند با هرمس</span>
</div>

<?php if ($notice): ?>
    <div class="alert alert-ok" style="font-size:14px;padding:16px;border-radius:12px;margin-bottom:16px">
        <?= app_icon('check', '', 20) ?>
        <div style="flex:1">
            <b><?= e($notice) ?></b>
            <?php if ($appliedResult): ?>
                <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">
                    <a class="btn btn-sm btn-brand" href="<?= e($appliedResult['pdf_url']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px">
                        <?= app_icon('files', '', 15) ?>
                        <span>مشاهده و دانلود فایل PDF جدید</span>
                    </a>
                    <a class="btn btn-sm btn-ghost" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=docs" style="display:inline-flex;align-items:center;gap:6px">
                        <span>پرونده فرآیند (تب مستندات)</span>
                    </a>
                    <a class="btn btn-sm btn-ghost" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=changes" style="display:inline-flex;align-items:center;gap:6px">
                        <span>سوابق تغییرات</span>
                    </a>
                    <a class="btn btn-sm btn-ghost" href="discussion.php?imp=<?= (int)$imp['ID'] ?>" style="display:inline-flex;align-items:center;gap:6px">
                        <span>بازگشت به رشته گفتگو</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="font-size:13.5px;padding:14px;border-radius:12px;margin-bottom:16px">
        <?= app_icon('x', '', 18) ?>
        <span><?= e($error) ?></span>
    </div>
<?php endif; ?>

<?php if (!$appliedResult): ?>
    <div class="card" style="margin-bottom:16px;border-top:4px solid var(--brand)">
        <div class="page-head" style="margin-bottom:12px">
            <div>
                <h2 style="margin:0;display:flex;align-items:center;gap:8px">
                    <?= app_icon('bot', '', 24) ?>
                    <span>موتور بازنگری و تدوین هوشمند مستندات (هرمس)</span>
                </h2>
                <p class="sub" style="margin:4px 0 0 0;color:var(--muted);font-size:13px">
                    بررسی انطباق بهبود، بازنویسی گام‌های اجرایی و تولید سند مصوب PDF فرآیند با شماره نسخه جدید
                </p>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <span class="badge" style="background:#e0e7ff;color:#3730a3;font-size:12px;padding:5px 12px;border-radius:20px">
                    نسخه فعلی: <?= e($imp['CurrentVersion'] ?: '1.0') ?>
                </span>
                <span style="font-weight:bold;color:var(--muted)">←</span>
                <span class="badge" style="background:#dcfce7;color:#166534;font-size:12px;padding:5px 12px;border-radius:20px">
                    نسخه بازنگری: <?= e($draft['new_version'] ?? '1.1') ?>
                </span>
            </div>
        </div>

        <div style="background:var(--brand-soft);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px">
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:6px">
                <b>پیشنهاد بهبود مبنا:</b>
                <span><?= e($imp['Title']) ?></span>
                <?= hermes_badge($imp['AiVerdict'] ?? 'pending') ?>
            </div>
            <?php if ($imp['Description']): ?>
                <div style="color:var(--muted);line-height:1.6"><?= nl2br(e($imp['Description'])) ?></div>
            <?php endif; ?>
        </div>

        <form method="post" id="revisionForm" style="display:flex;flex-direction:column;gap:16px">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="imp_id" value="<?= (int)$imp['ID'] ?>">

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:16px">
                <label class="field" style="margin:0">
                    <span class="field-label">شماره نسخه جدید مصوب:</span>
                    <input type="text" name="new_version" required value="<?= e($draft['new_version'] ?? '1.1') ?>" style="font-weight:bold">
                    <span class="sub" style="font-size:11.5px;color:var(--muted)">نسخه قبلی: <?= e($imp['CurrentVersion'] ?: '1.0') ?></span>
                </label>
                <label class="field" style="margin:0">
                    <span class="field-label">خلاصه تغییرات اعمال‌شده در این نسخه:</span>
                    <input type="text" name="change_summary" required value="<?= e($draft['change_summary'] ?? '') ?>">
                </label>
            </div>

            <label class="field" style="margin:0">
                <span class="field-label">هدف و دامنه کاربرد دستورالعمل:</span>
                <textarea name="purpose" rows="2" style="font-size:13px"><?= e($draft['purpose'] ?? '') ?></textarea>
            </label>

            <label class="field" style="margin:0">
                <span class="field-label">تاثیر و دستاورد عملیاتی این بهینه‌سازی (تحلیل هرمس):</span>
                <input type="text" name="optimization_impact" value="<?= e($draft['optimization_impact'] ?? '') ?>">
            </label>

            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span class="field-label" style="margin:0;font-size:13.5px;font-weight:bold">مراحل اجرایی فرآیند (گردش کار اصلاح‌شده):</span>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="addStepRow()" style="display:inline-flex;align-items:center;gap:4px">
                        <?= app_icon('plus', '', 13) ?>
                        <span>افزودن گام جدید</span>
                    </button>
                </div>

                <div class="table-wrap">
                    <table class="data" id="stepsTable">
                        <thead>
                            <tr>
                                <th style="width:6%;text-align:center">گام</th>
                                <th style="width:24%">نقش / واحد مسئول</th>
                                <th style="width:44%">شرح اقدام اجرایی</th>
                                <th style="width:22%">الزامات و نکات کنترلی</th>
                                <th style="width:4%"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $steps = $draft['steps'] ?? [];
                            foreach ($steps as $idx => $st):
                            ?>
                            <tr>
                                <td style="text-align:center;font-weight:bold;color:var(--brand)">
                                    <span class="step-num"><?= (int)($st['step'] ?? ($idx + 1)) ?></span>
                                </td>
                                <td>
                                    <input type="text" name="steps[<?= $idx ?>][role]" value="<?= e($st['role'] ?? '') ?>" required style="width:100%;font-size:12.5px;padding:6px 8px">
                                </td>
                                <td>
                                    <textarea name="steps[<?= $idx ?>][action]" required rows="2" style="width:100%;font-size:12.5px;padding:6px 8px"><?= e($st['action'] ?? '') ?></textarea>
                                </td>
                                <td>
                                    <input type="text" name="steps[<?= $idx ?>][notes]" value="<?= e($st['notes'] ?? '—') ?>" style="width:100%;font-size:12px;padding:6px 8px">
                                </td>
                                <td style="text-align:center">
                                    <button type="button" class="btn btn-ghost btn-xs" onclick="removeStepRow(this)" title="حذف این گام" style="color:var(--danger)">×</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:flex;gap:12px;align-items:center;justify-content:flex-end;margin-top:12px;padding-top:16px;border-top:1px solid var(--border)">
                <a class="btn btn-ghost" href="discussion.php?imp=<?= (int)$imp['ID'] ?>">انصراف و بازگشت</a>

                <button type="submit" name="action" value="preview_pdf" formtarget="_blank" class="btn btn-ghost" style="display:inline-flex;align-items:center;gap:6px">
                    <?= app_icon('search', '', 15) ?>
                    <span>پیش‌نمایش آنلاین PDF</span>
                </button>

                <button type="submit" name="action" value="apply_revision" class="btn btn-brand" style="display:inline-flex;align-items:center;gap:8px;padding:9px 20px" onclick="return confirm('آیا از تصویب نهایی و اعمال این بهینه‌سازی در پرونده و صدور فایل PDF مطمئن هستید؟')">
                    <?= app_icon('check', '', 16) ?>
                    <span>تأیید نهایی، صدور PDF و انتشار در پرونده</span>
                </button>
            </div>
        </form>
    </div>

    <script>
    function reindexSteps() {
        const rows = document.querySelectorAll('#stepsTable tbody tr');
        rows.forEach((row, i) => {
            row.querySelector('.step-num').textContent = (i + 1);
            const roleInput = row.querySelector('input[name*="[role]"]');
            const actionText = row.querySelector('textarea[name*="[action]"]');
            const notesInput = row.querySelector('input[name*="[notes]"]');
            if (roleInput) roleInput.name = `steps[${i}][role]`;
            if (actionText) actionText.name = `steps[${i}][action]`;
            if (notesInput) notesInput.name = `steps[${i}][notes]`;
        });
    }

    function addStepRow() {
        const tbody = document.querySelector('#stepsTable tbody');
        const count = tbody.querySelectorAll('tr').length;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="text-align:center;font-weight:bold;color:var(--brand)">
                <span class="step-num">${count + 1}</span>
            </td>
            <td>
                <input type="text" name="steps[${count}][role]" value="مجری فرآیند" required style="width:100%;font-size:12.5px;padding:6px 8px">
            </td>
            <td>
                <textarea name="steps[${count}][action]" required rows="2" placeholder="شرح اقدام..." style="width:100%;font-size:12.5px;padding:6px 8px"></textarea>
            </td>
            <td>
                <input type="text" name="steps[${count}][notes]" value="—" style="width:100%;font-size:12px;padding:6px 8px">
            </td>
            <td style="text-align:center">
                <button type="button" class="btn btn-ghost btn-xs" onclick="removeStepRow(this)" title="حذف این گام" style="color:var(--danger)">×</button>
            </td>
        `;
        tbody.appendChild(tr);
        reindexSteps();
    }

    function removeStepRow(btn) {
        const row = btn.closest('tr');
        const tbody = row.parentElement;
        if (tbody.querySelectorAll('tr').length <= 1) {
            alert('حداقل یک گام اجرایی برای فرآیند الزامی است.');
            return;
        }
        row.remove();
        reindexSteps();
    }
    </script>
<?php endif; ?>

<?php app_footer(); ?>
