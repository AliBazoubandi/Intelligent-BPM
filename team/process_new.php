<?php
/**
 * فرآیند جدید — پنل تیم (ساخت رکورد + پوشه فایل‌ها در processes/)
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/files.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$error = '';
$old = [
    'name' => '', 'category_id' => 0, 'company_id' => 0, 'owner_position_id' => 0,
    'owner_id' => 0, 'audience_type' => 'all_company', 'audience_dept' => '',
    'version' => '1.0', 'description' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } else {
        $old = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'company_id' => (int)($_POST['company_id'] ?? 0),
            'owner_position_id' => (int)($_POST['owner_position_id'] ?? 0),
            'owner_id' => (int)($_POST['owner_id'] ?? 0),
            'audience_type' => (string)($_POST['audience_type'] ?? 'all_company'),
            'audience_dept' => trim((string)($_POST['audience_dept'] ?? '')),
            'version' => trim((string)($_POST['version'] ?? '1.0')) ?: '1.0',
            'description' => trim((string)($_POST['description'] ?? '')),
        ];
        if (!in_array($old['audience_type'], ['all_company', 'specific_positions', 'specific_personnel', 'department'], true)) {
            $old['audience_type'] = 'all_company';
        }
        $status = (string)($_POST['status'] ?? 'draft');
        if (!in_array($status, ['draft','active','archived'], true)) $status = 'draft';

        $catFolder = '';
        $st = $db->prepare('SELECT FolderName FROM ProcessCategories WHERE ID = ? AND Active = 1');
        $st->bind_param('i', $old['category_id']); $st->execute();
        $cat = $st->get_result()->fetch_assoc(); $st->close();

        $cleanName = fm_clean_name($old['name']);
        if ($cleanName === null || mb_strlen($cleanName) < 3) {
            $error = 'نام فرآیند را کامل‌تر وارد کنید (حداقل ۳ نویسه بدون کاراکترهای غیرمجاز).';
        } elseif (!$cat) {
            $error = 'دسته فرآیند معتبر نیست.';
        } else {
            $old['name'] = $cleanName;
            $st = $db->prepare('SELECT ID FROM Processes WHERE Name = ? LIMIT 1');
            $st->bind_param('s', $cleanName); $st->execute();
            if ($st->get_result()->fetch_assoc()) { $st->close(); $error = 'فرآیندی با این نام قبلاً ثبت شده است.'; }
            else {
                $st->close();
                $catFolder = $cat['FolderName'];
                $rel = $catFolder . '/' . $cleanName . '/';
                $abs = fm_abs($rel);
                if (!is_dir($abs)) {
                    @mkdir($abs, 0775, true);
                    @file_put_contents($abs . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
                }

                $companyId = $old['company_id'] ?: null;
                $ownerPosId = $old['owner_position_id'] ?: null;
                $ownerId = $old['owner_id'] ?: null;
                $audDept = ($old['audience_type'] === 'department' && $old['audience_dept'] !== '') ? $old['audience_dept'] : null;

                $st = $db->prepare(
                    "INSERT INTO Processes (Name, CategoryID, CompanyID, FilePath, OwnerPersonnelID, OwnerPositionID, ResponsibleTeamID, Description, AudienceType, AudienceDepartment, Status, Version)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('siisiissssss', $cleanName, $old['category_id'], $companyId, $rel, $ownerId, $ownerPosId, $tid, $old['description'], $old['audience_type'], $audDept, $status, $old['version']);
                $st->execute();
                $newId = (int)$db->insert_id;
                $st->close();
                header('Location: file.php?id=' . $newId . '&created=1');
                exit;
            }
        }
    }
}

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
?>
<?php team_header($id, 'process_new', 'فرآیند جدید'); ?>

<div class="card" style="max-width:760px">
    <h2>ثبت فرآیند جدید</h2>
    <p style="color:var(--muted);font-size:13px;margin-top:0">با ثبت این فرم، یک پوشه به نام فرآیند در دایرکتوری <span class="num-ltr">processes/</span> زیرمجموعه دسته انتخابی ساخته می‌شود.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
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
                <select name="audience_type" id="audienceTypeSelect" onchange="toggleAudienceDept(this.value)">
                    <option value="all_company" <?= $old['audience_type'] === 'all_company' ? 'selected' : '' ?>>همه پرسنل شرکت (عمومی — مانند مرخصی، مأموریت و ...)</option>
                    <option value="specific_positions" <?= $old['audience_type'] === 'specific_positions' ? 'selected' : '' ?>>سمت‌های اثرپذیر از چارت (تخصصی)</option>
                    <option value="specific_personnel" <?= $old['audience_type'] === 'specific_personnel' ? 'selected' : '' ?>>افراد و پرسنل مشخص اثرپذیر</option>
                    <option value="department" <?= $old['audience_type'] === 'department' ? 'selected' : '' ?>>واحد / دپارتمان سازمانی مشخص</option>
                </select>
            </label>
            <label class="field" id="audienceDeptWrap" style="display:<?= $old['audience_type'] === 'department' ? 'block' : 'none' ?>">
                <span class="field-label">نام دپارتمان اثرپذیر</span>
                <input type="text" name="audience_dept" value="<?= e($old['audience_dept']) ?>" placeholder="مثلاً بازرگانی یا فناوری اطلاعات">
            </label>
        </div>
        <script>
        function toggleAudienceDept(val) {
            document.getElementById('audienceDeptWrap').style.display = (val === 'department') ? 'block' : 'none';
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
                    <option value="draft" <?= $old['version'] === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                    <option value="active">فعال</option>
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

<?php app_footer(); ?>
