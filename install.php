<?php
/**
 * نصب پردازشگر فرآیند سازمان
 *  - ساخت دیتابیس org_process و جداول (db/schema.sql)
 *  - ساخت دایرکتوری processes و فولدرهای دسته‌های فرآیند
 *  - همگام‌سازی جدول ProcessCategories با فولدرهای موجود
 *  - درج داده نمونه (شرکت / تیم فرآیند / پرسنل) — فقط در اولین نصب
 *
 * اجرا: php install.php   یا   http://localhost:8000/install.php
 * بعد از نصب این فایل را حذف یا محدود کنید.
 */
require __DIR__ . '/config.php';

$isCli = PHP_SAPI === 'cli';
$out = function (string $m) use ($isCli) {
    echo $isCli ? $m . PHP_EOL : '<p style="font-family:Tahoma">' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</p>';
};

$lockFile = __DIR__ . '/.installed';
if (file_exists($lockFile)) {
    $canForce = ($isCli && in_array('--force', $argv ?? [], true));
    if (!$canForce) {
        die('<p style="font-family:Tahoma;color:#b00">سامانه از قبل نصب شده است. برای اجرای مجدد، فایل <code>.installed</code> را در سرور حذف کرده یا از خط فرمان با سوئیچ <code>--force</code> اجرا نمایید.</p>');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* 1) ساخت دیتابیس */
$conn = db();
$conn->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db(DB_NAME);
$out('دیتابیس ' . DB_NAME . ' آماده است.');

/* 2) اجرای اسکیما */
$schema = file_get_contents(__DIR__ . '/db/schema.sql');
if ($schema === false) {
    throw new RuntimeException('فایل db/schema.sql خوانده نشد.');
}
$conn->multi_query($schema);
while ($conn->more_results() && $conn->next_result()) { /* مصرف نتایج */ }
if ($conn->errno) {
    throw new RuntimeException('خطا در اسکیما: ' . $conn->error);
}
$out('جداول ساخته/به‌روزرسانی شدند.');

/* 3) ساخت دایرکتوری فرآیندها و فولدرهای دسته‌ها */
$categoryFolders = [
    'اداری و مالی',
    'منابع انسانی',
    'فناوری اطلاعات',
    'تجاری و بازرگانی',
    'پشتیبانی و خدمات',
];
if (!is_dir(PROCESSES_ROOT)) {
    mkdir(PROCESSES_ROOT, 0775, true);
}
$ht = PROCESSES_ROOT . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($ht)) {
    file_put_contents($ht, "Options -Indexes\n");
}
foreach ($categoryFolders as $folder) {
    $path = PROCESSES_ROOT . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
        file_put_contents($path . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\n");
        $out('فولدر ساخته شد: ' . $folder);
    }
}

/* 4) همگام‌سازی جدول دسته‌ها با فولدرهای روی دیسک */
sync_categories($conn);
$out('دسته‌های فرآیند با فولدرها همگام شدند.');

/* 5) داده نمونه در اولین نصب */
$row = $conn->query('SELECT COUNT(*) c FROM Companies')->fetch_assoc();
if ((int)$row['c'] === 0) {
    $st = $conn->prepare("INSERT INTO Companies (Name) VALUES (?)");
    $companyName = 'شرکت نمونه';
    $st->bind_param('s', $companyName);
    $st->execute();
    $companyId = (int)$conn->insert_id;
    $st->close();

    // تیم فرآیند نمونه — رمز: team123
    $teamName = 'تیم فرآیند اداری';
    $teamCode = 'TP-1000';
    $teamPass = password_hash('team123', PASSWORD_DEFAULT);
    $desc = 'تیم مسئول فرآیندهای اداری و مالی';
    $st = $conn->prepare("INSERT INTO ProcessTeams (TeamName, TeamCode, PasswordHash, CompanyID, Description) VALUES (?,?,?,?,?)");
    $st->bind_param('sssis', $teamName, $teamCode, $teamPass, $companyId, $desc);
    $st->execute();
    $teamId = (int)$conn->insert_id;
    $st->close();

    // پرسنل نمونه — رمز: 123456
    $hash = password_hash('123456', PASSWORD_DEFAULT);
    $persons = [
        ['P-1001', 'مریم', 'رضایی', 1, 1, 0, 0], // درخواست + جستجو
        ['P-1002', 'علی', 'محمدی', 0, 1, 1, 0],  // جستجو + بهبود
        ['P-1003', 'سارا', 'کاظمی', 1, 1, 1, 1], // همه اجازه‌ها
    ];
    $st = $conn->prepare(
        "INSERT INTO Personnel (PersonnelCode, Name, Family, CompanyID, TeamID, CanRequest, CanSearch, CanImprove, CanApprove, PasswordHash)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($persons as [$code, $name, $family, $canReq, $canSearch, $canImp, $canAppr]) {
        $st->bind_param('sssiiiiiis', $code, $name, $family, $companyId, $teamId, $canReq, $canSearch, $canImp, $canAppr, $hash);
        $st->execute();
    }
    $st->close();
    $out('داده نمونه درج شد (تیم: ' . $teamCode . ' / team123 — پرسنل: P-1001 تا P-1003 با رمز 123456).');
} else {
    $out('داده نمونه از قبل وجود دارد؛ درج نشد.');
}

/* 6) داده نمونه فرآیندها، بهبودها و تغییرات */
$pc = (int)$conn->query('SELECT COUNT(*) c FROM Processes')->fetch_assoc()['c'];
if ($pc === 0) {
    seed_demo_processes($conn, $out);
} else {
    $out('فرآیندهای نمونه از قبل وجود دارد؛ درج نشد.');
}

/* 7) مجریان نمونه */
$ex = (int)$conn->query('SELECT COUNT(*) c FROM ProcessExecutors')->fetch_assoc()['c'];
if ($ex === 0) {
    $pidMap = [];
    $res = $conn->query('SELECT ID, Name FROM Processes');
    while ($r = $res->fetch_assoc()) $pidMap[$r['Name']] = (int)$r['ID'];
    $st = $conn->prepare('INSERT INTO ProcessExecutors (ProcessID, PersonnelID, TeamID, Role) VALUES (?,?,?,?)');
    $demoExec = [
        ['درخواست مرخصی', 1, null, 'متولی ثبت'],
        ['درخواست مرخصی', 2, null, 'تأییدکننده مدیر'],
        ['درخواست مرخصی', null, 1, 'تیم پیگیر'],
        ['صدور دسترسی شبکه', 2, null, 'مجری فنی'],
        ['صدور پیش‌فاکتور', 3, null, 'متولی'],
    ];
    foreach ($demoExec as [$procName, $persId, $teamId, $role]) {
        $pid = $pidMap[$procName] ?? 0;
        if (!$pid) continue;
        $st->bind_param('iiis', $pid, $persId, $teamId, $role);
        $st->execute();
    }
    $st->close();
    $out('مجریان نمونه درج شد.');
}

$out('نصب با موفقیت انجام شد.');
@file_put_contents($lockFile, date('Y-m-d H:i:s'));

/* ---------- توابع ---------- */
function seed_demo_processes(mysqli $conn, callable $out): void
{
    // دسته‌ها بر اساس نام فولدر
    $cats = [];
    $res = $conn->query('SELECT ID, FolderName FROM ProcessCategories WHERE Active=1');
    while ($r = $res->fetch_assoc()) $cats[$r['FolderName']] = (int)$r['ID'];

    $demo = [
        ['اداری و مالی', 'درخواست مرخصی', 'ثبت و تأیید مرخصی کارکنان از طریق مدیر مستقیم و امور اداری', 'active', '1.1'],
        ['اداری و مالی', 'درخواست مأموریت', 'صدور حکم مأموریت و تسویه هزینه‌های مأموریت', 'active', '1.0'],
        ['اداری و مالی', 'پرداخت هزینه‌های جاری', 'درخواست و تأیید پرداخت هزینه‌های خرد سازمان', 'draft', '0.9'],
        ['منابع انسانی', 'جذب و استخدام نیرو', 'از درخواست نیرو تا قرارداد و بدو خدمت', 'active', '2.0'],
        ['منابع انسانی', 'ارزیابی عملکرد', 'چرخه سالانه ارزیابی عملکرد کارکنان', 'active', '1.2'],
        ['فناوری اطلاعات', 'صدور دسترسی شبکه', 'ساخت حساب کاربری و سطوح دسترسی در شبکه داخلی', 'active', '1.0'],
        ['فناوری اطلاعات', 'پشتیبانی سخت‌افزار', 'ثبت خرابی، اعزام تکنسین و قطعه یدکی', 'draft', '0.5'],
        ['تجاری و بازرگانی', 'صدور پیش‌فاکتور', 'درخواست مشتری، استعلام قیمت و صدور پیش‌فاکتور', 'active', '1.3'],
        ['پشتیبانی و خدمات', 'ثبت درخواست تعمیرات', 'درخواست تعمیر تجهیزات ساختمانی و خدماتی', 'active', '1.0'],
    ];

    $st = $conn->prepare(
        "INSERT INTO Processes (Name, CategoryID, CompanyID, FilePath, OwnerPersonnelID, ResponsibleTeamID, Description, Status, Version)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $teamId = 1;
    foreach ($demo as [$catFolder, $name, $desc, $status, $version]) {
        $catId = $cats[$catFolder] ?? 0;
        $companyId = 1;
        $ownerId = 3; // سارا کاظمی — مدیر فرآیند
        $rel = $catFolder . '/' . $name . '/';
        // پوشه فایل‌های فرآیند + یک فایل نمونه
        $abs = PROCESSES_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_dir($abs)) {
            mkdir($abs, 0775, true);
            file_put_contents($abs . '.htaccess', "Options -Indexes\n");
        }
        $sampleFile = $abs . 'دستورالعمل-خلاصه.txt';
        if (!file_exists($sampleFile)) {
            file_put_contents($sampleFile, "فرآیند: {$name}\nوضعیت: {$status}\nنسخه: {$version}\n\n" . $desc . "\n");
        }
        $st->bind_param('siisiisss', $name, $catId, $companyId, $rel, $ownerId, $teamId, $desc, $status, $version);
        $st->execute();
    }
    $st->close();
    $out('۹ فرآیند نمونه با پوشه و فایل نمونه ساخته شد.');

    // دو بهبود از سوی P-1001 (شناسه ۱)
    $st = $conn->prepare("SELECT ID FROM Processes WHERE Name = ? LIMIT 1");
    $procNameLeave = 'درخواست مرخصی';
    $st->bind_param('s', $procNameLeave);
    $st->execute();
    $pidLeave = (int)($st->get_result()->fetch_assoc()['ID'] ?? 0);

    $procNameLeave = 'صدور دسترسی شبکه';
    $st->execute();
    $pidAccess = (int)($st->get_result()->fetch_assoc()['ID'] ?? 0);
    $st->close();

    $reqType = 'user'; $reqId = 1;
    $st = $conn->prepare("INSERT INTO Improvements (ProcessID, requester_type, requester_id, Title, Description, Status) VALUES (?,?,?,?,?,?)");
    $t1 = 'حذف امضای فیزیکی از فرم مرخصی';
    $d1 = 'با ثبت الکترونیکی تأیید مدیر، نیازی به امضای دستی در امور اداری نیست و زمان چرخه از ۳ روز به کمتر از ۱ روز می‌رسد.';
    $s1 = 'accepted';
    $st->bind_param('isisss', $pidLeave, $reqType, $reqId, $t1, $d1, $s1);
    $st->execute();
    $impLeave = (int)$conn->insert_id;

    $t2 = 'اعلان پیامکی پایان دسترسی';
    $d2 = 'پس از انقضای دسترسی شبکه، به کاربر و مدیرش پیامک یادآوری ارسال شود.';
    $s2 = 'pending';
    $st->bind_param('isisss', $pidAccess, $reqType, $reqId, $t2, $d2, $s2);
    $st->execute();
    $st->close();

    // پاسخ تیم به بهبود اول
    $sendType = 'team'; $sendId = 1;
    $body = 'پیشنهاد بررسی و تأیید شد؛ اصلاحات در نسخه ۱.۱ فرآیند اعمال گردید.';
    $st = $conn->prepare("INSERT INTO Responses (RefType, RefID, SenderType, SenderID, Body) VALUES ('improvement',?,?,?,?)");
    $st->bind_param('isis', $impLeave, $sendType, $sendId, $body);
    $st->execute();
    $st->close();

    // تغییرات مرتبط
    $st = $conn->prepare(
        "INSERT INTO Changes (ProcessID, ImprovementID, ChangeType, Description, OldVersion, NewVersion, AppliedByType, AppliedByID, Status, EffectiveDate)
         VALUES (?,?,?,?,?,?,?,?,?,CURDATE())"
    );
    $chType = 'revision'; $chDesc = 'حذف مرحله امضای فیزیکی؛ تأیید نهایی الکترونیکی شد.';
    $ov = '1.0'; $nv = '1.1'; $apType = 'team'; $apId = 1; $chStatus = 'applied';
    $st->bind_param('iisssssis', $pidLeave, $impLeave, $chType, $chDesc, $ov, $nv, $apType, $apId, $chStatus);
    $st->execute();

    $impNull = null;
    $chType = 'addition'; $chDesc = 'افزودن مرحله تأیید حراست برای دسترسی‌های حساس.';
    $ov = null; $nv = '2.1'; $apType = 'team'; $chStatus = 'approved';
    $st->bind_param('iisssssis', $pidAccess, $impNull, $chType, $chDesc, $ov, $nv, $apType, $apId, $chStatus);
    $st->execute();
    $st->close();
    $out('بهبودها، پاسخ و تغییرات نمونه درج شد.');
}
function sync_categories(mysqli $conn): void
{
    $root = PROCESSES_ROOT;
    $folders = array_values(array_filter(scandir($root), fn($f) => $f !== '.' && $f !== '..' && is_dir($root . DIRECTORY_SEPARATOR . $f)));
    sort($folders, SORT_STRING);

    $order = 0;
    $st = $conn->prepare(
        "INSERT INTO ProcessCategories (Title, FolderName, FolderPath, SortOrder)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE Title=VALUES(Title), Active=1, FolderPath=VALUES(FolderPath), SortOrder=VALUES(SortOrder)"
    );
    foreach ($folders as $f) {
        $order++;
        $rel = $f . '/';
        $st->bind_param('sssi', $f, $f, $rel, $order);
        $st->execute();
    }
    $st->close();

    // غیرفعال‌کردن دسته‌هایی که فولدرشان روی دیسک حذف شده است
    if ($folders) {
        $in = implode(',', array_map(fn($f) => "'" . $conn->real_escape_string($f) . "'", $folders));
        $conn->query("UPDATE ProcessCategories SET Active=0 WHERE FolderName NOT IN ($in)");
    } else {
        $conn->query('UPDATE ProcessCategories SET Active=0');
    }
}
