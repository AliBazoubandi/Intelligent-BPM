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

<?php if ($notice): ?><div class="alert alert-ok"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$canWrite): ?><div class="alert alert-info"><?= e($writeReason) ?></div><?php endif; ?>

<!-- نوار ابزار و ناوبری مسیر -->
<div class="fm-topbar">
    <div class="fm-crumbs">
        <a href="files.php" title="پوشه ریشه"><?= app_icon('files', '', 14) ?><span>processes</span></a>
        <?php
        $acc = '';
        foreach ($segments as $i => $seg):
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $last = ($i === count($segments) - 1);
        ?>
            <span class="sep">/</span>
            <?php if ($last): ?>
                <span class="current"><?= e($seg) ?></span>
            <?php else: ?>
                <a href="files.php?dir=<?= e(rawurlencode($acc)) ?>"><?= e($seg) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="fm-toolbar">
        <input type="search" id="fmQuickFilter" class="fm-filter-input" placeholder="🔍 فیلتر در این پوشه…" autocomplete="off">
        <?php if ($canWrite): ?>
            <button type="button" class="btn btn-sm" onclick="openFmModal('fmUploadModal')" style="display:inline-flex;align-items:center;gap:6px">
                <?= app_icon('export', '', 14) ?><span>بارگذاری فایل</span>
            </button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="openFmModal('fmNewDirModal')" style="display:inline-flex;align-items:center;gap:5px">
                <?= app_icon('plus', '', 14) ?><span>پوشه جدید</span>
            </button>
        <?php endif; ?>
        <a href="files.php<?= $rel !== '' ? '?dir=' . rawurlencode($rel) : '' ?>" class="btn btn-ghost btn-sm" title="تازه‌سازی" style="padding:6px 9px">
            <?= app_icon('refresh', '', 14) ?>
        </a>
    </div>
</div>

<!-- نوار خلاصه آمار پوشه -->
<div class="fm-summary-bar">
    <span class="fm-summary-pill"><?= app_icon('files', '', 14) ?> <?= farsi_num($dirCount) ?> پوشه</span>
    <span style="opacity:.4">·</span>
    <span class="fm-summary-pill"><?= app_icon('processes', '', 14) ?> <?= farsi_num($fileCount) ?> فایل</span>
    <span style="opacity:.4">·</span>
    <span class="fm-summary-pill"><?= app_icon('basics', '', 14) ?> حجم کل مخزن: <?= e(fmt_size($rootSize)) ?></span>
    <span class="sub num-ltr" style="margin-inline-start:auto;opacity:.8">processes<?= $rel === '' ? '' : '/' . e($rel) ?></span>
    <span class="badge <?= $canWrite ? 'badge-team' : 'badge-user' ?>" style="margin:0"><?= $canWrite ? 'قابل ویرایش' : 'فقط خواندنی' ?></span>
</div>

<!-- ساختار دو ستونه: سایدبار درختی + محتوای پوشه -->
<div class="fm-layout">
    <!-- ستون کناری: درخت دایرکتوری‌ها -->
    <aside class="fm-sidebar">
        <div class="fm-sidebar-title">
            <span style="display:inline-flex;align-items:center;gap:6px"><?= app_icon('files', '', 15) ?> پوشه‌های فرآیندها</span>
            <a href="files.php" title="رفتن به ریشه" style="font-size:11.5px;color:var(--brand);font-weight:700">ریشه</a>
        </div>
        <?php if (!$tree): ?>
            <div class="empty" style="padding:20px 10px"><p style="font-size:12px;margin:0">پوشه‌ای وجود ندارد.</p></div>
        <?php else: ?>
            <ul class="fm-tree">
            <?php
            $renderTree = function (array $nodes) use (&$renderTree, $rel): void {
                foreach ($nodes as $n): ?>
                    <li class="<?= $n['rel'] === $rel ? 'here' : '' ?>">
                        <a href="files.php?dir=<?= e(rawurlencode($n['rel'])) ?>" title="<?= e($n['name']) ?>">
                            <?= app_icon('files', '', 13) ?>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($n['name']) ?></span>
                            <span class="cnt-badge"><?= farsi_num((int)$n['files']) ?></span>
                        </a>
                        <?php if ($n['children']): ?>
                            <ul>
                                <?php foreach ($n['children'] as $c): ?>
                                    <li class="<?= $c['rel'] === $rel ? 'here' : '' ?>">
                                        <a href="files.php?dir=<?= e(rawurlencode($c['rel'])) ?>" title="<?= e($c['name']) ?>">
                                            <?= app_icon('processes', '', 13) ?>
                                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['name']) ?></span>
                                            <span class="cnt-badge"><?= farsi_num((int)$c['files']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach;
            };
            $renderTree($tree);
            ?>
            </ul>
        <?php endif; ?>
    </aside>

    <!-- بخش اصلی: جدول محتویات پوشه جاری -->
    <main class="fm-main-card">
        <div class="fm-main-head">
            <h2>
                <?php if ($rel === ''): ?>
                    <span><?= app_icon('files', '', 18) ?> پوشه‌های دسته‌بندی فرآیندها</span>
                <?php else: ?>
                    <span><?= app_icon('files', '', 18) ?> محتوای «<?= e($segments[count($segments) - 1]) ?>»</span>
                <?php endif; ?>

                <?php if ($map['kind'] === 'category'): ?>
                    <span class="badge badge-team">دسته: <?= e($map['label']) ?></span>
                <?php endif; ?>
                <?php if ($map['kind'] === 'process'): ?>
                    <span class="badge badge-user">فرآیند: <?= e($map['label']) ?></span>
                    <a class="btn btn-ghost btn-xs" href="file.php?id=<?= (int)$map['process_id'] ?>" style="display:inline-flex;align-items:center;gap:4px">
                        <?= app_icon('processes', '', 13) ?><span>پرونده فرآیند</span>
                    </a>
                <?php endif; ?>
            </h2>
        </div>

        <?php if (!$list['dirs'] && !$list['files']): ?>
            <div class="empty">
                <span class="ico"><?= app_icon('files', '', 36) ?></span>
                <p>این پوشه خالی است.</p>
                <?php if ($canWrite): ?>
                    <button type="button" class="btn btn-sm" onclick="openFmModal('fmUploadModal')" style="margin-top:10px">
                        <?= app_icon('export', '', 14) ?> بارگذاری اولین فایل
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
            <table class="data fm-table">
                <thead>
                    <tr>
                        <th>نام فایل یا پوشه</th>
                        <th style="width:110px">حجم</th>
                        <th style="width:180px">آخرین تغییر</th>
                        <th style="width:140px;text-align:left">عملیات</th>
                    </tr>
                </thead>
                <tbody id="fmTableBody">
                <?php if ($rel !== ''): ?>
                    <tr class="fm-up">
                        <td colspan="4">
                            <a href="files.php?dir=<?= e(rawurlencode(implode('/', array_slice($segments, 0, -1)))) ?>" style="display:inline-flex;align-items:center;gap:6px;color:var(--brand);font-weight:700">
                                <?= app_icon('reply', '', 14) ?><span>.. (پوشه بالاتر)</span>
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>

                <!-- پوشه‌ها -->
                <?php foreach ($list['dirs'] as $d):
                    $childRel = $rel === '' ? $d['name'] : $rel . '/' . $d['name'];
                    $dMap = fm_db_map($db, $childRel);
                    $dAbs = $abs . DIRECTORY_SEPARATOR . $d['name'];
                    $locked = $dMap['kind'] === 'process' && $dMap['team_id'] !== null && $dMap['team_id'] !== $tid;
                    $nonEmpty = fm_count_files($dAbs) > 0;
                ?>
                    <tr>
                        <td>
                            <a class="fm-name" href="files.php?dir=<?= e(rawurlencode($childRel)) ?>" style="display:inline-flex;align-items:center;gap:6px">
                                <?= app_icon('files', '', 15) ?>
                                <span><?= e($d['name']) ?></span>
                            </a>
                            <?php if ($dMap['kind'] === 'category'): ?><span class="db-tag">دسته فرآیند</span>
                            <?php elseif ($dMap['kind'] === 'process'): ?><span class="db-tag db-tag-proc">فرآیند: <?= e($dMap['label']) ?></span>
                            <?php else: ?><span class="db-tag db-tag-free">پوشه آزاد</span><?php endif; ?>
                            <span class="sub" style="margin-inline-start:4px">(<?= farsi_num((int)$d['files']) ?> فایل)</span>
                            <?php if ($locked): ?><span class="db-tag db-tag-lock">🔒 تیم دیگر</span><?php endif; ?>
                        </td>
                        <td><span class="sub"><?= e(fmt_size(fm_dir_size($dAbs))) ?></span></td>
                        <td><span class="sub"><?= e(fmt_date($d['time'])) ?></span></td>
                        <td>
                            <div class="fm-row-actions">
                                <a href="files.php?dir=<?= e(rawurlencode($childRel)) ?>" class="fm-act-btn" title="باز کردن پوشه">
                                    <?= app_icon('search', '', 13) ?>
                                </a>
                                <?php if ($canWrite && !$locked): ?>
                                    <button type="button" class="fm-act-btn" onclick="openRenameModal('<?= e(addslashes($d['name'])) ?>', 'dir')" title="تغییر نام پوشه">
                                        <?= app_icon('edit', '', 13) ?>
                                    </button>
                                    <button type="button" class="fm-act-btn danger" onclick="openDeleteDirModal('<?= e(addslashes($d['name'])) ?>', <?= $nonEmpty ? 'true' : 'false' ?>)" title="حذف پوشه">
                                        <?= app_icon('trash', '', 13) ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <!-- فایل‌ها -->
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
                        <td>
                            <span class="fm-name" style="display:inline-flex;align-items:center;gap:6px">
                                <span><?= $type ?></span>
                                <span><?= e($f['name']) ?></span>
                            </span>
                        </td>
                        <td><span class="sub"><?= e(fmt_size($f['size'])) ?></span></td>
                        <td><span class="sub"><?= e(fmt_date($f['time'])) ?></span></td>
                        <td>
                            <div class="fm-row-actions">
                                <a class="fm-act-btn" href="<?= e($url) ?>" target="_blank" rel="noopener" title="مشاهده / دانلود">
                                    <?= app_icon('search', '', 13) ?>
                                </a>
                                <?php if ($canWrite): ?>
                                    <button type="button" class="fm-act-btn" onclick="openRenameModal('<?= e(addslashes($f['name'])) ?>', 'file')" title="تغییر نام">
                                        <?= app_icon('edit', '', 13) ?>
                                    </button>
                                    <?php if ($allDirs): ?>
                                        <button type="button" class="fm-act-btn" onclick="openMoveModal('<?= e(addslashes($f['name'])) ?>')" title="انتقال فایل">
                                            <?= app_icon('reply', '', 13) ?>
                                        </button>
                                    <?php endif; ?>
                                    <form method="post" class="inline-form" onsubmit="return confirm('آیا از حذف فایل «<?= e(addslashes($f['name'])) ?>» مطمئن هستید؟');" style="margin:0">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete_file">
                                        <input type="hidden" name="dir" value="<?= e($rel) ?>">
                                        <input type="hidden" name="name" value="<?= e($f['name']) ?>">
                                        <button type="submit" class="fm-act-btn danger" title="حذف فایل">
                                            <?= app_icon('trash', '', 13) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- ================= مودال‌های مدیریت فایل (Modals) ================= -->

<?php if ($canWrite): ?>
<!-- مودال بارگذاری فایل -->
<div class="fm-modal-backdrop" id="fmUploadModal">
    <div class="fm-modal-box">
        <div class="fm-modal-header">
            <h3 class="fm-modal-title"><?= app_icon('export', '', 18) ?> بارگذاری فایل</h3>
            <button type="button" class="fm-modal-close" onclick="closeFmModal('fmUploadModal')">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">
            
            <div class="field">
                <label class="field-label">انتخاب فایل‌ها (چند فایل هم‌زمان مجاز است):</label>
                <input type="file" name="docs[]" multiple required style="width:100%;padding:10px;border:1px dashed var(--line);border-radius:10px;background:var(--bg)">
                <p class="sub" style="font-size:12px;color:var(--muted);margin-top:8px;line-height:1.6">
                    حداکثر ۲۰ مگابایت برای هر فایل. فرمت‌های مجاز: pdf، doc/docx، xls/xlsx، ppt/pptx، vsd/vsdx، txt، csv، zip/rar/7z، تصاویر و mp4.
                </p>
            </div>

            <div class="fm-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeFmModal('fmUploadModal')">انصراف</button>
                <button type="submit" class="btn btn-sm">شروع بارگذاری</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال ساخت پوشه جدید -->
<div class="fm-modal-backdrop" id="fmNewDirModal">
    <div class="fm-modal-box">
        <div class="fm-modal-header">
            <h3 class="fm-modal-title"><?= app_icon('plus', '', 18) ?> ساخت پوشه جدید</h3>
            <button type="button" class="fm-modal-close" onclick="closeFmModal('fmNewDirModal')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="mkdir">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">

            <div class="field">
                <label class="field-label">نام پوشه:</label>
                <input type="text" name="new_dir" maxlength="120" required placeholder="مثلاً: فاز-دوم" autofocus style="width:100%">
                <?php if ($rel === ''): ?>
                    <p class="sub" style="font-size:12px;color:var(--muted);margin-top:6px">
                        نکته: پوشه‌های ساخته‌شده در سطح اول معادل «دسته فرآیند» تلقی می‌شوند.
                    </p>
                <?php endif; ?>
            </div>

            <div class="fm-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeFmModal('fmNewDirModal')">انصراف</button>
                <button type="submit" class="btn btn-sm">ساخت پوشه</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال تغییر نام فایل یا پوشه -->
<div class="fm-modal-backdrop" id="fmRenameModal">
    <div class="fm-modal-box">
        <div class="fm-modal-header">
            <h3 class="fm-modal-title" id="fmRenameTitle"><?= app_icon('edit', '', 18) ?> تغییر نام</h3>
            <button type="button" class="fm-modal-close" onclick="closeFmModal('fmRenameModal')">&times;</button>
        </div>
        <form method="post" id="fmRenameForm">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="fmRenameAction" value="rename_file">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">
            <input type="hidden" name="name" id="fmRenameOldName" value="">

            <div class="field">
                <label class="field-label">نام جدید:</label>
                <input type="text" name="new_name" id="fmRenameNewName" maxlength="120" required style="width:100%" autofocus>
            </div>

            <div class="fm-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeFmModal('fmRenameModal')">انصراف</button>
                <button type="submit" class="btn btn-sm">ذخیره نام جدید</button>
            </div>
        </form>
    </div>
</div>

<!-- مودال انتقال فایل -->
<?php if ($allDirs): ?>
<div class="fm-modal-backdrop" id="fmMoveModal">
    <div class="fm-modal-box">
        <div class="fm-modal-header">
            <h3 class="fm-modal-title"><?= app_icon('reply', '', 18) ?> انتقال فایل</h3>
            <button type="button" class="fm-modal-close" onclick="closeFmModal('fmMoveModal')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="move_file">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">
            <input type="hidden" name="name" id="fmMoveFileName" value="">

            <div class="field">
                <label class="field-label">فایل انتخابی:</label>
                <p id="fmMoveFileLabel" style="font-weight:700;color:var(--ink);margin:0 0 10px"></p>
                <label class="field-label">پوشه مقصد را انتخاب کنید:</label>
                <select name="target" required style="width:100%">
                    <option value="">— انتخاب پوشه مقصد —</option>
                    <option value="__root__">📁 ریشهٔ فرآیندها (processes/)</option>
                    <?php foreach ($allDirs as $d):
                        if ($d === $rel) continue; ?>
                        <option value="<?= e($d) ?>"><?= e(str_repeat('— ', substr_count($d, '/') + 1)) ?>📁 <?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fm-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeFmModal('fmMoveModal')">انصراف</button>
                <button type="submit" class="btn btn-sm">انتقال فایل</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- مودال حذف پوشه -->
<div class="fm-modal-backdrop" id="fmDeleteDirModal">
    <div class="fm-modal-box">
        <div class="fm-modal-header">
            <h3 class="fm-modal-title" style="color:var(--danger)"><?= app_icon('trash', '', 18) ?> حذف پوشه</h3>
            <button type="button" class="fm-modal-close" onclick="closeFmModal('fmDeleteDirModal')">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="delete_dir">
            <input type="hidden" name="dir" value="<?= e($rel) ?>">
            <input type="hidden" name="name" id="fmDelDirName" value="">

            <p style="font-size:13.5px;color:var(--ink);line-height:1.7;margin:0 0 14px">
                آیا از حذف پوشه <b id="fmDelDirLabel"></b> مطمئن هستید؟
            </p>

            <div id="fmDelDirForceWrap" style="margin-bottom:14px;display:none;background:var(--bg);padding:10px 14px;border-radius:8px;border:1px solid var(--line)">
                <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
                    <input type="checkbox" name="force" value="1" id="fmDelDirForceCheck">
                    <span style="color:var(--danger);font-weight:700">این پوشه حاوی فایل است؛ حذف همه محتوای داخلی آن تأیید شود.</span>
                </label>
            </div>

            <div class="fm-modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" onclick="closeFmModal('fmDeleteDirModal')">انصراف</button>
                <button type="submit" class="btn btn-danger btn-sm">تأیید و حذف پوشه</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// توابع کنترل پنجره‌های مودال
function openFmModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.add('open');
    const input = el.querySelector('input[type=text], input[type=file]');
    if (input) setTimeout(() => input.focus(), 100);
}

function closeFmModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

// بستن مودال با کلیک روی پس‌زمینه
document.querySelectorAll('.fm-modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// بستن مودال با کلید Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.fm-modal-backdrop.open').forEach(m => m.classList.remove('open'));
    }
});

// تنظیم مودال تغییر نام
function openRenameModal(name, kind) {
    const actionInput = document.getElementById('fmRenameAction');
    const oldNameInput = document.getElementById('fmRenameOldName');
    const newNameInput = document.getElementById('fmRenameNewName');
    const titleEl = document.getElementById('fmRenameTitle');

    if (actionInput) actionInput.value = (kind === 'dir') ? 'rename_dir' : 'rename_file';
    if (oldNameInput) oldNameInput.value = name;
    if (newNameInput) newNameInput.value = name;
    if (titleEl) titleEl.innerText = 'تغییر نام ' + (kind === 'dir' ? 'پوشه' : 'فایل') + ' «' + name + '»';

    openFmModal('fmRenameModal');
}

// تنظیم مودال انتقال
function openMoveModal(name) {
    const fileInput = document.getElementById('fmMoveFileName');
    const labelEl = document.getElementById('fmMoveFileLabel');
    if (fileInput) fileInput.value = name;
    if (labelEl) labelEl.innerText = name;
    openFmModal('fmMoveModal');
}

// تنظیم مودال حذف پوشه
function openDeleteDirModal(name, nonEmpty) {
    const dirInput = document.getElementById('fmDelDirName');
    const labelEl = document.getElementById('fmDelDirLabel');
    const forceWrap = document.getElementById('fmDelDirForceWrap');
    const forceCheck = document.getElementById('fmDelDirForceCheck');

    if (dirInput) dirInput.value = name;
    if (labelEl) labelEl.innerText = '«' + name + '»';
    if (forceWrap) forceWrap.style.display = nonEmpty ? 'block' : 'none';
    if (forceCheck) forceCheck.checked = false;

    openFmModal('fmDeleteDirModal');
}

// فیلتر سریع لحظه‌ای فایل‌ها و پوشه‌ها
document.getElementById('fmQuickFilter')?.addEventListener('input', function(e) {
    const q = e.target.value.trim().toLowerCase();
    const rows = document.querySelectorAll('#fmTableBody tr');
    rows.forEach(r => {
        if (r.classList.contains('fm-up')) return;
        const text = r.innerText.toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
    });
});
</script>

<?php app_footer(); ?>
