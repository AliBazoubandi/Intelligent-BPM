<?php
/**
 * اطلاعات پایه > لیست کاربران و اتصال پیام‌رسان بله
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();

$notice = '';
$error = '';
$filter = trim((string)($_GET['q'] ?? ''));

$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'ok') {
        $notice = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectUrl = 'basics_users.php' . ($filter !== '' ? '?q=' . urlencode($filter) : '');
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'اعتبار صفحه منقضی شده است.');
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        $pid = (int)($_POST['personnel_id'] ?? 0);
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'toggle') {
            $st = $db->prepare('UPDATE Personnel SET Active = 1 - Active WHERE ID = ?');
            $st->bind_param('i', $pid); $st->execute(); $st->close();
            flash_set('ok', 'وضعیت کاربر تغییر کرد.');
            header('Location: ' . $redirectUrl);
            exit;
        } elseif ($act === 'quick_edit') {
            $baleId = trim((string)($_POST['bale_chat_id'] ?? '')) ?: null;
            $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
            $posId = (int)($_POST['org_position_id'] ?? 0) ?: null;

            $st = $db->prepare('UPDATE Personnel SET BaleChatID = ?, Phone = ?, OrgPositionID = ? WHERE ID = ?');
            $st->bind_param('ssii', $baleId, $phone, $posId, $pid);
            $st->execute();
            $st->close();

            // اگر سمتی انتخاب شده بود، شاغل آن سمت در جدول OrgPositions نیز ثبت شود
            if ($posId) {
                $stP = $db->prepare("UPDATE OrgPositions SET CurrentPersonnelID = ? WHERE ID = ?");
                $stP->bind_param('ii', $pid, $posId);
                $stP->execute();
                $stP->close();
            }

            flash_set('ok', 'اطلاعات بله و سمت سازمانی کاربر به‌روزرسانی شد.');
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}
if (!empty($_GET['created'])) $notice = 'کاربر «' . $_GET['created'] . '» با موفقیت ثبت شد.';

$sql = "SELECT p.*, c.Name AS CompanyName, t.TeamName, op.Title AS OrgPositionTitle, op.Department
        FROM Personnel p
        LEFT JOIN Companies c ON c.ID = p.CompanyID
        LEFT JOIN ProcessTeams t ON t.ID = p.TeamID
        LEFT JOIN OrgPositions op ON op.ID = p.OrgPositionID";
$types = '';
if ($filter !== '') {
    $sql .= ' WHERE p.Name LIKE ? OR p.Family LIKE ? OR p.PersonnelCode LIKE ? OR op.Title LIKE ?';
    $types = 'ssss';
}
$sql .= ' ORDER BY p.PersonnelCode';
$st = $db->prepare($sql);
if ($types) { $like = '%' . $filter . '%'; $st->bind_param($types, $like, $like, $like, $like); }
$st->execute();
$rows = []; $res = $st->get_result();
while ($r = $res->fetch_assoc()) $rows[] = $r;
$st->close();

// لیست سمت‌های سازمانی برای مدال ویرایش سریع
$positions = [];
$res = $db->query('SELECT ID, Title, Department FROM OrgPositions WHERE Active=1 ORDER BY Title');
while ($r = $res->fetch_assoc()) $positions[] = $r;

$csrf = csrf_token();
?>
<?php team_header($id, 'basics_users', 'اطلاعات پایه — لیست کاربران'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="page-head">
    <form method="get" class="inline-form">
        <input type="text" name="q" value="<?= e($filter) ?>" placeholder="جستجوی نام، کد پرسنلی یا سمت…"
               style="padding:8px 12px;border:1px solid var(--input-border, var(--line));border-radius:9px;font:inherit;background:var(--input-bg);color:var(--ink)">
        <button class="btn btn-sm">فیلتر</button>
    </form>
    <div style="display:flex;gap:8px">
        <a class="btn btn-sm" href="basics_user_new.php">＋ کاربر جدید</a>
    </div>
</div>

<div class="card">
    <h2>کاربران سازمان (<?= count($rows) ?>)</h2>
    <div class="table-wrap">
    <table class="data">
        <thead>
            <tr>
                <th>کد پرسنلی</th>
                <th>نام و نام خانوادگی</th>
                <th>سمت در چارت</th>
                <th>شناسه بله / تماس</th>
                <th>شرکت</th>
                <th>اجازه‌ها</th>
                <th>وضعیت</th>
                <th style="text-align:left">عملیات</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php 
                $baleId = $r['BaleChatID'] ?: ($r['Phone'] ?: '');
            ?>
            <tr>
                <td><span class="num-ltr title"><?= e($r['PersonnelCode']) ?></span></td>
                <td>
                    <strong><?= e($r['Name'] . ' ' . $r['Family']) ?></strong>
                </td>
                <td>
                    <?php if (!empty($r['OrgPositionTitle'])): ?>
                        <span class="badge" style="background:var(--brand-soft);color:var(--brand);font-weight:700">
                            <?= e($r['OrgPositionTitle']) ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($baleId !== ''): ?>
                        <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11.5px;direction:ltr;display:inline-flex;align-items:center;gap:4px">
                            <?= app_icon('bot', '', 12) ?> <?= e($baleId) ?>
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:11px">بدون شناسه بله</span>
                    <?php endif; ?>
                </td>
                <td><?= e($r['CompanyName'] ?? '—') ?></td>
                <td>
                    <?php
                    $permMap = [['CanRequest', 'درخواست'], ['CanSearch', 'جستجو'], ['CanImprove', 'بهبود'], ['CanApprove', 'تأیید']];
                    foreach ($permMap as [$col, $lbl]):
                        if ((int)$r[$col]): ?><span class="badge badge-user" style="margin-inline-end:4px"><?= $lbl ?></span><?php endif;
                    endforeach;
                    if (!$r['CanRequest'] && !$r['CanSearch'] && !$r['CanImprove'] && !$r['CanApprove']) echo '—';
                    ?>
                </td>
                <td><span class="badge <?= (int)$r['Active'] ? 'badge-active' : 'badge-archived' ?>"><?= (int)$r['Active'] ? 'فعال' : 'غیرفعال' ?></span></td>
                <td style="text-align:left">
                    <div style="display:inline-flex;gap:4px">
                        <button type="button" class="btn btn-xs btn-ghost" onclick='openQuickEdit(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                            ویرایش بله و سمت
                        </button>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="personnel_id" value="<?= (int)$r['ID'] ?>">
                            <button class="btn btn-ghost btn-xs"><?= (int)$r['Active'] ? 'غیرفعال' : 'فعال' ?></button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- مدال ویرایش سریع اطلاعات بله و سمت پرسنل -->
<div id="modalQuickEdit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;padding:16px">
    <div class="card" style="width:100%;max-width:480px;box-shadow:0 20px 50px rgba(0,0,0,0.35);border-radius:16px;animation:modalIn .2s ease">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h2 style="margin:0;font-size:16px" id="quickEditHeading">ویرایش سمت سازمانی و حساب بله کاربر</h2>
            <button type="button" class="btn btn-ghost btn-xs" onclick="closeQuickEdit()" style="font-size:16px;line-height:1">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="quick_edit">
            <input type="hidden" name="personnel_id" id="quickPersId" value="0">

            <label class="field">
                <span class="field-label">سمت در چارت سازمانی</span>
                <select name="org_position_id" id="quickOrgPosId">
                    <option value="0">— بدون سمت —</option>
                    <?php foreach ($positions as $p): ?>
                        <option value="<?= (int)$p['ID'] ?>"><?= e($p['Title']) ?><?= !empty($p['Department']) ? ' (' . e($p['Department']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field-label">شناسه / چت‌آیدی بله (Bale Chat ID)</span>
                <input type="text" name="bale_chat_id" id="quickBaleId" placeholder="مثلاً 123456789 یا نام کاربری بله" class="num-ltr" style="text-align:left">
                <small style="color:var(--muted);display:block;margin-top:3px">پیام‌های تغییر فرآیند به این شناسه در پیام‌رسان بله ارسال خواهد شد.</small>
            </label>

            <label class="field">
                <span class="field-label">شماره تلفن همراه</span>
                <input type="text" name="phone" id="quickPhone" placeholder="0912..." class="num-ltr" style="text-align:left">
            </label>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
                <button type="button" class="btn btn-ghost" onclick="closeQuickEdit()">انصراف</button>
                <button type="submit" class="btn">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<script>
function openQuickEdit(user) {
    document.getElementById('quickPersId').value = user.ID;
    document.getElementById('quickOrgPosId').value = user.OrgPositionID || 0;
    document.getElementById('quickBaleId').value = user.BaleChatID || '';
    document.getElementById('quickPhone').value = user.Phone || '';
    document.getElementById('quickEditHeading').textContent = 'ویرایش بله و سمت: ' + user.Name + ' ' + (user.Family || '');
    document.getElementById('modalQuickEdit').style.display = 'flex';
}
function closeQuickEdit() {
    document.getElementById('modalQuickEdit').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeQuickEdit();
});
</script>

<?php app_footer(); ?>
