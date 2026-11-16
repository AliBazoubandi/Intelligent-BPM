<p align="center">
  <img src="assets/logo.png" alt="RabinFlow Logo" width="130">
</p>

# ⚡ RabinFlow (رابین‌فلو)

> **سامانه هوشمند مدیریت و بهبود فرآیندهای سازمانی**  
> *Intelligent Organizational Business Process Management (BPM) & Continuous Improvement System*

---

## 📖 Overview

**RabinFlow** is an enterprise-grade web application engineered to catalog, document, execute, and continuously optimize organizational business processes across departments. 

It integrates an **Autonomous AI Agent (Hermes)** that acts as an impartial referee for proposed process improvements (checking against conflicting departmental procedures and ripple effects), automatically drafts and compiles approved process guidelines into official PDF documents with shaped Persian typography (**Vazirmatn**), and sends real-time notifications to process owners and affected stakeholders via **Bale Messenger**.

---

## ✨ Key Features

- **🏢 Departmental Process Hierarchy**: Structured repository categorized into Administrative & Financial, HR, IT, Commercial, and Support services.
- **👥 Dual Dedicated Portals**:
  - **Employee Portal (`/user`)**: Browse active company processes, view files, submit improvement proposals, track version changes, and ask questions.
  - **Process Engineering Console (`/team`)**: Full dossier management, version tracking, process editor, category definitions, company setups, organizational chart mapping, and improvement proposal reviews.
- **🤖 Autonomous AI Improvement Referee & Document Compiler (Hermes)**:
  - Non-blocking asynchronous background queue (`bin/hermes_worker.php`) with atomic task locking.
  - Cross-process paradoxical checks, side-effect detection, and verdict generation (`yes` / `no` / `uncertain`).
  - Automated official PDF guideline drafting, revision compiling, and version archiving.
  - Interactive process design from audio interviews and documents with asynchronous transcription (`bin/transcribe_audio.py`).
- **📱 Live Bale Messenger Integration (`inc/bale.php`)**:
  - Automated notification dispatch via Bale Messenger Gateway API (`https://api.example.com/api_send.php`).
  - Strict TLS 1.2+ certificate verification.
  - Dynamic organizational chart lookups to notify process owners and affected personnel.
  - Asynchronous background queue delivery for instant, non-blocking HTTP requests.
- **📁 Secure Process File Manager (`/team/files.php` & `download.php`)**:
  - Interactive collapsible/expandable directory tree with live process dossier linking.
  - Multi-extension upload protection against malicious scripts (e.g. `*.php.*`).
  - Authenticated and authorized file downloads (`download.php`) preventing direct unauthenticated URL scraping.
  - Vector SVG icons for upload, folder creation, rename, move, view, download, and delete actions.
- **💬 Process AI Chat & RAG Assistant (`/ask.php`)**:
  - Natural language Persian chat assistant grounded in official company process files and guidelines.
  - High-performance disk caching (`RagDocumentCache`) avoiding redundant full-disk scans.
  - Real-time typing indicators, quick prompt chips, and instant process dossier citations.
- **📄 Official PDF Publishing Engine (`inc/pdf_generator.php`)**:
  - Unified mPDF document generator with native Persian font shaping (**Vazirmatn**).
  - Standardized organizational headers, metadata blocks, flow steps, and revision logs.
- **📅 Comprehensive Jalali (Shamsi) Calendar**:
  - Standardized Shamsi date formatting across all tables, logs, and notifications.
  - Clear date-before-time ordering (`YYYY/MM/DD - HH:MM`).
- **🎨 Modern Persian Design System**:
  - Native **Mikhak** and **Vazirmatn** typography.
  - Unified vector SVG icon library (`inc/icons.php`).
  - Adaptive Light & Dark themes with high-contrast form controls and custom-styled inputs.

---

## 🛠️ Tech Stack & Requirements

- **PHP**: 8.2+ (with `mysqli`, `curl`, `mbstring`, `json`, `gd`)
- **Database**: MySQL 8.0+ / MariaDB 10.4+
- **PDF Engine**: mPDF 8+ with Vazirmatn Persian TrueType fonts
- **Python**: 3.10+ (for background audio transcription and text extraction)
- **AI Engine**: Hermes AI Agent (`http://127.0.0.1:8642/v1`)
- **Messenger API**: Bale Messenger Gateway API
- **Frontend**: Vanilla ES6 JavaScript, Modern CSS3 with Design Tokens, Mikhak Font
- **Web Server**: Apache (`mod_rewrite` enabled) or PHP Built-in Server with `bin/router.php`

---

## 🚀 Quick Start

### 1. Clone & Configure
```bash
git clone <repo-url> rabinflow
cd rabinflow
copy .env.example .env
```

Edit `.env` to configure your database, Hermes credentials, and Bale settings:
```ini
APP_ENV=dev
APP_URL=http://localhost:8000

DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=org_process

HERMES_URL=http://127.0.0.1:8642/v1/chat/completions
HERMES_KEY=your_hermes_key_here
HERMES_MODEL=hermes-agent

BALE_API_URL=https://api.example.com/api_send.php
BALE_API_KEY=your_bale_api_key_here
BALE_API_ENABLED=true
```

### 2. Initialize Database & Schemas
Run the installer via CLI:
```bash
php install.php
```
*(In `APP_ENV=dev`, the installer automatically seeds default test departments, personnel, and sample processes).*

Alternatively, install via web/curl (requires `ALLOW_INSTALL=1` and authenticated team session):
```bash
curl -X POST -F "setup_token=YOUR_SETUP_TOKEN" -b "OPSESS=..." http://localhost:8000/install.php
```

### Audio Transcription Engines (Speech-to-Text)

RabinFlow supports two speech recognition modes for process discovery from interview audio:
- **Local On-Premise Engine (`TRANSCRIBE_ENGINE=local`, Default)**: Privacy-first, air-gapped transcription using `faster-whisper` on CPU (int8 quantization). Audio data never leaves the local network.
  - Dependencies: `ffmpeg` (installed and in PATH), and `pip install faster-whisper`
- **Google Cloud Speech Engine (`TRANSCRIBE_ENGINE=google`)**: Cloud transcription via Google Speech API over HTTPS.
  - Dependencies: `ffmpeg`, and `pip install SpeechRecognition`

### 3. Start Application Server (1-Click)

Simply double-click `run.bat` (or `srun.bat` / `start.bat`) in the project root, or execute:
```cmd
run.bat
```
*(On PowerShell, you can also run `.\start.ps1`, or `php bin/serve.php`)*

This script automatically:
1. Detects and verifies the PHP runtime and required extensions.
2. Checks MySQL/MariaDB and starts the database daemon automatically if not yet running.
3. Checks connectivity to the Hermes AI agent service.
4. Boots the PHP web server with `bin/router.php` firewall protecting sensitive files.
5. Opens `http://localhost:8000/` in your default web browser.

To stop the server at any time, press `Ctrl+C` in the console window or run `stop.bat`.

### 4. Development Accounts (Seeded in Dev Mode)

| Portal | URL | Identifier | Initial Password |
| :--- | :--- | :--- | :--- |
| **Employee Portal** | `http://localhost:8000/user/` | `P-1001` | `123456` |
| **Team Engineering Console** | `http://localhost:8000/team/` | `TP-1000` | `team123` |

> *Note: For security, login forms implement rate limiting (locked after 10 consecutive failed attempts for 10 minutes).*

---

## 📂 Project Directory Structure

```text
rabinflow/
├── assets/                  # Mikhak & Vazirmatn fonts, stylesheets & design tokens
│   ├── fonts/               # Vazirmatn TTF fonts for official PDF generation
│   ├── fonts/mikhak/        # Mikhak font families for web interface
│   ├── mikhak.css           # Typography font-face declarations
│   └── style.css            # Unified application styles & adaptive theme tokens
├── bin/                     # CLI workers & server tooling
│   ├── hermes_worker.php    # Async background task for AI evaluations & revisions
│   ├── router.php           # Built-in web server security firewall & router
│   ├── serve.php            # Multi-platform local server launcher
│   ├── transcribe_audio.py  # Asynchronous speech-to-text Python helper
│   └── extract_document_text.py # Document extraction helper (.docx, .pdf, .txt)
├── db/                      # Database schema & migrations
│   └── schema.sql           # Canonical relational schema
├── inc/                     # Reusable backend core modules
│   ├── auth.php             # Authentication, session security & rate limiting
│   ├── bale.php             # Bale Messenger integration & async queue dispatch
│   ├── chat_view.php        # Process AI Chat view controller
│   ├── files.php            # File attachments & extension security filters
│   ├── hermes.php           # Hermes API client, RAG engine & process compiler
│   ├── icons.php            # Unified SVG vector icon catalog
│   ├── layout.php           # Responsive header/footer layouts & notification toasts
│   ├── pdf_generator.php    # Unified mPDF document compilation engine
│   └── ui.php               # Jalali dates, Persian numbers & status badges
├── processes/               # Departmental process documents & guidelines
│   ├── اداری و مالی/
│   ├── تجاری و بازرگانی/
│   ├── فناوری اطلاعات/
│   ├── منابع انسانی/
│   └── پشتیبانی و خدمات/
├── team/                    # Process engineering team management portal
├── user/                    # Employee self-service portal
├── config.php               # Environment loader, security constants & DB connection
├── download.php             # Authenticated file download gateway
├── index.php                # Landing page & router
├── install.php              # Automated initialization wizard
├── run.bat                  # 1-Click launcher
├── srun.bat                 # 1-Click launcher alias
├── start.bat                # Windows Command Prompt launcher
├── start.ps1                # PowerShell launcher
└── stop.bat                 # Server stop utility
```

---

## 🔒 Security Hardening

- **Built-in Router Firewall (`bin/router.php`)**: Strictly blocks direct web access to `.env`, `.sql`, `.log`, `.md`, `db/`, `bin/`, `inc/`, and internal configuration files (`403 Forbidden`).
- **Protected Process Documents (`download.php`)**: Direct HTTP access to `processes/` is denied (`Require all denied`). All file downloads require valid active session authentication and authorization.
- **Strict Extension Filtering**: Multi-extension upload/rename protection (`fm_allowed_ext()`) prevents code execution via disguised filenames (e.g. `shell.php.txt`).
- **XSS Sanitization**: Automated output escaping via `e()` and client-side `escapeHtml()` with strict URL validation (`is_safe_app_url()`).
- **Brute-Force & Rate Limiting**: Automatic account/IP lockout for 10 minutes upon 10 failed login attempts.
- **CSRF Token Protection**: Cryptographically secure tokens (`random_bytes(16)` + `hash_equals()`) enforced on all state-changing POST requests.
- **Atomic File Operations & Transactions**: Procedure revisions and process creations execute within DB transactions (`begin_transaction`), staging files in temporary directories and rolling back automatically on failure.
- **Independent Notification Read Tracking**: `NotificationReads` tracks read status per individual user/team, ensuring broadcast notifications remain unread for other users.
- **Strict TLS Verification**: All communications with Bale Messenger API enforce TLS certificate and host verification.
