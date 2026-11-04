<?php
/**
 * اطلاعات پایه > چارت سازمانی و سمت‌های شغلی
 * مدیریت ساختار سازمانی، تخصیص شاغلین به سمت‌ها و اتصال به فرآیندها جهت اطلاع‌رسانی خودکار در بله
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/bale.php';

$id = require_area('team');
$db = app_db();

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

/* ---------- پردازش فرم‌های ارسالی (ایجاد / ویرایش / تغییر شاغل) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        flash_set('error', 'اعتبار صفحه منقضی شده است.');
        header('Location: basics_chart.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_position') {
        $title = trim((string)($_POST['title'] ?? ''));
        $dept = trim((string)($_POST['department'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
        $companyId = (int)($_POST['company_id'] ?? 0) ?: null;
        $persId = (int)($_POST['personnel_id'] ?? 0) ?: null;
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($title === '') {
            flash_set('error', 'عنوان سمت سازمانی الزامی است.');
        } else {
            $st = $db->prepare(
                "INSERT INTO OrgPositions (CompanyID, Title, Department, ParentID, CurrentPersonnelID, Description)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $st->bind_param('issiis', $companyId, $title, $dept, $parentId, $persId, $desc);
            $st->execute();
            $newPosId = (int)$db->insert_id;
            $st->close();

            // در صورت انتخاب پرسنل، سمت سازمانی کاربر نیز به‌روزرسانی شود
            if ($persId) {
                $stU = $db->prepare("UPDATE Personnel SET OrgPositionID = ? WHERE ID = ?");
                $stU->bind_param('ii', $newPosId, $persId);
                $stU->execute();
                $stU->close();
            }

            flash_set('ok', 'سمت سازمانی «' . $title . '» با موفقیت در چارت ثبت شد.');
        }
        header('Location: basics_chart.php');
        exit;
    } elseif ($action === 'edit_position') {
        $posId = (int)($_POST['position_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $dept = trim((string)($_POST['department'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
        $persId = (int)($_POST['personnel_id'] ?? 0) ?: null;
        $desc = trim((string)($_POST['description'] ?? ''));

        // جلوگیری از ایجاد حلقه والد با خودش
        if ($parentId === $posId) {
            $parentId = null;
        }

        if ($posId <= 0 || $title === '') {
            flash_set('error', 'اطلاعات سمت نامعتبر است.');
        } else {
            $st = $db->prepare(
                "UPDATE OrgPositions 
                 SET Title = ?, Department = ?, ParentID = ?, CurrentPersonnelID = ?, Description = ?, UpdatedAt = NOW()
                 WHERE ID = ?"
            );
            $st->bind_param('ssiisi', $title, $dept, $parentId, $persId, $desc, $posId);
            $st->execute();
            $st->close();

            // به‌روزرسانی سمت در جدول پرسنل
            if ($persId) {
                $stU = $db->prepare("UPDATE Personnel SET OrgPositionID = ? WHERE ID = ?");
                $stU->bind_param('ii', $posId, $persId);
                $stU->execute();
                $stU->close();
            }

            flash_set('ok', 'سمت «' . $title . '» و انتصاب شاغل با موفقیت ذخیره شد.');
        }
        header('Location: basics_chart.php');
        exit;
    } elseif ($action === 'toggle_active') {
        $posId = (int)($_POST['position_id'] ?? 0);
        $st = $db->prepare("UPDATE OrgPositions SET Active = 1 - Active WHERE ID = ?");
        $st->bind_param('i', $posId);
        $st->execute();
        $st->close();
        flash_set('ok', 'وضعیت سمت تغییر کرد.');
        header('Location: basics_chart.php');
        exit;
    }
}

/* ---------- استخراج داده‌های چارت سازمانی ---------- */
$positionsQuery = "
    SELECT op.*, 
           p.PersonnelCode, p.Name AS PersName, p.Family AS PersFamily, p.BaleChatID, p.Phone,
           parent.Title AS ParentTitle,
           (SELECT COUNT(*) FROM Processes proc WHERE proc.OwnerPositionID = op.ID) AS ProcessCount
    FROM OrgPositions op
    LEFT JOIN Personnel p ON p.ID = op.CurrentPersonnelID
    LEFT JOIN OrgPositions parent ON parent.ID = op.ParentID
    ORDER BY op.SortOrder ASC, op.ID ASC
";
$res = $db->query($positionsQuery);
$positions = [];
$deptList = [];
while ($r = $res->fetch_assoc()) {
    $positions[] = $r;
    if (!empty($r['Department']) && !in_array($r['Department'], $deptList, true)) {
        $deptList[] = $r['Department'];
    }
}

// لیست پرسنل جهت انتصاب به سمت‌ها
$personnelList = [];
$resPers = $db->query("SELECT ID, PersonnelCode, Name, Family, BaleChatID, Phone, OrgPositionID FROM Personnel WHERE Active = 1 ORDER BY Name, Family");
while ($p = $resPers->fetch_assoc()) {
    $personnelList[] = $p;
}

// آمار کلی
$totalPositions = count($positions);
$occupiedPositions = count(array_filter($positions, fn($p) => !empty($p['CurrentPersonnelID'])));
$vacantPositions = $totalPositions - $occupiedPositions;
$totalAssignedProcesses = array_sum(array_column($positions, 'ProcessCount'));

$csrf = csrf_token();
?>
<?php team_header($id, 'basics_chart', 'چارت سازمانی و سمت‌ها'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<!-- کارت‌های آمار و خلاصه وضعیت -->
<div class="stats-grid" style="margin-bottom:18px">
    <div class="stat-card">
        <span class="stat-ico" style="background:#e0e7ff;color:#4338ca"><?= app_icon('basics', '', 20) ?></span>
        <div>
            <div class="stat-val"><?= farsi_num($totalPositions) ?></div>
            <div class="stat-lbl">کل سمت‌های چارت</div>
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-ico" style="background:#dcfce7;color:#15803d"><?= app_icon('users', '', 20) ?></span>
        <div>
            <div class="stat-val"><?= farsi_num($occupiedPositions) ?></div>
            <div class="stat-lbl">سمت‌های دارای شاغل فعال</div>
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-ico" style="background:#fef3c7;color:#b45309"><?= app_icon('changes', '', 20) ?></span>
        <div>
            <div class="stat-val"><?= farsi_num($vacantPositions) ?></div>
            <div class="stat-lbl">سمت‌های بدون شاغل (بلاتصدی)</div>
        </div>
    </div>
    <div class="stat-card">
        <span class="stat-ico" style="background:#ccfbf1;color:#0f766e"><?= app_icon('processes', '', 20) ?></span>
        <div>
            <div class="stat-val"><?= farsi_num($totalAssignedProcesses) ?></div>
            <div class="stat-lbl">فرآیند متصل به چارت</div>
        </div>
    </div>
</div>

<div class="page-head" style="margin-bottom:16px">
    <div>
        <h1 style="font-size:18px;margin:0 0 4px">چارت سازمانی و متولیان فرآیند</h1>
        <div class="sub">با اتصال فرآیندها به سمت‌های این چارت، هنگام هرگونه بازنگری یا تغییر، پیام اطلاع‌رسانی خودکار از طریق پیام‌رسان بله به متولی فعلی آن سمت و مخاطبان اثرپذیر ارسال می‌شود.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-sm btn-ghost" href="basics_bale.php" title="تنظیمات وب‌سرویس و تست ارسال پیام">
            <?= app_icon('bot', '', 14) ?> تنظیمات پیام‌رسان بله
        </a>
        <button type="button" class="btn btn-sm" onclick="openNewPositionModal()">
            <?= app_icon('plus_circle', '', 14) ?> ＋ سمت سازمانی جدید
        </button>
    </div>
</div>

<!-- جدول کامل سمت‌های چارت سازمانی -->
<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap">
        <h2 style="margin:0">سمت‌های سازمانی و شاغلین (<?= farsi_num($totalPositions) ?>)</h2>
        <div style="display:flex;align-items:center;gap:8px">
            <input type="text" id="chartSearchInput" placeholder="جستجوی سمت یا شاغل…" oninput="filterChartTable()" style="padding:6px 12px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:12.5px;min-width:200px">
        </div>
    </div>

    <div class="table-wrap">
        <table class="data" id="chartTable">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>عنوان سمت سازمانی</th>
                    <th>واحد سازمانی</th>
                    <th>سمت بالادستی (گزارش به)</th>
                    <th>شاغل فعلی (متولی)</th>
                    <th>شناسه بله / تماس</th>
                    <th style="text-align:center">فرآیندهای متولی</th>
                    <th>وضعیت</th>
                    <th style="text-align:left">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$positions): ?>
                    <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--muted)">سمتی در چارت سازمانی تعریف نشده است.</td></tr>
                <?php else: ?>
                    <?php foreach ($positions as $idx => $pos): ?>
                        <?php 
                            $hasPers = !empty($pos['CurrentPersonnelID']);
                            $fullName = $hasPers ? trim($pos['PersName'] . ' ' . $pos['PersFamily']) : '—';
                            $baleId = $pos['BaleChatID'] ?: ($pos['Phone'] ?: '');
                        ?>
                        <tr data-title="<?= e(mb_strtolower($pos['Title'])) ?>" data-person="<?= e(mb_strtolower($fullName)) ?>" data-dept="<?= e(mb_strtolower($pos['Department'] ?? '')) ?>">
                            <td class="num-ltr" style="color:var(--muted);font-size:12px"><?= farsi_num($idx + 1) ?></td>
                            <td>
                                <strong style="font-size:13.5px;color:var(--ink)"><?= e($pos['Title']) ?></strong>
                                <?php if (!empty($pos['Description'])): ?>
                                    <div style="font-size:11.5px;color:var(--muted);margin-top:2px"><?= e($pos['Description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($pos['Department'])): ?>
                                    <span class="badge" style="background:var(--brand-soft);color:var(--brand);font-weight:600"><?= e($pos['Department']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($pos['ParentTitle']) ? e($pos['ParentTitle']) : '<span style="color:var(--muted)">رأس هرم</span>' ?>
                            </td>
                            <td>
                                <?php if ($hasPers): ?>
                                    <div style="display:flex;align-items:center;gap:7px">
                                        <span class="avatar avatar-compact" style="width:24px;height:24px;font-size:11px"><?= e(mb_substr($pos['PersName'], 0, 1)) ?></span>
                                        <div>
                                            <span style="font-weight:700"><?= e($fullName) ?></span>
                                            <span class="num-ltr" style="font-size:11px;color:var(--muted);margin-inline-start:4px">(<?= e($pos['PersonnelCode']) ?>)</span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="badge" style="background:#fef3c7;color:#92400e;font-size:11px">بلاتصدی (بدون شاغل)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($baleId !== ''): ?>
                                    <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11px;direction:ltr;display:inline-flex;align-items:center;gap:4px">
                                        <?= app_icon('bot', '', 12) ?> <?= e($baleId) ?>
                                    </span>
                                <?php elseif ($hasPers): ?>
                                    <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:11px" title="جهت ارسال پیام به این شاغل در بله، شماره یا Chat ID وی را در بخش کاربران وارد کنید">
                                        فاقد شناسه بله
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--muted)">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center">
                                <?php if ((int)$pos['ProcessCount'] > 0): ?>
                                    <a href="processes.php?q=<?= urlencode($pos['Title']) ?>" class="badge badge-active" style="text-decoration:none;font-weight:700">
                                        <?= farsi_num($pos['ProcessCount']) ?> فرآیند
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--muted);font-size:12px">۰</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= (int)$pos['Active'] ? 'badge-active' : 'badge-archived' ?>">
                                    <?= (int)$pos['Active'] ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td style="text-align:left">
                                <div style="display:inline-flex;gap:4px">
                                    <button type="button" class="btn btn-xs btn-ghost" onclick='editPosition(<?= json_encode($pos, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                                        ویرایش / انتصاب
                                    </button>
                                    <form method="post" style="display:inline" onsubmit="return confirm('آیا از تغییر وضعیت این سمت اطمینان دارید؟');">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="position_id" value="<?= (int)$pos['ID'] ?>">
                                        <button class="btn btn-xs btn-ghost" style="color:var(--muted)">
                                            <?= (int)$pos['Active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مدال / دیالوگ افزودن سمت جدید -->
<div id="modalNewPos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;padding:16px">
    <div class="card" style="width:100%;max-width:540px;box-shadow:0 20px 50px rgba(0,0,0,0.35);border-radius:16px;animation:modalIn .2s ease">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h2 style="margin:0;font-size:16px">＋ تعریف سمت سازمانی جدید در چارت</h2>
            <button type="button" class="btn btn-ghost btn-xs" onclick="closeNewPositionModal()" style="font-size:16px;line-height:1">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="create_position">

            <label class="field">
                <span class="field-label">عنوان سمت شغلی <span style="color:var(--danger)">*</span></span>
                <input type="text" name="title" required placeholder="مثلاً مدیر بازرگانی، مدیر مالی، سرپرست انبار…" maxlength="150">
            </label>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">واحد / دپارتمان سازمانی</span>
                    <input type="text" name="department" placeholder="مثلاً بازرگانی، اداری و مالی، منابع انسانی" list="deptSuggestions" maxlength="100">
                    <datalist id="deptSuggestions">
                        <?php foreach ($deptList as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?>
                    </datalist>
                </label>
                <label class="field">
                    <span class="field-label">سمت بالادستی در چارت</span>
                    <select name="parent_id">
                        <option value="0">— بدون بالادست (رأس هرم سازمانی) —</option>
                        <?php foreach ($positions as $p): ?>
                            <option value="<?= (int)$p['ID'] ?>"><?= e($p['Title']) ?><?= !empty($p['Department']) ? ' (' . e($p['Department']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label class="field">
                <span class="field-label">شاغل فعلی سمت (شخص متولی)</span>
                <select name="personnel_id">
                    <option value="0">— فعلاً بلاتصدی (بدون شاغل) —</option>
                    <?php foreach ($personnelList as $pers): ?>
                        <option value="<?= (int)$pers['ID'] ?>"><?= e($pers['Name'] . ' ' . $pers['Family']) ?> (کد: <?= e($pers['PersonnelCode']) ?>) <?= !empty($pers['BaleChatID']) ? '✓ بله' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span class="field-label">شرح اختیارات و حوزه مسئولیت فرآیندی</span>
                <textarea name="description" rows="2" placeholder="توضیح مختصر در خصوص مسئولیت‌های این سمت…"></textarea>
            </label>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                <button type="button" class="btn btn-ghost" onclick="closeNewPositionModal()">انصراف</button>
                <button type="submit" class="btn">ثبت سمت در چارت</button>
            </div>
        </form>
    </div>
</div>

<!-- مدال / دیالوگ ویرایش سمت و تغییر شاغل -->
<div id="modalEditPos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9000;align-items:center;justify-content:center;padding:16px">
    <div class="card" style="width:100%;max-width:540px;box-shadow:0 20px 50px rgba(0,0,0,0.35);border-radius:16px;animation:modalIn .2s ease">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <h2 style="margin:0;font-size:16px" id="editModalHeading">ویرایش سمت سازمانی و انتصاب شاغل</h2>
            <button type="button" class="btn btn-ghost btn-xs" onclick="closeEditPositionModal()" style="font-size:16px;line-height:1">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="edit_position">
            <input type="hidden" name="position_id" id="editPosId" value="0">

            <label class="field">
                <span class="field-label">عنوان سمت شغلی <span style="color:var(--danger)">*</span></span>
                <input type="text" name="title" id="editTitle" required maxlength="150">
            </label>

            <div class="form-row">
                <label class="field">
                    <span class="field-label">واحد / دپارتمان سازمانی</span>
                    <input type="text" name="department" id="editDepartment" list="deptSuggestions" maxlength="100">
                </label>
                <label class="field">
                    <span class="field-label">سمت بالادستی در چارت</span>
                    <select name="parent_id" id="editParentId">
                        <option value="0">— بدون بالادست (رأس هرم سازمانی) —</option>
                        <?php foreach ($positions as $p): ?>
                            <option value="<?= (int)$p['ID'] ?>"><?= e($p['Title']) ?><?= !empty($p['Department']) ? ' (' . e($p['Department']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <label class="field">
                <span class="field-label">شاغل فعلی سمت (شخص متولی پیام‌ها و فرآیندها)</span>
                <select name="personnel_id" id="editPersonnelId">
                    <option value="0">— بلاتصدی (بدون شاغل) —</option>
                    <?php foreach ($personnelList as $pers): ?>
                        <option value="<?= (int)$pers['ID'] ?>"><?= e($pers['Name'] . ' ' . $pers['Family']) ?> (کد: <?= e($pers['PersonnelCode']) ?>) <?= !empty($pers['BaleChatID']) ? '✓ بله' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color:var(--muted);margin-top:4px;display:block">
                    💡 با تغییر شاغل این سمت، پیام‌های بله مربوط به تمام فرآیندهایی که متولی آن این سمت است، به صورت خودکار به شاغل جدید ارسال خواهد شد.
                </small>
            </label>

            <label class="field">
                <span class="field-label">شرح اختیارات و حوزه مسئولیت</span>
                <textarea name="description" id="editDescription" rows="2"></textarea>
            </label>

            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                <button type="button" class="btn btn-ghost" onclick="closeEditPositionModal()">انصراف</button>
                <button type="submit" class="btn">ذخیره تغییرات سمت</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewPositionModal() {
    const m = document.getElementById('modalNewPos');
    m.style.display = 'flex';
}
function closeNewPositionModal() {
    document.getElementById('modalNewPos').style.display = 'none';
}

function editPosition(pos) {
    document.getElementById('editPosId').value = pos.ID;
    document.getElementById('editTitle').value = pos.Title || '';
    document.getElementById('editDepartment').value = pos.Department || '';
    document.getElementById('editParentId').value = pos.ParentID || 0;
    document.getElementById('editPersonnelId').value = pos.CurrentPersonnelID || 0;
    document.getElementById('editDescription').value = pos.Description || '';
    document.getElementById('editModalHeading').textContent = 'ویرایش سمت: ' + (pos.Title || '');
    document.getElementById('modalEditPos').style.display = 'flex';
}
function closeEditPositionModal() {
    document.getElementById('modalEditPos').style.display = 'none';
}

function filterChartTable() {
    const q = (document.getElementById('chartSearchInput').value || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#chartTable tbody tr');
    rows.forEach(tr => {
        const title = tr.getAttribute('data-title') || '';
        const person = tr.getAttribute('data-person') || '';
        const dept = tr.getAttribute('data-dept') || '';
        if (q === '' || title.includes(q) || person.includes(q) || dept.includes(q)) {
            tr.style.display = '';
        } else {
            tr.style.display = 'none';
        }
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeNewPositionModal();
        closeEditPositionModal();
    }
});
</script>

<?php app_footer(); ?>
