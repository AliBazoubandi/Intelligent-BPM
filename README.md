# ⚡ RabinFlow (رابین‌فلو)

> **سامانه هوشمند مدیریت و بهبود فرآیندهای سازمانی**  
> *Intelligent Organizational Business Process Management (BPM) & Continuous Improvement System*  
> Developed for **تیم هوش تجاری رهباریان رابین (Rahbarian Rabin BI Team)**

---

## 📖 Overview

**RabinFlow** is an enterprise-grade web application engineered to catalog, document, execute, and continuously optimize organizational business processes across departments. 

It integrates an **Autonomous AI Agent (Hermes)** that acts as an impartial referee for proposed process improvements (checking against conflicting departmental procedures and ripple effects), automatically rewrites and compiles approved process guidelines into official PDF documents, and sends real-time notifications to process owners and affected stakeholders via **Bale Messenger**.

---

## ✨ Key Features

- **🏢 Departmental Process Hierarchy**: Structured repository categorized into Administrative & Financial, HR, IT, Commercial, and Support services.
- **👥 Dual Dedicated Portals**:
  - **Employee Portal (`/user`)**: Browse active company processes, view files, submit improvement proposals, track version changes, and ask questions.
  - **Process Engineering Console (`/team`)**: Full dossier management, version tracking, process editor, category definitions, company setups, organizational chart mapping, and improvement proposal reviews.
- **🤖 Autonomous AI Improvement Referee & Document Compiler (Hermes)**:
  - Non-blocking asynchronous background queue (`bin/hermes_worker.php`).
  - Cross-process paradoxical checks, side-effect detection, and verdict generation (`approved` / `rejected` / `conditional`).
  - Automated official PDF guideline drafting, revision compiling, and version archiving.
- **📱 Live Bale Messenger Integration (`inc/bale.php`)**:
  - Automated notification dispatch via Palood API (`https://rbb.palood.ir/api_send.php`).
  - Dynamic organizational chart lookups to notify process owners and affected personnel.
  - Direct message delivery with document links and compiled PDF attachment files.
- **📁 Smart Process File Manager (`/team/files.php`)**:
  - Interactive collapsible/expandable directory tree with live process dossier linking.
  - Crisp, modern vector SVG icons for upload, folder creation, rename, move, view, download, and delete actions.
  - Instant client-side filtering and file management modals.
- **💬 Process AI Chat & RAG Assistant (`/ask.php`)**:
  - Natural language Persian chat assistant grounded in official company process files and guidelines.
  - Real-time typing indicators, quick prompt chips, and instant process dossier citations.
- **📅 Comprehensive Jalali (Shamsi) Calendar**:
  - Standardized Shamsi date formatting across all tables, logs, and notifications.
  - Clear date-before-time ordering (`YYYY/MM/DD - HH:MM`).
- **🎨 Modern Persian Design System**:
  - Native **Mikhak** Persian font typography.
  - Lightweight vector SVG icon library (`inc/icons.php`).
  - Adaptive Light & Dark themes with high-contrast form controls and custom-styled checkboxes.

---

## 🛠️ Tech Stack & Requirements

- **PHP**: 8.1+ (with `mysqli`, `curl`, `mbstring`, `json`)
- **Database**: MySQL 8.0+ / MariaDB 10.4+
- **AI Engine**: Hermes AI Agent (`http://127.0.0.1:8642/v1`)
- **Messenger API**: Bale Messenger Gateway (via Palood Web Service)
- **Frontend**: Vanilla ES6 JavaScript, Modern CSS3 with Design Tokens, Mikhak Font
- **Web Server**: Apache (`mod_rewrite` enabled) or PHP Built-in Server

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
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=org_process

HERMES_URL=http://127.0.0.1:8642/v1/chat/completions
HERMES_KEY=your_hermes_key_here
HERMES_MODEL=hermes-agent

BALE_API_URL=https://rbb.palood.ir/api_send.php
BALE_API_KEY=your_bale_api_key_here
BALE_API_ENABLED=true
```

### 2. Run Database & Seed Wizard
Run the installer via CLI:
```bash
php install.php
```
*Or visit `http://localhost:8000/install.php` in your browser.*

### 3. Start Application Server (1-Click)

Simply double-click `start.bat` (or `run.bat`) in the project root, or execute:
```cmd
start.bat
```
*(On PowerShell, you can also run `.\start.ps1`, or `php bin/serve.php`)*

This script automatically:
1. Detects and verifies the PHP runtime and required extensions.
2. Checks MySQL/MariaDB and starts the database daemon automatically if not yet running.
3. Checks connectivity to the Hermes AI agent service.
4. Opens `http://localhost:8000/` in your default web browser.
5. Displays test credentials for both User and Team consoles.

To stop the server at any time, press `Ctrl+C` in the console window or run `stop.bat`.

### 4. Portals & Default Credentials

| Portal | URL | Default Identifier | Default Password |
| :--- | :--- | :--- | :--- |
| **Employee Portal** | `http://localhost:8000/user/` | `P-1001` | `123456` |
| **Team Engineering Console** | `http://localhost:8000/team/` | `TP-1000` | `team123` |

---

## 📂 Project Directory Structure

```text
rabinflow/
├── assets/                  # Mikhak fonts, stylesheets & design tokens
│   ├── fonts/mikhak/        # Mikhak font families (Variable + Static + FD)
│   ├── mikhak.css           # Typography font-face declarations
│   └── style.css            # Unified application styles & adaptive theme tokens
├── bin/                     # CLI workers & dev tools
│   ├── hermes_worker.php    # Async background task for AI evaluations & revisions
│   └── serve.php            # Multi-platform local server launcher
├── db/                      # Database schema & migrations
│   └── schema.sql           # Canonical relational schema
├── inc/                     # Reusable backend core modules
│   ├── auth.php             # Authentication, CSRF, security headers
│   ├── bale.php             # Bale Messenger integration & automated dispatch
│   ├── chat_view.php        # Process AI Chat view controller
│   ├── files.php            # File attachments & security utilities
│   ├── hermes.php           # Hermes API client, RAG engine, evaluators
│   ├── icons.php            # Unified SVG vector icon catalog
│   ├── layout.php           # Responsive header & footer layouts
│   └── ui.php               # Jalali dates, Persian numbers & status badges
├── processes/               # Departmental process documents & guidelines
│   ├── اداری و مالی/
│   ├── تجاری و بازرگانی/
│   ├── فناوری اطلاعات/
│   ├── منابع انسانی/
│   └── پشتیبانی و خدمات/
├── team/                    # Process engineering team management portal
├── user/                    # Employee self-service portal
├── config.php               # Environment loader & global constants
├── index.php                # Gateway router
├── install.php              # Automated initialization wizard
├── start.bat                # 1-Click Windows Command Prompt launcher
├── start.ps1                # 1-Click PowerShell launcher
└── stop.bat                 # Server stop utility
```

---

## 🔒 Security

- Parameterized SQL statements with `mysqli` prepared queries throughout.
- Built-in CSRF token protection for state-changing forms and AJAX endpoints.
- `.htaccess` access-denial guards across all document upload directories.
- Sensitive environment variables isolated in `.env` (ignored by git).
