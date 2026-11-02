<?php
/**
 * بهبودهای من — فهرست درخواست‌های بهبود + ثبت بهبود جدید
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require_once __DIR__ . '/../inc/hermes.php';
$id = require_area('user');
$db = app_db();

$canSubmit = !empty($id['perms']['improve']) || !empty($id['perms']['request']);
$notice = '';
$error = '';

/* ثبت بهبود جدید */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canSubmit) {
        $error = 'شما اجازه ثبت درخواست بهبود ندارید.';
    } elseif (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده؛ دوباره تلاش کنید.';
    } else {
        $procId = (int)($_POST['process_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $check = $db->prepare('SELECT ID FROM Processes WHERE ID = ? AND Status != "archived"');
        $check->bind_param('i', $procId);
        $check->execute();
        $found = (bool)$check->get_result()->fetch_assoc();
        $check->close();

        if ($title === '' || mb_strlen($title) < 3) {
            $error = 'عنوان بهبود را کامل‌تر بنویسید (حداقل ۳ نویسه).';
        } elseif (!$found) {
            $error = 'فرآیند مورد نظر انتخاب نشده یا معتبر نیست.';
        } else {
            $uid = (int)$id['id'];
            $type = 'user';
            $st = $db->prepare(
                "INSERT INTO Improvements (ProcessID, requester_type, requester_id, Title, Description)
                 VALUES (?,?,?,?,?)"
            );
            $st->bind_param('isiss', $procId, $type, $uid, $title, $desc);
            $st->execute();
            $newImpId = (int)$db->insert_id;
            $st->close();

            // ثبت درخواست بهبود و ارسال غیراسنکرون (Async) به صف داور هوشمند هرمس
            hermes_dispatch_async_eval($newImpId);
            $notice = 'درخواست بهبود شما با شماره پیگیری #' . $newImpId . ' با موفقیت ثبت شد و در صف ارزیابی داور هوشمند (هرمس) و بررسی تیم فرآیند قرار گرفت.';
        }
    }
}

/* فهرست بهبودهای من */
$uid = (int)$id['id'];
$st = $db->prepare(
    "SELECT i.*, p.Name AS ProcName, cat.Title AS CatTitle,
            (SELECT r.Body FROM Responses r WHERE r.RefType='improvement' AND r.RefID=i.ID ORDER BY r.ID DESC LIMIT 1) AS LastReply
     FROM Improvements i
     JOIN Processes p ON p.ID = i.ProcessID
     JOIN ProcessCategories cat ON cat.ID = p.CategoryID
     WHERE i.requester_type='user' AND i.requester_id = ?
     ORDER BY i.CreatedAt DESC"
);
$st->bind_param('i', $uid);
$st->execute();
$mine = [];
$res = $st->get_result();
while ($r = $res->fetch_assoc()) $mine[] = $r;
$st->close();

/* فرآیندهای قابل انتخاب */
$procs = [];
$res = $db->query("SELECT ID, Name, CategoryID FROM Processes WHERE Status != 'archived' ORDER BY Name");
while ($r = $res->fetch_assoc()) $procs[] = $r;

$preselect = (int)($_GET['process'] ?? $_POST['process_id'] ?? 0);
?>
<?php user_header($id, 'improvements', 'بهبودهای من'); ?>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($canSubmit): ?>
<div class="card">
    <h2>💡 ثبت درخواست بهبود جدید</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0 16px">
            <label class="field">
                <span class="field-label">فرآیند</span>
                <select name="process_id" required>
                    <option value="0">— انتخاب فرآیند —</option>
                    <?php foreach ($procs as $p): ?>
                        <option value="<?= (int)$p['ID'] ?>" <?= $preselect === (int)$p['ID'] ? 'selected' : '' ?>><?= e($p['Name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span class="field-label">عنوان بهبود</span>
                <input type="text" name="title" required maxlength="200" placeholder="مثلاً حذف امضای فیزیکی از فرم مرخصی">
            </label>
        </div>
        <label class="field">
            <span class="field-label">شرح بهبود</span>
            <textarea name="description" maxlength="2000" placeholder="مشکل فعلی و پیشنهاد شما…"></textarea>
        </label>
        <button type="submit" class="btn">ثبت درخواست</button>
    </form>
</div>
<?php else: ?>
    <div class="alert alert-info">شما اجازه ثبت درخواست بهبود ندارید؛ تنها وضعیت درخواست‌های قبلی را مشاهده می‌کنید.</div>
<?php endif; ?>

<div class="card">
    <h2>درخواست‌های ثبت‌شده شما (<?= farsi_num(count($mine)) ?>)</h2>
    <?php if (!$mine): ?>
        <div class="empty"><span class="ico"><?= app_icon('improvements', '', 36) ?></span><p>هنوز درخواست بهبودی ثبت نکرده‌اید.</p></div>
    <?php else: ?>
        <div class="table-wrap">
        <table class="data">
            <thead><tr><th>بهبود</th><th>فرآیند</th><th>داور هرمس (AI)</th><th>وضعیت</th><th>تاریخ</th><th>آخرین پاسخ</th><th style="width:100px"></th></tr></thead>
            <tbody>
            <?php foreach ($mine as $m): ?>
                <tr>
                    <td style="min-width:180px"><span class="title"><?= e($m['Title']) ?></span><?php if ($m['Description']): ?><br><span class="sub"><?= e(mb_strimwidth((string)$m['Description'], 0, 100, '…')) ?></span><?php endif; ?></td>
                    <td><a href="processes.php?id=<?= (int)$m['ProcessID'] ?>"><?= e($m['ProcName']) ?></a><br><span class="sub"><?= e($m['CatTitle']) ?></span></td>
                    <td><?= hermes_badge($m['AiVerdict'] ?? 'pending') ?></td>
                    <td><span class="badge badge-<?= e($m['Status']) ?>"><?= status_label($m['Status']) ?></span></td>
                    <td><?= e(fmt_date_short($m['CreatedAt'])) ?></td>
                    <td style="min-width:160px"><?= $m['LastReply'] ? e(mb_strimwidth((string)$m['LastReply'], 0, 120, '…')) : '—' ?></td>
                    <td><a class="btn btn-ghost btn-xs" href="discussion.php?imp=<?= (int)$m['ID'] ?>" style="display:inline-flex;align-items:center;gap:4px"><?= app_icon('discussion', '', 13) ?><span>گفتگو</span></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
