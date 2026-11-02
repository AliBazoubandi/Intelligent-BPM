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
$old = ['name' => '', 'category_id' => 0, 'company_id' => 0, 'owner_id' => 0, 'version' => '1.0', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } else {
        $old = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'company_id' => (int)($_POST['company_id'] ?? 0),
            'owner_id' => (int)($_POST['owner_id'] ?? 0),
            'version' => trim((string)($_POST['version'] ?? '1.0')) ?: '1.0',
            'description' => trim((string)($_POST['description'] ?? '')),
        ];
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
                $ownerId = $old['owner_id'] ?: null;
                $st = $db->prepare(
                    "INSERT INTO Processes (Name, CategoryID, CompanyID, FilePath, OwnerPersonnelID, ResponsibleTeamID, Description, Status, Version)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('siisiisss', $cleanName, $old['category_id'], $companyId, $rel, $ownerId, $tid, $old['description'], $status, $old['version']);
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
                <span class="field-label">مالک فرآیند (پرسنل)</span>
                <select name="owner_id">
                    <option value="0">— انتخاب —</option>
                    <?php foreach ($persons as $p): ?>
                        <option value="<?= (int)$p['ID'] ?>" <?= $old['owner_id'] === (int)$p['ID'] ? 'selected' : '' ?>><?= e($p['Name'] . ' ' . $p['Family']) ?> (<?= e($p['PersonnelCode']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="form-row">
            <label class="field">
                <span class="field-label">نسخه اولیه</span>
                <input type="text" name="version" maxlength="20" value="<?= e($old['version']) ?>">
            </label>
            <label class="field">
                <span class="field-label">وضعیت</span>
                <select name="status">
                    <option value="draft">پیش‌نویس</option>
                    <option value="active">فعال</option>
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
