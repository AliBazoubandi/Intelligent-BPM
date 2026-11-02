<?php
/**
 * کمک‌توابع مدیریت فایل شاخه processes/
 * همه مسیرها نسبت به PROCESSES_ROOT و با جداکننده «/» بیان می‌شوند.
 */

/** مسیر مطلق ریشه فایل‌ها */
function fm_root(): string
{
    return rtrim(PROCESSES_ROOT, '/\\');
}

/** پاک‌سازی مسیر نسبی: حذف .. و بخش‌های خالی (مقاوم در برابر traversal) */
function fm_normalize_rel(string $rel): string
{
    $rel = str_replace('\\', '/', $rel);
    $parts = [];
    foreach (explode('/', $rel) as $p) {
        $p = trim($p);
        if ($p === '' || $p === '.' || $p === '..') continue;
        $parts[] = $p;
    }
    return implode('/', $parts);
}

/** مسیر مطلق از مسیر نسبی */
function fm_abs(string $rel): string
{
    $rel = fm_normalize_rel($rel);
    if ($rel === '') return fm_root();
    return fm_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/** مسیر مطلق امن (چک ماندن داخل ریشه) یا null */
function fm_safe_abs(string $rel): ?string
{
    $abs = fm_abs($rel);
    $real = realpath($abs);
    $rootReal = realpath(fm_root());
    if ($real === false || $rootReal === false) return null;
    $real = str_replace('\\', '/', $real);
    $rootReal = str_replace('\\', '/', $rootReal);
    if ($real === $rootReal) return $real;
    return str_starts_with($real, $rootReal . '/') ? $real : null;
}

/** نام فایل/پوشه معتبر */
function fm_clean_name(string $name): ?string
{
    $name = str_replace(['\\', '/', "\0"], '', trim($name));
    $name = preg_replace('/[<>:"|?*\x00-\x1F]+/u', '', $name);
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || $name === '.' || $name === '..') return null;
    if (mb_strlen($name) > 120) return null;
    return $name;
}

/** فرمت‌های مجاز بارگذاری */
function fm_allowed_ext(string $name): bool
{
    static $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','vsd','vsdx','txt','csv','zip','rar','7z','jpg','jpeg','png','gif','webp','mp4'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, $allowed, true);
}

/** فهرست محتوای یک پوشه مطلق: ['dirs'=>[…], 'files'=>[…]] */
function fm_list(string $absDir): array
{
    $out = ['dirs' => [], 'files' => []];
    if (!is_dir($absDir)) return $out;
    $items = scandir($absDir);
    foreach ($items as $f) {
        if ($f === '.' || $f === '..') continue;
        if (str_starts_with($f, '.')) continue; // .htaccess و… نمایش داده نمی‌شود
        $p = $absDir . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) {
            $out['dirs'][] = ['name' => $f, 'time' => (int)@filemtime($p), 'files' => fm_count_files($p)];
        } elseif (is_file($p)) {
            $out['files'][] = ['name' => $f, 'size' => (int)@filesize($p), 'time' => (int)@filemtime($p),
                              'ext' => strtolower(pathinfo($f, PATHINFO_EXTENSION))];
        }
    }
    usort($out['dirs'], fn($a, $b) => strcmp($a['name'], $b['name']));
    usort($out['files'], fn($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/** شمارش فایل‌ها (بازگشتی) و پوشه‌های یک درخت */
function fm_count_files(string $absDir): int
{
    $n = 0;
    if (!is_dir($absDir)) return 0;
    foreach (scandir($absDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $absDir . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) $n += fm_count_files($p);
        elseif (is_file($p) && !str_starts_with($f, '.')) $n++;
    }
    return $n;
}

/** حجم کل پوشه (بازگشتی) */
function fm_dir_size(string $absDir): int
{
    $s = 0;
    if (!is_dir($absDir)) return $s;
    foreach (scandir($absDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $absDir . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) $s += fm_dir_size($p);
        elseif (is_file($p) && !str_starts_with($f, '.')) $s += (int)@filesize($p);
    }
    return $s;
}

/** همه پوشه‌های موجود برای انتخاب مقصد انتقال */
function fm_all_dirs(string $rel = '', int $maxDepth = 6): array
{
    $out = [];
    $walk = function (string $cur, int $depth) use (&$walk, &$out, $maxDepth): void {
        if ($depth > $maxDepth) return;
        $abs = fm_safe_abs($cur);
        if ($abs === null) return;
        foreach (scandir($abs) as $f) {
            if ($f === '.' || $f === '..' || str_starts_with($f, '.')) continue;
            $p = $abs . DIRECTORY_SEPARATOR . $f;
            if (!is_dir($p)) continue;
            $child = $cur === '' ? $f : $cur . '/' . $f;
            $out[] = $child;
            $walk($child, $depth + 1);
        }
    };
    $walk(fm_normalize_rel($rel), 1);
    sort($out, SORT_STRING);
    return $out;
}

/** URL عمومی فایل (هم‌الگو با تب مستندات) */
function fm_url(string $rel): string
{
    $rel = fm_normalize_rel($rel);
    if ($rel === '') return BASE_URL . '/processes/';
    return BASE_URL . '/processes/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
}

/** ذخیره امن یک فایل آپلودی در پوشه مطلق؛ در خطا پیام را در &$err می‌گذارد */
function fm_store_upload(array $file, string $absDir, ?string &$err): ?string
{
    $err = null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $err = 'خطا در دریافت فایل.'; return null; }
    $name = fm_clean_name(basename((string)($file['name'] ?? '')));
    if ($name === null) { $err = 'نام فایل معتبر نیست.'; return null; }
    if (!fm_allowed_ext($name)) { $err = 'فرمت فایل «' . $name . '» مجاز نیست.'; return null; }
    if ((int)$file['size'] > 20 * 1048576) { $err = 'حجم فایل «' . $name . '» بیشتر از ۲۰ مگابایت است.'; return null; }
    if (!is_dir($absDir)) {
        if (!@mkdir($absDir, 0775, true) && !is_dir($absDir)) { $err = 'ساخت پوشه ممکن نشد.'; return null; }
        @file_put_contents($absDir . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
    }
    $dest = $absDir . DIRECTORY_SEPARATOR . $name;
    if (file_exists($dest)) {
        $dest = $absDir . DIRECTORY_SEPARATOR . pathinfo($name, PATHINFO_FILENAME) . '-' . date('ymd-His') . '.' . pathinfo($name, PATHINFO_EXTENSION);
    }
    if (!@move_uploaded_file($file['tmp_name'], $dest)) { $err = 'ذخیره فایل «' . $name . '» ناموفق بود.'; return null; }
    return basename($dest);
}

/** حذف بازگشتی فایل یا پوشه */
function fm_remove(string $abs): bool
{
    if (is_file($abs)) return @unlink($abs);
    if (!is_dir($abs)) return false;
    foreach (scandir($abs) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $abs . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) fm_remove($p);
        else @unlink($p);
    }
    return @rmdir($abs);
}

/**
 * نگاشت مسیر به دیتابیس
 * @return array{kind:string|null,label:string,process_id:int,category_id:int,team_id:int|null,team_name:string|null}
 */
function fm_db_map(mysqli $db, string $rel): array
{
    $rel = fm_normalize_rel($rel);
    $res = ['kind' => null, 'label' => '', 'process_id' => 0, 'category_id' => 0, 'team_id' => null, 'team_name' => null];
    if ($rel === '') return $res;
    $parts = explode('/', $rel);

    if (count($parts) === 1) {
        $st = $db->prepare('SELECT ID, Title FROM ProcessCategories WHERE FolderName = ? OR Title = ? LIMIT 1');
        $n = $parts[0];
        $st->bind_param('ss', $n, $n);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) { $res['kind'] = 'category'; $res['label'] = $row['Title']; $res['category_id'] = (int)$row['ID']; }
        return $res;
    }

    $needle = rtrim($rel, '/') . '/';
    $st = $db->prepare(
        'SELECT p.ID, p.Name, p.CategoryID, p.ResponsibleTeamID, t.TeamName
         FROM Processes p LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID
         WHERE REPLACE(p.FilePath, CHAR(92), "/") = ? LIMIT 1'
    );
    $st->bind_param('s', $needle);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row && count($parts) === 2) {
        // پوشه‌های نمونه: دسته/نام-فرآیند
        $st = $db->prepare(
            'SELECT p.ID, p.Name, p.CategoryID, p.ResponsibleTeamID, t.TeamName
             FROM Processes p
             JOIN ProcessCategories c ON c.ID = p.CategoryID
             LEFT JOIN ProcessTeams t ON t.ID = p.ResponsibleTeamID
             WHERE (c.FolderName = ? OR c.Title = ?) AND (p.Name = ?) LIMIT 1'
        );
        $a = $parts[0]; $b = $parts[1];
        $st->bind_param('sss', $a, $a, $b);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if ($row) {
        $res['kind'] = 'process';
        $res['label'] = $row['Name'];
        $res['process_id'] = (int)$row['ID'];
        $res['category_id'] = (int)$row['CategoryID'];
        $res['team_id'] = $row['ResponsibleTeamID'] !== null ? (int)$row['ResponsibleTeamID'] : null;
        $res['team_name'] = $row['TeamName'];
        return $res;
    }
    // زیرمجموعه یک فرآیند
    foreach ($parts as $i => $_) {
        if ($i === 0) continue;
        $upper = fm_db_map($db, implode('/', array_slice($parts, 0, $i)));
        if ($upper['kind'] === 'process') return $upper;
    }
    return $res;
}

/** بازگرداندن لیست پوشه‌های یک درخت برای نمایش */
function fm_tree(mysqli $db, int $maxDepth = 2): array
{
    $build = function (string $rel, int $depth) use (&$build, $maxDepth): array {
        $abs = fm_safe_abs($rel);
        if ($abs === null || $depth > $maxDepth) return [];
        $list = fm_list($abs);
        $nodes = [];
        foreach ($list['dirs'] as $d) {
            $childRel = $rel === '' ? $d['name'] : $rel . '/' . $d['name'];
            $nodes[] = [
                'rel' => $childRel,
                'name' => $d['name'],
                'files' => (int)$d['files'],
                'children' => $depth < $maxDepth ? $build($childRel, $depth + 1) : [],
            ];
        }
        return $nodes;
    };
    return $build('', 1);
}
