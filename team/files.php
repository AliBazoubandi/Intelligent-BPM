<?php
/**
 * مدیریت فایل‌های شاخه processes/ — پنل تیم فرآیند
 * مرور درخت، بارگذاری، ساخت پوشه، تغییر نام، انتقال و حذف (همگام با دیتابیس)
 */
require __DIR__ . '/../inc/layout.php';
require __DIR__ . '/../inc/ui.php';
require __DIR__ . '/../inc/files.php';
$id = require_area('team');
$db = app_db();
$tid = (int)$id['id'];

$rel = fm_normalize_rel((string)($_GET['dir'] ?? $_POST['dir'] ?? ''));
$abs = fm_safe_abs($rel);
$notice = '';
$error = '';
$badDir = ($abs === null);

$flash = flash_get();
if ($flash) {
    if ($flash['type'] === 'ok') {
        $notice = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}

if ($badDir) {
    // مسیر نامعتبر: فقط ریشه نمایش داده می‌شود و هیچ اکشنی اجرا نمی‌شود
    $error = 'مسیر درخواستی معتبر نیست (خارج از شاخه processes/).';
    $abs = fm_root();
}

$map = $badDir ? ['kind' => null, 'label' => '', 'process_id' => 0, 'category_id' => 0, 'team_id' => null, 'team_name' => null] : fm_db_map($db, $rel);

/** فقط تیم مسئول یک فرآیند می‌تواند داخل پوشه آن فرآیند تغییری ایجاد کند */
$canWrite = true;
$writeReason = '';
if ($badDir) {
    $canWrite = false;
    $writeReason = 'مسیر نامعتبر است؛ فقط مرور ریشه امکان‌پذیر است.';
}
if ($map['kind'] === 'process' && $map['team_id'] !== null && $map['team_id'] !== $tid) {
    $canWrite = false;
    $writeReason = 'این پوشه متعلق به فرآیند «' . $map['label'] . '» و تیم «' . ($map['team_name'] ?? '—') . '» است؛ نمایش فقط‌خواندنی.';
}

/** همگام‌سازی دیتابیس پس از تغییر نام پوشه */
function fm_sync_after_rename(mysqli $db, string $oldRel, string $newRel, string $oldBase, string $newBase): void
{
    $oldDir = rtrim($oldRel, '/') . '/';
    $newDir = rtrim($newRel, '/') . '/';
    if ($oldRel === $newRel) return;

    if (!str_contains($oldRel, '/')) {
        // پوشه سطح اول = دسته فرآیند
        $st = $db->prepare('UPDATE ProcessCategories SET Title = ?, FolderName = ?, FolderPath = ? WHERE FolderName = ? OR FolderPath = ?');
        $oldPath = $oldDir; $newPath = $newDir;
        $st->bind_param('sssss', $newBase, $newBase, $newPath, $oldBase, $oldPath);
        $st->execute(); $st->close();
    } else {
        // پوشه فرآیند: نام و مسیر رکورد فرآیند
        $st = $db->prepare('UPDATE Processes SET Name = ?, FilePath = ? WHERE REPLACE(FilePath, CHAR(92), "/") = ?');
        $needle = $oldDir;
        $st->bind_param('sss', $newBase, $newDir, $needle);
        $st->execute(); $st->close();
    }

    // مسیرهای تودرتو (زیرپوشه‌های همان پوشه)
    $like = $oldDir . '%';
    $pos = mb_strlen($oldDir) + 1;
    $st = $db->prepare('UPDATE Processes SET FilePath = CONCAT(?, SUBSTRING(REPLACE(FilePath, CHAR(92), "/"), ?)) WHERE REPLACE(FilePath, CHAR(92), "/") LIKE ?');
    $prefix = $newDir;
    $st->bind_param('sis', $prefix, $pos, $like);
    $st->execute(); $st->close();
}

/* ---------------- اکشن‌ها ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($badDir) {
        $error = 'مسیر درخواستی معتبر نیست؛ عملیاتی انجام نشد.';
    } elseif (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'اعتبار صفحه منقضی شده است.';
    } elseif (!$canWrite) {
        $error = $writeReason;
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'upload') {
            $files = $_FILES['docs'] ?? null;
            $names = [];
            $errCount = 0;
            if (is_array($files) && isset($files['name']) && is_array($files['name'])) {
                $count = count($files['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                    $one = ['name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i],
                           'size' => $files['size'][$i], 'error' => $files['error'][$i]];
                    $saved = fm_store_upload($one, $abs, $upErr);
                    if ($saved !== null) $names[] = $saved;
                    else { $errCount++; $error = $upErr; }
                }
            }
            if ($names) $notice = (count($names) > 1 ? count($names) . ' فایل بارگذاری شد: ' . implode('، ', $names) : '«' . $names[0] . '» بارگذاری شد.');
            elseif ($errCount === 0) $error = 'فایلی انتخاب نشده است.';
        }

        elseif ($action === 'mkdir') {
            $name = fm_clean_name((string)($_POST['new_dir'] ?? ''));
            if ($name === null) $error = 'نام پوشه معتبر نیست.';
            elseif ($name[0] === '.') $error = 'نام پوشه نمی‌تواند با نقطه شروع شود.';
            elseif (file_exists($abs . DIRECTORY_SEPARATOR . $name)) $error = 'پوشه‌ای با این نام وجود دارد.';
            else {
                $target = $abs . DIRECTORY_SEPARATOR . $name;
                if (@mkdir($target, 0775, true)) {
                    @file_put_contents($target . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
                    $notice = 'پوشه «' . $name . '» ساخته شد.';
                } else $error = 'ساخت پوشه ناموفق بود.';
            }
        }

        elseif ($action === 'rename_file') {
            $old = fm_clean_name((string)($_POST['name'] ?? ''));
            $new = fm_clean_name((string)($_POST['new_name'] ?? ''));
            $srcAbs = $old === null ? null : $abs . DIRECTORY_SEPARATOR . $old;
            if ($old === null || $new === null) $error = 'نام معتبر نیست.';
            elseif (!is_file((string)$srcAbs)) $error = 'فایل پیدا نشد.';
            elseif ($old === $new) $notice = 'نام تغییری نکرد.';
            elseif (file_exists($abs . DIRECTORY_SEPARATOR . $new)) $error = 'فایلی با نام جدید وجود دارد.';
            elseif (!@rename($srcAbs, $abs . DIRECTORY_SEPARATOR . $new)) $error = 'تغییر نام ناموفق بود.';
            else $notice = 'فایل به «' . $new . '» تغییر نام داد.';
        }

        elseif ($action === 'move_file') {
            $old = fm_clean_name((string)($_POST['name'] ?? ''));
            $destRel = fm_normalize_rel((string)($_POST['target'] ?? ''));
            if ($destRel === '__root__') $destRel = '';
            $destAbs = fm_safe_abs($destRel);
            if ($old === null || $destAbs === null) $error = 'مقصد یا فایل نامعتبر است.';
            elseif (!is_file($abs . DIRECTORY_SEPARATOR . $old)) $error = 'فایل پیدا نشد.';
            elseif (str_replace('\\', '/', $destAbs) === str_replace('\\', '/', $abs)) $error = 'فایل همین‌جا قرار دارد.';
            elseif (@rename($abs . DIRECTORY_SEPARATOR . $old, $destAbs . DIRECTORY_SEPARATOR . $old)) {
                $notice = '«' . $old . '» به پوشه ' . ($destRel === '' ? 'ریشه' : $destRel) . ' منتقل شد.';
            } else $error = 'انتقال فایل ناموفق بود.';
        }

        elseif ($action === 'delete_file') {
            $f = fm_clean_name((string)($_POST['name'] ?? ''));
            $fp = ($f !== null) ? $abs . DIRECTORY_SEPARATOR . $f : '';
            if ($f === null || str_starts_with($f, '.') || !is_file($fp)) $error = 'فایل معتبر نیست.';
            elseif (@unlink($fp)) $notice = '«' . $f . '» حذف شد.';
            else $error = 'حذف فایل ناموفق بود.';
        }

        elseif ($action === 'rename_dir') {
            $child = fm_clean_name((string)($_POST['name'] ?? ''));
            $newBase = fm_clean_name((string)($_POST['new_name'] ?? ''));
            if ($child === null || $newBase === null) $error = 'نام پوشه معتبر نیست.';
            elseif ($child === $newBase) $notice = 'نام تغییری نکرد.';
            else {
                $oldAbs = $abs . DIRECTORY_SEPARATOR . $child;
                $newAbs = $abs . DIRECTORY_SEPARATOR . $newBase;
                $oldRel = ($rel === '' ? $child : $rel . '/' . $child);
                $newRel = ($rel === '' ? $newBase : $rel . '/' . $newBase);
                $childMap = fm_db_map($db, $oldRel);
                if (!is_dir($oldAbs)) $error = 'پوشه پیدا نشد.';
                elseif ($childMap['kind'] === 'process' && ($childMap['team_id'] === null || $childMap['team_id'] !== $tid)) {
                    $error = 'تغییر نام پوشه فرآیندهای تیم دیگر مجاز نیست.';
                } elseif (file_exists($newAbs)) $error = 'پوشه‌ای با نام جدید وجود دارد.';
                elseif (!@rename($oldAbs, $newAbs)) $error = 'تغییر نام پوشه ناموفق بود.';
                else {
                    fm_sync_after_rename($db, $oldRel, $newRel, $child, $newBase);
                    $notice = 'پوشه «' . $newBase . '» تغییر نام کرد و اطلاعات سامانه همگام شد.';
                }
            }
        }

        elseif ($action === 'delete_dir') {
            $child = fm_clean_name((string)($_POST['name'] ?? ''));
            $force = !empty($_POST['force']);
            if ($child === null) $error = 'نام پوشه معتبر نیست.';
            else {
                $oldAbs = $abs . DIRECTORY_SEPARATOR . $child;
                $oldRel = ($rel === '' ? $child : $rel . '/' . $child);
                $childMap = fm_db_map($db, $oldRel);
                if (!is_dir($oldAbs)) $error = 'پوشه پیدا نشد.';
                elseif ($childMap['kind'] === 'process') {
                    $error = 'این پوشه پرونده فرآیند «' . $childMap['label'] . '» است؛ حذف آن از این صفحه مجاز نیست.';
                } elseif ($childMap['kind'] === 'category' && $childMap['category_id']) {
                    $st = $db->prepare('SELECT COUNT(*) c FROM Processes WHERE CategoryID = ?');
                    $cid = (int)$childMap['category_id'];
                    $st->bind_param('i', $cid); $st->execute();
                    $nProc = (int)$st->get_result()->fetch_assoc()['c']; $st->close();
                    if ($nProc > 0) $error = 'این دسته دارای ' . $nProc . ' فرآیند است؛ ابتدا فرآیندهای آن را منتقل یا حذف کنید.';
                    elseif (!$force && fm_count_files($oldAbs) > 0) $error = 'پوشه خالی نیست؛ برای حذف گزینه «حذف محتوای داخلی» را انتخاب کنید.';
                    elseif (!fm_remove($oldAbs)) $error = 'حذف پوشه ناموفق بود.';
                    else {
                        $st = $db->prepare('UPDATE ProcessCategories SET Active = 0 WHERE ID = ?');
                        $st->bind_param('i', $cid); $st->execute(); $st->close();
                        $notice = 'پوشه «' . $child . '» حذف و دسته آن در سامانه غیرفعال شد.';
                    }
                } elseif (!$force && fm_count_files($oldAbs) > 0) {
                    $error = 'پوشه خالی نیست؛ برای حذف فایل‌ها و زیرپوشه‌های آن گزینه «حذف محتوای داخلی» را انتخاب کنید.';
                } elseif (!fm_remove($oldAbs)) {
                    $error = 'حذف پوشه ناموفق بود.';
                } else {
                    if ($childMap['kind'] === 'category' && $childMap['category_id']) {
                        $st = $db->prepare('UPDATE ProcessCategories SET Active = 0 WHERE ID = ?');
                        $cid = (int)$childMap['category_id'];
                        $st->bind_param('i', $cid);
                        $st->execute(); $st->close();
                    }
                    $notice = 'پوشه «' . $child . '» و محتوای آن حذف شد.';
                }
            }
        }
    }
    if ($notice) {
        flash_set('ok', $notice);
    } elseif ($error) {
        flash_set('error', $error);
    }
    $redir = 'files.php' . ($rel !== '' ? '?dir=' . rawurlencode($rel) : '');
    header('Location: ' . $redir);
    exit;
}

$list = fm_list($abs);
$dirCount = count($list['dirs']);
$fileCount = count($list['files']);
$rootSize = fm_dir_size(fm_root());
$allDirs = $canWrite ? fm_all_dirs() : [];
$tree = fm_tree($db, 2);
$csrf = csrf_token();

/** مسیرهای بالاسری */
$segments = $rel === '' ? [] : explode('/', $rel);
?>
<?php team_header($id, 'files', 'مدیریت فایل‌های فرآیندها'); ?>

<div class="crumbs">
    <a href="files.php" style="display:inline-flex;align-items:center;gap:5px"><?= app_icon('files', '', 14) ?><span>ریشهٔ فرآیندها</span></a>
    <?php
    $acc = '';
    foreach ($segments as $i => $seg):
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $last = ($i === count($segments) - 1);
    ?>
        ← <?php if ($last): ?><b><?= e($seg) ?></b><?php else: ?><a href="files.php?dir=<?= e(rawurlencode($acc)) ?>"><?= e($seg) ?></a><?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$canWrite): ?><div class="alert alert-info"><?= e($writeReason) ?></div><?php endif; ?>

<div class="stat-grid">
    <div class="stat"><span class="ico"><?= app_icon('files', '', 24) ?></span><span class="num"><?= farsi_num($dirCount) ?></span><span class="lbl">پوشه در این مسیر</span></div>
    <div class="stat"><span class="ico"><?= app_icon('processes', '', 24) ?></span><span class="num"><?= farsi_num($fileCount) ?></span><span class="lbl">فایل در این مسیر</span></div>
    <div class="stat"><span class="ico"><?= app_icon('basics', '', 24) ?></span><span class="num"><?= e(fmt_size($rootSize)) ?></span><span class="lbl">حجم کل شاخه <span class="num-ltr">processes/</span></span></div>
    <div class="stat"><span class="ico"><?= $canWrite ? app_icon('check', '', 24) : app_icon('logout', '', 24) ?></span><span class="num" style="font-size:15px"><?= $canWrite ? 'قابل ویرایش' : 'فقط خواندنی' ?></span><span class="lbl">سطح دسترسی شما در این پوشه</span></div>
</div>

<div class="card">
    <h2>
        <?php if ($rel === ''): ?>پوشه‌های دسته‌بندی فرآیندها<?php else: ?>محتوای «<?= e($segments[count($segments) - 1]) ?>»<?php endif; ?>
        <?php if ($map['kind'] === 'category'): ?><span class="badge badge-team">دسته: <?= e($map['label']) ?></span><?php endif; ?>
        <?php if ($map['kind'] === 'process'): ?><span class="badge badge-user">فرآیند: <?= e($map['label']) ?></span>
            <a class="btn btn-ghost btn-xs" href="file.php?id=<?= (int)$map['process_id'] ?>" style="display:inline-flex;align-items:center;gap:4px"><?= app_icon('processes', '', 13) ?><span>پرونده فرآیند</span></a><?php endif; ?>
    </h2>
    <p class="sub" style="color:var(--muted);font-size:12.5px;margin-top:-4px">
        مسیر فیزیکی: <span class="num-ltr">processes<?= $rel === '' ? '' : '/' . e($rel) ?></span>
    </p>

    <?php if (!$list['dirs'] && !$list['files']): ?>
        <div class="empty"><span class="ico"><?= app_icon('files', '', 36) ?></span><p>این پوشه خالی است.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data fm-table">
        <thead><tr><th>نام</th><th>حجم</th><th>آخرین تغییر</th><th style="width:340px">عملیات</th></tr></thead>
        <tbody>
        <?php if ($rel !== ''): ?>
            <tr class="fm-up">
                <td><a href="files.php?dir=<?= e(rawurlencode(implode('/', array_slice($segments, 0, -1)))) ?>" style="display:inline-flex;align-items:center;gap:6px"><?= app_icon('reply', '', 14) ?><span>پوشه بالاتر</span></a></td>
                <td>—</td><td>—</td><td></td>
            </tr>
        <?php endif; ?>

        <?php foreach ($list['dirs'] as $d):
            $childRel = $rel === '' ? $d['name'] : $rel . '/' . $d['name'];
            $dMap = fm_db_map($db, $childRel);
            $dAbs = $abs . DIRECTORY_SEPARATOR . $d['name'];
            $locked = $dMap['kind'] === 'process' && $dMap['team_id'] !== null && $dMap['team_id'] !== $tid;
            $nonEmpty = fm_count_files($dAbs) > 0;
        ?>
            <tr>
                <td>
                    <a class="fm-name" href="files.php?dir=<?= e(rawurlencode($childRel)) ?>" style="display:inline-flex;align-items:center;gap:6px"><?= app_icon('files', '', 15) ?><span><?= e($d['name']) ?></span></a>
                    <?php if ($dMap['kind'] === 'category'): ?><span class="db-tag">دسته فرآیند</span>
                    <?php elseif ($dMap['kind'] === 'process'): ?><span class="db-tag db-tag-proc">فرآیند: <?= e($dMap['label']) ?></span>
                    <?php else: ?><span class="db-tag db-tag-free">پوشه آزاد</span><?php endif; ?>
                    <span class="sub"> · <?= (int)$d['files'] ?> فایل</span>
                    <?php if ($locked): ?><span class="db-tag db-tag-lock">🔒 تیم دیگر</span><?php endif; ?>
                </td>
                <td><span class="sub"><?= e(fmt_size(fm_dir_size($dAbs))) ?></span></td>
                <td><span class="sub num-ltr"><?= e(date('Y/m/d H:i', $d['time'])) ?></span></td>
                <td>
                    <?php if ($canWrite && !$locked): ?>
                    <div class="fm-actions">
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="rename_dir">
                            <input type="hidden" name="dir" value="<?= e($rel) ?>">
                            <input type="hidden" name="name" value="<?= e($d['name']) ?>">
                            <input type="text" name="new_name" value="<?= e($d['name']) ?>" maxlength="120" required>
                            <button class="btn btn-ghost btn-xs" title="تغییر نام پوشه و همگام‌سازی با سامانه">✏️ تغییر نام</button>
                        </form>
                        <form method="post" class="inline-form" onsubmit="return confirm('این پوشه<?= $nonEmpty ? ' و همه محتوای آن' : '' ?> حذف شود؟');">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="delete_dir">
                            <input type="hidden" name="dir" value="<?= e($rel) ?>">
                            <input type="hidden" name="name" value="<?= e($d['name']) ?>">
                            <?php if ($nonEmpty): ?><label class="fm-force"><input type="checkbox" name="force" value="1"> حذف محتوای داخلی</label><?php else: ?><input type="hidden" name="force" value="1"><?php endif; ?>
                            <button class="btn btn-danger btn-xs">🗑 حذف پوشه</button>
                        </form>
                    </div>
                    <?php else: ?><span class="sub">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php foreach ($list['files'] as $f):
            $type = match (true) {
                in_array($f['ext'], ['pdf']) => '📕',
                in_array($f['ext'], ['doc','docx']) => '📘',
                in_array($f['ext'], ['xls','xlsx','csv']) => '📗',
                in_array($f['ext'], ['ppt','pptx']) => '📙',
                in_array($f['ext'], ['zip','rar','7z']) => '🗜',
                in_array($f['ext'], ['jpg','jpeg','png','gif','webp']) => '🖼',
                in_array($f['ext'], ['mp4']) => '🎞',
                in_array($f['ext'], ['txt']) => '📝',
                default => '📄',
            };
            $url = fm_url($rel === '' ? $f['name'] : $rel . '/' . $f['name']);
        ?>
            <tr>
                <td><span class="fm-name"><?= $type ?> <?= e($f['name']) ?></span></td>
                <td><span class="sub"><?= e(fmt_size($f['size'])) ?></span></td>
                <td><span class="sub num-ltr"><?= e(date('Y/m/d H:i', $f['time'])) ?></span></td>
                <td>
                    <div class="fm-actions">
                        <a class="btn btn-ghost btn-xs" href="<?= e($url) ?>" target="_blank" rel="noopener">🔗 مشاهده / دانلود</a>
                        <?php if ($canWrite): ?>
                        <div class="fm-actions-row">
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="rename_file">
                                <input type="hidden" name="dir" value="<?= e($rel) ?>">
                                <input type="hidden" name="name" value="<?= e($f['name']) ?>">
                                <input type="text" name="new_name" value="<?= e($f['name']) ?>" maxlength="120" required>
                                <button class="btn btn-ghost btn-xs">✏️</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('این فایل حذف شود؟');">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="delete_file">
                                <input type="hidden" name="dir" value="<?= e($rel) ?>">
                                <input type="hidden" name="name" value="<?= e($f['name']) ?>">
                                <button class="btn btn-danger btn-xs">🗑</button>
                            </form>
                        </div>
                        <?php if ($allDirs): ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="move_file">
                            <input type="hidden" name="dir" value="<?= e($rel) ?>">
                            <input type="hidden" name="name" value="<?= e($f['name']) ?>">
                            <select name="target" required>
                                <option value="">— مقصد انتقال —</option>
                                <option value="__root__">📁 ریشهٔ فرآیندها</option>
                                <?php foreach ($allDirs as $d):
                                    if ($d === $rel) continue; ?>
                                    <option value="<?= e($d) ?>"><?= e(str_repeat('— ', substr_count($d, '/') + 1)) ?><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-ghost btn-xs">📦 انتقال</button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($canWrite): ?>
<div class="card">
    <h2>افزودن به این پوشه</h2>
    <div class="form-row">
        <form method="post" enctype="multipart/form-data" class="field" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">
            <span class="field-label">بارگذاری فایل (چند فایل هم‌زمان مجاز است — حداکثر ۲۰ مگابایت هر فایل)</span>
            <input type="file" name="docs[]" multiple required>
            <p class="sub" style="color:var(--muted);font-size:12px;margin:6px 0 8px">فرمت‌های مجاز: pdf، doc/docx، xls/xlsx، ppt/pptx، vsd/vsdx، txt، csv، zip/rar/7z، تصویر و mp4</p>
            <button class="btn btn-sm">⬆ بارگذاری</button>
        </form>
        <form method="post" class="field" style="margin:0">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="mkdir">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">
            <span class="field-label">ساخت زیرپوشه</span>
            <input type="text" name="new_dir" maxlength="120" required placeholder="مثلاً فاز-دوم">
            <?php if ($rel === ''): ?><p class="sub" style="color:var(--muted);font-size:12px;margin:6px 0 8px">پوشه‌ای که در سطح اول ساخته شود به عنوان «دسته فرآیند» شناخته می‌شود؛ برای ثبت در سامانه از «اطلاعات پایه ← دسته فرآیند» یا دکمه همگام‌سازی استفاده کنید.</p><?php endif; ?>
            <button class="btn btn-ghost btn-sm">＋ ساخت پوشه</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h2>نقشه پوشه‌های processes/</h2>
    <?php if (!$tree): ?>
        <div class="empty"><span class="ico"><?= app_icon('files', '', 36) ?></span><p>هیچ پوشه‌ای در ریشه وجود ندارد.</p></div>
    <?php else: ?>
        <ul class="fm-tree">
        <?php
        $render = function (array $nodes) use (&$render, $rel): void {
            foreach ($nodes as $n): ?>
                <li class="<?= $n['rel'] === $rel ? 'here' : '' ?>">
                    <a href="files.php?dir=<?= e(rawurlencode($n['rel'])) ?>" style="display:inline-flex;align-items:center;gap:6px">
                        <?= app_icon('files', '', 14) ?>
                        <span><?= e($n['name']) ?></span>
                    </a>
                    <span class="sub">(<?= farsi_num((int)$n['files']) ?> فایل)</span>
                    <?php if ($n['children']): ?>
                        <ul>
                            <?php foreach ($n['children'] as $c): ?>
                                <li class="<?= $c['rel'] === $rel ? 'here' : '' ?>">
                                    <a href="files.php?dir=<?= e(rawurlencode($c['rel'])) ?>" style="display:inline-flex;align-items:center;gap:6px">
                                        <?= app_icon('processes', '', 14) ?>
                                        <span><?= e($c['name']) ?></span>
                                    </a>
                                    <span class="sub">(<?= farsi_num((int)$c['files']) ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach;
        };
        $render($tree);
        ?>
        </ul>
    <?php endif; ?>
</div>

<?php app_footer(); ?>
