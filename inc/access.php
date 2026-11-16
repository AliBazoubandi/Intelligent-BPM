<?php
/**
 * لایه کنترل دسترسی متمرکز فرآیندها (Centralized Process Authorization & Audience Layer)
 * اعمال یکپارچه قوانین دسترسی و دامنه مخاطبان در تمام بخش‌ها:
 *  - مشاهده و پیمایش لیست فرآیندها (user/processes.php)
 *  - جستجوی فرآیندها (user/search.php)
 *  - دانلود اسناد و فایل‌ها (download.php)
 *  - بازیابی دانش در هوش مصنوعی (RAG - inc/hermes.php)
 */

declare(strict_types=1);

/**
 * دریافت مشخصات دسترسی و سمت کاربر با کش استاتیک درون‌درخواستی
 *
 * @param mysqli $db
 * @param int $uid شناسه پرسنلی کاربر
 * @return array{pos_id:int, dept:string, aud_proc_ids:array<int,bool>}
 */
function user_load_access_context(mysqli $db, int $uid): array
{
    static $cache = [];
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }

    $userPosId = 0;
    $userDept = '';
    $audProcIds = [];

    if ($uid > 0) {
        $stU = $db->prepare("SELECT p.OrgPositionID, op.Department FROM Personnel p LEFT JOIN OrgPositions op ON op.ID = p.OrgPositionID WHERE p.ID = ?");
        if ($stU) {
            $stU->bind_param('i', $uid);
            $stU->execute();
            $uData = $stU->get_result()->fetch_assoc();
            $stU->close();
            if ($uData) {
                $userPosId = (int)($uData['OrgPositionID'] ?? 0);
                $userDept = trim((string)($uData['Department'] ?? ''));
            }
        }

        $stAud = $db->prepare("SELECT DISTINCT ProcessID FROM ProcessAudience WHERE (TargetType = 'personnel' AND TargetID = ?) OR (TargetType = 'position' AND TargetID = ?)");
        if ($stAud) {
            $stAud->bind_param('ii', $uid, $userPosId);
            $stAud->execute();
            $resAud = $stAud->get_result();
            while ($row = $resAud->fetch_assoc()) {
                $audProcIds[(int)$row['ProcessID']] = true;
            }
            $stAud->close();
        }
    }

    $ctx = [
        'pos_id' => $userPosId,
        'dept' => $userDept,
        'aud_proc_ids' => $audProcIds,
    ];
    $cache[$uid] = $ctx;
    return $ctx;
}

/**
 * بررسی دسترسی مجاز کاربر به یک فرآیند بر اساس شرکت، وضعیت و دامنه مخاطبان
 *
 * @param array $proc رکورد فرآیند شامل فیلدهای Status, CompanyID, OwnerPersonnelID, OwnerPositionID, AudienceType, AudienceDepartment
 * @param int $uid شناسه کاربر
 * @param ?int $companyId شناسه شرکت کاربر (یا null)
 * @param int $posId شناسه سمت سازمانی کاربر
 * @param string $dept دپارتمان سازمانی کاربر
 * @param array<int,bool> $audProcIds شناسه‌های فرآیندهای مجازشده در جدول ProcessAudience
 * @return bool
 */
function process_user_can_access(
    array $proc,
    int $uid,
    ?int $companyId,
    int $posId,
    string $dept,
    array $audProcIds
): bool {
    // ۱. فقط فرآیندهای فعال برای کاربران در دسترس است
    $status = (string)($proc['Status'] ?? ($proc['status'] ?? 'active'));
    if ($status !== 'active') {
        return false;
    }

    // ۲. تفکیک شرکت (Multi-tenancy): فرآیند باید متعلق به شرکت کاربر باشد یا عمومی (بدون شرکت)
    $procCompId = array_key_exists('CompanyID', $proc)
        ? (!empty($proc['CompanyID']) ? (int)$proc['CompanyID'] : null)
        : (array_key_exists('company_id', $proc) && !empty($proc['company_id']) ? (int)$proc['company_id'] : null);

    if ($procCompId !== null && $procCompId !== $companyId) {
        return false;
    }
    if ($companyId === null && $procCompId !== null) {
        return false;
    }

    // ۳. بررسی دامنه مخاطبان (Audience Evaluation)
    $ownerPersId = !empty($proc['OwnerPersonnelID']) ? (int)$proc['OwnerPersonnelID'] : (!empty($proc['owner_personnel_id']) ? (int)$proc['owner_personnel_id'] : 0);
    if ($ownerPersId > 0 && $ownerPersId === $uid) {
        return true;
    }

    $ownerPosId = !empty($proc['OwnerPositionID']) ? (int)$proc['OwnerPositionID'] : (!empty($proc['owner_position_id']) ? (int)$proc['owner_position_id'] : 0);
    if ($ownerPosId > 0 && $posId > 0 && $ownerPosId === $posId) {
        return true;
    }

    $audType = (string)($proc['AudienceType'] ?? ($proc['audience_type'] ?? 'all_company'));
    if ($audType === '' || $audType === 'all_company') {
        return true;
    }

    if ($audType === 'department') {
        $targetDept = trim((string)($proc['AudienceDepartment'] ?? ($proc['audience_department'] ?? '')));
        if ($targetDept !== '' && $dept !== '' && strcasecmp($targetDept, $dept) === 0) {
            return true;
        }
    }

    $procId = (int)($proc['ID'] ?? ($proc['id'] ?? 0));
    if ($procId > 0 && isset($audProcIds[$procId])) {
        return true;
    }

    return false;
}

/**
 * بررسی دسترسی کاربر به یک فرآیند (ساده‌شده با بارگذاری خودکار کانتکست)
 */
function process_user_has_access(mysqli $db, array $proc, int $uid, ?int $companyId): bool
{
    $ctx = user_load_access_context($db, $uid);
    return process_user_can_access($proc, $uid, $companyId, $ctx['pos_id'], $ctx['dept'], $ctx['aud_proc_ids']);
}
