-- ============================================================
-- دیتابیس پردازشگر خودکار فرآیندهای سازمان
-- 8 جدول اصلی + جدول کمکی شرکت‌ها
-- ============================================================

CREATE TABLE IF NOT EXISTS Companies (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  Name VARCHAR(120) NOT NULL COMMENT 'نام شرکت',
  Active TINYINT(1) NOT NULL DEFAULT 1,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  UNIQUE KEY uq_company_name (Name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='شرکت‌های سازمان';

-- 1) لیست پرسنل
CREATE TABLE IF NOT EXISTS Personnel (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  PersonnelCode VARCHAR(20) NOT NULL COMMENT 'کد پرسنلی',
  Name VARCHAR(60) NOT NULL COMMENT 'نام',
  Family VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'نام خانوادگی',
  CompanyID INT UNSIGNED NULL COMMENT 'شرکت',
  TeamID INT UNSIGNED NULL COMMENT 'تیم فرآیند (در صورت عضویت)',
  CanRequest TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'اجازه درخواست',
  CanSearch TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'اجازه جستجو',
  CanImprove TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'اجازه ثبت بهبود',
  CanApprove TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'اجازه تأیید',
  PasswordHash VARCHAR(255) NULL COMMENT 'رمز ورود کاربر',
  Active TINYINT(1) NOT NULL DEFAULT 1,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  UNIQUE KEY uq_personnel_code (PersonnelCode),
  KEY idx_personnel_company (CompanyID),
  KEY idx_personnel_team (TeamID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست پرسنل';

-- 2) لیست تیم‌های فرآیند
CREATE TABLE IF NOT EXISTS ProcessTeams (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TeamName VARCHAR(120) NOT NULL COMMENT 'نام تیم',
  TeamCode VARCHAR(30) NOT NULL COMMENT 'کد تیم برای ورود تیم فرآیند',
  PasswordHash VARCHAR(255) NOT NULL COMMENT 'رمز ورود تیم',
  CompanyID INT UNSIGNED NULL COMMENT 'شرکت',
  LeaderPersonnelID INT UNSIGNED NULL COMMENT 'سرپرست تیم (از پرسنل)',
  Description VARCHAR(255) NOT NULL DEFAULT '',
  Active TINYINT(1) NOT NULL DEFAULT 1,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  UNIQUE KEY uq_team_code (TeamCode),
  KEY idx_team_company (CompanyID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست تیم‌های فرآیند';

-- 3) لیست دسته‌های فرآیند = فولدرهای داخل دایرکتوری processes
CREATE TABLE IF NOT EXISTS ProcessCategories (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  Title VARCHAR(120) NOT NULL COMMENT 'عنوان دسته (نام فولدر)',
  FolderName VARCHAR(120) NOT NULL COMMENT 'نام فولدر روی دیسک',
  FolderPath VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'مسیر نسبی نسبت به processes/',
  SortOrder INT NOT NULL DEFAULT 0,
  Active TINYINT(1) NOT NULL DEFAULT 1,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  UNIQUE KEY uq_category_folder (FolderName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='دسته‌های فرآیند (فولدرها)';

-- 4) لیست فرآیندها
CREATE TABLE IF NOT EXISTS Processes (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  Name VARCHAR(150) NOT NULL COMMENT 'نام فرآیند',
  CategoryID INT UNSIGNED NOT NULL COMMENT 'دسته (فولدر)',
  CompanyID INT UNSIGNED NULL COMMENT 'شرکت',
  FilePath VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'مسیر فایل/پوشه فرآیند نسبت به processes/',
  OwnerPersonnelID INT UNSIGNED NULL COMMENT 'مالک فرآیند',
  ResponsibleTeamID INT UNSIGNED NULL COMMENT 'تیم فرآیند مسئول',
  Description TEXT NULL,
  Status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft' COMMENT 'پیش‌نویس/فعال/بایگانی',
  Version VARCHAR(20) NOT NULL DEFAULT '1.0',
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_process_category (CategoryID),
  KEY idx_process_company (CompanyID),
  KEY idx_process_team (ResponsibleTeamID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست فرآیندها';

-- 5) لیست بهبودها
CREATE TABLE IF NOT EXISTS Improvements (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ProcessID INT UNSIGNED NOT NULL COMMENT 'فرآیند مورد نظر',
  requester_type ENUM('user','team') NOT NULL DEFAULT 'user' COMMENT 'نوع درخواست‌کننده',
  requester_id INT UNSIGNED NOT NULL COMMENT 'شناسه درخواست‌کننده (پرسنل یا تیم)',
  Title VARCHAR(200) NOT NULL COMMENT 'عنوان بهبود',
  Description TEXT NULL COMMENT 'شرح بهبود',
  Status ENUM('pending','accepted','rejected','implemented') NOT NULL DEFAULT 'pending',
  AiVerdict ENUM('yes','no','pending','error') NOT NULL DEFAULT 'pending' COMMENT 'رأی داور هوش مصنوعی هرمس',
  AiAnalysis TEXT NULL COMMENT 'تحلیل تعارضات، پارادوکس‌ها و عوارض جانبی توسط هرمس',
  AiEvaluatedAt DATETIME NULL COMMENT 'تاریخ ارزیابی هوش مصنوعی',
  ReviewedByTeamID INT UNSIGNED NULL COMMENT 'تیم بررسی‌کننده',
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_improvement_process (ProcessID),
  KEY idx_improvement_status (Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست بهبودها';

-- 6) لیست پاسخ‌ها (پاسخ به بهبود/تغییر/اطلاع‌رسانی)
CREATE TABLE IF NOT EXISTS Responses (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  RefType ENUM('improvement','change','notification','process') NOT NULL COMMENT 'نوع مرجع',
  RefID INT UNSIGNED NOT NULL COMMENT 'شناسه مرجع',
  SenderType ENUM('user','team') NOT NULL DEFAULT 'user',
  SenderID INT UNSIGNED NOT NULL COMMENT 'شناسه فرستنده',
  Body TEXT NOT NULL COMMENT 'متن پاسخ',
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_response_ref (RefType, RefID),
  KEY idx_response_sender (SenderType, SenderID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست پاسخ‌ها';

-- 7) لیست تغییرات
CREATE TABLE IF NOT EXISTS Changes (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ProcessID INT UNSIGNED NOT NULL COMMENT 'فرآیند',
  ImprovementID INT UNSIGNED NULL COMMENT 'بهبود منجر به تغییر (در صورت وجود)',
  ChangeType ENUM('revision','replacement','addition','removal') NOT NULL DEFAULT 'revision' COMMENT 'نوع تغییر',
  Description TEXT NULL COMMENT 'شرح تغییر',
  OldVersion VARCHAR(20) NULL,
  NewVersion VARCHAR(20) NULL,
  AppliedByType ENUM('user','team') NOT NULL DEFAULT 'team',
  AppliedByID INT UNSIGNED NULL,
  Status ENUM('pending','approved','applied','rejected') NOT NULL DEFAULT 'pending',
  EffectiveDate DATE NULL COMMENT 'تاریخ اعمال',
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_change_process (ProcessID),
  KEY idx_change_status (Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست تغییرات';

-- 8) لیست اطلاع‌رسانی‌ها
CREATE TABLE IF NOT EXISTS Notifications (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  Title VARCHAR(200) NOT NULL,
  Body TEXT NULL,
  Link VARCHAR(255) NULL COMMENT 'لینک مستقیم به فرآیند، مستند یا گفتگو',
  FromType ENUM('user','team') NOT NULL DEFAULT 'team',
  FromID INT UNSIGNED NOT NULL COMMENT 'فرستنده',
  ProcessID INT UNSIGNED NULL COMMENT 'فرآیند مرتبط',
  TargetCompanyID INT UNSIGNED NULL COMMENT 'مخاطب: شرکت (NULL = همه)',
  TargetTeamID INT UNSIGNED NULL COMMENT 'مخاطب: تیم',
  TargetPersonnelID INT UNSIGNED NULL COMMENT 'مخاطب: پرسنل مشخص',
  Priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
  IsRead TINYINT(1) NOT NULL DEFAULT 0,
  SentAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_notification_target (TargetCompanyID, TargetTeamID, TargetPersonnelID),
  KEY idx_notification_process (ProcessID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست اطلاع‌رسانی‌ها';

-- 9) صف کارهای پس‌زمینه هرمس (Hermes Background Tasks)
CREATE TABLE IF NOT EXISTS HermesTasks (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  TaskType VARCHAR(50) NOT NULL DEFAULT 'revision',
  RefID INT UNSIGNED NOT NULL,
  TeamID INT UNSIGNED NULL,
  Status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  Payload MEDIUMTEXT NULL,
  Result MEDIUMTEXT NULL,
  ErrorMessage TEXT NULL,
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_task_type_ref (TaskType, RefID),
  KEY idx_task_status (Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='صف کارهای پس‌زمینه هرمس';

-- جدول کمکی: مجریان فرآیندها
CREATE TABLE IF NOT EXISTS ProcessExecutors (
  ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ProcessID INT UNSIGNED NOT NULL COMMENT 'فرآیند',
  PersonnelID INT UNSIGNED NULL COMMENT 'مجری پرسنلی',
  TeamID INT UNSIGNED NULL COMMENT 'مجری تیمی',
  Role VARCHAR(60) NOT NULL DEFAULT 'مجری' COMMENT 'نقش در فرآیند',
  CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_executor_process (ProcessID),
  KEY idx_executor_person (PersonnelID),
  KEY idx_executor_team (TeamID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لیست مجریان فرآیندها';

-- جدول کمکی: نشست‌های ورود (برای رهگیری)
CREATE TABLE IF NOT EXISTS LoginLog (
  ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  LoginType ENUM('user','team') NOT NULL,
  RefID INT UNSIGNED NULL COMMENT 'شناسه پرسنل یا تیم',
  Identifier VARCHAR(60) NOT NULL COMMENT 'کد وارد شده',
  Success TINYINT(1) NOT NULL,
  Ip VARCHAR(45) NOT NULL DEFAULT '',
  LoggedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ID),
  KEY idx_login_ref (LoginType, RefID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='لاگ ورود‌ها';


-- --------------------------------------------------------
-- تاریخچه پرسش و پاسخ کاربران با دستیار هوشمند فرآیندها (Hermes RAG Agent)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS AgentChats (
    ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    SessionType ENUM('user', 'team') NOT NULL,
    SessionID INT UNSIGNED NOT NULL,
    UserQuery TEXT NOT NULL,
    AiResponse TEXT NOT NULL,
    RelatedProcesses TEXT NULL,
    CreatedAt DATETIME NOT NULL,
    INDEX idx_session (SessionType, SessionID, CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='گفتگو با دستیار هوشمند فرآیندها';
