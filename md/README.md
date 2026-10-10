# Report Tracking System (Pure Native PHP & MySQL)

Isang web-based Report Tracking System para sa pamamahala at pag-track ng mga report/reklamo ng mga mamamayan sa pamahalaan o organisasyon.

Binuo gamit ang **Native PHP (Vanilla PHP)**, **MySQL (PDO)**, at **HTML5/CSS3**.

---

## 📁 Project Structure

```text
report-tracking-system/
├── app/
│   ├── .htaccess          # Prevent direct web access to application internals
│   ├── bootstrap.php      # Project-root path constant
│   ├── route.php          # Maps requested public URLs to page implementations
│   ├── router.php         # PHP built-in-server front controller
│   └── pages/
│       ├── admin/         # Administration and management pages
│       ├── auth/          # Login, registration, verification, and account pages
│       ├── client/        # Dashboard, documents, messages, and profile pages
│       ├── public/        # Public landing page
│       └── reports/       # Report, workflow, and attachment endpoints
├── auth/
│   └── google_callback.php # OAuth callback; public URL retained
├── config/
│   └── db.php            # Database connection (PDO)
├── includes/
│   ├── auth.php          # Session authentication and helpers
│   ├── header.php        # Navigation and page header
│   └── footer.php        # Shared footer and page scripts
├── css/
│   └── style.css         # Shared responsive styling
├── image/                # Uploaded and static images
├── uploads/              # Report and message attachments
├── docs/                 # Project documentation and guides
├── md/                   # Markdown documentation
├── database/
│   └── Document_tracking.sql # MySQL schema and initial data
├── backups/              # Database and upload backups
├── .htaccess             # Apache front-controller rewrites and security headers
├── package.json          # Front-end dependencies
└── package-lock.json     # Locked front-end dependency versions
```

Page implementations live under `app/pages/`; public PHP URLs are dispatched
through `app/route.php`. Apache uses the root `.htaccess`; the PHP development
server must use `app/router.php`. The OAuth callback remains at
`auth/google_callback.php` so its configured redirect URL stays unchanged.

---

## � Documentation Hub

Para sa pinagsama-samang guide ng lahat ng Markdown docs, tingnan ang [DOCUMENTATION_HUB.md](DOCUMENTATION_HUB.md).

---

## �👥 User Roles & Permissions

| Role                   | Access & Permissions                                                                                           |
| :--------------------- | :------------------------------------------------------------------------------------------------------------- |
| **Admin**              | Full access: Mag-assign/reassign ng departamento, mag-approve/reject, at mag-manage ng users.                  |
| **Department / Staff** | Makita ang mga nakatokalang report, mag-update ng status (_Processing_, _Completed_), at maglagay ng work log. |
| **Client (Citizen)**   | Mag-submit ng bagong report at subaybayan ang status ng sariling mga ipinasa.                                  |

---

## 🚀 How to Run (Paano Patakbuhin)

### 1. Database Setup

Siguraduhing naka-import ang MySQL schema:

```sql
database/report_tracking.sql
```

Itinatakda ng `.user.ini` ang PHP upload limit na 25 MB bawat file at 512 MB
para sa kabuuang request (hanggang 20 file). Kung hindi agad makita ang bagong limit, maghintay
ng ilang minuto para mag-reload ang PHP configuration o itanong sa hosting
provider kung pinapayagan nila ang mga value na ito.

Maaari mong palitan ang DB password sa `config/db.php` kung kinakailangan.

Patakbuhin ang schema migrations minsan matapos mag-update ng application:

```bash
php config/db_migrate.php
```

Kailangang patakbuhin ang migration na ito bago magtalaga ng custom permission
roles sa mga user. Nagdadagdag ito ng `users.custom_role_id`; kung wala pa ang
column, hindi pa maia-assign o magagamit ang custom role ng account.

### 2. Run Local Server

Buksan ang PowerShell o Command Prompt sa root folder (`report-tracking-system`) at patakbuhin:

```bash
php -d upload_max_filesize=25M -d post_max_size=512M -d max_file_uploads=20 -d max_input_time=300 -S localhost:8000 -t . app/router.php
```

Pagkatapos, bisitahin ang: **`http://localhost:8000`**

### 3. Process Queued Emails

Ang report submission ay naglalagay ng email sa outbox para hindi maghintay ang
submit request sa SMTP server. Patakbuhin ang worker mula sa project root:

```bash
php app/workers/send_email_outbox.php
```

Para sa production, i-schedule ang command na ito bawat minuto gamit ang cron o
Windows Task Scheduler. Sa Windows server, buksan ang PowerShell sa project
folder at patakbuhin ang registration script:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\Register-EmailOutboxTask.ps1
```

Kailangan na available sa `PATH` ang `php.exe`; kung hindi, ibigay ang lokasyon
nito sa `-PhpPath`. Hihingi ang script ng credentials ng Windows account na
magpapatakbo sa worker. Tiyaking may access ang account na iyon sa project files
at sa database; kung environment variables ang DB settings, itakda ang mga ito
para sa account o sa buong system.

### 🔑 Test Accounts

The database migration no longer creates accounts with shared default
passwords. Before running `php config/db_migrate.php` on a new database, set
`INITIAL_ADMIN_EMAIL` and a unique `INITIAL_ADMIN_PASSWORD` of at least 16
characters in the server environment.
