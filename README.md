# DENR FP-OMS — Feasibility Permit Online Management System

**Phase 1 standalone application** · DENR Caraga Regional Office XIII  
**Stack:** PHP 8.2+ (PHP 8), MySQL 8+/MariaDB 10.5+, Bootstrap 5.3, PDO, no PHP framework required.

> Status: This is a locally deployable Phase 1 codebase, not a tested production system. In particular, full end-to-end MySQL integration testing and a formal security review are still required before actual DENR data or identification documents are used. PHP and core-domain static checks were run in the authoring environment; database services were not available there.

## Implemented in Phase 1

- Public external applicant registration with one-time account creation.
- Representative, company, company address, contact details and valid-ID upload (PDF/JPEG/PNG).
- Registration files held outside web root, checked for MIME/type/size and protected by per-request authorization.
- Email-address login, PHP password hashing, CSRF tokens, hardened session cookies, throttled login attempts, password-change workflow, and one-time password-reset links.
- RICTU Super Administrator can provision DENR internal users and assign a primary role; passwords are forced to change at first login.
- LPDD-FUS review queue and individual review details: approve/activate, or return with mandatory correction remarks.
- Applicant sees registration status, deficiencies, latest attachments; can edit and resubmit while corrections are required.
- Audit log of registration submission, resubmission, review, login and staff provisioning.
- Clickable user Profile page plus a topbar notification bell with unread count, unread-first latest items, mark-all-read, and View All Notifications.
- Permission checks on each protected route; Bootstrap 5 responsive UI.

**Not yet available:** application submission (Step 2), document evaluation (Step 4), CENRO assessment, RRDC scheduling/deliberation, TOR/FP generation, electronic signatures, approval/release, service-clock automation, and general SMS/email notifications. Password-reset email is supported when server mail delivery is configured. The future-workflow SQL file only creates possible foundational tables for later work; it does not implement those screens or actions.

## Public Landing Page

- The site root `/` now opens a public DENR FP-OMS landing page instead of the authenticated dashboard.
- Landing sections cover the system overview, permit process, core application preparation, system user groups, contact information, and calls to register/sign in.
- Logged-in users see a direct **Go to Dashboard** action; applicants can also jump to **My Registration**.
- The landing page reuses the existing PWA installer, DENR/AMSOS-inspired green theme, Bootstrap 5, and clean URLs without `.php`.

## UI and Install App (AMSOS V2-inspired)

- Adapted the visual shell from **ICT-AMSOS V2** for FP-OMS: Inter typography, #08783f DENR green, deep-green gradient left sidebar, white cards, blue accent and Bootstrap 5.3 icons.
- Sidebar is **270px expanded / 82px collapsed**, remembers its state, supports responsive mobile offcanvas, and only shows available routes for each role. Permit workflow menu items labeled **Soon** are placeholders, not functioning modules.
- The topbar text-size selector (Small, Medium, Large, XL, XXL, XXXL) remembers its value per browser.
- The login screen includes an **Install DENR FP-OMS** card and native install prompt or browser-specific guidance. The PWA manifest, 192/512 PNG icons, and service worker cache **public static assets only**; it deliberately does not cache authenticated pages, registration uploads, API responses, or form submissions. Installation depends on browser support and secure HTTPS (or localhost).
- All internal links/forms use **clean URLs without .php**, such as `/login`, `/register`, `/dashboard`, `/my-registration`, `/review-queue`, `/staff`, `/change-password`. The application handles a legacy `GET /index.php?page=register` with a 302 redirect. It **does not redirect POST submissions**.
- **Apache:** keep `/public` as DocumentRoot and enable `mod_rewrite` + `AllowOverride FileInfo` / `.htaccess` for clean URLs. On another web server, reproduce the route rewrite rules. For PHP's built-in development server, use the router command below.

## 1. Requirements

- PHP 8.2 or newer with extensions `pdo_mysql`, `fileinfo`, `mbstring`, and `openssl` (PDO MySQL is mandatory; OpenSSL is required for Gmail STARTTLS).
- MySQL 8.0+ or MariaDB 10.5+ with InnoDB and utf8mb4 support.
- Apache 2.4 or another server configured so **only `/public` is web-accessible**, ideally HTTPS.
- `storage` writable by the PHP process, not publicly web-accessible.
- Bootstrap 5.3.3 CSS/JS currently loaded via jsDelivr CDN. For disconnected deployment, pin and host an approved local Bootstrap distribution, then modify `app/views.php` and the CSP in `app/bootstrap.php`.

## 2. Create an empty database + limited user

From a MySQL administrative session:

```sql
CREATE DATABASE denr_fp_oms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fp_oms_user'@'localhost' IDENTIFIED BY 'CHOOSE_A_LONG_RANDOM_DATABASE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON denr_fp_oms.* TO 'fp_oms_user'@'localhost';
```

Import schema using a separate MySQL migration/admin account with DDL privileges:

```bash
mysql -u root -p denr_fp_oms < database/001_core.sql
# Optional, schemas for later features; no working UI for steps 2–11:
mysql -u root -p denr_fp_oms < database/002_future_workflow.sql
# Required for Forgot Password / Reset Password:
mysql -u root -p denr_fp_oms < database/004_password_reset.sql
# Required for the bell notification center:
mysql -u root -p denr_fp_oms < database/005_notifications.sql
```

If using `DB_HOST=127.0.0.1`, MySQL may treat the connection as `'fp_oms_user'@'127.0.0.1'` rather than `'localhost'`. Set up the matching MySQL host permissions or change the host configuration accordingly. The supplied SQL user has DML privileges only; keep DDL privileges for database migrations/admins.

## 3. Configure the application

```bash
cp .env.example .env
# Edit .env: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL
# Set APP_DEBUG=false in production. Secure .env (e.g. chmod 600).
```

For XAMPP on Windows: install under `C:\xampp\htdocs\denr-fp-oms\`, configure the Apache vhost DocumentRoot to `...\denr-fp-oms\public`, enable PDO MySQL in `php.ini`, and make sure the storage directory is writable. **Do not** host the entire project tree as public content.

Optional upload settings in `php.ini`:

```ini
file_uploads=On
upload_max_filesize=5M
post_max_size=14M
max_file_uploads=10
```

## 4. Seed roles, create the initial RICTU admin

```bash
php scripts/seed_roles.php
php scripts/create_admin.php
```

The provisioning script creates an **active internal** account with the `super_admin` role and forces a password change on first sign-in. It rejects duplicate email addresses and records the bootstrap action in the audit log.

By default it securely prompts for full name, work email and an initial 12+ character password. For controlled deployment automation, the same values may be supplied at runtime through `FPOMS_ADMIN_NAME`, `FPOMS_ADMIN_EMAIL`, and `FPOMS_ADMIN_PASSWORD`. These values are read from the process environment only and are **not** stored in GitHub source files.

Example for PowerShell:

```powershell
$env:FPOMS_ADMIN_NAME="RICTU Super Administrator"
$env:FPOMS_ADMIN_EMAIL="authorized.admin@agency.gov.ph"
$env:FPOMS_ADMIN_PASSWORD="<temporary strong password>"
php scripts/create_admin.php
Remove-Item Env:FPOMS_ADMIN_NAME,Env:FPOMS_ADMIN_EMAIL,Env:FPOMS_ADMIN_PASSWORD
```

Use the actual authorized DENR administrator name/email at deployment time. Do **not** commit, paste into source code, or retain the temporary bootstrap password in scripts or documentation. On first sign-in the administrator must change it.

### Web-based first Super Administrator setup

FP-OMS also provides a one-time clean URL:

```text
/setup-admin
```

This route is available only while **no account with the `super_admin` role exists**. Before opening it, configure a long random one-time secret in the server `.env`:

```env
FPOMS_SETUP_KEY=use_a_long_random_one_time_secret_here
```

The setup form requires the RICTU administrator's full name, official/work email, the setup key, and an initial password. On successful submission the system creates the actual `users` and `user_roles` records, writes an audit event, and forces a password change on first login. Once a Super Administrator exists, `/setup-admin` returns an access notice and can no longer create another bootstrap administrator.

After bootstrap, remove or rotate `FPOMS_SETUP_KEY` in the server environment.

## Profile and notification bell

The authenticated top-right user card and the sidebar user card open the clean route `/profile`. The Profile page displays the fields already stored by FP-OMS, including name, email, account type, role, status, account ID, last login and activation date. Applicant accounts also show their company/proponent and contact details.

The topbar bell shows unread count and the latest five notifications with unread items first. It supports **Mark all read** and **View All Notifications**. Phase 1 currently creates notifications for:

- new applicant registration → LPDD-FUS;
- applicant registration resubmission → LPDD-FUS;
- LPDD-FUS approval or return-for-correction → applicant;
- creation of a DENR internal account → the new user.

Import the notification table once:

```bash
mysql -u root -p denr_fp_oms < database/005_notifications.sql
```

The shell is migration-safe: before the table is imported, the rest of FP-OMS continues to load and the bell simply shows no stored items.

## Password recovery

The Sign In page includes **Forgot password?**. The recovery flow uses a cryptographically random one-time token; only its SHA-256 hash is stored in the database. Reset links expire after **30 minutes**, are invalidated after use, and all outstanding reset links for the account are invalidated when a password is successfully changed through the reset flow.

Import the password-reset migration before using the feature:

```bash
mysql -u root -p denr_fp_oms < database/004_password_reset.sql
```

Password-reset email now uses **PHPMailer 6.9.1**, matching the PNPKI/D-Sign mail transport pattern. PHPMailer is bundled under `lib/phpmailer/`, so no Composer installation is required. Configure Gmail SMTP in the server `.env`:

```env
APP_URL=https://your-fpoms-host.example

MAIL_ENABLED=true
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-gmail-account@gmail.com
MAIL_PASSWORD=your-gmail-app-password
MAIL_FROM_NAME="DENR FP-OMS"
MAIL_FROM_ADDRESS=your-gmail-account@gmail.com
MAIL_VERIFY_PEER=true
MAIL_TIMEOUT=20
MAIL_DEBUG=false
```

`APP_URL` must be the real URL users can open because it is used to build the password-reset link. For the PNPKI-style Gmail setup, use STARTTLS (`tls`) on port 587 and keep `MAIL_VERIFY_PEER=true`. A Gmail account with 2-Step Verification normally needs an App Password rather than the ordinary account password.

The Gmail App Password is a deployment secret. The PNPKI/D-Sign source contains an older hardcoded Gmail credential, but FP-OMS deliberately does **not** copy or commit that secret. Put the authorized Gmail account and its current App Password only in the FP-OMS server `.env`. For Gmail, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, and `MAIL_ENCRYPTION=tls` match the PNPKI transport. `MAIL_FROM_ADDRESS` should normally match the authenticated Gmail account.

For XAMPP/local deployment, the easiest safe setup is the CLI helper. It writes the Gmail settings and your newly generated App Password only to the ignored local `.env`:

```bash
php scripts/configure_smtp.php
```

The helper defaults the Gmail account prompt to `feasibilitypermit@gmail.com`, strips spaces from Google's displayed 16-character App Password, and never prints the password back. If an App Password has been exposed in chat, screenshots, source control, or another shared location, revoke it and generate a new one before running this command.

Test SMTP delivery from the FP-OMS application directory:

```bash
php scripts/test_smtp.php
```

The command prompts for a recipient email and sends a DENR FP-OMS test message. On failure, it writes a non-secret SMTP diagnostic to the PHP error log.

`APP_URL` must be the real URL users can open because it is used to build the password-reset link. The public form returns a generic confirmation whether or not an email exists, limits reset requests per account and IP address, and does not store raw reset tokens.

If outbound mail is not yet available, RICTU can recover an account locally from the application server:

```bash
php scripts/reset_password.php
```

The CLI tool prompts for the account email and a new password, hashes the password with PHP's password API, invalidates unused reset tokens, and writes an audit event. Do not put the new password in source files or shell scripts.

## 5. Run locally

```bash
php -S 127.0.0.1:8000 -t public public/router.php
```

Open `http://127.0.0.1:8000`.

Typical smoke test:

1. Sign in as RICTU admin; change the initial password.
2. Create a staff user with role `LPDD-FUS` on **Users**.
3. Sign out; register a test applicant and attach a dummy/sample ID, **not a real government ID**.
4. Sign in as applicant to see `Pending LPDD-FUS Review`.
5. Sign in as LPDD-FUS and open **Review Queue**.
6. Return a test registration for correction with remarks. As applicant, resubmit and observe the new revision.
7. Approve the resubmitted registration; applicant should now see `Account Activated`.
8. Verify applicants cannot open the review or internal-users route, and cannot download another applicant's files.

## Roles provisioned

| Role code | Assigned function | Phase 1 UI |
|---|---|---|
| `super_admin` | RICTU: system/user administration | Internal-user creation |
| `applicant` | External applicant | Registration/status/correction |
| `lpdd_fus` | LPDD-FUS: registration/document review, drafting | Registration review queue |
| `ro_receiving` | RO Receiving/Releasing | Placeholder dashboard |
| `lpdd_chief` | LPDD Chief | Placeholder dashboard |
| `ard_ts` | ARD Technical Services | Placeholder dashboard |
| `red` | Regional Executive Director | Placeholder dashboard |
| `cenro_twg` | CENRO technical field validation | Placeholder dashboard |
| `penro_user` | PENRO coordination | Placeholder dashboard |
| `rrdc_member` | RRDC review committee | Placeholder dashboard |
| `rrdc_secretariat` | RRDC scheduling/document handling | Placeholder dashboard |

Permissions are checked on the server, not simply by hiding buttons. The schema supports multiple roles per account (`user_roles`), while Phase 1 creates accounts with one role. Office scoping/editing and admin user lifecycle management are reserved for Phase 2.

## Project tree

```text
denr-fp-oms/
├── .env.example                # copy to .env (secret; never commit)
├── .gitignore
├── app/
│   ├── bootstrap.php            # environment + security headers/session
│   ├── core.php                 # PDO, CSRF, authorization, audit, helpers
│   ├── auth.php                 # login, logout, password change
│   ├── smtp.php                 # PHPMailer SMTP wrapper
│   ├── password_reset.php       # forgot/reset password workflow
│   ├── domain.php               # status, validators, role definitions
│   ├── registration.php         # applicant submission/review/uploads
│   ├── staff.php                # RICTU provisioning
│   ├── notifications.php        # bell notification center
│   ├── profile.php              # authenticated user profile
│   └── views.php                # HTML views
├── database/
│   ├── 001_core.sql             # Phase 1 schema
│   ├── 002_future_workflow.sql  # tables only (Steps 2–11 later)
│   ├── 004_password_reset.sql   # password reset token storage
│   └── 005_notifications.sql    # bell notification storage
├── public/
│   ├── index.php                # application entry/router
│   ├── router.php               # built-in PHP development server clean routing
│   ├── .htaccess                # Apache rewrite rules
│   ├── manifest.webmanifest     # PWA configuration
│   ├── service-worker.js        # public static assets only
│   └── assets/                 # AMSOS-inspired styles, JS, and install icons
├── scripts/
│   ├── seed_roles.php
│   ├── create_admin.php
│   ├── reset_password.php
│   ├── configure_smtp.php        # safely writes Gmail SMTP values to local .env
│   └── test_smtp.php
├── storage/                    # PRIVATE uploaded documents
├── tests/domain_test.php
└── docs/ARCHITECTURE.md
```

## Processing rules and source traceability

Annex A, *Issuance of Feasibility Permit Process Flow*, specifies 11 steps. Step 1 registration and activation (within 1 working day) is **excluded** from the 15-day permit processing time, which begins after account activation. The LPDD-FUS controls registration validation and resubmission/approval. The uploaded *USERS-ROLES-FUNCTIONS_FP* specifies Super Administrator (RICTU), Client, CENRO/TWG and Regional internal functions. Template Nos. 1–5, FP form, approval sheet and related memo formats are reserved as inputs for later document automation and are **not** rewritten or autogenerated in this phase.

The diagram shows a 15-day total while its individually labeled durations for Steps 3–11 appear to account for 14 days. Step 10 has no separate duration. Do not invent or hard-code a processing-calendar allocation without DENR review and approval. The system currently does not start or calculate an SLA clock.

## Before production

- Confirm detailed official documentary requirements, eligibility, approval policies and role/office matrix with LPDD-FUS and RICTU.
- Conduct automated/integration tests on a real MySQL staging instance; perform penetration/security review, malware scanning for uploads, backups, disaster recovery, retention/privacy impact assessment and authorized user acceptance testing.
- Add encrypted HTTPS transport, trusted email verification/reset flow, production monitoring, controlled administrator credential distribution, data access logging and operational incident procedures.
- Consider securing applicant onboarding against spam/automation and requiring stronger authentication for government approvers.
- Secure staff initial passwords using an approved out-of-band channel; Phase 1 does not send emails automatically.
