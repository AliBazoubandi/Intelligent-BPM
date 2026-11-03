<?php
/**
 * اطلاعات پایه > کاربر جدید (ثبت پرسنل با اجازه‌ها)
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();

$error = '';
$old = ['code' => '', 'name' => '', 'family' => '', 'company_id' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) $error = 'اعتبار صفحه منقضی شده است.';
    else {
        $old = [
            'code' => trim((string)($_POST['code'] ?? '')),
            'name' => trim((string)($_POST['name'] ?? '')),
            'family' => trim((string)($_POST['family'] ?? '')),
            'company_id' => (int)($_POST['company_id'] ?? 0),
        ];
        $password = (string)($_POST['password'] ?? '');
        $canReq = isset($_POST['can_request']) ? 1 : 0;
        $canSearch = isset($_POST['can_search']) ? 1 : 0;
        $canImprove = isset($_POST['can_improve']) ? 1 : 0;
        $canApprove = isset($_POST['can_approve']) ? 1 : 0;

        if ($old['code'] === '' || $old['name'] === '') $error = 'کد پرسنلی و نام الزامی است.';
        elseif (mb_strlen($password) < 6) $error = 'رمز عبور حداقل ۶ نویسه.';
        else {
            $st = $db->prepare('SELECT ID FROM Personnel WHERE PersonnelCode = ? LIMIT 1');
            $st->bind_param('s', $old['code']); $st->execute();
            $dupe = (bool)$st->get_result()->fetch_assoc(); $st->close();
            if ($dupe) $error = 'این کد پرسنلی تکراری است.';
            else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $companyId = $old['company_id'] ?: null;
                $st = $db->prepare(
                    'INSERT INTO Personnel (PersonnelCode, Name, Family, CompanyID, CanRequest, CanSearch, CanImprove, CanApprove, PasswordHash)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $st->bind_param('sssiiiiis', $old['code'], $old['name'], $old['family'], $companyId, $canReq, $canSearch, $canImprove, $canApprove, $hash);
                $st->execute(); $st->close();
                header('Location: basics_users.php?created=' . rawurlencode($old['name'] . ' ' . $old['family']));
                exit;
            }
        }
    }
}
$companies = [];
$res = $db->query('SELECT ID, Name FROM Companies WHERE Active=1 ORDER BY Name');
while ($r = $res->fetch_assoc()) $companies[] = $r;
?>
<?php team_header($id, 'basics_user_new', 'اطلاعات پایه — کاربر جدید'); ?>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width:720px">
    <h2>ثبت کاربر سازمان</h2>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-row">
            <label class="field">
                <span class="field-label">کد پرسنلی</span>
                <input type="text" name="code" required maxlength="20" value="<?= e($old['code']) ?>" placeholder="P-1004">
            </label>
            <label class="field">
                <span class="field-label">نام</span>
                <input type="text" name="name" required maxlength="60" value="<?= e($old['name']) ?>">
            </label>
            <label class="field">
                <span class="field-label">نام خانوادگی</span>
                <input type="text" name="family" maxlength="60" value="<?= e($old['family']) ?>">
            </label>
        </div>
        <div class="form-row">
            <label class="field">
                <span class="field-label">شرکت</span>
                <select name="company_id">
                    <option value="0">— انتخاب —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['ID'] ?>" <?= $old['company_id'] === (int)$c['ID'] ? 'selected' : '' ?>><?= e($c['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">رمز عبور (حداقل ۶ نویسه)</span>
                <input type="password" name="password" required minlength="6" maxlength="100">
            </label>
        </div>
        <span class="field-label">اجازه‌ها</span>
        <div class="chips" style="margin-bottom:14px">
            <?php foreach (['can_request' => 'درخواست', 'can_search' => 'جستجو', 'can_improve' => 'ثبت بهبود', 'can_approve' => 'تأیید'] as $k => $lbl): ?>
                <label class="chip" style="cursor:pointer"><input type="checkbox" name="<?= $k ?>" value="1" style="margin-inline-end:6px" <?= in_array($k, ['can_search', 'can_improve'], true) ? 'checked' : '' ?>> <?= $lbl ?></label>
            <?php endforeach; ?>
        </div>
        <button class="btn">ثبت کاربر</button>
        <a class="btn btn-ghost" href="basics_users.php">لیست کاربران</a>
    </form>
</div>

<?php app_footer(); ?>
