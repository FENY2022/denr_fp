# DENR FP-OMS — Feasibility Permit Online Management System

**Phase 1 standalone application** · DENR Caraga Regional Office XIII  
**Stack:** PHP 8.2+ (PHP 8), MySQL 8+/MariaDB 10.5+, Bootstrap 5.3, PDO, no PHP framework required.

> Status: This is a locally deployable Phase 1 codebase, not a tested production system. In particular, full end-to-end MySQL integration testing and a formal security review are still required before actual DENR data or identification documents are used. PHP and core-domain static checks were run in the authoring environment; database services were not available there.

## Implemented in Phase 1

- Public external applicant registration now saves credentials in **user_client**, separate from DENR personnel in **users**, with distinct session identity and optional SMTP acknowledgment after submission.
- Applicant name, company, contact details, and multiple Valid ID uploads with individual ID Type selections (PDF/JPEG/PNG).
- Up to 5 Valid IDs and 5 supporting documents per submission. On public registration, each selected file uploads immediately to **private draft storage**, so it stays listed after reload in the same browser session. Drafts expire after 24 hours and successful registration moves their content into permanent private storage. Existing document revisions, MIME checks and download authorization remain.
- Email-address login, PHP password hashing, CSRF tokens, hardened session cookies, throttled login attempts, password-change workflow, and one-time password-reset links.
- RICTU Super Administrator can provision DENR internal users and assign a primary role; passwords are forced to change at first login.
- LPDD-FUS review queue and individual review details: approve/activate, or return with mandatory correction remarks.
- Applicant sees registration status, deficiencies, latest attachments; can edit and resubmit while corrections are required.
- Audit log of registration submission, resubmission, review, login and staff provisioning.
- Clickable user Profile page plus a topbar notification bell with unread count, unread-first latest items, mark-all-read, and View All Notifications.
- Permission checks on each protected route; Bootstrap 5 responsive UI.

**Not yet available:** RO Receiving Step 3 processing actions, formal document evaluation (Step 4), CENRO assessment, RRDC scheduling/deliberation, TOR/FP generation, electronic signatures, RED approval/permit release, and service-clock automation. Annex A Step 2 permit submission and LPDD-FUS initial review are implemented in an isolated add-on module (see below). Its RO Receiving inbox is read-only for handoff; it does not constitute Step 3 processing. Password-reset and permit notification emails require working SMTP configuration.

## Annex A Step 2 — Applicant Submission and LPDD-FUS Initial Review

**Approved process reference:** the second Annex A flowchart supplied for this project, which routes **Applicant → LPDD-FUS Technical Staff initial review → RO Receiving/Releasing (Admin)**. An incomplete initial submission returns to the applicant with a letter/instructions describing the lacking requirements and can be resubmitted for review; a complete one forwards to RO Receiving. Step 2 initial review and Step 4 formal documentary evaluation are distinct. Step 1 account registration and activation remain unchanged.

**Deployment:** Before opening Step 2, back up the `denr_fp_oms` database and private `storage/` directory, then apply the one-time additive migration:

```bash
mysql -u root -p denr_fp_oms < database/014_step2_initial_application_review.sql
```

This migration creates `permit_step2_applications`, `permit_step2_documents`, and `permit_step2_events`, separate from the future-workflow scaffold tables. It does not modify existing Step 1 accounts, permit scaffold, documents or notification tables. **Database Changes: YES—SQL migration required on the deployment server; committed SQL does not automatically run on XAMPP.** The module displays a setup notice when the three new tables have not been installed.

**MariaDB/phpMyAdmin permission compatibility (October 2026):** Some installations return MySQL **#1044** for explicit `information_schema.tables` queries. The Step 2 schema preflight now uses read-only `SELECT 1 FROM <table> LIMIT 0` checks against each of the three Step 2 tables instead. The end of migration 014 uses `SHOW TABLES LIKE 'permit_step2_%';` for verification. If all three tables already appear in that result, **do not re-import migration 014**. The FP-OMS application DB user must have `SELECT` access to the new tables; if a required table is missing or not readable, the module keeps its setup notice. New code is activated by updating the running PHP installation and refreshing the browser. No additional SQL changes are needed for this compatibility fix.

**Applicant (activated accounts only):** My FP Applications → New FP Application. Enter a project title and location, optionally describe the project, attach supporting PDFs/JPGs/PNGs (max 10 per submission; each limited by `MAX_UPLOAD_BYTES`, default 5 MB) and submit. Files are checked server-side for upload validity/MIME and are stored in randomized names beneath private `storage/fp_step2/` with 0600 permissions. Step 2 files and registration ID uploads use separate tables and access rules. Corrections can be submitted only when the application is in `corrections_required` state; earlier file revisions are retained. No submission is made without the user's POST and CSRF validation.

**LPDD-FUS Technical Staff (active `lpdd_fus` role):** FP Initial Review – Step 2. Review submission and documents, then either (a) Return for Correction with required written deficiencies or (b) Complete – Forward to RO Receiving. The decision is transactionally locked to ensure it applies only to the currently submitted revision. Individual and office-level reviewer email alerts and role-targeted bell notifications are best-effort; a mail transport failure does not undo a committed business action.

**Applicant after decision:** The applicant gets a bell alert and an attempted email. A correction decision shows the remarks on the application page, with a Resubmit Corrections action; forwarding results in `forwarded_to_ro`. Document revisions and review events are retained as a history. The server checks that applicants only see their own application files.

**RO Receiving/Releasing (active `ro_receiving` role):** A read-only RO Receiving Inbox displays forwarded applications and their documents, plus bell/email alerts. This is a handoff checkpoint only. RO receiving acknowledgment, official document evaluation, hardcopy requirements, payment, field assessment, RRDC, signing, approval and permit release are separate Steps 3–11 and are not implemented by this migration.

**Important documentary-rule limitation:** Annex A does not enumerate the full official FP documentary checklist. Consequently, the Step 2 form accepts general supporting files and technical staff assess completeness manually. The project title and location are basic identifiers, **not a claim of formally approved documentary sufficiency**. Before real-world deployment, LPDD/FUS must approve the official document checklist, rejection-letter template, intake policy and acceptance testing. The 15-day statutory/service processing tracker is not started or calculated by Step 2, because the time allocations in the supplied flowchart still require reconciliation.

**Step 2 QA scenarios:** active vs pending applicant; submit with PDF/JPG/PNG; reject file exceeding server size limit or invalid MIME; verify non-owner cannot view/download; reviewer return with mandatory remarks; applicant resubmission preserving file history; reviewer forwards; RO reads forwarded case only; duplicated/stale POST cannot repeat a review; unauthorized staff cannot decide; test applicant and staff bells independently of SMTP. Perform real PHP/MariaDB integration and email tests on a staging server before production.

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
# Required for authenticated profile editing and profile photos:
mysql -u root -p denr_fp_oms < database/006_user_profile_fields.sql
# Required for multiple registration file uploads and ID type selection (import ONCE):
mysql -u root -p denr_fp_oms < database/007_registration_id_types.sql
# Required for Configuration → User Management, contact/office fields, and 34 staged names (import ONCE):
mysql -u root -p denr_fp_oms < database/008_user_management.sql
# Merge the two prior tables into users; REMOVE redundant user_designations (import ONCE):
mysql -u root -p denr_fp_oms < database/009_merge_designations_into_users.sql
# ONE TIME client migration 011 (requires the existing permit scaffold tables):
mysql -u root -p denr_fp_oms < database/011_separate_client_accounts.sql
# Registration draft attachments (refresh-safe staging):
mysql -u root -p denr_fp_oms < database/012_registration_draft_uploads.sql
# Required for OTP-first registration and verified-email FUU review:
mysql -u root -p denr_fp_oms < database/013_registration_email_otp.sql
```

If using `DB_HOST=127.0.0.1`, MySQL may treat the connection as `'fp_oms_user'@'127.0.0.1'` rather than `'localhost'`. Set up the matching MySQL host permissions or change the host configuration accordingly. The supplied SQL user has DML privileges only; keep DDL privileges for database migrations/admins.


**For an existing XAMPP installation:** Export a complete SQL backup of `denr_fp_oms` and back up `storage/`. Shut down client registration and review operations while importing. Import **011 first, then 012** in phpMyAdmin. Both require pre-existing FP-OMS schema, including the permit scaffold tables. The 011 migration is **one-time and non-repeatable**: it preserves existing client IDs/password hashes, remaps the applicant foreign keys and moves only client rows out of `users`. Do not rerun if import partially fails; restore the backup and investigate. The earlier 008/009 merges should not be repeated if already completed. 010 remains optional for missing office-reference emails.

## Client account separation and refresh-safe registration drafts

**DENR personnel:** `users` + internal `user_roles`. **External clients/applicants:** `user_client`, with distinct PHP session key `client_id`. Staff and client numeric IDs can overlap without granting each other's permissions. Migration 011 migrates existing applicants, including their password hashes, registration links, history, notifications and password-reset links. FUU reviewers remain staff in `users`.

**On /register:** choosing a file saves it immediately in `storage/registration_drafts/` using a CSRF-protected HTTP request. The Saved Attachments section shows the uploaded ID/type or supporting file even when the page refreshes. Saved files can be removed without resetting the entire form. Invalid registration fields do not erase saved uploads; only after successful registration are they copied into permanent registration documents and the temporary originals removed. **Maximum 5 Valid IDs and 5 supporting documents**, each restricted to PDF/JPG/PNG and the server's configured maximum size. Drafts **expire after 24 hours** and are only recoverable while using the same browser's PHP session. Clearing cookies or changing browsers may prevent recovery. This feature does not persist passwords or other form entries.

**Daily cleanup of expired anonymous drafts** (Windows Task Scheduler or CLI; change folder path if needed):

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\denr_fp\scripts\cleanup_registration_drafts.php
```

**Read-only verification after importing migrations 011 and 012:**

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\denr_fp\scripts\verify_client_separation.php
```

Run end-to-end tests in a staging database for existing and new clients, saved uploads surviving refresh, removal, form validation errors, first login, profile editing, LPDD-FUS review and password resets. GitHub static checks are **not** a substitute for real MariaDB/PHP browser testing.

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
post_max_size=64M
max_file_uploads=12
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

The authenticated top-right user card and the sidebar user card open the clean route `/profile`. The Profile area has **View Profile / Edit Profile** tabs. Editing now uses the canonical route `GET /profile?tab=edit` and `POST /profile?tab=edit`; the old `/edit-profile` GET route redirects to the canonical tab.

The signed-in user can update only their own full name and optional email address. Full name is validated at 3–160 characters. A supplied email must be valid and unique; leaving it blank preserves the current login email so email-based authentication is not broken. Username, normalized role assignment, office level/assignment and account status are rendered read-only and ignored from ordinary profile POSTs. Applicant full-name changes are synchronized to the applicant registration representative name. Successful updates are audited, regenerate the authenticated session ID and use POST-Redirect-GET.

Staff photos are stored under `storage/profile_photos/<user-id>/`; new applicant photos use `storage/profile_photos/clients/<client-id>/`, all private, outside the public web root. The database stores only the relative path. The protected `/profile-photo` route serves only the currently authenticated user's photo. Uploads are limited server-side to real JPG/PNG/WEBP images, maximum 2 MB and minimum 100×100 pixels, with MIME/content inspection and randomized filenames. Replacements delete the previous file only after a successful database commit; a newly written file is deleted if the database update fails. Existing photos can be removed through a CSRF-protected confirmation POST.

The browser editor supports a device image picker and camera capture. Camera access is same-origin only, enumerates available cameras after permission, supports switching/restarting, mirrors front-camera preview without incorrectly mirroring the raw capture, and stops all media tracks when the camera modal closes. Before upload, the user can drag/reposition, zoom, crop to a square and flip the image; the adjusted result is exported as a 600×600 JPEG and is still fully revalidated by the server.

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

## Registration troubleshooting: CSP and database migrations

The public **/register** form runs under the existing strict Content Security Policy. The **Selected Files** progress display now uses native HTML `<progress>` controls styled only in `public/assets/style.css` instead of an inline `style` attribute, and the landing registration modal uses `public/assets/landing-registration-success.js` rather than an inline script. **Do not add `unsafe-inline` to CSP** to work around browser console warnings.

Before committing a new applicant account, the server performs a **read-only registration schema check** for required columns in `user_client`, `applicant_registrations`, `registration_documents`, `registration_email_otps` and `audit_logs`. If migration 011 or 013 is missing/partial, the client receives a database setup notice rather than an unexplained generic error. **No SQL migration is automatically installed or edited by the PHP code.**

Other registration failures show a random **`REG-XXXXXXXXXX` reference**. The administrator should look for exactly that reference in the configured Apache/PHP error log. Its entry includes the stage (`client-account`, `registration-documents`, `otp-create`, etc.), SQLSTATE, database driver code and the exception message, without logging the registration password or OTP. SQLSTATE `23000` is not by itself a duplicate email; only driver error **1062** is treated as a duplicate-key failure. Avoid disclosing full PHP/MySQL error traces to public applicants.

To verify migrations manually using **phpMyAdmin → denr_fp_oms → SQL**, run read-only statements:

```sql
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('user_client','applicant_registrations',
      'registration_documents','registration_email_otps','audit_logs');
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND
  ((TABLE_NAME='user_client' AND COLUMN_NAME IN ('status','email_verified_at'))
   OR (TABLE_NAME='registration_email_otps' AND COLUMN_NAME IN ('client_id','otp_hash'))
   OR (TABLE_NAME='applicant_registrations' AND COLUMN_NAME='client_id')
   OR (TABLE_NAME='audit_logs' AND COLUMN_NAME='actor_client_id'));
```

Apply the **one-time SQL 011 and 013 migrations** only if needed, in their required order and after backing up `denr_fp_oms` plus private storage. **Do not rerun** a migration that has already completed or partially applied; restore/reconcile first. The CSP correction itself does not require new SQL. Refresh assets with `git pull origin main` and **Ctrl + Shift + R**, then test registration in XAMPP and verify the email-confirmation link arrives before the acknowledgment.

## Forest Utilization Section (FUU) terminology

Applicant-facing OTP instructions, the successful verification modal, and the HTML/plain-text acknowledgment email use the full office name **Forest Utilization Section (FUU)**. The internal `lpdd_fus` role, reviewer permissions, review queue and existing database values remain unchanged.

## Email-link verification, applicant acknowledgment, and mobile QR access

The current registration flow uses **one-time email confirmation links**, not six-digit OTPs. The historical verify-registration-otp page now shows link-resend instructions; the one-time verification success modal still appears on the login page.

1. **Submit registration:** A new client is stored as pending_email_verification. A random one-time token is generated, its SHA-256 hash is stored in registration_email_otps, and the client receives a **Verify My Email Address** link.
2. **Confirm email:** The link expires after **30 minutes** and works across sessions. Resending is limited to one request per **60 seconds**, with a maximum of **5 per hour**. Confirmation sets email_verified_at and status pending_review.
3. **Applicant acknowledgment first:** Following a successful commit, the verified client receives **Email Successfully Verified! – DENR CARAGA FP-OMS**, with the pending-validation explanation, a **Login to FP-OMS – Check Registration Status** button, and the equivalent plain-text link. The email also includes **Scan via Mobile**, a locally generated QR-code PNG embedded through PHPMailer CID when QR generation succeeds. The QR encodes **only the ordinary login URL**, never passwords, email verification tokens, applicant identity, or session information. The acknowledgment is **not** registration approval or issuance of a Feasibility Permit.
4. **LPDD/FUS alerts:** Staff bell notifications, active individual reviewer emails, and a deduplicated shared-office email using the LPDD/FUS reference_email continue independently. Shared office recipients are not signed in automatically and must use their own active reviewer accounts.
5. **Review decision:** Following reviewer validation, the applicant receives a separate decision email.

**Mobile QR prerequisite:** In the server's private environment file, set **APP_URL** to the actual reachable HTTPS URL (including any deployment subfolder). A QR generated from http://localhost or http://127.0.0.1 only works on the host computer; it **cannot open the system from a different phone**. With a missing/invalid URL, the email falls back to manual access instructions. QR generation uses bundled MIT-licensed QR matrix code in lib/qrcode-generator, PHP zlib, and app/registration_qr.php; **GD and third-party QR services are not required**. If the image is unavailable or hidden by the email client, the normal clickable login button and text URL remain.

SMTP delivery requires MAIL_ENABLED=true and the existing MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_NAME, and MAIL_FROM_ADDRESS settings. No QR-specific schema or migration is required. New installs still need the existing database/013_registration_email_otp.sql email-verification migration once, in prerequisite order, after a database backup. Do not commit SMTP credentials.

## Multiple Registration Attachments

The **Registration Attachments** form accepts up to **5 government-issued Valid IDs** plus **5 optional supporting documents** per submission. Each Valid ID gets an explicit ID Type selection (Philippine National ID, passport, driver's license, UMID, PRC ID, postal ID, voter's ID, or other government-issued ID). On initial registration, **at least one Valid ID with an ID Type is mandatory**. During applicant correction/resubmission, additional uploads are optional.

Users can add/remove upload rows before submitting. Each file is checked server-side using the existing upload security checks (PDF/JPG/PNG, max `MAX_UPLOAD_BYTES`, default 5 MB). Client-side form checks are convenience only; the backend independently validates ID types, upload counts, file types/sizes, and file errors. Every file receives its own immutable `registration_documents` record with an optional `id_type`, preserving old submissions and earlier revisions. The applicant and LPDD-FUS review pages show **every uploaded file** with its ID Type and revision rather than collapsing records by `document_type`.

**Database migration required on an existing installation — apply this ONCE before deploying the new application code:**

```bash
mysql -u root -p denr_fp_oms < database/007_registration_id_types.sql
```

The migration adds nullable `registration_documents.id_type` (VARCHAR(80)); pre-existing uploads remain intact and appear as "Valid ID (type not recorded)." The original `valid_id` and `supporting_document` single-upload POST names remain accepted by the backend during rolling deployment. No changes are made to user credentials, review-role permissions, or transactional registration email notifications.

Increase the XAMPP/server PHP upload limits if needed (for five 5 MB IDs plus five 5 MB supporting files, recommended: `upload_max_filesize=5M`, `post_max_size=64M`, `max_file_uploads=12`). Restart Apache after changing `php.ini`. The app will still reject individual files beyond its configured `MAX_UPLOAD_BYTES`. Use synthetic test documents only until privacy and security reviews are complete.

Regression checks:

```bash
php -l app/registration_attachments.php
php -l app/registration.php
php -l app/views.php
php tests/registration_attachments_test.php
```

## Configuration → User Management (Single Users Table)

FP-OMS stores internal employee accounts **and** not-yet-provisioned designated personnel in ONE `users` table. The earlier `user_designations` table has been retired by migration `009_merge_designations_into_users.sql`. `user_roles` remains a separate role-assignment junction table; this is necessary to preserve user-to-role relationships, not duplicate user identities.

**Routes:** Super Administrators see **Configuration → User Management** in the sidebar. The clean URL is `/user-management`. The old `/staff` GET route redirects there.

**All Users — Login Email versus Office Email:** the table has a dedicated **Login Email** column (the unique address required to sign in) and a separate **Office Email (Reference)** column containing the email supplied in the October 21, 2026 personnel form. The reference is displayed even when login credentials have not been set up. The imported personnel records include **6 pending users whose reference address appears only once** and **27 pending users whose office address appears on more than one record**; the existing active Super Administrator already has a login address. These counts apply to the supplied October 26 SQL export, not necessarily to a later database state. Shared addresses are labelled and **rejected if someone tries to assign them as a personal login**.

**Verified-only suggestions:** when RICTU clicks **Set Up**, an existing office-reference address is suggested inside Individual Login Email only if it appears for precisely one internal person across the **entire users table** and is not already used as an account login. This does *not* prove personal ownership; the administrator must still verify it, confirm identity and enter a strong initial password. For administrator convenience, the **Set Up modal and full-page fallback prefill the Individual Login Email field with the Office Email (Reference), even when shared**; the address is an editable starting value only, not a verified login. Shared office emails still require replacement with a separate individual address before activation; backend validation rejects shared or already-assigned login emails. The shared-address warning remains visible. The Edit User modal likewise displays the office reference separately from the editable account login address.

**Pending Setup → Set Up (modal):** clicking a pending user's **Set Up** button on `/user-management` opens a responsive Bootstrap popup on the same page, instead of navigating to another page. The popup pre-fills the person's name, contact, office and assigned role from the existing user record; it displays the office email as a **reference only**, never as a login address. Enter a unique individual login email, provide and confirm a temporary password (12–200 UTF-8 bytes with a letter and number), then check the employee identity verification box. The **Activate Account** button is enabled when form inputs pass validation. Show/hide buttons are provided for both password fields. Form submission still uses the original CSRF-protected `/user-edit?id=<id>` activation route and all server-side checks; any validation errors reopen the same modal with non-password fields preserved. On success, the user returns to the refreshed User Management list. If Bootstrap JavaScript is unavailable, the Set Up link offers the existing full-page fallback. This **updates the existing users row in place**, assigns username and forces a first-login password change; it never creates another row for that person.

**Edit (modal):** clicking **Edit** for an active/suspended internal user opens a responsive Bootstrap popup on the same User Management page. The **Edit Profile** tab is prefilled with their full name, individual login email, contact number, office/station, role and status. The **Reset Password** tab is a separate CSRF-protected form for another person's strong temporary password (with show/hide and confirmation matching); it is hidden for the currently logged-in Super Administrator, who should use their own Profile → Change Password workflow. On successful save/reset, the user returns to the refreshed User Management list. Server-side validation errors reopen the correct tab and preserve only non-password form inputs. Both actions retain the existing RICTU authorization, audit log, strong password hashing and user record. The final active Super Administrator cannot be disabled/demoted, and self-demotion/suspension remains blocked. With JavaScript unavailable, the Edit link opens the existing full-page editing form.

**Add User:** create a separate manually added staff account only for personnel not already present in All Users. The backend checks duplicate names in the same office and enforces a unique individual login email. Account changes are audited.

**Optional office email repair (migration 010):** The supplied `users (6).sql` already contains the office emails under `users.reference_email`, so no import is needed solely to display them after pulling the new code. If some office-reference values are blank on another installed database, back it up and import `database/010_restore_office_email_references.sql` **after** completing migration 009. It fills missing `reference_email` values using the 34 source keys from the supplied form, without replacing existing references or changing `users.email`, passwords, role assignments or account status. It is idempotent. The SQL ends with read-only verification and the list of unshared reference email candidates. You must still activate each person individually.

```bash
# Optional, only when office email references are missing:
mysql -u root -p denr_fp_oms < database/010_restore_office_email_references.sql
```

**Database migration — apply ONCE to your existing MariaDB/XAMPP database, AFTER taking a full SQL backup and AFTER migration 008:**

```bash
mysql -u root -p denr_fp_oms < database/009_merge_designations_into_users.sql
```

The migration makes `users.email` and `users.password_hash` nullable for pending records; adds the `source_key`, `reference_email`, and `designation_notes` columns; and adds the `pending_setup` status. It merges the October 21, 2026 form's **34 personnel designations** into the `users` table. It matches existing accounts only when both the full name and email match (or are already explicitly linked). This preserves existing IDs, password hashes, credentials, role assignments, login history, and references. A stored office email becomes `reference_email`, **not** a shared login email. It also attaches the designated role when missing from `user_roles`; the supplied October 26 database dump includes one existing administrator in `users` but no user-role data rows.

A built-in reconciliation step conditionally removes `user_designations` only when every designation is represented in `users` and its assigned role exists. **For the supplied SQL export:** the expected result is **34 internal users**, consisting of **1 existing active administrator** and **33 pending_setup users**, with the redundant table gone. The actual results depend on changes to the live database since export.

**phpMyAdmin:** download/pull migration 009, open database `denr_fp_oms`, choose Import, upload `009_merge_designations_into_users.sql`, then check `users`. **Do not re-import the full old database dump** over your live database. This migration is one-time; do not run it again after it has succeeded.

**After migration:** run `php scripts/seed_roles.php` to keep role labels current (this preserves assignments). Then run `php scripts/verify_user_merge.php` for a **read-only** check of the single-table structure, pending-account login restrictions and existing active super-administrator role. Finally, test with an existing administrator and a separate dummy pending account. Confirm unauthenticated and non-admin visitors cannot access User Management, and a pending user cannot log in without credentials.

PHP checks:

```bash
php -l app/user_management.php
php -l app/user_management_views.php
php -l app/auth.php
php tests/domain_test.php
php tests/registration_attachments_test.php
php tests/user_management_test.php
php tests/user_setup_modal_test.php
php tests/user_edit_modal_test.php
php tests/user_office_email_test.php
```

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
| `super_admin` | RICTU: configuration and account administration | User Management |
| `applicant` | External applicant | Registration/status/correction; Step 2 FP submission/resubmission (active only) |
| `lpdd_fus` | LPDD-FUS: account activation and initial FP review | Registration review + Step 2 initial review queue |
| `ro_receiving` | RO Receiving/Releasing | Read-only Step 2 handoff inbox (Step 3 actions pending) |
| `lpdd_chief` | LPDD Chief | Placeholder dashboard |
| `ard_ts` | ARD Technical Services | Placeholder dashboard |
| `red` | Regional Executive Director | Placeholder dashboard |
| `cenro_twg` | CENRO technical field validation | Placeholder dashboard |
| `penro_user` | PENRO coordination | Placeholder dashboard |
| `rrdc_member` | RRDC review committee | Placeholder dashboard |
| `rrdc_secretariat` | RRDC scheduling/document handling | Placeholder dashboard |
| `gis_focal` | GIS focal mapping | Placeholder dashboard |

Permissions are checked on the server, not simply by hiding buttons. The schema supports multiple roles per account (`user_roles`), while User Management currently edits one primary role at a time. Administrator editing of internal user profiles, contact/office assignments, status and passwords is implemented. Automatic office-scoped processing rights and full permit workflow permissions remain future work.

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
│   ├── registration_attachments.php # multiple uploads / ID metadata / file listings
│   ├── registration_drafts.php # refresh-safe private temporary uploads
│   ├── registration_mail.php    # applicant acknowledgment and review emails
│   ├── staff.php                # legacy provisioning helper
│   ├── user_management.php      # RICTU user lifecycle + admin authorization
│   ├── user_management_views.php # consolidated users and pending setup screens
│   ├── notifications.php        # bell notification center
│   ├── profile.php              # authenticated user profile
│   └── views.php                # HTML views
├── database/
│   ├── 001_core.sql             # Phase 1 schema
│   ├── 002_future_workflow.sql  # tables only (Steps 2–11 later)
│   ├── 004_password_reset.sql   # password reset token storage
│   ├── 005_notifications.sql    # bell notification storage
│   ├── 006_user_profile_fields.sql # username/photo/office profile fields
│   ├── 007_registration_id_types.sql # multiple ID uploads metadata
│   ├── 008_user_management.sql  # former designation staging schema
│   ├── 009_merge_designations_into_users.sql # one-table safe consolidation
│   ├── 010_restore_office_email_references.sql # optional reference data repair
│   ├── 011_separate_client_accounts.sql # separate applicant authentication
│   ├── 012_registration_draft_uploads.sql # private session draft attachments
│   └── 013_registration_email_otp.sql # pending verification + hashed codes
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
│   ├── verify_user_merge.php     # read-only post-migration verification
│   ├── verify_client_separation.php # read-only account split verification
│   ├── cleanup_registration_drafts.php # daily expired draft removal
│   ├── reset_password.php
│   ├── configure_smtp.php        # safely writes Gmail SMTP values to local .env
│   └── test_smtp.php
├── storage/                    # PRIVATE uploaded documents
├── tests/domain_test.php
├── tests/registration_attachments_test.php
├── tests/user_management_test.php
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
- Secure staff initial passwords using an approved out-of-band channel; applicant registration acknowledgment and review decision emails use the configured SMTP transport when enabled.
