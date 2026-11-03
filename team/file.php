<?php
/**
 * مشاهده پرونده فرآیند — پنل تیم
 * تب‌ها: مشاهده فرآیند | مستندات | لیست بهبودها | لیست مجریان | لیست تغییرات
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/files.php';
require_once __DIR__ . '/../inc/hermes.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$procId = (int)($_GET['id'] ?? 0);
$st = $db->prepare(
    "SELECT p.*, cat.Title AS CatTitle, cat.FolderName, c.Name AS CompanyName, t.TeamName AS RespTeam
     FROM Processes p
     JOIN ProcessCategories cat ON cat.ID = p.CategoryID
     LEFT JOIN Companies c ON c.ID = p.CompanyID
     LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID
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

/* ---------- پردازش اکشن‌ها ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } elseif (!$isOwnerTeam) {
        $error = 'فقط تیم مسئول این فرآیند می‌تواند پرونده را ویرایش کند.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $tabBack = (string)($_POST['tab'] ?? 'docs');

        if ($action === 'upload' && isset($_FILES['doc'])) {
            $upErr = null;
            $saved = fm_store_upload($_FILES['doc'], rtrim($absDir, DIRECTORY_SEPARATOR), $upErr);
            if ($saved !== null) {
                $notice = 'مستند «' . $saved . '» با موفقیت بارگذاری شد.';
            } else {
                $error = $upErr ?: 'خطا در ذخیره فایل.';
            }
        } elseif ($action === 'delete_doc') {
            $f = fm_clean_name(basename((string)($_POST['file'] ?? '')));
            $fp = $f ? $absDir . $f : '';
            if ($f && is_file($fp) && !str_starts_with($f, '.')) {
                unlink($fp);
                $notice = 'فایل «' . $f . '» حذف شد.';
            } else {
                $error = 'فایل معتبر نیست.';
            }
        } elseif ($action === 'add_executor') {
            $persId = (int)($_POST['personnel_id'] ?? 0);
            $role = trim((string)($_POST['role'] ?? 'مجری')) ?: 'مجری';
            $exTeam = (int)($_POST['team_id'] ?? 0) ?: null;
            if ($persId <= 0 && !$exTeam) {
                $error = 'نفر یا تیم مجری را انتخاب کنید.';
            } else {
                $persIdVal = ($persId > 0) ? $persId : null;
                $st = $db->prepare('INSERT INTO ProcessExecutors (ProcessID, PersonnelID, TeamID, Role) VALUES (?,?,?,?)');
                $st->bind_param('iiis', $procId, $persIdVal, $exTeam, $role);
                $st->execute(); $st->close();
                $notice = 'مجری افزوده شد.';
            }
        } elseif ($action === 'remove_executor') {
            $exId = (int)($_POST['executor_id'] ?? 0);
            $st = $db->prepare('DELETE FROM ProcessExecutors WHERE ID = ? AND ProcessID = ?');
            $st->bind_param('ii', $exId, $procId);
            $st->execute(); $st->close();
            $notice = 'مجری حذف شد.';
        } elseif ($action === 'add_change') {
            $chType = (string)($_POST['change_type'] ?? 'revision');
            if (!in_array($chType, ['revision','replacement','addition','removal'], true)) $chType = 'revision';
            $chDesc = trim((string)($_POST['description'] ?? ''));
            $nv = trim((string)($_POST['new_version'] ?? ''));
            $chStatus = (string)($_POST['status'] ?? 'pending');
            if (!in_array($chStatus, ['pending','approved','applied','rejected'], true)) $chStatus = 'pending';
            $impId = (int)($_POST['improvement_id'] ?? 0) ?: null;
            if ($chDesc === '') {
                $error = 'شرح تغییر را وارد کنید.';
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
                    $notice = 'تغییر ثبت و نسخه فرآیند به ' . $nv . ' به‌روزرسانی شد.';
                } else {
                    $notice = 'تغییر ثبت شد.';
                }
                $tabBack = 'changes';
            }
        } elseif ($action === 'set_status' && $tabBack === 'info') {
            $newStatus = (string)($_POST['status'] ?? '');
            if (in_array($newStatus, ['draft','active','archived'], true)) {
                $st = $db->prepare('UPDATE Processes SET Status = ?, UpdatedAt = NOW() WHERE ID = ?');
                $st->bind_param('si', $newStatus, $procId);
                $st->execute(); $st->close();
                $proc['Status'] = $newStatus;
                $notice = 'وضعیت فرآیند تغییر کرد.';
            }
        }
    }
    if ($action === 'add_change' || $action === 'upload' || $action === 'delete_doc') {
        // refresh process (version may change)
        $st = $db->prepare('SELECT Version, Status, UpdatedAt FROM Processes WHERE ID = ?');
        $st->bind_param('i', $procId); $st->execute();
        $fresh = $st->get_result()->fetch_assoc(); $st->close();
        if ($fresh) $proc = array_merge($proc, $fresh);
    }
}

$tab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'info');
if (!in_array($tab, ['info','docs','improvements','executors','changes'], true)) $tab = 'info';

/* ---------- شمارش‌ها برای تب‌ها ---------- */
$counts = [];
foreach (['docs' => null, 'improvements' => 'Improvements', 'executors' => 'ProcessExecutors', 'changes' => 'Changes'] as $k => $tbl) {
    if ($k === 'docs') {
        $n = 0;
        if (is_dir($absDir)) foreach (scandir($absDir) as $f) if ($f !== '.' && $f !== '..' && $f !== '.htaccess' && is_file($absDir . $f)) $n++;
        $counts['docs'] = $n;
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
            if (is_file($absDir . $f)) $files[] = ['name' => $f, 'size' => (int)filesize($absDir . $f), 'time' => date('Y/m/d H:i', (int)filemtime($absDir . $f))];
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
    <form method="post" enctype="multipart/form-data" style="margin-top:16px;display:flex;gap:12px;align-items:end;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="tab" value="docs">
        <label class="field" style="margin:0;flex:1;min-width:240px">
            <span class="field-label">بارگذاری مستند جدید (pdf, docx, xls, zip, تصویر و… حداکثر ۲۰ مگابایت)</span>
            <input type="file" name="doc" required>
        </label>
        <button class="btn">⬆ بارگذاری</button>
    </form>
    <?php endif; ?>
</div>

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
                    <td><?= hermes_badge($m['AiVerdict'] ?? 'pending') ?></td>
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
        <button class="btn btn-sm">＋ ثبت تغییر</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php app_footer(); ?>
