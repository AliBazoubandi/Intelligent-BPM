<?php
/**
 * فرآیند جدید — پنل تیم مهندسی فرآیند (رابین‌فلو)
 * طراحی و تدوین هوشمند فرآیند با دستیار هرمس (AI Process Designer) بر پایه تحلیل مصاحبه صوتی/متنی
 * همراه با پشتیبانی از بازبینی تعاملی انسان‌محور (Human-in-the-Loop) و ثبت دستی سنتی
 */
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/files.php';
require_once __DIR__ . '/../inc/hermes.php';
require_once __DIR__ . '/../inc/pdf_generator.php';

$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$error = '';
$notice = '';
$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'ok') {
        $notice = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

/* ==============================================================
 * ۱. پیش‌نمایش زنده سند رسمی PDF فرآیند
 * ============================================================== */
if (isset($_GET['preview_pdf'])) {
    $draft = $_SESSION['hermes_new_process_draft'] ?? null;
    if (!$draft || !is_array($draft)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="font-family:tahoma,sans-serif;direction:rtl;padding:30px;color:#b91c1c;text-align:center"><h3>پیش‌نویسی برای نمایش یافت نشد.</h3><p>لطفاً ابتدا از طریق دستیار هرمس، متن مصاحبه را تحلیل نمایید.</p></div>';
        exit;
    }

    $catTitle = 'دسته‌بندی فرآیندی';
    $catId = (int)($draft['suggested_category_id'] ?? 0);
    if ($catId > 0) {
        $st = $db->prepare("SELECT Title FROM ProcessCategories WHERE ID = ?");
        $st->bind_param('i', $catId);
        $st->execute();
        $cRow = $st->get_result()->fetch_assoc();
        $st->close();
        if ($cRow) $catTitle = $cRow['Title'];
    }

    $ownerTitle = $draft['owner_role_title'] ?? 'متولی تعیین‌نشده';
    $ownerPosId = (int)($draft['owner_position_id'] ?? 0);
    if ($ownerPosId > 0) {
        $st = $db->prepare("SELECT Title FROM OrgPositions WHERE ID = ?");
        $st->bind_param('i', $ownerPosId);
        $st->execute();
        $pRow = $st->get_result()->fetch_assoc();
        $st->close();
        if ($pRow) $ownerTitle = $pRow['Title'];
    }

    $procMeta = [
        'Name' => $draft['name'] ?? 'فرآیند جدید',
        'CatTitle' => $catTitle,
        'CompanyName' => defined('APP_NAME') ? APP_NAME : 'سازمان',
        'RespTeam' => !empty($id['name']) ? $id['name'] : 'تیم فرآیند',
        'OwnerPositionTitle' => $ownerTitle,
        'AudienceLabel' => match ($draft['audience_type'] ?? 'all_company') {
            'department' => 'دپارتمان ' . ($draft['audience_dept'] ?? ''),
            'specific_positions' => 'سمت‌های تخصصی درگیر',
            'specific_personnel' => 'پرسنل مشخص',
            default => 'کلیه پرسنل سازمان'
        },
        'Version' => $draft['version'] ?? '1.0',
    ];

    try {
        $pdfData = generate_new_process_procedure_pdf($procMeta, $draft, '');
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="draft-process.pdf"');
        echo $pdfData;
    } catch (Throwable $e) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="font-family:tahoma,sans-serif;direction:rtl;padding:30px;color:#b91c1c;text-align:center"><h3>خطا در تولید فایل PDF پیش‌نمایش:</h3><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
    }
    exit;
}

/* ==============================================================
 * ۲. شروع مجدد و پاک‌کردن پیش‌نویس هرمس
 * ============================================================== */
if (isset($_GET['reset_draft'])) {
    unset($_SESSION['hermes_new_process_draft'], $_SESSION['hermes_new_process_raw']);
    header('Location: process_new.php?mode=ai');
    exit;
}

/* ==============================================================
 * ۳. عملیات‌های AJAX دستیار هرمس
 * ============================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    // الف) تبدیل فایل صوتی مصاحبه به متن
    if ($action === 'transcribe_audio') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_check($_POST['csrf'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'اعتبار صفحه منقضی شده است.']);
            exit;
        }

        if (empty($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'فایل صوتی دریافت نشد یا حجم آن بیش از حد مجاز است.']);
            exit;
        }

        $file = $_FILES['audio_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3', 'wav', 'm4a', 'ogg', 'webm', 'aac', 'flac', 'amr', 'wma'];
        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['success' => false, 'error' => 'فرمت فایل صوتی مجاز نیست. لطفاً از mp3, m4a, wav, ogg یا webm استفاده نمایید.']);
            exit;
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rf_audio_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            echo json_encode(['success' => false, 'error' => 'امکان ذخیره فایل صوتی در پوشه موقت وجود ندارد.']);
            exit;
        }

        // ایجاد تسک پس‌زمینه در HermesTasks جهت پردازش بدون مسدودسازی وب‌سرور
        $st = $db->prepare("INSERT INTO HermesTasks (TaskType, RefID, TeamID, Status, Payload) VALUES ('audio_transcription', 0, ?, 'queued', ?)");
        $payloadJson = json_encode(['file_path' => $tempPath, 'filename' => $file['name']], JSON_UNESCAPED_UNICODE);
        $st->bind_param('is', $tid, $payloadJson);
        $st->execute();
        $taskId = (int)$st->insert_id;
        $st->close();

        hermes_spawn_worker("--task={$taskId}");

        echo json_encode([
            'success' => true,
            'queued' => true,
            'task_id' => $taskId,
            'message' => 'فایل صوتی دریافت شد و در پس‌زمینه در حال پردازش است.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // الف-۲) بررسی وضعیت تسک تبدیل صوت
    if ($action === 'check_transcription') {
        header('Content-Type: application/json; charset=utf-8');
        $taskId = (int)($_REQUEST['task_id'] ?? 0);
        $st = $db->prepare("SELECT Status, Result, ErrorMessage FROM HermesTasks WHERE ID = ? AND TaskType = 'audio_transcription' AND TeamID = ?");
        $st->bind_param('ii', $taskId, $tid);
        $st->execute();
        $task = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$task) {
            echo json_encode(['success' => false, 'error' => 'تسک صوتی یافت نشد.']);
            exit;
        }

        if ($task['Status'] === 'completed') {
            $res = json_decode((string)$task['Result'], true) ?: [];
            echo json_encode([
                'success' => true,
                'status' => 'completed',
                'text' => $res['text'] ?? '',
                'message' => $res['message'] ?? null,
                'partial' => !empty($res['partial']),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } elseif ($task['Status'] === 'failed') {
            echo json_encode([
                'success' => false,
                'status' => 'failed',
                'error' => $task['ErrorMessage'] ?: 'پردازش فایل صوتی با خطا مواجه شد.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            echo json_encode(['success' => true, 'status' => $task['Status']]);
            exit;
        }
    }

    // الف-۳) استخراج متن از اسناد مصاحبه و نیازمندی‌ها (.docx, .pdf, .txt, .md)
    if ($action === 'extract_document') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_check($_POST['csrf'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'اعتبار صفحه منقضی شده است.']);
            exit;
        }

        if (empty($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'فایل سند دریافت نشد یا حجم آن بیش از حد مجاز است.']);
            exit;
        }

        $file = $_FILES['document_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['docx', 'pdf', 'txt', 'md', 'text'];
        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['success' => false, 'error' => 'فرمت فایل سند مجاز نیست. لطفاً از فایلهای docx, pdf, txt یا md استفاده فرمایید.']);
            exit;
        }

        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rf_doc_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            echo json_encode(['success' => false, 'error' => 'امکان ذخیره فایل سند در پوشه موقت وجود ندارد.']);
            exit;
        }

        $res = hermes_extract_document_text($tempPath, $ext);
        $res['filename'] = $file['name'];
        @unlink($tempPath);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ب) تحلیل مصاحبه و طراحی ساختار اولیه فرآیند توسط هرمس
    if ($action === 'design_process') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_check($_POST['csrf'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'اعتبار نشست منقضی شده است.']);
            exit;
        }

        $interviewText = trim((string)($_POST['interview_text'] ?? ''));
        if (mb_strlen($interviewText) < 15) {
            echo json_encode(['success' => false, 'error' => 'متن مصاحبه یا یادداشت‌ها بسیار کوتاه است؛ لطفاً توضیحات کامل‌تری از گردش‌کار وارد کنید.']);
            exit;
        }

        $hints = [
            'category_id' => (int)($_POST['category_hint'] ?? 0),
            'company_id' => (int)($_POST['company_hint'] ?? 0),
        ];

        @set_time_limit(120);
        $res = hermes_design_process_from_interview($db, $interviewText, $hints);
        if ($res['success'] && !empty($res['design'])) {
            $_SESSION['hermes_new_process_draft'] = $res['design'];
            $_SESSION['hermes_new_process_raw'] = $interviewText;
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ج) اصلاحیه و بازنگری تعاملی با هرمس (Human-in-the-Loop Refinement)
    if ($action === 'refine_process') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_check($_POST['csrf'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'اعتبار نشست منقضی شده است.']);
            exit;
        }

        $userFeedback = trim((string)($_POST['user_feedback'] ?? ''));
        if ($userFeedback === '') {
            echo json_encode(['success' => false, 'error' => 'لطفاً دستور یا بازخورد خود برای اصلاح فرآیند را بنویسید.']);
            exit;
        }

        // دریافت آخرین وضعیت طراحی (ترکیب داده‌های فرم ارسالی و نشست)
        $currentDesign = $_SESSION['hermes_new_process_draft'] ?? [];
        if (!empty($_POST['current_design_json'])) {
            $postedJson = json_decode((string)$_POST['current_design_json'], true);
            if (is_array($postedJson)) {
                $currentDesign = array_merge($currentDesign, $postedJson);
            }
        }

        @set_time_limit(120);
        $res = hermes_refine_process_design($db, $currentDesign, $userFeedback);
        if ($res['success'] && !empty($res['design'])) {
            $_SESSION['hermes_new_process_draft'] = $res['design'];
        }
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // د) تأیید نهایی، ایجاد پرونده و انتشار رسمی فرآیند
    if ($action === 'publish_process') {
        if (!csrf_check($_POST['csrf'] ?? null)) {
            flash_set('error', 'اعتبار نشست منقضی شده است.');
            header('Location: process_new.php?mode=ai');
            exit;
        }

        // دریافت داده‌های نهایی ارسال‌شده از فرم بازبینی
        $designData = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'suggested_category_id' => (int)($_POST['category_id'] ?? 0),
            'owner_position_id' => (int)($_POST['owner_position_id'] ?? 0),
            'owner_role_title' => trim((string)($_POST['owner_role_title'] ?? '')),
            'audience_type' => (string)($_POST['audience_type'] ?? 'all_company'),
            'audience_dept' => trim((string)($_POST['audience_dept'] ?? '')),
            'version' => trim((string)($_POST['version'] ?? '1.0')) ?: '1.0',
            'purpose' => trim((string)($_POST['purpose'] ?? '')),
            'scope' => trim((string)($_POST['scope'] ?? '')),
            'kpis_and_slas' => trim((string)($_POST['kpis_and_slas'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'optimization_notes' => trim((string)($_POST['optimization_notes'] ?? '')),
            'steps' => [],
        ];

        // گام‌های اجرایی ارسالی
        if (!empty($_POST['steps']) && is_array($_POST['steps'])) {
            $stepIdx = 1;
            foreach ($_POST['steps'] as $st) {
                if (!is_array($st)) continue;
                $actionText = trim((string)($st['action'] ?? ''));
                if ($actionText === '') continue;
                $designData['steps'][] = [
                    'step' => $stepIdx++,
                    'role' => trim((string)($st['role'] ?? 'مجری')),
                    'action' => $actionText,
                    'notes' => trim((string)($st['notes'] ?? '—')),
                ];
            }
        }

        $companyId = (int)($_POST['company_id'] ?? 0);
        $res = hermes_create_process_from_design($db, $designData, $tid, $companyId);

        if (!$res['success']) {
            flash_set('error', 'خطا در ثبت و انتشار فرآیند: ' . ($res['error'] ?? 'خطای ناشناخته'));
            header('Location: process_new.php?mode=ai');
            exit;
        }

        unset($_SESSION['hermes_new_process_draft'], $_SESSION['hermes_new_process_raw']);
        flash_set('ok', 'فرآیند جدید «' . $res['name'] . '» با موفقیت توسط هرمس معماری، تدوین و منتشر شد.');
        header('Location: file.php?id=' . (int)$res['process_id'] . '&created=1');
        exit;
    }
}

/* ==============================================================
 * ۴. ثبت دستی سنتی (Manual Form POST)
 * ============================================================== */
$old = [
    'name' => '', 'category_id' => 0, 'company_id' => 0, 'owner_position_id' => 0,
    'owner_id' => 0, 'audience_type' => 'all_company', 'audience_dept' => '',
    'version' => '1.0', 'status' => 'draft', 'description' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'manual_save')) {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } else {
        $status = (string)($_POST['status'] ?? 'draft');
        if (!in_array($status, ['draft','active','archived'], true)) $status = 'draft';

        $old = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'company_id' => (int)($_POST['company_id'] ?? 0),
            'owner_position_id' => (int)($_POST['owner_position_id'] ?? 0),
            'owner_id' => (int)($_POST['owner_id'] ?? 0),
            'audience_type' => (string)($_POST['audience_type'] ?? 'all_company'),
            'audience_dept' => trim((string)($_POST['audience_dept'] ?? '')),
            'version' => trim((string)($_POST['version'] ?? '1.0')) ?: '1.0',
            'status' => $status,
            'description' => trim((string)($_POST['description'] ?? '')),
        ];
        if (!in_array($old['audience_type'], ['all_company', 'specific_positions', 'specific_personnel', 'department'], true)) {
            $old['audience_type'] = 'all_company';
        }

        $st = $db->prepare('SELECT FolderName FROM ProcessCategories WHERE ID = ? AND Active = 1');
        $st->bind_param('i', $old['category_id']);
        $st->execute();
        $cat = $st->get_result()->fetch_assoc();
        $st->close();

        $cleanName = fm_clean_name($old['name']);
        if ($cleanName === null || mb_strlen($cleanName) < 3) {
            $error = 'نام فرآیند را کامل‌تر وارد کنید (حداقل ۳ نویسه بدون کاراکترهای غیرمجاز).';
        } elseif (!$cat) {
            $error = 'دسته فرآیند معتبر نیست.';
        } else {
            $old['name'] = $cleanName;
            $st = $db->prepare('SELECT ID FROM Processes WHERE Name = ? LIMIT 1');
            $st->bind_param('s', $cleanName);
            $st->execute();
            if ($st->get_result()->fetch_assoc()) {
                $st->close();
                $error = 'فرآیندی با این نام قبلاً ثبت شده است.';
            } else {
                $st->close();
                $catFolder = $cat['FolderName'];
                $rel = $catFolder . '/' . $cleanName . '/';
                $abs = fm_abs($rel);

                $companyId = $old['company_id'] ?: null;
                $ownerPosId = $old['owner_position_id'] ?: null;
                $ownerId = $old['owner_id'] ?: null;
                $audDept = ($old['audience_type'] === 'department' && $old['audience_dept'] !== '') ? $old['audience_dept'] : null;

                $db->begin_transaction();
                try {
                    $st = $db->prepare(
                        "INSERT INTO Processes (Name, CategoryID, CompanyID, FilePath, OwnerPersonnelID, OwnerPositionID, ResponsibleTeamID, Description, AudienceType, AudienceDepartment, Status, Version)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $st->bind_param('siisiissssss', $cleanName, $old['category_id'], $companyId, $rel, $ownerId, $ownerPosId, $tid, $old['description'], $old['audience_type'], $audDept, $status, $old['version']);
                    $st->execute();
                    $newId = (int)$db->insert_id;
                    $st->close();

                    if (!is_dir($abs)) {
                        @mkdir($abs, 0775, true);
                        @file_put_contents($abs . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
                    }

                    $db->commit();
                    header('Location: file.php?id=' . $newId . '&created=1');
                    exit;
                } catch (Throwable $e) {
                    $db->rollback();
                    $error = 'خطا در ثبت فرآیند: ' . $e->getMessage();
                }
            }
        }
    }
}

// داده‌های پایه برای فرم‌ها
$categories = active_categories($db);
$companies = [];
$res = $db->query('SELECT ID, Name FROM Companies WHERE Active=1 ORDER BY Name');
while ($r = $res->fetch_assoc()) $companies[] = $r;
$positions = [];
$res = $db->query("
    SELECT op.ID, op.Title, op.Department, p.Name, p.Family, p.PersonnelCode
    FROM OrgPositions op
    LEFT JOIN Personnel p ON p.ID = op.CurrentPersonnelID
    WHERE op.Active = 1
    ORDER BY op.SortOrder, op.Title
");
while ($r = $res->fetch_assoc()) $positions[] = $r;
$persons = [];
$res = $db->query('SELECT ID, PersonnelCode, Name, Family FROM Personnel WHERE Active=1 ORDER BY Name');
while ($r = $res->fetch_assoc()) $persons[] = $r;

// تعیین حالت فعلی صفحه: هوشمند هرمس (ai) یا دستی (manual)
$mode = (string)($_GET['mode'] ?? 'ai');
if (!in_array($mode, ['ai', 'manual'], true)) {
    $mode = 'ai';
}

$draft = $_SESSION['hermes_new_process_draft'] ?? null;
$hasDraft = is_array($draft) && !empty($draft['name']);
$rawInterview = $_SESSION['hermes_new_process_raw'] ?? '';
?>
<?php team_header($id, 'process_new', 'فرآیند جدید'); ?>

<div class="crumbs">
    <a href="processes.php">لیست فرآیندها</a> ← ثبت فرآیند جدید
</div>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<!-- تب‌های انتخاب حالت تدوین فرآیند -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;border-bottom:1px solid var(--border);padding-bottom:12px;flex-wrap:wrap">
    <a href="process_new.php?mode=ai" class="btn <?= $mode === 'ai' ? 'btn-brand' : 'btn-ghost' ?>" style="display:inline-flex;align-items:center;gap:8px">
        <?= app_icon('hermes', 'chat-avatar-img', 18) ?>
        <span style="font-weight:700">تدوین هوشمند با دستیار هرمس (AI Process Designer)</span>
        <span class="badge" style="background:#10b981;color:#fff;font-size:10.5px;padding:2px 7px;border-radius:999px">پیشنهادی</span>
    </a>
    <a href="process_new.php?mode=manual" class="btn <?= $mode === 'manual' ? 'btn-brand' : 'btn-ghost' ?>" style="display:inline-flex;align-items:center;gap:8px">
        <?= app_icon('plus_circle', '', 16) ?>
        <span>ثبت دستی سنتی (فرم استاندارد)</span>
    </a>
</div>

<?php if ($mode === 'ai'): ?>
<!-- ==============================================================
     حالت تدوین هوشمند با هرمس (HERMES AI PROCESS DESIGNER)
     ============================================================== -->

    <?php if (!$hasDraft): ?>
    <!-- ----------------------------------------------------------
         مرحله ۱: دریافت ورودی مصاحبه و کشف فرآیند
         ---------------------------------------------------------- -->
    <div class="card" style="max-width:960px;margin:0 auto">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:1px solid var(--line);padding-bottom:14px;margin-bottom:18px">
            <div style="display:flex;align-items:center;gap:12px">
                <div style="width:48px;height:48px;border-radius:12px;background:var(--brand-soft);display:flex;align-items:center;justify-content:center">
                    <?= app_icon('hermes', 'chat-avatar-img', 30) ?>
                </div>
                <div>
                    <h2 style="margin:0;font-size:18px;color:var(--text)">کشف و طراحی هوشمند فرآیند با هرمس</h2>
                    <p style="margin:3px 0 0;font-size:12.5px;color:var(--muted)">
                        متن مصاحبه یا فایل صوتی جلسه را وارد کنید؛ هرمس شناسنامه، ماتریس نقش‌ها، گام‌ها و سند رسمی PDF را تولید می‌کند.
                    </p>
                </div>
            </div>
            <div style="display:inline-flex;align-items:center;gap:6px;background:var(--brand-soft);color:var(--brand);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600">
                <?= app_icon('bot', '', 14) ?>
                <span>مرحله ۱ از ۲: دریافت مصاحبه و کشف نیازمندی‌ها</span>
            </div>
        </div>

        <!-- نوار ابزارهای ورود صوت و متن -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;background:var(--card-bg);border:1px dashed var(--border);border-radius:12px;padding:12px 16px">
            <span style="font-weight:600;font-size:13px;color:var(--text);margin-left:auto">روش‌های ورود مصاحبه فرآیندی:</span>
            
            <!-- دکمه ضبط زنده صدا با میکروفون -->
            <button type="button" id="micRecordBtn" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px;color:#dc2626;border-color:rgba(220,38,38,0.25)">
                <span id="micDot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#dc2626"></span>
                <?= app_icon('mic', '', 14) ?>
                <span id="micBtnText">ضبط زنده با میکروفون (گفتار به متن)</span>
            </button>

            <!-- دکمه بارگذاری فایل صوتی مصاحبه -->
            <label class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:var(--brand);margin:0">
                <?= app_icon('audio', '', 14) ?>
                <span>بارگذاری فایل صوتی جلسه (mp3, wav, m4a, ogg)</span>
                <input type="file" id="audioFileInput" accept="audio/*,.mp3,.wav,.m4a,.ogg,.webm,.aac,.flac,.amr" style="display:none">
            </label>

            <!-- دکمه بارگذاری فایل متنی و اسناد مصاحبه (.docx, .pdf, .txt, .md) -->
            <label class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;color:#0284c7;border-color:rgba(2,132,199,0.3);margin:0">
                <?= app_icon('files', '', 14) ?>
                <span>بارگذاری سند مصاحبه (.docx, .pdf, .txt, .md)</span>
                <input type="file" id="docFileInput" accept=".docx,.pdf,.txt,.md,.text" style="display:none">
            </label>
        </div>

        <?php
        $isLocalEngine = (!defined('TRANSCRIBE_ENGINE') || TRANSCRIBE_ENGINE !== 'google');
        ?>
        <div class="audio-engine-hint" style="font-size:12px;color:<?= $isLocalEngine ? '#166534' : '#b45309' ?>;background:<?= $isLocalEngine ? 'rgba(22,101,52,0.06)' : 'rgba(180,83,9,0.08)' ?>;padding:6px 12px;border-radius:8px;margin-bottom:12px;display:inline-flex;align-items:center;gap:6px">
            <?= app_icon($isLocalEngine ? 'check' : 'alert', '', 14) ?>
            <span><?= $isLocalEngine ? 'پردازش صوت روی سرور داخلی انجام می‌شود' : 'هشدار: صوت برای تبدیل به متن به سرویس ابری گوگل ارسال می‌شود' ?></span>
        </div>

        <!-- پیام وضعیت ضبط یا تبدیل صوت -->
        <div id="audioStatusBox" style="display:none;align-items:center;gap:10px;padding:10px 14px;border-radius:10px;margin-bottom:12px;font-size:13px"></div>

        <form id="hermesDesignForm" onsubmit="handleStartDesign(event)">
            <input type="hidden" name="csrf" id="designCsrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="design_process">

            <div class="form-row">
                <label class="field">
                    <span class="field-label">دسته پیشنهادی فرآیند (اختیاری — هرمس خود تشخیص می‌دهد)</span>
                    <select name="category_hint" id="categoryHint">
                        <option value="0">— تشخیص خودکار هوشمند توسط هرمس —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c['ID'] ?>"><?= e($c['Title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span class="field-label">شرکت مربوطه</span>
                    <select name="company_hint" id="companyHint">
                        <option value="0">— همه / شرکت عمومی —</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int)$c['ID'] ?>"><?= e($c['Name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label class="field" style="margin-bottom:16px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <span class="field-label" style="margin:0;font-weight:700">متن مصاحبه، یادداشت‌های جلسه یا شرح گردش‌کار فعلی:</span>
                    <button type="button" class="btn btn-ghost btn-xs" onclick="insertSampleInterview()" style="font-size:11px">درج نمونه مصاحبه آزمایشی</button>
                </div>
                <textarea id="interviewTextInput" name="interview_text" rows="11" required placeholder="متن مصاحبه با پرسنل، پیاده‌شده جلسه یا شرح اقداماتی که در عمل انجام می‌شود را اینجا بنویسید یا الصاق کنید…
مثال: در مصاحبه با واحد اداری و منابع انسانی مطرح شد که پرسنل برای دریافت وام سازمانی، ابتدا باید فرم درخواست وام را پر کنند و به تأیید مدیر مستقیم برسانند، سپس واحد مالی اعتبار را ظرف ۴۸ ساعت استعلام می‌کند و در صورت تایید، چک توسط امور مالی صادر و تحویل متقاضی می‌شود..." style="line-height:1.8;font-size:13.5px"><?= e($rawInterview) ?></textarea>
            </label>

            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;border-top:1px solid var(--line);padding-top:16px">
                <button type="submit" id="btnRunHermes" class="btn btn-brand" style="display:inline-flex;align-items:center;gap:10px;padding:12px 24px;font-size:14px">
                    <?= app_icon('bot', '', 18) ?>
                    <span>تحلیل مصاحبه و طراحی فرآیند توسط هرمس</span>
                </button>
                <a href="processes.php" class="btn btn-ghost">انصراف و بازگشت</a>
            </div>
        </form>

        <!-- وضعیت در حال پردازش هرمس -->
        <div id="hermesLoadingOverlay" style="display:none;background:var(--card-bg);border:2px dashed var(--brand);border-radius:14px;padding:36px 20px;text-align:center;margin-top:20px;animation:slideIn 0.3s ease">
            <div style="display:inline-block;margin-bottom:12px">
                <?= app_icon('hermes', 'ai-spin', 48) ?>
            </div>
            <h3 style="margin:0 0 6px 0;font-size:17px;color:var(--text)">هرمس در حال مهندسی و معماری فرآیند است…</h3>
            <div class="typing-dots" style="margin:8px 0"><span></span><span></span><span></span></div>
            <p style="color:var(--muted);font-size:13px;max-width:540px;margin:8px auto 0;line-height:1.7">
                تحلیل گام‌های اجرایی، انتساب به سمت‌های سازمانی، تعریف شاخص‌های سطح خدمت (SLA)، حذف گلوگاه‌ها و تدوین شناسنامه استاندارد ISO/BPMN در حال انجام است. لطفاً چند لحظه شکیبا باشید.
            </p>
        </div>
    </div>

    <?php else: ?>
    <!-- ----------------------------------------------------------
         مرحله ۲: بازبینی تعاملی، اصلاح با هرمس و انتشار نهایی (Human-in-the-Loop)
         ---------------------------------------------------------- -->
    <div style="max-width:1120px;margin:0 auto">
        <div style="margin-bottom:18px">
            <h2 style="margin:0;display:flex;align-items:center;gap:10px">
                <?= app_icon('hermes', 'chat-avatar-img', 24) ?>
                <span>پیش‌نویس فرآیند: <?= e($draft['name'] ?? 'فرآیند جدید') ?></span>
                <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:12px">نسخه <?= e($draft['version'] ?? '1.0') ?></span>
            </h2>
            <p style="margin:4px 0 10px 0;color:var(--muted);font-size:13px">
                مرحله ۲ از ۲: بازبینی انسانی و ویرایش تعاملی (شما می‌توانید گام‌ها را ویرایش کنید، با هرمس گفتگو نمایید یا فرآیند را مستقیماً منتشر کنید).
            </p>
            <div>
                <a href="process_new.php?reset_draft=1" class="btn btn-ghost btn-sm" onclick="return confirm('آیا مایلید این پیش‌نویس پاک شده و از اول مصاحبه را وارد کنید؟')" style="display:inline-flex;align-items:center;gap:6px">
                    <?= app_icon('refresh', '', 14) ?>
                    <span>شروع مجدد مصاحبه</span>
                </a>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
            
            <!-- ستون اصلی: شناسنامه و گام‌های قابل ویرایش مستقیم -->
            <form id="publishForm" method="post" action="process_new.php">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="publish_process">

                <!-- ۱. کارت مشخصات و شناسنامه فرآیند -->
                <div class="card" style="margin-bottom:16px">
                    <h3 style="margin:0 0 14px 0;font-size:15px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--line);padding-bottom:10px">
                        <?= app_icon('processes', '', 16) ?>
                        <span>۱. شناسنامه و مشخصات فرآیند</span>
                    </h3>

                    <label class="field">
                        <span class="field-label">نام رسمی فرآیند</span>
                        <input type="text" name="name" id="fieldName" required maxlength="150" value="<?= e($draft['name'] ?? '') ?>" style="font-weight:700;font-size:14.5px">
                    </label>

                    <div class="form-row">
                        <label class="field">
                            <span class="field-label">دسته‌بندی فرآیند</span>
                            <select name="category_id" id="fieldCategory" required>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['ID'] ?>" <?= (int)($draft['suggested_category_id'] ?? 0) === (int)$c['ID'] ? 'selected' : '' ?>><?= e($c['Title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span class="field-label">شرکت</span>
                            <select name="company_id" id="fieldCompany">
                                <option value="0">— همه / شرکت عمومی —</option>
                                <?php foreach ($companies as $c): ?>
                                    <option value="<?= (int)$c['ID'] ?>" <?= (int)($draft['suggested_company_id'] ?? 0) === (int)$c['ID'] ? 'selected' : '' ?>><?= e($c['Name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span class="field-label">سمت متولی از چارت سازمانی</span>
                            <select name="owner_position_id" id="fieldOwnerPos">
                                <option value="0">— بدون متولی چارت —</option>
                                <?php foreach ($positions as $p): ?>
                                    <option value="<?= (int)$p['ID'] ?>" <?= (int)($draft['owner_position_id'] ?? 0) === (int)$p['ID'] ? 'selected' : '' ?>>
                                        <?= e($p['Title']) ?><?= !empty($p['Department']) ? ' [' . e($p['Department']) . ']' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="form-row">
                        <label class="field">
                            <span class="field-label">دامنه مخاطبان اثرپذیر</span>
                            <select name="audience_type" id="fieldAudienceType" onchange="toggleAudienceDeptField(this.value)">
                                <option value="all_company" <?= ($draft['audience_type'] ?? '') === 'all_company' ? 'selected' : '' ?>>همه پرسنل شرکت (عمومی)</option>
                                <option value="specific_positions" <?= ($draft['audience_type'] ?? '') === 'specific_positions' ? 'selected' : '' ?>>سمت‌های تخصصی در چارت</option>
                                <option value="specific_personnel" <?= ($draft['audience_type'] ?? '') === 'specific_personnel' ? 'selected' : '' ?>>پرسنل مشخص اثرپذیر</option>
                                <option value="department" <?= ($draft['audience_type'] ?? '') === 'department' ? 'selected' : '' ?>>دپارتمان / واحد مشخص</option>
                            </select>
                        </label>
                        <label class="field" id="audienceDeptWrap" style="display:<?= ($draft['audience_type'] ?? '') === 'department' ? 'block' : 'none' ?>">
                            <span class="field-label">نام دپارتمان اثرپذیر</span>
                            <input type="text" name="audience_dept" id="fieldAudienceDept" value="<?= e($draft['audience_dept'] ?? '') ?>" placeholder="مثلاً بازرگانی، مالی یا منابع انسانی">
                        </label>
                        <label class="field">
                            <span class="field-label">نسخه سند</span>
                            <input type="text" name="version" id="fieldVersion" value="<?= e($draft['version'] ?? '1.0') ?>" style="width:100px">
                        </label>
                    </div>

                    <label class="field">
                        <span class="field-label">هدف و مأموریت فرآیند</span>
                        <textarea name="purpose" id="fieldPurpose" rows="2" style="font-size:13px"><?= e($draft['purpose'] ?? '') ?></textarea>
                    </label>

                    <label class="field">
                        <span class="field-label">دامنه کاربرد و پیش‌نیازها</span>
                        <textarea name="scope" id="fieldScope" rows="2" style="font-size:13px"><?= e($draft['scope'] ?? '') ?></textarea>
                    </label>

                    <label class="field">
                        <span class="field-label">شاخص‌های کلیدی عملکرد و موافقت‌نامه سطح خدمات (KPI / SLA)</span>
                        <textarea name="kpis_and_slas" id="fieldKpis" rows="2" style="font-size:13px"><?= e($draft['kpis_and_slas'] ?? '') ?></textarea>
                    </label>
                </div>

                <!-- ۲. کارت گام‌های اجرایی استاندارد (قابل ویرایش، افزودن و حذف) -->
                <div class="card" style="margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:12px">
                        <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:8px">
                            <?= app_icon('discussion', '', 16) ?>
                            <span>۲. گام‌های اجرایی و گردش‌کار استاندارد</span>
                        </h3>
                        <button type="button" class="btn btn-ghost btn-xs" onclick="addProcedureStepRow()" style="display:inline-flex;align-items:center;gap:4px">
                            <?= app_icon('plus_circle', '', 13) ?>
                            <span>افزودن گام جدید</span>
                        </button>
                    </div>

                    <div class="table-wrap">
                        <table class="data" id="stepsTable">
                            <thead>
                                <tr>
                                    <th style="width:5%;text-align:center">گام</th>
                                    <th style="width:24%">سمت / نقش مسئول</th>
                                    <th style="width:45%">شرح دقیق اقدام اجرایی</th>
                                    <th style="width:21%">نکات / مهلت / الزامات</th>
                                    <th style="width:5%"></th>
                                </tr>
                            </thead>
                            <tbody id="stepsTableBody">
                                <?php 
                                $steps = $draft['steps'] ?? [];
                                foreach ($steps as $idx => $st): 
                                    $sNum = $idx + 1;
                                ?>
                                <tr class="step-row" data-index="<?= $idx ?>">
                                    <td style="text-align:center;font-weight:700;color:var(--brand)" class="step-num"><?= $sNum ?></td>
                                    <td>
                                        <input type="text" name="steps[<?= $idx ?>][role]" value="<?= e($st['role'] ?? 'مسئول') ?>" required style="font-size:12.5px;padding:6px 8px;width:100%">
                                    </td>
                                    <td>
                                        <textarea name="steps[<?= $idx ?>][action]" rows="2" required style="font-size:12.5px;padding:6px 8px;width:100%;resize:vertical"><?= e($st['action'] ?? '') ?></textarea>
                                    </td>
                                    <td>
                                        <input type="text" name="steps[<?= $idx ?>][notes]" value="<?= e($st['notes'] ?? '—') ?>" style="font-size:12px;padding:6px 8px;width:100%">
                                    </td>
                                    <td style="text-align:center">
                                        <button type="button" class="btn btn-ghost btn-xs" onclick="removeStepRow(this)" title="حذف این گام" style="color:#ef4444;padding:4px 6px">×</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ۳. تحلیل و نکات معماری هرمس -->
                <div class="card" style="margin-bottom:16px">
                    <h3 style="margin:0 0 10px 0;font-size:14.5px;display:flex;align-items:center;gap:8px">
                        <?= app_icon('bot', '', 15) ?>
                        <span>۳. ملاحظات بهینه‌سازی و معماری هرمس</span>
                    </h3>
                    <textarea name="optimization_notes" id="fieldOptNotes" rows="2" style="font-size:12.5px"><?= e($draft['optimization_notes'] ?? '') ?></textarea>
                    <input type="hidden" name="description" id="fieldDesc" value="<?= e($draft['description'] ?? '') ?>">
                </div>

                <!-- دکمه‌های انتشار نهایی -->
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:20px">
                    <button type="submit" class="btn btn-brand" style="display:inline-flex;align-items:center;gap:10px;padding:12px 28px;font-size:15px;font-weight:700">
                        <?= app_icon('check', '', 18) ?>
                        <span>تأیید نهایی، ایجاد پرونده و انتشار رسمی فرآیند</span>
                    </button>
                    <a href="process_new.php?reset_draft=1" class="btn btn-ghost" onclick="return confirm('آیا مایلید انصراف دهید؟')">انصراف و شروع مجدد</a>
                </div>
            </form>

            <!-- ستون فرعی: گفتگو و اصلاح با هرمس + پیش‌نمایش سند PDF -->
            <div style="display:flex;flex-direction:column;gap:16px">
                
                <!-- کارت پیش‌نمایش سند رسمی PDF -->
                <div class="card" style="background:var(--brand-soft);border:1px solid var(--brand);text-align:center;padding:18px 16px">
                    <div style="width:44px;height:44px;border-radius:10px;background:#ef4444;color:#fff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:8px">
                        <?= app_icon('files', '', 24) ?>
                    </div>
                    <h4 style="margin:0 0 4px 0;font-size:14px;color:var(--text)">پیش‌نویس سند رسمی PDF فرآیند</h4>
                    <p style="margin:0 0 12px 0;font-size:11.5px;color:var(--muted)">تولیدشده با استانداردهای رسمی ایزو و فونت‌های فارسی</p>
                    <a href="process_new.php?preview_pdf=1" target="_blank" class="btn btn-sm" style="background:#ef4444;color:#fff;display:inline-flex;align-items:center;gap:6px;width:100%;justify-content:center">
                        <?= app_icon('files', '', 14) ?>
                        <span>مشاهده و دانلود پیش‌نمایش PDF ↗</span>
                    </a>
                </div>

                <!-- کارت گفتگو و اصلاحیه با هرمس (Chat & Refine) -->
                <div class="card" style="border:1px solid var(--border)">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                        <?= app_icon('hermes', 'chat-avatar-img', 22) ?>
                        <h4 style="margin:0;font-size:14px">اصلاح تعاملی با هرمس</h4>
                    </div>
                    <p style="margin:0 0 10px 0;font-size:12px;color:var(--muted);line-height:1.6">
                        اگر مایلید تغییری در مراحل، نقش‌ها یا مهلت‌ها اعمال شود، دستور خود را بنویسید تا هرمس سند را مجدداً بازنویسی کند:
                    </p>

                    <form id="refineForm" onsubmit="handleRefineProcess(event)">
                        <textarea id="refineFeedbackInput" rows="3" required placeholder="مثلاً: در گام ۲ تأییدیه مدیر مالی را هم اضافه کن و مهلت اقدام را ۲۴ ساعت قرار بده…" style="font-size:12.5px;margin-bottom:10px;line-height:1.6"></textarea>
                        
                        <button type="submit" id="btnRefineSubmit" class="btn btn-brand btn-sm" style="display:inline-flex;align-items:center;gap:6px;width:100%;justify-content:center">
                            <?= app_icon('bot', '', 14) ?>
                            <span>اعمال بازنگری توسط هرمس</span>
                        </button>
                    </form>

                    <div id="refineLoadingBox" style="display:none;padding:12px;text-align:center;margin-top:10px;background:var(--brand-soft);border-radius:8px">
                        <div class="typing-dots" style="margin-bottom:6px"><span></span><span></span><span></span></div>
                        <small style="color:var(--brand);font-weight:600">هرمس در حال اعمال تغییرات بر روی فرآیند است…</small>
                    </div>
                </div>

            </div>

        </div>
    </div>
    <?php endif; ?>

<!-- ==============================================================
     اسکریپت‌های تعاملی دستیار هوشمند هرمس (صوت، میکروفون، AJAX)
     ============================================================== -->
<script>
function toggleAudienceDeptField(val) {
    const wrap = document.getElementById('audienceDeptWrap');
    if (wrap) {
        wrap.style.display = (val === 'department') ? 'block' : 'none';
    }
}

// درج نمونه مصاحبه تستی
function insertSampleInterview() {
    const txt = `در مصاحبه با کارشناس تدارکات و مسئول فناوری اطلاعات مشخص شد:
وقتی کارمند جدیدی استخدام می‌شود، نیاز به لپ‌تاپ و تجهیزات جانبی دارد. در حال حاضر کارمند به صورت حضوری به واحد IT مراجعه می‌کند که باعث بی‌نظمی است.
روال صحیح این است:
۱. کارشناس منابع انسانی در روز اول شروع به کار کارمند، فرم الکترونیکی درخواست تجهیزات را در سامانه ثبت می‌کند و مشخصات سیستم مورد نیاز را انتخاب می‌کند.
۲. مدیر واحد مربوطه ظرف ۲۴ ساعت درخواست را بررسی و تایید می‌نماید.
۳. واحد پشتیبانی IT پس از تایید مدیر، تجهیزات را از انبار تحویل گرفته و ظرف ۴۸ ساعت سیستم‌عامل و نرم‌افزارهای سازمانی را نصب می‌کند.
۴. در نهایت کارمند تحویل‌گیرنده پس از دریافت فیزیکی لپ‌تاپ، فرم صورت‌جلسه تحویل را امضا کرده و مسئول IT پرونده را در سامانه می‌بندد.
مهلت کل این فرآیند نباید بیش از ۳ روز کاری باشد.`;
    document.getElementById('interviewTextInput').value = txt;
}

// ۱. مدیریت ضبط زنده صدا با میکروفون (Web Speech API)
(function() {
    const micBtn = document.getElementById('micRecordBtn');
    const micDot = document.getElementById('micDot');
    const micBtnText = document.getElementById('micBtnText');
    const statusBox = document.getElementById('audioStatusBox');
    const textarea = document.getElementById('interviewTextInput');

    if (!micBtn) return;

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
        micBtn.title = 'مرورگر شما از تبدیل مستقیم گفتار به متن پشتیبانی نمی‌کند؛ از بارگذاری فایل صوتی استفاده کنید.';
        micBtn.onclick = () => alert('مرورگر شما از وب‌اسپیچ پشتیبانی نمی‌کند. لطفاً فایل صوتی مصاحبه را از طریق دکمه بارگذاری صوتی وارد نمایید.');
        return;
    }

    let recognition = new SpeechRecognition();
    recognition.lang = 'fa-IR';
    recognition.continuous = true;
    recognition.interimResults = true;
    let isRecording = false;

    micBtn.addEventListener('click', function() {
        if (!isRecording) {
            try {
                recognition.start();
                isRecording = true;
                micBtn.classList.add('mic-recording');
                micDot.style.background = '#22c55e';
                micDot.style.boxShadow = '0 0 10px #22c55e';
                micBtnText.textContent = 'توقف ضبط صدا (در حال گوش دادن به صحبت‌های شما…)';
                showAudioStatus('🎙️ میکروفون فعال است. لطفاً صحبت کنید، کلمات شما به صورت زنده تایپ می‌شوند…', 'info');
            } catch (err) {
                console.error(err);
            }
        } else {
            recognition.stop();
            isRecording = false;
            micBtn.classList.remove('mic-recording');
            micDot.style.background = '#dc2626';
            micDot.style.boxShadow = 'none';
            micBtnText.textContent = 'ضبط زنده با میکروفون (گفتار به متن)';
            showAudioStatus('✅ ضبط با موفقیت پایان یافت.', 'ok');
        }
    });

    recognition.onresult = function(e) {
        let finalTrans = '';
        for (let i = e.resultIndex; i < e.results.length; ++i) {
            if (e.results[i].isFinal) {
                finalTrans += e.results[i][0].transcript + ' ';
            }
        }
        if (finalTrans && textarea) {
            textarea.value = (textarea.value.trim() ? textarea.value.trim() + ' ' : '') + finalTrans.trim();
        }
    };

    recognition.onerror = function(e) {
        console.warn('Speech recognition error:', e.error);
        if (e.error !== 'no-speech') {
            showAudioStatus('خطا در دسترسی به میکروفون: ' + e.error, 'error');
        }
    };

    recognition.onend = function() {
        if (isRecording) {
            // در صورتی که مکث طولانی رخ داد، مجدداً متصل بماند
            try { recognition.start(); } catch(e) {}
        }
    };
})();

// ۲. مدیریت بارگذاری فایل صوتی و تبدیل با پایتون / سرور
(function() {
    const audioInput = document.getElementById('audioFileInput');
    const textarea = document.getElementById('interviewTextInput');
    const csrf = document.getElementById('designCsrf')?.value;

    if (!audioInput) return;

    audioInput.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];

        const formData = new FormData();
        formData.append('action', 'transcribe_audio');
        formData.append('csrf', csrf);
        formData.append('audio_file', file);

        showAudioStatus('🎵 در حال بارگذاری و تبدیل فایل صوتی «' + file.name + '» به متن با موتور هوش مصنوعی… لطفاً شکیبا باشید.', 'loading');

        fetch('process_new.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.queued && data.task_id) {
                showAudioStatus('🎵 فایل صوتی با موفقیت ارسال شد. در حال تبدیل در پس‌زمینه… لطفاً شکیبا باشید.', 'loading');
                const taskId = data.task_id;
                let attempts = 0;
                const pollInterval = setInterval(() => {
                    attempts++;
                    if (attempts > 120) { // 4 minutes
                        clearInterval(pollInterval);
                        showAudioStatus('زمان تبدیل صوت طولانی شد؛ کار در پس‌زمینه ادامه دارد.', 'error');
                        return;
                    }
                    fetch('process_new.php?action=check_transcription&task_id=' + encodeURIComponent(taskId))
                        .then(r => r.json())
                        .then(res => {
                            if (res.status === 'completed') {
                                clearInterval(pollInterval);
                                if (res.text) {
                                    textarea.value = (textarea.value.trim() ? textarea.value.trim() + "\n\n" : '') + res.text;
                                    showAudioStatus('✅ فایل صوتی با موفقیت پیاده‌سازی و به متن اضافه شد.', 'ok');
                                } else {
                                    showAudioStatus(res.message || 'هیچ گفتار واضحی در فایل صوتی شناسایی نشد.', 'ok');
                                }
                            } else if (res.status === 'failed') {
                                clearInterval(pollInterval);
                                showAudioStatus('خطا در تبدیل صوت: ' + (res.error || 'پردازش با خطا مواجه شد.'), 'error');
                            }
                        })
                        .catch(() => {});
                }, 2000);
            } else if (data.success && data.text) {
                textarea.value = (textarea.value.trim() ? textarea.value.trim() + "\n\n" : '') + data.text;
                showAudioStatus('✅ فایل صوتی با موفقیت پیاده‌سازی و به متن اضافه شد.', 'ok');
            } else {
                showAudioStatus('خطا در تبدیل صوت: ' + (data.error || data.message || 'هیچ گفتار واضحی شناسایی نشد.'), 'error');
            }
        })
        .catch(err => {
            showAudioStatus('خطا در ارتباط با سرور: ' + err.message, 'error');
        })
        .finally(() => {
            audioInput.value = '';
        });
    });
})();

// ۳. بارگذاری اسناد مصاحبه و نیازمندی‌ها (.docx, .pdf, .txt, .md)
(function() {
    const docInput = document.getElementById('docFileInput');
    const textarea = document.getElementById('interviewTextInput');
    const csrf = document.getElementById('designCsrf')?.value;
    if (!docInput || !textarea) return;

    docInput.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const file = this.files[0];
        const ext = file.name.split('.').pop().toLowerCase();

        // برای فایلهای سبک متنی txt و md، ابتدا به صورت مستقیم در مرورگر می‌خوانیم
        if (['txt', 'md', 'text'].includes(ext)) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const text = (e.target.result || '').trim();
                if (text) {
                    textarea.value = (textarea.value.trim() ? textarea.value.trim() + "\n\n" : '') + text;
                    showAudioStatus('✅ فایل متنی «' + file.name + '» با موفقیت اضافه شد.', 'ok');
                } else {
                    showAudioStatus('فایل متنی انتخابی خالی است.', 'error');
                }
            };
            reader.onerror = function() {
                showAudioStatus('خطا در خواندن فایل متنی محلی.', 'error');
            };
            reader.readAsText(file, 'utf-8');
            docInput.value = '';
            return;
        }

        // برای فایلهای docx و pdf از ماژول پردازش اسناد سرور استفاده می‌کنیم
        const formData = new FormData();
        formData.append('action', 'extract_document');
        formData.append('csrf', csrf);
        formData.append('document_file', file);

        showAudioStatus('📄 در حال استخراج متن از سند «' + file.name + '» (' + ext.toUpperCase() + ') توسط سامانه… لطفاً چند لحظه شکیبا باشید.', 'loading');

        fetch('process_new.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.text) {
                textarea.value = (textarea.value.trim() ? textarea.value.trim() + "\n\n" : '') + data.text;
                const charCount = data.length || data.text.length;
                showAudioStatus('✅ متن مصاحبه از سند «' + file.name + '» (' + charCount.toLocaleString('fa-IR') + ' کاراکتر) با موفقیت استخراج شد.', 'ok');
            } else {
                showAudioStatus('خطا در استخراج سند: ' + (data.error || 'متنی از این فایل استخراج نشد.'), 'error');
            }
        })
        .catch(err => {
            showAudioStatus('خطا در ارتباط با سرور: ' + err.message, 'error');
        })
        .finally(() => {
            docInput.value = '';
        });
    });
})();

function showAudioStatus(msg, type) {
    const box = document.getElementById('audioStatusBox');
    if (!box) return;
    box.style.display = 'flex';
    if (type === 'error') {
        box.style.background = '#fee2e2';
        box.style.color = '#991b1b';
    } else if (type === 'ok') {
        box.style.background = '#dcfce7';
        box.style.color = '#166534';
    } else if (type === 'loading') {
        box.style.background = '#e0f2fe';
        box.style.color = '#0369a1';
    } else {
        box.style.background = '#f1f5f9';
        box.style.color = '#334155';
    }
    box.textContent = msg;
}

// ۴. ارسال متن مصاحبه به هرمس جهت طراحی فرآیند
function handleStartDesign(e) {
    e.preventDefault();
    const btn = document.getElementById('btnRunHermes');
    const overlay = document.getElementById('hermesLoadingOverlay');
    const form = document.getElementById('hermesDesignForm');
    const textarea = document.getElementById('interviewTextInput');

    if (!textarea.value.trim()) {
        alert('لطفاً متن مصاحبه یا شرح فرآیند را وارد کنید.');
        return;
    }

    btn.disabled = true;
    overlay.style.display = 'block';

    const formData = new FormData(form);

    fetch('process_new.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            btn.disabled = false;
            overlay.style.display = 'none';
            alert('خطا در تحلیل هرمس: ' + (data.error || 'خطای نامشخص'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        overlay.style.display = 'none';
        alert('خطا در ارتباط با سرور: ' + err.message);
    });
}

// ۵. مدیریت افزودن و حذف گام‌های اجرایی در جدول
function addProcedureStepRow() {
    const tbody = document.getElementById('stepsTableBody');
    if (!tbody) return;
    const currentRows = tbody.querySelectorAll('tr.step-row');
    const nextIdx = currentRows.length;
    const nextStepNum = nextIdx + 1;

    const tr = document.createElement('tr');
    tr.className = 'step-row';
    tr.setAttribute('data-index', nextIdx);
    tr.innerHTML = `
        <td style="text-align:center;font-weight:700;color:var(--brand)" class="step-num">${nextStepNum}</td>
        <td>
            <input type="text" name="steps[${nextIdx}][role]" value="مسئول اجرایی" required style="font-size:12.5px;padding:6px 8px;width:100%">
        </td>
        <td>
            <textarea name="steps[${nextIdx}][action]" rows="2" required placeholder="شرح اقدام..." style="font-size:12.5px;padding:6px 8px;width:100%;resize:vertical"></textarea>
        </td>
        <td>
            <input type="text" name="steps[${nextIdx}][notes]" value="—" style="font-size:12px;padding:6px 8px;width:100%">
        </td>
        <td style="text-align:center">
            <button type="button" class="btn btn-ghost btn-xs" onclick="removeStepRow(this)" title="حذف این گام" style="color:#ef4444;padding:4px 6px">×</button>
        </td>
    `;
    tbody.appendChild(tr);
    reindexStepNumbers();
}

function removeStepRow(btn) {
    const row = btn.closest('tr');
    if (row) {
        row.remove();
        reindexStepNumbers();
    }
}

function reindexStepNumbers() {
    const rows = document.querySelectorAll('#stepsTableBody tr.step-row');
    rows.forEach((r, idx) => {
        const numCell = r.querySelector('.step-num');
        if (numCell) numCell.textContent = idx + 1;
        const inputs = r.querySelectorAll('input, textarea');
        inputs.forEach(inp => {
            const name = inp.getAttribute('name');
            if (name) {
                inp.setAttribute('name', name.replace(/steps\[\d+\]/, 'steps[' + idx + ']'));
            }
        });
    });
}

// ۶. بازنگری تعاملی با هرمس (Chat & Refine)
function handleRefineProcess(e) {
    e.preventDefault();
    const feedbackInput = document.getElementById('refineFeedbackInput');
    const btn = document.getElementById('btnRefineSubmit');
    const loadingBox = document.getElementById('refineLoadingBox');
    const csrf = document.getElementById('designCsrf')?.value || document.querySelector('input[name="csrf"]')?.value;

    const feedback = feedbackInput.value.trim();
    if (!feedback) return;

    btn.disabled = true;
    loadingBox.style.display = 'block';

    // جمع‌آوری وضعیت فعلی فرم
    const currentDesign = {
        name: document.getElementById('fieldName')?.value,
        suggested_category_id: document.getElementById('fieldCategory')?.value,
        owner_position_id: document.getElementById('fieldOwnerPos')?.value,
        audience_type: document.getElementById('fieldAudienceType')?.value,
        audience_dept: document.getElementById('fieldAudienceDept')?.value,
        version: document.getElementById('fieldVersion')?.value,
        purpose: document.getElementById('fieldPurpose')?.value,
        scope: document.getElementById('fieldScope')?.value,
        kpis_and_slas: document.getElementById('fieldKpis')?.value,
        optimization_notes: document.getElementById('fieldOptNotes')?.value,
        steps: []
    };

    const stepRows = document.querySelectorAll('#stepsTableBody tr.step-row');
    stepRows.forEach((r, idx) => {
        const role = r.querySelector('input[name*="[role]"]')?.value;
        const action = r.querySelector('textarea[name*="[action]"]')?.value;
        const notes = r.querySelector('input[name*="[notes]"]')?.value;
        currentDesign.steps.push({
            step: idx + 1,
            role: role || 'مجری',
            action: action || '',
            notes: notes || '—'
        });
    });

    const formData = new FormData();
    formData.append('action', 'refine_process');
    formData.append('csrf', csrf);
    formData.append('user_feedback', feedback);
    formData.append('current_design_json', JSON.stringify(currentDesign));

    fetch('process_new.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        loadingBox.style.display = 'none';
        if (data.success && data.design) {
            // اعمال مقادیر اصلاح‌شده در صفحه
            const d = data.design;
            if (d.name && document.getElementById('fieldName')) document.getElementById('fieldName').value = d.name;
            if (d.purpose && document.getElementById('fieldPurpose')) document.getElementById('fieldPurpose').value = d.purpose;
            if (d.scope && document.getElementById('fieldScope')) document.getElementById('fieldScope').value = d.scope;
            if (d.kpis_and_slas && document.getElementById('fieldKpis')) document.getElementById('fieldKpis').value = d.kpis_and_slas;
            if (d.optimization_notes && document.getElementById('fieldOptNotes')) document.getElementById('fieldOptNotes').value = d.optimization_notes;

            // به‌روزرسانی جدول گام‌ها
            if (d.steps && Array.isArray(d.steps)) {
                const tbody = document.getElementById('stepsTableBody');
                tbody.innerHTML = '';
                d.steps.forEach((st, idx) => {
                    const tr = document.createElement('tr');
                    tr.className = 'step-row';
                    tr.setAttribute('data-index', idx);
                    tr.innerHTML = `
                        <td style="text-align:center;font-weight:700;color:var(--brand)" class="step-num">${idx + 1}</td>
                        <td><input type="text" name="steps[${idx}][role]" value="${escapeHtml(st.role || 'مسئول')}" required style="font-size:12.5px;padding:6px 8px;width:100%"></td>
                        <td><textarea name="steps[${idx}][action]" rows="2" required style="font-size:12.5px;padding:6px 8px;width:100%;resize:vertical">${escapeHtml(st.action || '')}</textarea></td>
                        <td><input type="text" name="steps[${idx}][notes]" value="${escapeHtml(st.notes || '—')}" style="font-size:12px;padding:6px 8px;width:100%"></td>
                        <td style="text-align:center"><button type="button" class="btn btn-ghost btn-xs" onclick="removeStepRow(this)" style="color:#ef4444;padding:4px 6px">×</button></td>
                    `;
                    tbody.appendChild(tr);
                });
            }
            feedbackInput.value = '';
            alert('✅ بازنگری هرمس با موفقیت اعمال و مقادیر فرم به‌روزرسانی شدند.');
        } else {
            alert('خطا در بازنگری هرمس: ' + (data.error || 'پاسخ نامعتبر'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        loadingBox.style.display = 'none';
        alert('خطا در ارتباط با سرور: ' + err.message);
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php else: ?>
<!-- ==============================================================
     حالت ثبت دستی سنتی (CLASSIC MANUAL FORM)
     ============================================================== -->
<div class="card" style="max-width:760px">
    <h2>ثبت دستی فرآیند جدید</h2>
    <p style="color:var(--muted);font-size:13px;margin-top:0">با ثبت این فرم، یک پوشه به نام فرآیند در دایرکتوری <span class="num-ltr">processes/</span> زیرمجموعه دسته انتخابی ساخته می‌شود.</p>

    <form method="post" action="process_new.php?mode=manual">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="manual_save">

        <label class="field">
            <span class="field-label">نام فرآیند</span>
            <input type="text" name="name" required maxlength="150" value="<?= e($old['name']) ?>" placeholder="مثلاً درخواست مرخصی استعلاجی">
        </label>
        <div class="form-row">
            <label class="field">
                <span class="field-label">دسته فرآیند</span>
                <select name="category_id" required>
                    <option value="0">— انتخاب دسته —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['ID'] ?>" <?= $old['category_id'] === (int)$c['ID'] ? 'selected' : '' ?>><?= e($c['Title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">شرکت</span>
                <select name="company_id">
                    <option value="0">— همه/نامشخص —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['ID'] ?>" <?= $old['company_id'] === (int)$c['ID'] ? 'selected' : '' ?>><?= e($c['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">متولی فرآیند از چارت سازمانی</span>
                <select name="owner_position_id">
                    <option value="0">— بدون متولی چارت —</option>
                    <?php foreach ($positions as $p): ?>
                        <?php 
                            $occupant = (!empty($p['Name'])) ? ' — شاغل: ' . e($p['Name'] . ' ' . $p['Family']) : ' (بلاتصدی)';
                        ?>
                        <option value="<?= (int)$p['ID'] ?>" <?= $old['owner_position_id'] === (int)$p['ID'] ? 'selected' : '' ?>>
                            <?= e($p['Title']) ?><?= !empty($p['Department']) ? ' [' . e($p['Department']) . ']' : '' ?><?= $occupant ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted);font-size:11px;margin-top:2px;display:block">هنگام تغییر یا به‌روزرسانی این فرآیند، پیام خودکار به شاغل فعلی این سمت در بله ارسال می‌شود.</small>
            </label>
        </div>
        <div class="form-row">
            <label class="field">
                <span class="field-label">دامنه مخاطبان اثرپذیر (ارسال اعلان در پیام‌رسان بله)</span>
                <select name="audience_type" id="audienceTypeSelect" onchange="toggleManualAudienceDept(this.value)">
                    <option value="all_company" <?= $old['audience_type'] === 'all_company' ? 'selected' : '' ?>>همه پرسنل شرکت (عمومی — مانند مرخصی، مأموریت و ...)</option>
                    <option value="specific_positions" <?= $old['audience_type'] === 'specific_positions' ? 'selected' : '' ?>>سمت‌های اثرپذیر از چارت (تخصصی)</option>
                    <option value="specific_personnel" <?= $old['audience_type'] === 'specific_personnel' ? 'selected' : '' ?>>افراد و پرسنل مشخص اثرپذیر</option>
                    <option value="department" <?= $old['audience_type'] === 'department' ? 'selected' : '' ?>>واحد / دپارتمان سازمانی مشخص</option>
                </select>
            </label>
            <label class="field" id="manualAudienceDeptWrap" style="display:<?= $old['audience_type'] === 'department' ? 'block' : 'none' ?>">
                <span class="field-label">نام دپارتمان اثرپذیر</span>
                <input type="text" name="audience_dept" value="<?= e($old['audience_dept']) ?>" placeholder="مثلاً بازرگانی یا فناوری اطلاعات">
            </label>
        </div>
        <script>
        function toggleManualAudienceDept(val) {
            document.getElementById('manualAudienceDeptWrap').style.display = (val === 'department') ? 'block' : 'none';
        }
        </script>
        <div class="form-row">
            <label class="field">
                <span class="field-label">نسخه اولیه</span>
                <input type="text" name="version" maxlength="20" value="<?= e($old['version']) ?>">
            </label>
            <label class="field">
                <span class="field-label">وضعیت</span>
                <select name="status">
                    <option value="draft" <?= ($old['status'] ?? '') === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                    <option value="active" <?= ($old['status'] ?? '') === 'active' ? 'selected' : '' ?>>فعال</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">مالک فرآیند (پرسنل مستقیم - اختیاری)</span>
                <select name="owner_id">
                    <option value="0">— انتخاب —</option>
                    <?php foreach ($persons as $p): ?>
                        <option value="<?= (int)$p['ID'] ?>" <?= $old['owner_id'] === (int)$p['ID'] ? 'selected' : '' ?>><?= e($p['Name'] . ' ' . $p['Family']) ?> (<?= e($p['PersonnelCode']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="field">
            <span class="field-label">شرح فرآیند</span>
            <textarea name="description" maxlength="2000" placeholder="خلاصه مراحل و دامنه کاربرد…"><?= e($old['description']) ?></textarea>
        </label>
        <button class="btn">ثبت فرآیند</button>
        <a class="btn btn-ghost" href="processes.php">انصراف</a>
    </form>
</div>
<?php endif; ?>

<?php app_footer(); ?>
