<?php
/**
 * مشاهده پرونده فرآیند — پنل تیم
 * تب‌ها: مشاهده فرآیند | مستندات | متولی و مخاطبان بله | لیست بهبودها | لیست مجریان | لیست تغییرات
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/files.php';
require_once __DIR__ . '/../inc/hermes.php';
require_once __DIR__ . '/../inc/bale.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$procId = (int)($_GET['id'] ?? 0);
$st = $db->prepare(
    "SELECT p.*, cat.Title AS CatTitle, cat.FolderName, c.Name AS CompanyName, t.TeamName AS RespTeam,
            op.Title AS OwnerPositionTitle, op.Department AS OwnerDepartment,
            per.Name AS OwnerPersName, per.Family AS OwnerPersFamily, per.PersonnelCode AS OwnerPersCode,
            per.BaleChatID AS OwnerBale, per.Phone AS OwnerPhone
     FROM Processes p
     JOIN ProcessCategories cat ON cat.ID = p.CategoryID
     LEFT JOIN Companies c ON c.ID = p.CompanyID
     LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID
     LEFT JOIN OrgPositions op ON op.ID = p.OwnerPositionID
     LEFT JOIN Personnel per ON per.ID = op.CurrentPersonnelID
     WHERE p.ID = ?"
);
$st->bind_param('i', $procId);
$st->execute();
$proc = $st->get_result()->fetch_assoc();
$st->close();

if (!$proc) {
    team_header($id, 'processes', 'فرآیند یافت نشد');
    echo '<div class="card"><div class="empty"><span class="ico">🔍</span><p>پرونده‌ای با این شناسه وجود ندارد.</p><p><a href="processes.php">بازگشت به لیست فرآیندها</a></p></div></div>';
    app_footer();
    exit;
}

$isOwnerTeam = (int)$proc['ResponsibleTeamID'] === $tid;
$relDir = fm_normalize_rel((string)$proc['FilePath']);
if ($relDir === '') $relDir = fm_normalize_rel($proc['FolderName'] . '/' . $proc['Name']);
$absDir = PROCESSES_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir) . DIRECTORY_SEPARATOR;

$notice = '';
$error = '';

$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'ok') {
        $notice = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

/* ---------- پردازش اکشن‌ها ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tabBack = (string)($_POST['tab'] ?? 'docs');
    $redirectUrl = 'file.php?id=' . $procId . '&tab=' . urlencode($tabBack);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'اعتبار صفحه منقضی شده است.');
    } elseif (!$isOwnerTeam) {
        flash_set('error', 'فقط تیم مسئول این فرآیند می‌تواند پرونده را ویرایش کند.');
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'upload' && isset($_FILES['doc'])) {
            $upErr = null;
            $saved = fm_store_upload($_FILES['doc'], rtrim($absDir, DIRECTORY_SEPARATOR), $upErr);
            if ($saved !== null) {
                $okMsg = 'مستند «' . $saved . '» با موفقیت بارگذاری شد.';
                if (!empty($_POST['notify_bale'])) {
                    $baleRes = bale_notify_process_change($db, $procId, 'بارگذاری مستند جدید: ' . $saved, 'مستند «' . $saved . '» در پرونده این فرآیند بارگذاری شد.', null, $tid);
                    if (!empty($baleRes['sent']) && $baleRes['sent'] > 0) {
                        $okMsg .= ' و پیام اطلاع‌رسانی به ' . farsi_num($baleRes['sent']) . ' مخاطب در بله ارسال شد.';
                    }
                }
                flash_set('ok', $okMsg);
            } else {
                flash_set('error', $upErr ?: 'خطا در ذخیره فایل.');
            }
        } elseif ($action === 'delete_doc') {
            $f = fm_clean_name(basename((string)($_POST['file'] ?? '')));
            $fp = $f ? $absDir . $f : '';
            if ($f && is_file($fp) && !str_starts_with($f, '.')) {
                unlink($fp);
                flash_set('ok', 'فایل «' . $f . '» حذف شد.');
            } else {
                flash_set('error', 'فایل معتبر نیست.');
            }
        } elseif ($action === 'broadcast_bale') {
            $baleTitle = trim((string)($_POST['bale_title'] ?? 'اطلاع‌رسانی فرآیند'));
            $baleDesc  = trim((string)($_POST['bale_description'] ?? ''));
            if ($baleTitle === '') $baleTitle = 'اطلاع‌رسانی فرآیند «' . $proc['Name'] . '»';
            $baleRes = bale_notify_process_change($db, $procId, $baleTitle, $baleDesc, null, $tid);
            if (!empty($baleRes['sent']) && $baleRes['sent'] > 0) {
                flash_set('ok', 'پیام با موفقیت به ' . farsi_num($baleRes['sent']) . ' مخاطب در بله ارسال گردید.');
            } else {
                flash_set('error', 'پیامی ارسال نشد (بررسی کنید مخاطبان دارای شناسه بله باشند یا وب‌سرویس فعال باشد).');
            }
            $redirectUrl = 'file.php?id=' . $procId . '&tab=audience';
        } elseif ($action === 'update_org_settings') {
            $ownerPosId = (int)($_POST['owner_position_id'] ?? 0) ?: null;
            $audType = (string)($_POST['audience_type'] ?? 'all_company');
            if (!in_array($audType, ['all_company', 'specific_positions', 'specific_personnel', 'department'], true)) $audType = 'all_company';
            $audDept = ($audType === 'department') ? trim((string)($_POST['audience_department'] ?? '')) : null;

            $st = $db->prepare("UPDATE Processes SET OwnerPositionID = ?, AudienceType = ?, AudienceDepartment = ?, UpdatedAt = NOW() WHERE ID = ?");
            $st->bind_param('issi', $ownerPosId, $audType, $audDept, $procId);
            $st->execute(); $st->close();
            flash_set('ok', 'تنظیمات متولی چارت و دامنه مخاطبان اثرپذیر فرآیند با موفقیت ذخیره شد.');
            $redirectUrl = 'file.php?id=' . $procId . '&tab=audience';
        } elseif ($action === 'add_audience_target') {
            $targetType = (string)($_POST['target_type'] ?? 'position');
            $targetId = (int)($_POST['target_id'] ?? 0);
            $targetName = trim((string)($_POST['target_name'] ?? ''));
            if ($targetId > 0) {
                $stCh = $db->prepare("SELECT ID FROM ProcessAudience WHERE ProcessID = ? AND TargetType = ? AND TargetID = ?");
                $stCh->bind_param('isi', $procId, $targetType, $targetId);
                $stCh->execute();
                $exists = (bool)$stCh->get_result()->fetch_assoc();
                $stCh->close();
                if (!$exists) {
                    $stIn = $db->prepare("INSERT INTO ProcessAudience (ProcessID, TargetType, TargetID, TargetName) VALUES (?, ?, ?, ?)");
                    $stIn->bind_param('isis', $procId, $targetType, $targetId, $targetName);
                    $stIn->execute();
                    $stIn->close();
                    flash_set('ok', 'مخاطب جدید به فهرست اثرپذیران فرآیند اضافه شد.');
                }
            }
            $redirectUrl = 'file.php?id=' . $procId . '&tab=audience';
        } elseif ($action === 'remove_audience_target') {
            $audId = (int)($_POST['audience_id'] ?? 0);
            $st = $db->prepare("DELETE FROM ProcessAudience WHERE ID = ? AND ProcessID = ?");
            $st->bind_param('ii', $audId, $procId);
            $st->execute(); $st->close();
            flash_set('ok', 'مخاطب از لیست اثرپذیران حذف شد.');
            $redirectUrl = 'file.php?id=' . $procId . '&tab=audience';
        } elseif ($action === 'add_executor') {
            $persId = (int)($_POST['personnel_id'] ?? 0);
            $role = trim((string)($_POST['role'] ?? 'مجری')) ?: 'مجری';
            $exTeam = (int)($_POST['team_id'] ?? 0) ?: null;
            if ($persId <= 0 && !$exTeam) {
                flash_set('error', 'نفر یا تیم مجری را انتخاب کنید.');
            } else {
                $persIdVal = ($persId > 0) ? $persId : null;
                $st = $db->prepare('INSERT INTO ProcessExecutors (ProcessID, PersonnelID, TeamID, Role) VALUES (?,?,?,?)');
                $st->bind_param('iiis', $procId, $persIdVal, $exTeam, $role);
                $st->execute(); $st->close();
                flash_set('ok', 'مجری افزوده شد.');
            }
        } elseif ($action === 'remove_executor') {
            $exId = (int)($_POST['executor_id'] ?? 0);
            $st = $db->prepare('DELETE FROM ProcessExecutors WHERE ID = ? AND ProcessID = ?');
            $st->bind_param('ii', $exId, $procId);
            $st->execute(); $st->close();
            flash_set('ok', 'مجری حذف شد.');
        } elseif ($action === 'add_change') {
            $chType = (string)($_POST['change_type'] ?? 'revision');
            if (!in_array($chType, ['revision','replacement','addition','removal'], true)) $chType = 'revision';
            $chDesc = trim((string)($_POST['description'] ?? ''));
            $nv = trim((string)($_POST['new_version'] ?? ''));
            $chStatus = (string)($_POST['status'] ?? 'pending');
            if (!in_array($chStatus, ['pending','approved','applied','rejected'], true)) $chStatus = 'pending';
            $impId = (int)($_POST['improvement_id'] ?? 0) ?: null;
            if ($chDesc === '') {
                flash_set('error', 'شرح تغییر را وارد کنید.');
            } else {
                $ov = $proc['Version'];
                $apType = 'team';
                $st = $db->prepare(
                    "INSERT INTO Changes (ProcessID, ImprovementID, ChangeType, Description, OldVersion, NewVersion, AppliedByType, AppliedByID, Status, EffectiveDate)
                     VALUES (?,?,?,?,?,?,?,?,?,CURDATE())"
                );
                $st->bind_param('iisssssis', $procId, $impId, $chType, $chDesc, $ov, $nv, $apType, $tid, $chStatus);
                $st->execute(); $st->close();
                if ($chStatus === 'applied' && $nv !== '') {
                    $st = $db->prepare('UPDATE Processes SET Version = ?, UpdatedAt = NOW() WHERE ID = ?');
                    $st->bind_param('si', $nv, $procId);
                    $st->execute(); $st->close();
                    $msg = 'تغییر ثبت و نسخه فرآیند به ' . $nv . ' به‌روزرسانی شد.';
                } else {
                    $msg = 'تغییر ثبت شد.';
                }

                if (!empty($_POST['notify_bale'])) {
                    $baleTitle = 'ثبت تغییر در فرآیند' . ($nv !== '' ? ' (نسخه ' . $nv . ')' : '');
                    $baleRes = bale_notify_process_change($db, $procId, $baleTitle, $chDesc, null, $tid);
                    if (!empty($baleRes['sent']) && $baleRes['sent'] > 0) {
                        $msg .= ' و پیام تغییر به ' . farsi_num($baleRes['sent']) . ' مخاطب در بله ارسال گردید.';
                    }
                }
                flash_set('ok', $msg);
                $redirectUrl = 'file.php?id=' . $procId . '&tab=changes';
            }
        } elseif ($action === 'set_status' && $tabBack === 'info') {
            $newStatus = (string)($_POST['status'] ?? '');
            if (in_array($newStatus, ['draft','active','archived'], true)) {
                $st = $db->prepare('UPDATE Processes SET Status = ?, UpdatedAt = NOW() WHERE ID = ?');
                $st->bind_param('si', $newStatus, $procId);
                $st->execute(); $st->close();
                flash_set('ok', 'وضعیت فرآیند تغییر کرد.');
            }
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'info');
if (!in_array($tab, ['info','docs','audience','improvements','executors','changes'], true)) $tab = 'info';

/* ---------- استخراج اطلاعات مخاطبان و شمارش‌ها برای تب‌ها ---------- */
$recipientsInfo = bale_get_process_recipients($db, $procId);
$counts = [];
foreach (['docs' => null, 'audience' => null, 'improvements' => 'Improvements', 'executors' => 'ProcessExecutors', 'changes' => 'Changes'] as $k => $tbl) {
    if ($k === 'docs') {
        $n = 0;
        if (is_dir($absDir)) foreach (scandir($absDir) as $f) if ($f !== '.' && $f !== '..' && $f !== '.htaccess' && is_file($absDir . $f)) $n++;
        $counts['docs'] = $n;
    } elseif ($k === 'audience') {
        $counts['audience'] = count($recipientsInfo['all_recipients'] ?? []);
    } else {
        $col = $tbl === 'Improvements' ? 'ProcessID' : 'ProcessID';
        $st = $db->prepare("SELECT COUNT(*) c FROM `$tbl` WHERE $col = ?");
        $st->bind_param('i', $procId); $st->execute();
        $counts[$k] = (int)$st->get_result()->fetch_assoc()['c'];
        $st->close();
    }
}

$tabs = [
    'info' => 'مشاهده فرآیند',
    'docs' => 'مستندات',
    'audience' => 'متولی و مخاطبان بله',
    'improvements' => 'لیست بهبودها',
    'executors' => 'لیست مجریان',
    'changes' => 'لیست تغییرات',
];
$csrf = csrf_token();
?>
<?php team_header($id, 'processes', 'پرونده فرآیند: ' . $proc['Name']); ?>

<div class="crumbs"><a href="processes.php">لیست فرآیندها</a> ← <?= e($proc['Name']) ?></div>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="file-tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a class="file-tab <?= $tab === $k ? 'active' : '' ?>" href="file.php?id=<?= $procId ?>&tab=<?= $k ?>">
            <?= e($label) ?><?php if ($k !== 'info'): ?><span class="cnt"><?= (int)$counts[$k] ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$isOwnerTeam): ?>
    <div class="alert alert-info">تیم مسئول این فرآیند «<?= e($proc['RespTeam'] ?? '—') ?>» است؛ نمایش فقط‌خواندنی.</div>
<?php endif; ?>

<?php if ($tab === 'info'): ?>
<div class="card">
    <h2><?= e($proc['Name']) ?> <span class="badge badge-<?= e($proc['Status']) ?>"><?= status_label($proc['Status']) ?></span></h2>
    <div class="table-wrap">
    <table class="data"><tbody>
        <tr><th style="width:190px">دسته (فولدر)</th><td><?= e($proc['CatTitle']) ?></td></tr>
        <tr><th>شرکت</th><td><?= e($proc['CompanyName'] ?? '—') ?></td></tr>
        <tr><th>تیم فرآیند مسئول</th><td><?= e($proc['RespTeam'] ?? '—') ?></td></tr>
        <tr>
            <th>متولی فرآیند (چارت سازمانی)</th>
            <td>
                <?php if (!empty($proc['OwnerPositionTitle'])): ?>
                    <strong style="color:var(--brand-dark)"><?= e($proc['OwnerPositionTitle']) ?></strong>
                    <?php if (!empty($proc['OwnerDepartment'])): ?><span class="badge" style="margin-inline-start:6px"><?= e($proc['OwnerDepartment']) ?></span><?php endif; ?>
                    <?php if (!empty($proc['OwnerPersName'])): ?>
                        <span style="margin-inline-start:8px;font-size:12.5px">
                            (شاغل فعلی: <strong><?= e($proc['OwnerPersName'] . ' ' . $proc['OwnerPersFamily']) ?></strong>
                            <?php if (!empty($proc['OwnerBale'])): ?>
                                <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11px;margin-inline-start:4px">✓ بله</span>
                            <?php endif; ?>)
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background:#fef3c7;color:#92400e;margin-inline-start:8px">بلاتصدی</span>
                    <?php endif; ?>
                    <a href="file.php?id=<?= $procId ?>&tab=audience" class="btn btn-ghost btn-xs" style="margin-inline-start:8px">مدیریت متولی و مخاطبان</a>
                <?php else: ?>
                    <span style="color:var(--muted)">تعیین نشده</span>
                    <a href="file.php?id=<?= $procId ?>&tab=audience" class="btn btn-ghost btn-xs" style="margin-inline-start:8px">تعیین متولی از چارت</a>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>دامنه مخاطبان اثرپذیر (بله)</th>
            <td>
                <?php
                $audLbl = match ($proc['AudienceType'] ?? 'all_company') {
                    'all_company' => 'تمام پرسنل شرکت (عمومی)',
                    'specific_positions' => 'سمت‌های اثرپذیر از چارت',
                    'specific_personnel' => 'پرسنل مشخص اثرپذیر',
                    'department' => 'واحد ' . ($proc['AudienceDepartment'] ?? ''),
                    default => 'سازمان',
                };
                ?>
                <span class="badge badge-active"><?= e($audLbl) ?></span>
                <span style="font-size:12px;color:var(--muted);margin-inline-start:8px">(<?= farsi_num($counts['audience']) ?> دریافت‌کننده در بله)</span>
            </td>
        </tr>
        <tr><th>نسخه</th><td><span class="num-ltr"><?= e($proc['Version']) ?></span></td></tr>
        <tr><th>مسیر فایل‌ها</th><td><span class="num-ltr"><?= e('processes/' . $relDir) ?></span></td></tr>
        <tr><th>آخرین به‌روزرسانی</th><td><?= e(fmt_date($proc['UpdatedAt'])) ?></td></tr>
        <?php if (!empty($proc['Description'])): ?><tr><th>شرح فرآیند</th><td><?= nl2br(e($proc['Description'])) ?></td></tr><?php endif; ?>
    </tbody></table>
    </div>
    <?php if ($isOwnerTeam): ?>
    <form method="post" class="inline-form" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="tab" value="info">
        <span class="field-label" style="margin:0">تغییر وضعیت:</span>
        <select name="status" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font:inherit">
            <option value="draft">پیش‌نویس</option>
            <option value="active" <?= $proc['Status'] === 'active' ? 'selected' : '' ?>>فعال</option>
            <option value="archived">بایگانی</option>
        </select>
        <button class="btn btn-sm">اعمال</button>
    </form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'docs'): ?>
<div class="card">
    <h2>مستندات فرآیند <a class="btn btn-ghost btn-xs" href="files.php?dir=<?= e(rawurlencode(str_replace('\\', '/', $relDir))) ?>" style="margin-inline-start:8px;display:inline-flex;align-items:center;gap:5px"><?= app_icon('files', '', 14) ?><span>مدیریت کامل فایل‌ها</span></a></h2>
    <?php
    $files = [];
    if (is_dir($absDir)) {
        foreach (scandir($absDir) as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess') continue;
            if (is_file($absDir . $f)) $files[] = ['name' => $f, 'size' => (int)filesize($absDir . $f), 'time' => fmt_date((int)filemtime($absDir . $f))];
        }
    }
    if (!$files): ?>
        <div class="empty"><span class="ico">📄</span><p>هنوز سندی بارگذاری نشده است.</p></div>
    <?php else: ?>
        <ul class="file-list">
            <?php foreach ($files as $f):
                $url = fm_url($relDir === '' ? $f['name'] : $relDir . '/' . $f['name']);
                $isHermesDoc = str_starts_with($f['name'], 'دستورالعمل-فرآیند');
            ?>
                <li style="<?= $isHermesDoc ? 'background:#f0fdf4;border-right:3px solid #16a34a;padding-right:10px;border-radius:6px;' : '' ?>">
                    <span><?= str_ends_with($f['name'], '.pdf') ? '📕' : '📄' ?></span>
                    <a href="<?= e($url) ?>" target="_blank" rel="noopener" style="font-weight:<?= $isHermesDoc ? 'bold' : 'normal' ?>"><?= e($f['name']) ?></a>
                    <?php if ($isHermesDoc): ?>
                        <span class="badge" style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px;display:inline-flex;align-items:center;gap:4px">
                            <?= app_icon('bot', '', 12) ?>
                            <span>سند مصوب هرمس</span>
                        </span>
                    <?php endif; ?>
                    <span class="fsize"><?= e(fmt_size($f['size'])) ?> · <?= e($f['time']) ?></span>
                    <?php if ($isOwnerTeam): ?>
                    <form method="post" class="inline-form" onsubmit="return confirm('حذف این فایل؟');" style="margin-inline-start:10px">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="delete_doc">
                        <input type="hidden" name="tab" value="docs">
                        <input type="hidden" name="file" value="<?= e($f['name']) ?>">
                        <button class="btn btn-danger btn-xs">حذف</button>
                    </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($isOwnerTeam): ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:16px;background:var(--bg);padding:14px;border-radius:12px;border:1px solid var(--line)">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="tab" value="docs">
        <div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
            <label class="field" style="margin:0;flex:1;min-width:240px">
                <span class="field-label">بارگذاری مستند جدید (pdf, docx, xls, zip, تصویر و… حداکثر ۲۰ مگابایت)</span>
                <input type="file" name="doc" required>
            </label>
            <button class="btn">⬆ بارگذاری مستند</button>
        </div>
        <div style="margin-top:10px">
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;cursor:pointer">
                <input type="checkbox" name="notify_bale" value="1" checked>
                <span>ارسال خودکار پیام اطلاع‌رسانی بارگذاری این مستند به <strong>متولی چارت</strong> و <strong>مخاطبان اثرپذیر</strong> در پیام‌رسان بله</span>
            </label>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'audience'): ?>
<?php
// دریافت لیست کامل سمت‌های چارت سازمانی
$resPos = $db->query("SELECT op.ID, op.Title, op.Department, p.Name, p.Family FROM OrgPositions op LEFT JOIN Personnel p ON p.ID = op.CurrentPersonnelID WHERE op.Active = 1 ORDER BY op.SortOrder, op.Title");
$allOrgPositions = [];
while ($r = $resPos->fetch_assoc()) $allOrgPositions[] = $r;

// دریافت لیست کامل پرسنل فعال
$resPers = $db->query("SELECT ID, PersonnelCode, Name, Family, BaleChatID, Phone FROM Personnel WHERE Active = 1 ORDER BY Name, Family");
$allPersonnel = [];
while ($r = $resPers->fetch_assoc()) $allPersonnel[] = $r;

// دریافت مخاطبان اختصاصی ثبت‌شده در ProcessAudience
$stAud = $db->prepare("SELECT * FROM ProcessAudience WHERE ProcessID = ? ORDER BY ID ASC");
$stAud->bind_param('i', $procId);
$stAud->execute();
$customAudience = $stAud->get_result()->fetch_all(MYSQLI_ASSOC);
$stAud->close();

$ownerPosTitle = $proc['OwnerPositionTitle'] ?? '';
$ownerOccupantName = trim(($proc['OwnerPersName'] ?? '') . ' ' . ($proc['OwnerPersFamily'] ?? ''));
$ownerBale = $proc['OwnerBale'] ?: ($proc['OwnerPhone'] ?: '');
$currentAudType = $proc['AudienceType'] ?? 'all_company';
?>

<!-- بخش ۱: متولی فرآیند از چارت سازمانی -->
<div class="card">
    <div style="margin-bottom:14px">
        <h2 style="margin:0 0 4px">👤 متولی فرآیند از چارت سازمانی (Process Owner)</h2>
        <div style="font-size:13px;color:var(--muted)">این فرآیند به کدام سمت سازمانی تعلق دارد؟ سیستم به صورت خودکار شاغل فعلی این سمت را شناسایی کرده و هنگام تغییر به وی پیام می‌دهد.</div>
    </div>

    <div style="background:var(--bg);padding:14px 18px;border-radius:12px;border:1px solid var(--line);margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
            <span class="avatar avatar-compact avatar-team" style="width:40px;height:40px;font-size:16px">
                <?= $ownerOccupantName ? e(mb_substr($proc['OwnerPersName'], 0, 1)) : '؟' ?>
            </span>
            <div>
                <div style="font-size:15px;font-weight:800;color:var(--ink)">
                    <?= $ownerPosTitle ? e($ownerPosTitle) : '<span style="color:var(--danger)">بدون متولی سازمانی</span>' ?>
                    <?php if (!empty($proc['OwnerDepartment'])): ?>
                        <span class="badge" style="margin-inline-start:6px"><?= e($proc['OwnerDepartment']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12.5px;color:var(--muted);margin-top:2px">
                    <?php if ($ownerOccupantName): ?>
                        شاغل فعلی در چارت: <strong style="color:var(--ink)"><?= e($ownerOccupantName) ?></strong> (کد: <?= e($proc['OwnerPersCode'] ?? '—') ?>)
                    <?php else: ?>
                        <span class="badge" style="background:#fef3c7;color:#92400e">این سمت فعلاً در چارت بلاتصدی است (فاقد شاغل)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div>
            <?php if ($ownerBale): ?>
                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px;font-size:12px">
                    <?= app_icon('bot', '', 14) ?> بله: <span class="num-ltr"><?= e($ownerBale) ?></span>
                </span>
            <?php elseif ($ownerOccupantName): ?>
                <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:12px">
                    ⚠️ شاغل فاقد شناسه بله
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isOwnerTeam): ?>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update_org_settings">
        <input type="hidden" name="tab" value="audience">
        <input type="hidden" name="audience_type" value="<?= e($currentAudType) ?>">
        <input type="hidden" name="audience_department" value="<?= e($proc['AudienceDepartment'] ?? '') ?>">

        <label class="field" style="margin:0;flex:1;min-width:260px">
            <span class="field-label">تغییر سمت متولی در چارت سازمانی</span>
            <select name="owner_position_id">
                <option value="0">— بدون سمت متولی —</option>
                <?php foreach ($allOrgPositions as $p): ?>
                    <?php 
                        $occ = (!empty($p['Name'])) ? ' (شاغل فعلی: ' . e($p['Name'] . ' ' . $p['Family']) . ')' : ' [بلاتصدی]';
                    ?>
                    <option value="<?= (int)$p['ID'] ?>" <?= (int)$proc['OwnerPositionID'] === (int)$p['ID'] ? 'selected' : '' ?>>
                        <?= e($p['Title']) ?><?= !empty($p['Department']) ? ' — ' . e($p['Department']) : '' ?><?= $occ ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-sm">ذخیره متولی فرآیند</button>
    </form>
    <?php endif; ?>
</div>

<!-- بخش ۲: دامنه و لیست مخاطبان اثرپذیر فرآیند -->
<div class="card">
    <div style="margin-bottom:14px">
        <h2 style="margin:0 0 4px">👥 دامنه مخاطبان اثرپذیر (ارسال اعلان در پیام‌رسان بله)</h2>
        <div style="font-size:13px;color:var(--muted)">چه افرادی از تغییر این فرآیند اثر می‌پذیرند؟ (مثلاً برای فرآیندی مانند «مرخصی» تمام پرسنل شرکت اثرپذیرند، و برای فرآیندهای تخصصی مانند «پیش‌فاکتور» سمت‌های بازرگانی و فروش).</div>
    </div>

    <?php if ($isOwnerTeam): ?>
    <form method="post" style="background:var(--bg);padding:14px 18px;border-radius:12px;border:1px solid var(--line);margin-bottom:18px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="update_org_settings">
        <input type="hidden" name="tab" value="audience">
        <input type="hidden" name="owner_position_id" value="<?= (int)($proc['OwnerPositionID'] ?? 0) ?>">

        <div class="form-row" style="align-items:flex-end">
            <label class="field" style="margin:0">
                <span class="field-label">تعیین دامنه اثرپذیری این فرآیند</span>
                <select name="audience_type" id="pageAudienceSelect" onchange="toggleAudienceInputs(this.value)">
                    <option value="all_company" <?= $currentAudType === 'all_company' ? 'selected' : '' ?>>همه پرسنل شرکت (عمومی — مانند مرخصی، مأموریت و ...)</option>
                    <option value="specific_positions" <?= $currentAudType === 'specific_positions' ? 'selected' : '' ?>>سمت‌های اثرپذیر از چارت (تخصصی)</option>
                    <option value="specific_personnel" <?= $currentAudType === 'specific_personnel' ? 'selected' : '' ?>>افراد و پرسنل مشخص اثرپذیر</option>
                    <option value="department" <?= $currentAudType === 'department' ? 'selected' : '' ?>>واحد / دپارتمان سازمانی مشخص</option>
                </select>
            </label>
            <div id="deptInputWrap" style="display:<?= $currentAudType === 'department' ? 'block' : 'none' ?>;flex:1">
                <label class="field" style="margin:0">
                    <span class="field-label">نام دپارتمان</span>
                    <input type="text" name="audience_department" value="<?= e($proc['AudienceDepartment'] ?? '') ?>" placeholder="مثلاً بازرگانی یا مالی">
                </label>
            </div>
            <button type="submit" class="btn btn-sm">ذخیره دامنه مخاطبان</button>
        </div>
    </form>
    <script>
    function toggleAudienceInputs(val) {
        document.getElementById('deptInputWrap').style.display = (val === 'department') ? 'block' : 'none';
    }
    </script>
    <?php endif; ?>

    <!-- در صورتی که دامنه سمت‌های مشخص باشد -->
    <?php if ($currentAudType === 'specific_positions'): ?>
        <h3 style="font-size:14px;margin-bottom:8px">سمت‌های اثرپذیر مشخص‌شده در چارت</h3>
        <?php if ($isOwnerTeam): ?>
            <form method="post" class="inline-form" style="margin-bottom:14px;background:var(--bg);padding:10px 14px;border-radius:10px">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="add_audience_target">
                <input type="hidden" name="tab" value="audience">
                <input type="hidden" name="target_type" value="position">
                <span class="field-label" style="margin:0">افزودن سمت اثرپذیر:</span>
                <select name="target_id" id="targetPosSelect" onchange="updateTargetName(this)" style="padding:6px 12px;border:1px solid var(--line);border-radius:8px;font:inherit">
                    <option value="0">— انتخاب سمت از چارت —</option>
                    <?php foreach ($allOrgPositions as $p): ?>
                        <option value="<?= (int)$p['ID'] ?>" data-name="<?= e($p['Title']) ?>">
                            <?= e($p['Title']) ?><?= !empty($p['Department']) ? ' [' . e($p['Department']) . ']' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="target_name" id="targetPosName" value="">
                <button type="submit" class="btn btn-sm">＋ افزودن به مخاطبان</button>
            </form>
            <script>
            function updateTargetName(sel) {
                const opt = sel.options[sel.selectedIndex];
                document.getElementById('targetPosName').value = opt ? opt.getAttribute('data-name') : '';
            }
            </script>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
            <?php 
                $posAudience = array_filter($customAudience, fn($a) => $a['TargetType'] === 'position');
                if (!$posAudience):
            ?>
                <span style="color:var(--muted);font-size:13px">سمت اثرپذیری ثبت نشده است. از فرم بالا اضافه کنید.</span>
            <?php else: ?>
                <?php foreach ($posAudience as $a): ?>
                    <span class="badge" style="padding:6px 10px;font-size:12.5px;background:var(--card);border:1px solid var(--line);border-radius:8px;display:inline-flex;align-items:center;gap:6px">
                        <?= app_icon('basics', '', 13) ?>
                        <strong><?= e($a['TargetName']) ?></strong>
                        <?php if ($isOwnerTeam): ?>
                            <form method="post" style="display:inline;margin:0" onsubmit="return confirm('حذف از فهرست اثرپذیران؟');">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="remove_audience_target">
                                <input type="hidden" name="tab" value="audience">
                                <input type="hidden" name="audience_id" value="<?= (int)$a['ID'] ?>">
                                <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-weight:700;padding:0 2px" title="حذف">✕</button>
                            </form>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <!-- در صورتی که دامنه پرسنل مشخص باشد -->
    <?php elseif ($currentAudType === 'specific_personnel'): ?>
        <h3 style="font-size:14px;margin-bottom:8px">پرسنل اثرپذیر مشخص‌شده</h3>
        <?php if ($isOwnerTeam): ?>
            <form method="post" class="inline-form" style="margin-bottom:14px;background:var(--bg);padding:10px 14px;border-radius:10px">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="add_audience_target">
                <input type="hidden" name="tab" value="audience">
                <input type="hidden" name="target_type" value="personnel">
                <span class="field-label" style="margin:0">افزودن فرد اثرپذیر:</span>
                <select name="target_id" id="targetPersSelect" onchange="updatePersTargetName(this)" style="padding:6px 12px;border:1px solid var(--line);border-radius:8px;font:inherit">
                    <option value="0">— انتخاب پرسنل —</option>
                    <?php foreach ($allPersonnel as $pers): ?>
                        <option value="<?= (int)$pers['ID'] ?>" data-name="<?= e($pers['Name'] . ' ' . $pers['Family']) ?>">
                            <?= e($pers['Name'] . ' ' . $pers['Family']) ?> (کد: <?= e($pers['PersonnelCode']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="target_name" id="targetPersName" value="">
                <button type="submit" class="btn btn-sm">＋ افزودن به مخاطبان</button>
            </form>
            <script>
            function updatePersTargetName(sel) {
                const opt = sel.options[sel.selectedIndex];
                document.getElementById('targetPersName').value = opt ? opt.getAttribute('data-name') : '';
            }
            </script>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
            <?php 
                $persAudience = array_filter($customAudience, fn($a) => $a['TargetType'] === 'personnel');
                if (!$persAudience):
            ?>
                <span style="color:var(--muted);font-size:13px">فردی در لیست اثرپذیران ثبت نشده است. از فرم بالا اضافه کنید.</span>
            <?php else: ?>
                <?php foreach ($persAudience as $a): ?>
                    <span class="badge" style="padding:6px 10px;font-size:12.5px;background:var(--card);border:1px solid var(--line);border-radius:8px;display:inline-flex;align-items:center;gap:6px">
                        <?= app_icon('users', '', 13) ?>
                        <strong><?= e($a['TargetName']) ?></strong>
                        <?php if ($isOwnerTeam): ?>
                            <form method="post" style="display:inline;margin:0" onsubmit="return confirm('حذف از فهرست اثرپذیران؟');">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="remove_audience_target">
                                <input type="hidden" name="tab" value="audience">
                                <input type="hidden" name="audience_id" value="<?= (int)$a['ID'] ?>">
                                <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-weight:700;padding:0 2px" title="حذف">✕</button>
                            </form>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php elseif ($currentAudType === 'all_company'): ?>
        <div class="alert alert-ok" style="margin-bottom:14px">
            ✓ دامنه این فرآیند <strong>عمومی</strong> تنظیم شده است. به محض ثبت هر تغییر، کلیه پرسنل فعال شرکت (<strong><?= farsi_num(count($recipientsInfo['all_recipients'])) ?> نفر</strong>) به عنوان مخاطب شناسایی شده و در بله پیام دریافت خواهند کرد.
        </div>
    <?php endif; ?>
</div>

<!-- بخش ۳: پیش‌نمایش جدول نهایی دریافت‌کنندگان پیام در بله -->
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap">
        <div>
            <h2 style="margin:0 0 2px">📋 فهرست کامل دریافت‌کنندگان پیام در بله (<?= farsi_num(count($recipientsInfo['all_recipients'])) ?> نفر)</h2>
            <div style="font-size:12.5px;color:var(--muted)">این افراد هنگام تغییر، بازنگری یا ارسال دستی، پیام دریافت خواهند کرد.</div>
        </div>
        <a class="btn btn-xs btn-ghost" href="basics_bale.php">
            <?= app_icon('bot', '', 13) ?> لاگ پیام‌های ارسالی
        </a>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th>نام و کد پرسنلی</th>
                    <th>سمت در چارت سازمانی</th>
                    <th>نقش در این فرآیند</th>
                    <th>شناسه بله / شماره تماس</th>
                    <th>وضعیت بله</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recipientsInfo['all_recipients'])): ?>
                    <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">هیچ دریافت‌کننده‌ای برای این فرآیند یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($recipientsInfo['all_recipients'] as $i => $rec): ?>
                        <?php 
                            $isOwnerRec = ($rec['role'] ?? '') === 'owner';
                            $hasBale = !empty($rec['bale_chat_id']);
                        ?>
                        <tr style="<?= $isOwnerRec ? 'background:var(--brand-soft);font-weight:600' : '' ?>">
                            <td class="num-ltr" style="font-size:12px;color:var(--muted)"><?= farsi_num($i + 1) ?></td>
                            <td>
                                <strong><?= e($rec['name']) ?></strong>
                            </td>
                            <td><?= e($rec['position']) ?></td>
                            <td>
                                <?php if ($isOwnerRec): ?>
                                    <span class="badge" style="background:#0e8a6d;color:#fff;font-weight:700">★ متولی اصلی فرآیند</span>
                                <?php else: ?>
                                    <span class="badge" style="background:var(--bg);color:var(--ink)"><?= e($rec['role_label']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasBale): ?>
                                    <code class="num-ltr" style="font-size:12px;background:var(--bg);padding:2px 6px;border-radius:4px"><?= e($rec['bale_chat_id']) ?></code>
                                <?php else: ?>
                                    <span style="color:var(--muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasBale): ?>
                                    <span class="badge badge-active" style="font-size:11px">✓ آماده ارسال بله</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:11px">⚠️ فاقد شناسه بله</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- بخش ۴: فرم ارسال فوری پیام اطلاع‌رسانی در پیام‌رسان بله -->
<?php if ($isOwnerTeam): ?>
<div class="card" style="border:2px solid var(--brand-soft)">
    <div style="margin-bottom:14px">
        <h2 style="margin:0 0 4px;display:flex;align-items:center;gap:8px">
            <?= app_icon('bot', '', 18) ?>
            <span>ارسال پیام اطلاع‌رسانی فوری به متولی و مخاطبان در پیام‌رسان بله</span>
        </h2>
        <div style="font-size:13px;color:var(--muted)">با ثبت این فرم، پیام دلخواه شما در قالب استاندارد رابین‌فلو همراه با لینک مستقیم به تمام افراد فوق در پیام‌رسان بله مخابره می‌شود.</div>
    </div>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="broadcast_bale">
        <input type="hidden" name="tab" value="audience">

        <label class="field">
            <span class="field-label">موضوع / تیتر اطلاع‌رسانی</span>
            <input type="text" name="bale_title" required value="تغییر و به‌روزرسانی در فرآیند «<?= e($proc['Name']) ?>»" maxlength="150">
        </label>

        <label class="field">
            <span class="field-label">متن شرح تغییر یا نکات مهم برای بررسی مخاطبان</span>
            <textarea name="bale_description" rows="3" required placeholder="لطفاً تغییرات جدید در مراحل فرآیند را بررسی نمایید…"></textarea>
        </label>

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <button type="submit" class="btn" style="display:inline-flex;align-items:center;gap:6px">
                <?= app_icon('bot', '', 16) ?>
                <span>ارسال پیام در بله به <?= farsi_num(count($recipientsInfo['all_recipients'])) ?> مخاطب</span>
            </button>
            <span style="font-size:12px;color:var(--muted)">پیام‌ها به صورت بلادرنگ به وب‌سرویس فعال بله تحویل داده می‌شوند.</span>
        </div>
    </form>
</div>
<?php endif; ?>

<?php elseif ($tab === 'improvements'): ?>
<div class="card">
    <h2>بهبودهای این فرآیند</h2>
    <?php
    $st = $db->prepare(
        "SELECT i.*, u.Name AS ReqName, u.Family AS ReqFamily, pt.TeamName AS ReqTeamName,
                (SELECT ht.Status FROM HermesTasks ht WHERE ht.TaskType='revision' AND ht.RefID=i.ID ORDER BY ht.ID DESC LIMIT 1) AS RevisionTaskStatus
         FROM Improvements i
         LEFT JOIN Personnel u ON u.ID = i.requester_id AND i.requester_type='user'
         LEFT JOIN ProcessTeams pt ON pt.ID = i.requester_id AND i.requester_type='team'
         WHERE i.ProcessID = ? ORDER BY i.CreatedAt DESC"
    );
    $st->bind_param('i', $procId); $st->execute();
    $imps = []; $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $imps[] = $r;
    $st->close();
    if (!$imps): ?>
        <div class="empty"><span class="ico"><?= app_icon('improvements', '', 36) ?></span><p>درخواست بهبودی برای این فرآیند ثبت نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>بهبود</th><th>داور هرمس (AI)</th><th>درخواست‌کننده</th><th>وضعیت</th><th>تاریخ</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($imps as $m): ?>
                <tr>
                    <td><span class="title"><?= e($m['Title']) ?></span><?php if ($m['Description']): ?><br><span class="sub"><?= e(mb_strimwidth((string)$m['Description'], 0, 120, '…')) ?></span><?php endif; ?></td>
                    <td><?= hermes_badge($m['AiVerdict'] ?? 'pending', $m['CreatedAt'] ?? null, (int)$m['ID'], false) ?></td>
                    <td><?= e(trim((string)$m['ReqName'] . ' ' . (string)$m['ReqFamily']) ?: (string)$m['ReqTeamName'] ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($m['Status']) ?>"><?= status_label($m['Status']) ?></span></td>
                    <td><?= e(fmt_date_short($m['CreatedAt'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                            <a class="btn btn-ghost btn-xs" href="discussion.php?imp=<?= (int)$m['ID'] ?>" style="display:inline-flex;align-items:center;gap:4px"><?= app_icon('discussion', '', 13) ?><span>گفتگو و تحلیل هرمس</span></a>
                            <?php if ($m['Status'] === 'implemented'): ?>
                                <span class="badge" style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 7px;border-radius:10px">اعمال‌شده</span>
                            <?php elseif (in_array($m['RevisionTaskStatus'] ?? '', ['queued', 'running'], true)): ?>
                                <a class="btn btn-xs" href="revision.php?imp=<?= (int)$m['ID'] ?>" style="background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;display:inline-flex;align-items:center;gap:4px">
                                    <?= app_icon('refresh', 'ai-spin', 12) ?>
                                    <span>در حال بازنویسی...</span>
                                </a>
                            <?php elseif ($isOwnerTeam): ?>
                                <a class="btn btn-brand btn-xs" href="revision.php?imp=<?= (int)$m['ID'] ?>" style="display:inline-flex;align-items:center;gap:4px">
                                    <?= app_icon('bot', '', 12) ?>
                                    <span>بازنویسی سند</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($isOwnerTeam): ?><p class="sub" style="color:var(--muted);font-size:12.5px">تعیین تکلیف بهبودها از صفحه «لیست بهبودها» انجام می‌شود.</p><?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'executors'): ?>
<div class="card">
    <h2>مجریان این فرآیند</h2>
    <?php
    $st = $db->prepare(
        "SELECT ex.*, u.Name, u.Family, u.PersonnelCode, t.TeamName
         FROM ProcessExecutors ex
         LEFT JOIN Personnel u ON u.ID = ex.PersonnelID
         LEFT JOIN ProcessTeams t ON t.ID = ex.TeamID
         WHERE ex.ProcessID = ? ORDER BY ex.ID"
    );
    $st->bind_param('i', $procId); $st->execute();
    $execs = []; $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $execs[] = $r;
    $st->close();
    if (!$execs): ?>
        <div class="empty"><span class="ico">👥</span><p>مجری‌ای تعریف نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>مجری</th><th>نقش</th><th>از تاریخ</th><?php if ($isOwnerTeam): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($execs as $x): ?>
                <tr>
                    <td><?php if ($x['PersonnelID']): ?>👤 <?= e(trim($x['Name'] . ' ' . $x['Family'])) ?> <span class="sub">(<?= e($x['PersonnelCode']) ?>)</span><?php else: ?>🛡 <?= e($x['TeamName'] ?? 'تیم') ?><?php endif; ?></td>
                    <td><?= e($x['Role']) ?></td>
                    <td><?= e(fmt_date_short($x['CreatedAt'])) ?></td>
                    <?php if ($isOwnerTeam): ?>
                    <td>
                        <form method="post" onsubmit="return confirm('حذف این مجری؟');">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="remove_executor">
                            <input type="hidden" name="tab" value="executors">
                            <input type="hidden" name="executor_id" value="<?= (int)$x['ID'] ?>">
                            <button class="btn btn-danger btn-xs">حذف</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($isOwnerTeam):
        $persons = [];
        $res = $db->query('SELECT ID, PersonnelCode, Name, Family FROM Personnel WHERE Active=1 ORDER BY Name');
        while ($r = $res->fetch_assoc()) $persons[] = $r;
    ?>
    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add_executor">
        <input type="hidden" name="tab" value="executors">
        <div class="form-row">
            <label class="field">
                <span class="field-label">نفر</span>
                <select name="personnel_id">
                    <option value="0">— انتخاب پرسنل —</option>
                    <?php foreach ($persons as $p): ?>
                        <option value="<?= (int)$p['ID'] ?>"><?= e($p['Name'] . ' ' . $p['Family']) ?> (<?= e($p['PersonnelCode']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">یا تیم</span>
                <select name="team_id">
                    <option value="0">— انتخاب تیم —</option>
                    <?php
                    $res = $db->query('SELECT ID, TeamName FROM ProcessTeams WHERE Active=1');
                    while ($r = $res->fetch_assoc()) echo '<option value="' . (int)$r['ID'] . '">' . e($r['TeamName']) . '</option>';
                    ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">نقش</span>
                <input type="text" name="role" maxlength="60" placeholder="مثلاً متولی ثبت">
            </label>
        </div>
        <button class="btn btn-sm">＋ افزودن مجری</button>
    </form>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'changes'): ?>
<div class="card">
    <h2>تغییرات این فرآیند</h2>
    <?php
    $st = $db->prepare(
        "SELECT ch.*, imp.Title AS ImpTitle
         FROM Changes ch LEFT JOIN Improvements imp ON imp.ID = ch.ImprovementID
         WHERE ch.ProcessID = ? ORDER BY ch.CreatedAt DESC"
    );
    $st->bind_param('i', $procId); $st->execute();
    $changes = []; $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $changes[] = $r;
    $st->close();
    if (!$changes): ?>
        <div class="empty"><span class="ico">🔄</span><p>تغییری برای این فرآیند ثبت نشده است.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>نوع</th><th>شرح</th><th>نسخه</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
            <tbody>
            <?php foreach ($changes as $c): ?>
                <tr>
                    <td><span class="badge badge-<?= e($c['ChangeType']) ?>"><?= status_label($c['ChangeType']) ?></span></td>
                    <td><?= e($c['Description']) ?><?php if ($c['ImpTitle']): ?><br><span class="sub">بهبود: «<?= e($c['ImpTitle']) ?>»</span><?php endif; ?></td>
                    <td><span class="num-ltr"><?= e($c['OldVersion'] ?? '—') ?> ← <?= e($c['NewVersion'] ?? '—') ?></span></td>
                    <td><span class="badge badge-<?= e($c['Status']) ?>"><?= status_label($c['Status']) ?></span></td>
                    <td><?= e(fmt_date_short($c['CreatedAt'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if ($isOwnerTeam):
        $st = $db->prepare("SELECT ID, Title FROM Improvements WHERE ProcessID = ? AND Status IN ('accepted','pending') ORDER BY ID DESC");
        $st->bind_param('i', $procId); $st->execute();
        $impOpts = []; $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $impOpts[] = $r;
        $st->close();
    ?>
    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add_change">
        <input type="hidden" name="tab" value="changes">
        <div class="form-row">
            <label class="field">
                <span class="field-label">نوع تغییر</span>
                <select name="change_type">
                    <option value="revision">اصلاح</option>
                    <option value="replacement">جایگزینی</option>
                    <option value="addition">افزودن</option>
                    <option value="removal">حذف</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">نسخه جدید</span>
                <input type="text" name="new_version" maxlength="20" placeholder="مثلاً 1.2">
            </label>
            <label class="field">
                <span class="field-label">وضعیت</span>
                <select name="status">
                    <option value="pending">در انتظار</option>
                    <option value="approved">تأییدشده</option>
                    <option value="applied">اعمال‌شده (نسخه به‌روز شود)</option>
                    <option value="rejected">ردشده</option>
                </select>
            </label>
            <label class="field">
                <span class="field-label">بهبود مرتبط (اختیاری)</span>
                <select name="improvement_id">
                    <option value="0">— بدون ارتباط —</option>
                    <?php foreach ($impOpts as $o): ?><option value="<?= (int)$o['ID'] ?>"><?= e($o['Title']) ?></option><?php endforeach; ?>
                </select>
            </label>
        </div>
        <label class="field">
            <span class="field-label">شرح تغییر</span>
            <textarea name="description" required maxlength="2000" placeholder="چه چیزی در فرآیند تغییر می‌کند…"></textarea>
        </label>
        <div style="margin-bottom:12px">
            <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;cursor:pointer">
                <input type="checkbox" name="notify_bale" value="1" checked>
                <span>ارسال خودکار اعلان این تغییر به <strong>متولی چارت</strong> و <strong>مخاطبان اثرپذیر</strong> در پیام‌رسان بله</span>
            </label>
        </div>
        <button class="btn btn-sm">＋ ثبت تغییر</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php app_footer(); ?>
