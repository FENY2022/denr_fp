# FP-OMS ↔ PNPKI_2: Authorized RED Signed PDF → Records Release (v2)

**This replaces the earlier unsigned-Template-to-PNPKI_2 RED signing flow.**
The FP-OMS RED is the initiating authorizing user. RED applies a real PNPKI
digital signature locally and uploads that signed PDF **inside FP-OMS**.
PNPKI_2 then receives the signed document directly in the linked Records
Office account; Records does not need to upload or sign a second PDF.
The applicant is NOT told to pay until the PNPKI_2 Records release is verified.

## Preferred setup: Admin UI (no routine manual SQL or .env editing)

1. **Back up both databases** in phpMyAdmin before installation.
2. In **PNPKI_2**, log in as an active **Admin**. On the dashboard,
   select **Admin Tools → FP-OMS Integration Setup**.
   - Click **Install / Verify PNPKI_2 Tables 001 & 002**.
   - Select the active RED and Records Office accounts. Initiator/Service is optional for the new RED signed-PDF upload workflow; select an active Initiator only if the legacy unsigned handoff is still used.
   - Set the full FP-OMS callback and authorization-return URLs.
   - Set Poppler `pdfsig.exe` and the exact RED signer certificate Common Name.
   - For one-machine XAMPP testing only, enable localhost HTTP.
   - Click **Save Private PNPKI_2 Settings**. If first set up without a
     secret, a 64-character random secret is generated and shown **once**.
     Copy it securely; the config is saved in a restricted folder outside
     the document root rather than in GitHub.
3. In **FP-OMS**, log in as **Super Administrator** and select
   **Configuration → PNPKI_2 Integration**.
   - Click **Install / Verify FP-OMS Tables 041 & 042**.
   - Enter the PNPKI_2 API URL (for example,
     `http://localhost/PNPKI_2/fpoms_bridge.php`).
   - Paste the **same** PNPKI_2 shared secret and enable localhost HTTP
     only for single-machine testing.
   - Click **Save Secure Connection** and **Test PNPKI_2 Connection**.
     FP-OMS encrypts the shared secret at rest using its existing private
     master encryption key.
4. Confirm the connection test shows database, account and signature verifier
   readiness. Then the RED can use the regular
   `/payment-notice-queue` authorization and signed upload actions.

**Prerequisites:** both projects must be updated/pulled from GitHub.
The web database accounts must have one-time CREATE TABLE/ALTER TABLE
permissions for the admin install buttons; otherwise use the manual SQL
steps below. Neither wizard accesses your local server from ChatGPT, and
neither uploads a PNPKI private key or certificate PIN.

### Manual fallback (only if Admin setup cannot run)

## Install database migrations (after separate database backups)
- FP-OMS database `denr_fp_oms`:
  1. `database/041_pnpki_2_bridge.sql` if not installed
  2. `database/042_pnpki_authorized_upload.sql`
- PNPKI_2 database `ddts_pnpki`:
  1. `migrations/fpoms_pnpki_bridge_001.sql` if not installed
  2. `migrations/fpoms_pnpki_bridge_002.sql`

**Run the bridge_002 migration only once** because it alters an existing
table. Do not import a full database dump, TRUNCATE records or drop old
PNPKI documents. Existing legacy PNPKI bridge links remain supported.

## FP-OMS environment variables (private `.env` only)
```ini
PNPKI_BRIDGE_URL=http://localhost/PNPKI_2/fpoms_bridge.php
PNPKI_BRIDGE_SECRET=YOUR_64_HEX_CHARACTER_RANDOM_SECRET
PNPKI_BRIDGE_ALLOW_LOCAL_HTTP=true
```
Use the exact actual installation path on XAMPP. `http://localhost`
is for **same-computer local development ONLY**. Production and cross-machine
deployments require HTTPS and `PNPKI_BRIDGE_ALLOW_LOCAL_HTTP=false`.
Generate a random secret locally with:
```
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

## PNPKI_2 private configuration
Copy `fpoms_bridge_config.example.php` to
`fpoms_bridge_config.local.php` and fill all required fields.
Better: store the configuration **outside the webroot** and set
`FPOMS_BRIDGE_CONFIG_PATH` in the PNPKI_2 server environment to its absolute path.

```php
<?php
return [
  'secret' => 'EXACT_SAME_SECRET_AS_FPOMS',
  'initiator_user_id' => 0,
  'red_user_id' => 0,
  'records_user_id' => 0,
  'callback_url' => 'http://localhost/denr_fp/denr_fp/public/pnpki-callback',
  'fpoms_return_url' => 'http://localhost/denr_fp/denr_fp/public/pnpki-authorized',
  'allow_local_http' => true,
  'pdfsig_bin' => 'C:/path/to/pdfsig.exe',
  'red_signer_common_name' => 'EXACT_RED_PNPKI_CERTIFICATE_COMMON_NAME',
];
```

Set valid active IDs from PNPKI_2 (`red_user_id` must have **RED**
role; `records_user_id` must have **Records Office** role).
`initiator_user_id` is retained for compatibility with the old flow.
Never confuse PNPKI_2 user IDs with FP-OMS user IDs.
Use this read-only SQL in `ddts_pnpki` to check IDs:
```sql
SELECT user_id, first_name, last_name, role, status
FROM users WHERE role IN ('RED','Records Office','Initiator','Admin')
ORDER BY role,user_id;
```

The signing verifier requires a trusted Poppler `pdfsig` executable and
a configured certificate trust store for the RED's PNPKI chain. The verifier
checks PDF signature validity, trusted certification and the configured
RED certificate common name. It never sees the PNPKI password, PIN or
private-key file. A JPEG/stamp does NOT qualify as a digital signature.

## How RED uses FP-OMS

1. Log in to FP-OMS with an authorized RED role.
2. Open `/payment-notice-queue`.
3. Click **Authorize PNPKI_2 Account**.
   - Browser opens PNPKI_2. Log in as the real RED user there (first time).
   - Review the consent page and click **Authorize FP-OMS**.
   - PNPKI_2 issues a short-lived, single-use code and redirects back to
     FP-OMS. FP-OMS redeems it server-to-server, links the exact RED accounts.
     No PNPKI password is copied or stored in FP-OMS.
4. Download **Open / Print Draft for Signing** (clean Template No. 2),
   digitally sign the PDF with your authorized PNPKI certificate locally.
5. Upload the digitally signed PDF in the **PNPKI Sign / Upload** section
   of FP-OMS, confirm the correct notice, and press
   **Submit Signed PDF to PNPKI_2 Records**.
6. PNPKI_2 verifies the genuine digital signature and creates a
   **CONFIDENTIAL** document owned by the configured Records Office user.
7. Records signs into PNPKI_2 and reviews the already-signed attachment.
   Select **Release PNPKI-Signed FP-OMS Notice**, then confirm release.
   For this imported document, NO second signed-file upload is required.
8. After Records release commits, PNPKI_2 sends a signed callback.
   FP-OMS does a separate authenticated status check, verifies the final
   digitally signed PDF, stores the PDF encrypted, notifies RED, then
   issues the applicant's **Official Notice to Pay** in-system and by email.
9. RED may click **Check PNPKI Status** if the callback failed. A signed
   PDF alone cannot trigger applicant payment instructions.

### One-time PNPKI_2 auto-login
After the account was authorized, RED may select **Auto-Login PNPKI_2**
from the linked document card. FP-OMS requests a short-lived one-time
ticket through the authenticated PNPKI_2 server API; PNPKI_2 verifies
the saved account link before creating a new RED session and opening
the linked document. It is NOT a permanent shared session or a bypass
of first-time user consent.

### Admin pause switch (PNPKI_2)

PNPKI_2 Admin → FP-OMS Integration Setup → **Digital Signature Verification** has an
ON/OFF switch. **ON** (default) requires Poppler `pdfsig`, a trusted RED PNPKI
certificate chain, and an exact RED certificate Common Name match.
**OFF** allows authorized RED uploads to reach PNPKI_2 Records solely as
**UNVERIFIED / PENDING VERIFICATION**. Records receives an in-system notification
and can preview the original integrity-checked PDF. No official release, verified
status callback, or applicant payment notification is permitted while verification
is OFF; turning it ON still requires successful trusted certificate and signer
checks before official release. The older unsigned-document bridge remains paused.
Previously stored documents and audit history are not deleted.
No SQL migration is required for this switch.

### Records final PDF: download → upload → official release

After an authorized FP-OMS RED PDF is received, PNPKI_2 Records gets an
in-system notification. On **View Document**, Records can click **Download RED
Document**, complete the applicable PNPKI document-signing workflow, and
**Upload Final Signed PDF**. The upload creates a new file version and an
audit-history entry without releasing the document.

When automatic verification is OFF, Records can receive, download and upload
a PDF for review, but **official release remains disabled**. When verification
is ON, the release action accepts only a staged Records final PDF whose RED
PNPKI signature, signer identity and certificate chain pass the trusted
verifier. After release, the final Records-uploaded PDF (not the initial upload)
is returned to FP-OMS via the verified status/callback workflow and applicant
notification. No SQL migration is required. Ensure `uploads/.htaccess` is
honored by the web server; new Records PDFs must not be publicly served.

### Troubleshooting
- **Setup Required**: apply 041 and 042 and configure the shared secret.
- **Authorize** still visible: complete the PNPKI_2 consent and return.
- **Upload saved, forward pending**: use **Retry PNPKI_2 Submission**.
  Repeated imports are idempotent and compare the original file SHA-256.
- **Verification required**: trusted digital signature, RED certificate,
  pdfsig binary, or Records release does not validate; applicant remains
  unnotified.
- **Missing applicant email**: inspect FP-OMS SMTP and use existing
  Retry Applicant Email for the already-released notice.
- **Different PNPKI user already logged in**: sign out of that PNPKI_2
  account before using the one-time auto-login link.

### Acceptance testing before production
Verify consent, account-link mismatch rejection, cross-session replay
rejection, expired code/one-time ticket rejection, forged HMAC/nonce
rejection, invalid certificate, wrong RED CN, unsigned PDF rejection,
Records refusal of a duplicate upload, delayed Records release,
successful callback and manual retry, protected access to applicant
PDF attachments, and exactly-once applicant notification.
Because this integration processes government applicant documents,
complete a privacy and deployment security review before live use.
New imported signed-PDF files in PNPKI_2 are classified CONFIDENTIAL.
On Apache/XAMPP, `uploads/.htaccess` blocks direct requests to
`fpoms_signed_*.pdf`; signed PDFs are streamed through the authenticated
`fpoms_bridge_file.php` endpoint for the authorized RED/Records accounts.
**Verify that Apache honors the .htaccess restriction before production.**
On Nginx or any webserver that ignores .htaccess, create equivalent deny
rules for the protected filename prefix or move the files to private
non-webroot storage. Never deploy while those URLs are publicly accessible.

**GitHub code is not proof of live operation:** Both migrations, local
server configuration, trusted certificate verification and browser
workflow testing are required.
