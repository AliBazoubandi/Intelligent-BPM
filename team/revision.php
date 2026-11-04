<?php
/**
 * بازنویسی و اعمال بهینه‌سازی در مستندات فرآیند توسط هوش مصنوعی هرمس — پنل تیم
 * پردازش کاملاً غیراسنکرون (Async Background Execution) همراه با اعلان پس از اتمام
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

/* ---------- بررسی وضعیت تسک با AJAX ---------- */
if (isset($_GET['ajax_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st = $db->prepare("SELECT * FROM HermesTasks WHERE TaskType = 'revision' AND RefID = ? ORDER BY ID DESC LIMIT 1");
    $st->bind_param('i', $impId);
    $st->execute();
    $task = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$task) {
        echo json_encode(['status' => 'none'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = $task['Result'] ? json_decode($task['Result'], true) : null;
    echo json_encode([
        'task_id' => (int)$task['ID'],
        'status' => $task['Status'],
        'result' => $res,
        'error' => $task['ErrorMessage'],
        'updated_at' => $task['UpdatedAt'],
    ], JSON_UNESCAPED_UNICODE);
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
            // راه‌اندازی غیراسنکرون (Async) کار در پس‌زمینه بدون ایجاد وقفه برای کاربر
            $taskId = hermes_dispatch_async_revision($db, $impId, $tid, $revisionData);
            if ($taskId > 0) {
                if (isset($_GET['ajax']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'task_id' => $taskId, 'status' => 'queued'], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                header('Location: revision.php?imp=' . $impId . '&task=' . $taskId);
                exit;
            } else {
                $error = 'خطا در ثبت و راه‌اندازی کار در پس‌زمینه.';
            }
        }
    }
}

// بررسی آخرین وضعیت تسک برای این بهبود
$st = $db->prepare("SELECT * FROM HermesTasks WHERE TaskType = 'revision' AND RefID = ? ORDER BY ID DESC LIMIT 1");
$st->bind_param('i', $impId);
$st->execute();
$currentTask = $st->get_result()->fetch_assoc();
$st->close();

$isTaskActive = ($currentTask && in_array($currentTask['Status'], ['queued', 'running'], true));
$isTaskCompleted = ($currentTask && $currentTask['Status'] === 'completed');
$taskResult = ($isTaskCompleted && !empty($currentTask['Result'])) ? json_decode($currentTask['Result'], true) : null;

// پیش‌نویس اولیه هرمس
$draft = null;
if (!$isTaskActive && !$isTaskCompleted && $imp['Status'] !== 'implemented') {
    @set_time_limit(45);
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
    <span>بازنویسی مستند با هرمس (Async)</span>
</div>

<?php if ($error): ?>
    <div class="alert alert-error" style="font-size:13.5px;padding:14px;border-radius:12px;margin-bottom:16px">
        <?= app_icon('x', '', 18) ?>
        <span><?= e($error) ?></span>
    </div>
<?php endif; ?>

<!-- کانتینر وضعیت پردازش پس‌زمینه (Async Processing Card) -->
<div id="asyncStatusContainer" style="<?= $isTaskActive ? 'display:block;' : 'display:none;' ?>margin-bottom:20px">
    <div class="card" style="border-top:4px solid var(--brand);text-align:center;padding:36px 20px">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:68px;height:68px;border-radius:50%;background:var(--brand-soft);color:var(--brand);margin-bottom:16px">
            <?= app_icon('bot', 'ai-spin', 36) ?>
        </div>
        <h2 style="margin:0 0 10px 0;color:var(--brand)">موتور هرمس در پس‌زمینه در حال تدوین و صدور سند است</h2>
        <p style="color:var(--muted);max-width:620px;margin:0 auto 20px auto;font-size:13.5px;line-height:1.7">
            درخواست بازنویسی و صدور سند رسمی فرآیند با موفقیت به صف پردازش پس‌زمینه ارسال شد.<br>
            <strong>نیازی به منتظر ماندن در این صفحه نیست؛</strong> می‌توانید آزادانه به سایر کارهای خود در سامانه بپردازید. به محض اتمام عملیات، اعلان تکمیل و لینک سند PDF در نوار اعلان‌ها ثبت خواهد شد.
        </p>
        <div style="max-width:380px;margin:0 auto 20px auto;background:#e2e8f0;height:6px;border-radius:3px;overflow:hidden">
            <div style="background:var(--brand);height:100%;width:70%;animation:ai-spin 3s infinite linear"></div>
        </div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <a class="btn btn-ghost btn-sm" href="processes.php">رفتن به لیست فرآیندها</a>
            <a class="btn btn-ghost btn-sm" href="discussion.php?imp=<?= (int)$imp['ID'] ?>">بازگشت به رشته گفتگو</a>
        </div>
    </div>
</div>

<!-- کانتینر نتیجه تکمیل‌شده (Completed Result Card) -->
<div id="completedContainer" style="<?= ($isTaskCompleted || $imp['Status'] === 'implemented') ? 'display:block;' : 'display:none;' ?>margin-bottom:20px">
    <div class="alert alert-ok" style="font-size:14px;padding:20px;border-radius:12px">
        <div style="display:flex;align-items:flex-start;gap:12px">
            <div style="background:#dcfce7;color:#166534;width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <?= app_icon('check', '', 22) ?>
            </div>
            <div style="flex:1">
                <h3 style="margin:0 0 6px 0;color:#166534">مستند مصوب فرآیند با موفقیت توسط هرمس بازنویسی و صادر شد</h3>
                <p style="margin:0 0 14px 0;font-size:13px;color:#334155;line-height:1.6">
                    نسخه‌های پیشین به صورت خودکار بایگانی شدند و فایل PDF رسمی با شماره نسخه جدید در پرونده ذخیره گردید. سیستم پرسش هوشمند (RAG) نیز با ضوابط جدید همگام شد.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap" id="completedButtons">
                    <?php if ($taskResult && !empty($taskResult['pdf_url'])): ?>
                        <a class="btn btn-sm btn-brand" href="<?= e($taskResult['pdf_url']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px">
                            <?= app_icon('files', '', 15) ?>
                            <span>مشاهده و دانلود فایل PDF جدید</span>
                        </a>
                    <?php else: ?>
                        <a class="btn btn-sm btn-brand" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=docs" style="display:inline-flex;align-items:center;gap:6px">
                            <?= app_icon('files', '', 15) ?>
                            <span>مشاهده مستند در پرونده فرآیند</span>
                        </a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-ghost" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=docs">پرونده فرآیند (مستندات)</a>
                    <a class="btn btn-sm btn-ghost" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=changes">سوابق تغییرات</a>
                    <a class="btn btn-sm btn-ghost" href="discussion.php?imp=<?= (int)$imp['ID'] ?>">بازگشت به گفتگو</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- فرم بازنگری و تنظیمات (در صورت عدم فعال بودن تسک) -->
<div id="formContainer" style="<?= ($isTaskActive || $isTaskCompleted || $imp['Status'] === 'implemented') ? 'display:none;' : 'display:block;' ?>">
    <div class="card" style="margin-bottom:16px;border-top:4px solid var(--brand)">
        <div class="page-head" style="margin-bottom:12px">
            <div>
                <h2 style="margin:0;display:flex;align-items:center;gap:8px">
                    <?= app_icon('bot', '', 24) ?>
                    <span>موتور بازنگری و تدوین هوشمند مستندات (هرمس)</span>
                </h2>
                <p class="sub" style="margin:4px 0 0 0;color:var(--muted);font-size:13px">
                    بررسی انطباق بهبود، بازنویسی گام‌های اجرایی و تولید سند مصوب PDF فرآیند با شماره نسخه جدید در پس‌زمینه
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
                <?= hermes_badge($imp['AiVerdict'] ?? 'pending', $imp['CreatedAt'] ?? null, (int)$imp['ID'], false) ?>
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

                <button type="submit" name="action" value="apply_revision" class="btn btn-brand" style="display:inline-flex;align-items:center;gap:8px;padding:9px 20px" onclick="return confirm('آیا از تصویب نهایی و ارسال عملیات بازنگری به هرمس (پردازش پس‌زمینه) مطمئن هستید؟')">
                    <?= app_icon('bolt', '', 16) ?>
                    <span>تأیید نهایی و اجرای پس‌زمینه (Async)</span>
                </button>
            </div>
        </form>
    </div>
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

// سیستم پایش خودکار وضعیت تسک در پس‌زمینه (Real-time Task Polling)
const impId = <?= (int)$imp['ID'] ?>;
let isPolling = <?= $isTaskActive ? 'true' : 'false' ?>;

function checkTaskStatus() {
    if (!isPolling) return;
    fetch(`revision.php?imp=${impId}&ajax_status=1`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'completed') {
                isPolling = false;
                document.getElementById('asyncStatusContainer').style.display = 'none';
                document.getElementById('formContainer').style.display = 'none';
                const compContainer = document.getElementById('completedContainer');
                compContainer.style.display = 'block';
                
                if (data.result && data.result.pdf_url) {
                    const btnWrap = document.getElementById('completedButtons');
                    btnWrap.innerHTML = `
                        <a class="btn btn-sm btn-brand" href="${data.result.pdf_url}" target="_blank" style="display:inline-flex;align-items:center;gap:6px">
                            <span>مشاهده و دانلود فایل PDF جدید</span>
                        </a>
                        <a class="btn btn-sm btn-ghost" href="file.php?id=<?= (int)$imp['Pid'] ?>&tab=docs">پرونده فرآیند (مستندات)</a>
                        <a class="btn btn-sm btn-ghost" href="discussion.php?imp=${impId}">بازگشت به گفتگو</a>
                    `;
                }
            } else if (data.status === 'failed') {
                isPolling = false;
                document.getElementById('asyncStatusContainer').style.display = 'none';
                document.getElementById('formContainer').style.display = 'block';
                alert('خطا در پردازش پس‌زمینه: ' + (data.error || 'خطای نامشخص'));
            } else {
                setTimeout(checkTaskStatus, 2000);
            }
        })
        .catch(err => {
            setTimeout(checkTaskStatus, 3000);
        });
}

if (isPolling) {
    setTimeout(checkTaskStatus, 1500);
}
</script>

<?php app_footer(); ?>
