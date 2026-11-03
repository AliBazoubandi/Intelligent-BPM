# ⚡ RobinFlow (رابین‌فلو)

> **سامانه هوشمند مدیریت و بهبود فرآیندهای سازمانی**  
> *Intelligent Organizational Business Process Management (BPM) & Continuous Improvement System*  
> Developed for **تیم هوش تجاری رهباریان رابین (Rahbarian Robin BI Team)**

---

## 📖 Overview

**RobinFlow** is an enterprise-grade web application engineered to catalog, document, execute, and continuously optimize organizational business processes across departments. 

It integrates an **Autonomous AI Agent (Hermes)** that acts as an impartial referee for proposed process improvements (checking against conflicting departmental procedures and ripple effects) and provides a **Retrieval-Augmented Generation (RAG) Conversational Assistant** that answers employee process questions with live citations.

---

## ✨ Key Features

- **🏢 Departmental Process Hierarchy**: Structured repository categorized into Administrative & Financial, HR, IT, Commercial, and Support services.
- **👥 Dual Dedicated Portals**:
  - **Employee Portal (`/user`)**: Browse active company processes, view files, submit improvement proposals, track version changes, and ask questions.
  - **Process Engineering Console (`/team`)**: Full dossier management, version tracking, process editor, category definitions, company setups, and improvement proposal reviews.
- **🤖 Autonomous AI Improvement Referee (Hermes Agent)**:
  - Non-blocking asynchronous background queue (`bin/hermes_worker.php`).
  - Cross-process paradoxical checks, side-effect detection, and verdict generation (`approved` / `rejected` / `conditional`).
- **💬 Process AI Chat & RAG Assistant (`/ask.php`)**:
  - Natural language Persian chat assistant grounded in official company process files and guidelines.
  - Real-time typing indicators, quick prompt chips, and instant process dossier citations.
- **🎨 Modern Persian Design System**:
  - Native **Mikhak** Persian font typography.
  - Lightweight vector SVG icon library.
  - Responsive RTL layouts with customized user & team themes.

---

## 🛠️ Tech Stack & Requirements

- **PHP**: 8.1+ (with `mysqli`, `curl`, `mbstring`, `json`)
- **Database**: MySQL 8.0+ / MariaDB 10.4+
- **AI Engine**: Hermes AI Agent (`http://127.0.0.1:8642/v1`)
- **Frontend**: Vanilla ES6 JavaScript, Modern CSS3 with Design Tokens, Mikhak Font
- **Web Server**: Apache (`mod_rewrite` enabled) or PHP Built-in Server

---

## 🚀 Quick Start

### 1. Clone & Configure
```bash
git clone <repo-url> robinflow
cd robinflow
copy .env.example .env
```

Edit `.env` to configure your database and Hermes credentials:
```ini
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=org_process

HERMES_URL=http://127.0.0.1:8642/v1/chat/completions
HERMES_KEY=your_hermes_key_here
HERMES_MODEL=hermes-agent
```

### 2. Run Database & Seed Wizard
Run the installer via CLI:
```bash
php install.php
```
*Or visit `http://localhost:8000/install.php` in your browser.*

### 3. Start Application Server
```bash
php -S 0.0.0.0:8000
```

### 4. Portals & Default Credentials

| Portal | URL | Default Identifier | Default Password |
| :--- | :--- | :--- | :--- |
| **Employee Portal** | `http://localhost:8000/user/` | `P-1001` | `123456` |
| **Team Engineering Console** | `http://localhost:8000/team/` | `TP-1000` | `team123` |

---

## 📂 Project Directory Structure

```text
robinflow/
├── assets/                  # Mikhak fonts, stylesheets & design tokens
│   ├── fonts/mikhak/        # Mikhak font families (Variable + Static + FD)
│   ├── mikhak.css           # Typography font-face declarations
│   └── style.css            # Unified application styles
├── bin/                     # CLI workers & daemons
│   └── hermes_worker.php    # Async background task for AI evaluations
├── db/                      # Database schema & migrations
│   └── schema.sql           # Canonical relational schema
├── inc/                     # Reusable backend core modules
│   ├── auth.php             # Authentication, CSRF, security headers
│   ├── chat_view.php        # Process AI Chat view controller
│   ├── files.php            # File attachments & security utilities
│   ├── hermes.php           # Hermes API client, RAG engine, evaluators
│   ├── icons.php            # Unified SVG vector icon catalog
│   ├── layout.php           # Responsive header & footer layouts
│   └── ui.php               # Persian formatting, dates & badges
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
└── install.php              # Automated initialization wizard
```

---

## 🔒 Security

- Parameterized SQL statements with `mysqli` prepared queries throughout.
- Built-in CSRF token protection for state-changing forms and AJAX endpoints.
- `.htaccess` access-denial guards across all document upload directories.
- Sensitive environment variables isolated in `.env` (ignored by git).

---

## 📜 License

✨ تمام حقوق اين وب‌سايت مربوط به تیم هوش تجاری رهباریان رابین است. ✨

