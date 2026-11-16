<?php
/**
 * جستجوی فرآیندها — نیازمند اجازه جستجو
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('user');
$db = app_db();

if (empty($id['perms']['search'])) {
    user_header($id, 'search', 'جستجو');
    echo '<div class="card"><div class="empty"><span class="ico">🔒</span><p>حساب شما اجازه جستجو ندارد. برای دریافت دسترسی با تیم فرآیند تماس بگیرید.</p></div></div>';
    app_footer();
    exit;
}

$uid = (int)($id['id'] ?? 0);
$userCompanyId = !empty($id['company_id']) ? (int)$id['company_id'] : null;
$accessCtx = user_load_access_context($db, $uid);

$q = trim((string)($_GET['q'] ?? ''));
$results = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';
    $st = $db->prepare(
        "SELECT p.*, cat.Title AS CatTitle, c.Name AS CompanyName
         FROM Processes p
         JOIN ProcessCategories cat ON cat.ID = p.CategoryID
         LEFT JOIN Companies c ON c.ID = p.CompanyID
         WHERE p.Status = 'active'
           AND (p.CompanyID IS NULL OR p.CompanyID = ?)
           AND (p.Name LIKE ? OR p.Description LIKE ? OR cat.Title LIKE ? OR c.Name LIKE ?)
         ORDER BY p.Name LIMIT 100"
    );
    $st->bind_param('issss', $userCompanyId, $like, $like, $like, $like);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        if (process_user_can_access($r, $uid, $userCompanyId, $accessCtx['pos_id'], $accessCtx['dept'], $accessCtx['aud_proc_ids'])) {
            $results[] = $r;
        }
    }
    $st->close();
}
?>
<?php user_header($id, 'search', 'جستجوی فرآیندها'); ?>

<div class="card">
    <form method="get">
        <label class="field">
            <span class="field-label">عبارت جستجو</span>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="نام فرآیند، دسته، شرکت یا شرح… (حداقل ۲ نویسه)" autofocus>
        </label>
        <button type="submit" class="btn" style="display:inline-flex;align-items:center;gap:6px">
            <?= app_icon('search', '', 15) ?>
            <span>جستجو</span>
        </button>
    </form>
</div>

<?php if (mb_strlen($q) >= 2): ?>
    <div class="card">
        <h2>نتایج «<?= e($q) ?>» <span class="sub">(<?= farsi_num(count($results)) ?> مورد)</span></h2>
        <?php if (!$results): ?>
            <div class="empty"><span class="ico"><?= app_icon('search', '', 36) ?></span><p>موردی یافت نشد. عبارت دیگری را امتحان کنید.</p></div>
        <?php else: ?>
            <div class="table-wrap">
            <table class="data">
                <thead><tr><th>فرآیند</th><th>دسته</th><th>شرکت</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><span class="title"><?= e($r['Name']) ?></span><br><span class="sub"><?= e(mb_strimwidth(strip_tags((string)$r['Description']), 0, 90, '…')) ?></span></td>
                        <td><?= e($r['CatTitle']) ?></td>
                        <td><?= e($r['CompanyName'] ?? '—') ?></td>
                        <td><span class="badge badge-<?= e($r['Status']) ?>"><?= status_label($r['Status']) ?></span></td>
                        <td><a class="btn btn-ghost btn-sm" href="processes.php?id=<?= (int)$r['ID'] ?>">مشاهده</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php app_footer(); ?>
