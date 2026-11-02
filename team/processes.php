<?php
/**
 * لیست فرآیندها — پنل تیم (با کلید مشاهده پرونده)
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
$id = require_area('team');
$db = app_db();

$categories = active_categories($db);
$catId = (int)($_GET['cat'] ?? 0);

$sql = "SELECT p.ID, p.Name, p.Version, p.Status, p.UpdatedAt, p.Description, cat.Title AS CatTitle, t.TeamName
        FROM Processes p
        JOIN ProcessCategories cat ON cat.ID = p.CategoryID
        LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID";
$types = '';
if ($catId > 0) { $sql .= ' WHERE p.CategoryID = ?'; $types = 'i'; }
$sql .= ' ORDER BY cat.SortOrder, p.Name';

$st = $db->prepare($sql);
if ($types) $st->bind_param($types, $catId);
$st->execute();
$procs = [];
$res = $st->get_result();
while ($r = $res->fetch_assoc()) $procs[] = $r;
$st->close();

$cnt = [];
$res = $db->query('SELECT CategoryID, COUNT(*) c FROM Processes GROUP BY CategoryID');
while ($r = $res->fetch_assoc()) $cnt[(int)$r['CategoryID']] = (int)$r['c'];
?>
<?php team_header($id, 'processes', 'لیست فرآیندها'); ?>

<div class="page-head" style="margin-bottom:10px">
    <div class="chips" style="margin:0">
        <a class="chip <?= $catId === 0 ? 'active' : '' ?>" href="processes.php">همه دسته‌ها <span class="cnt">(<?= farsi_num(count($procs)) ?>)</span></a>
        <?php foreach ($categories as $c): ?>
            <a class="chip <?= $catId === (int)$c['ID'] ? 'active' : '' ?>" href="processes.php?cat=<?= (int)$c['ID'] ?>" style="display:inline-flex;align-items:center;gap:6px">
                <?= app_icon('files', '', 14) ?>
                <span><?= e($c['Title']) ?></span>
                <span class="cnt">(<?= farsi_num($cnt[(int)$c['ID']] ?? 0) ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
    <a class="btn btn-sm" href="process_new.php" style="display:inline-flex;align-items:center;gap:6px">
        <?= app_icon('plus_circle', '', 15) ?>
        <span>فرآیند جدید</span>
    </a>
</div>

<div class="card">
    <?php if (!$procs): ?>
        <div class="empty"><span class="ico"><?= app_icon('processes', '', 36) ?></span><p>فرآیندی در این دسته ثبت نشده است.</p><p><a href="process_new.php">ایجاد فرآیند جدید</a></p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>فرآیند</th><th>دسته</th><th>تیم مسئول</th><th>نسخه</th><th>وضعیت</th><th>آخرین به‌روزرسانی</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($procs as $p): ?>
                <tr>
                    <td><span class="title"><?= e($p['Name']) ?></span><?php if ($p['Description']): ?><br><span class="sub"><?= e(mb_strimwidth(strip_tags((string)$p['Description']), 0, 80, '…')) ?></span><?php endif; ?></td>
                    <td><?= e($p['CatTitle']) ?></td>
                    <td><?= e($p['TeamName'] ?? '—') ?></td>
                    <td><span class="num-ltr"><?= farsi_num($p['Version']) ?></span></td>
                    <td><span class="badge badge-<?= e($p['Status']) ?>"><?= status_label($p['Status']) ?></span></td>
                    <td><?= e(fmt_date($p['UpdatedAt'])) ?></td>
                    <td>
                        <a class="btn btn-sm" href="file.php?id=<?= (int)$p['ID'] ?>" style="display:inline-flex;align-items:center;gap:6px">
                            <?= app_icon('files', '', 14) ?>
                            <span>مشاهده پرونده</span>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
