# FP-OMS ↔ PNPKI_2: RED Template No. 2 signing and Records release

## Current integration scope
This is **server-to-server integration**, NOT an in-browser certificate signer.
1. Authorized FP-OMS RED clicks **PNPKI Sign**. FP-OMS generates a clean unsigned
   Template No. 2 (not the watermark draft) and securely imports it to PNPKI_2.
2. PNPKI_2 creates a document assigned to its configured active **RED**.
3. RED applies their personal PNPKI digital signature using an approved local
   signing program, uploads the signed **PDF**, and forwards it to the configured
   **Records Office** user in PNPKI_2.
4. Records officially releases it using PNPKI_2 **Finalize**. The existing
   PNPKI_2 page requires a processed signed PDF upload at this step.
   For the bridge, PNPKI_2 verifies that final PDF: trusted certificate,
   RED certificate Common Name, all signature validity results, and full
   document coverage. A JPEG signature/image stamp does **not** qualify.
5. After commit, PNPKI_2 sends an authenticated release callback to FP-OMS.
   FP-OMS independently fetches release status and the signed PDF using
   authenticated API calls. Only after verification does FP-OMS store an
   encrypted signed artifact, notify RED, finalize Template No. 2 and send
   the applicant the original existing in-system + email notice to pay.
6. **Check PNPKI Status** on the RED queue provides a manual retry/recovery
   if a callback or SMTP delivery fails. A callback cannot by itself
   force a release: FP-OMS always queries the PNPKI_2 source of truth.

### One-time database migrations
Back up both databases first:
- On FP-OMS database `denr_fp_oms`, run only `database/041_pnpki_2_bridge.sql`.
- On PNPKI_2 database `ddts_pnpki`, run only
  `migrations/fpoms_pnpki_bridge_001.sql`.
Do not import an entire backup or TRUNCATE any existing user/application table.

### FP-OMS .env (never commit credentials)
Copy these settings into your real `.env` (change URL to your actual
PNPKI_2 local subfolder):
```ini
PNPKI_BRIDGE_URL=http://localhost/PNPKI_2/fpoms_bridge.php
PNPKI_BRIDGE_SECRET=PUT_YOUR_64_PLUS_CHARACTER_RANDOM_SECRET_HERE
PNPKI_BRIDGE_ALLOW_LOCAL_HTTP=true
```
Only allow loopback HTTP on the **same** XAMPP computer. For separate
machines or production, configure HTTPS, real DNS names, and set
`PNPKI_BRIDGE_ALLOW_LOCAL_HTTP=false`.

Create a strong matching secret locally with:
```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

### PNPKI_2 configuration
In the PNPKI_2 repository, copy
`fpoms_bridge_config.example.php` to `fpoms_bridge_config.local.php`
(excluded in Git):
```php
<?php
return [
  'secret' => 'THE_EXACT_SAME_SECRET_AS_FPOMS',
  'initiator_user_id' => 0,
  'red_user_id' => 0,
  'records_user_id' => 0,
  'callback_url' => 'http://localhost/denr_fp/denr_fp/public/pnpki-callback',
  'allow_local_http' => true,
  'pdfsig_bin' => 'C:/path/to/pdfsig.exe',
  'red_signer_common_name' => 'EXACT_COMMON_NAME_FROM_THE_RED_PNPKI_CERTIFICATE',
];
```
Replace the three zero IDs with **real active PNPKI_2 account IDs**.
Look them up in PNPKI_2's `users` table and verify their assigned roles:
```sql
SELECT user_id, first_name, last_name, role, status
FROM users
WHERE role IN ('RED','Records Office','Initiator','Admin')
ORDER BY role, user_id;
```
The initiator must be an active account; RED/Records IDs must match their
exact roles. Do not confuse PNPKI_2 user IDs with FP-OMS user IDs.

**Important:** Install/configure a trusted Poppler `pdfsig` executable on the
PNPKI_2 server. Its certificate trust store must trust the relevant
DICT/PNPKI chain. `pdfsig` must report valid signature(s), a trusted
certificate, a fully signed document, and the expected RED certificate
Common Name. Never turn off certificate verification or use `-nocert`
for this workflow. If verification cannot be performed, the bridge
intentionally leaves status at **verification_required** and does not
notify the applicant.

### Web server / PHP requirements
- PHP 8.2+, mysqli, PDO MySQL, cURL, random_bytes.
- PHP `exec()` enabled and `pdfsig_bin` executable by the web server.
- Both installations can reach one another's URLs. Use HTTPS between machines.
- Increase POST body limits on PNPKI_2 to support your PDF file sizes:
  e.g., `post_max_size=20M`, `upload_max_filesize=10M`.
- Ensure both server clocks are synchronized to within five minutes.
- Keep both application secret and signing keys in private server-side
  configuration, never Git, browser JavaScript, or SQL backups shared publicly.

### Smoke-test before deployment
1. Apply both migrations and configure both ends. Visit RED
   `/payment-notice-queue`; an unsigned notice now shows an enabled
   PNPKI Sign action rather than Setup Required.
2. Send. Confirm the PNPKI_2 document ID and RED inbox assignment.
3. Upload an actually PNPKI-signed PDF as RED and **Forward** to the
   configured Records Office account.
4. Records uploads the signed final PDF and uses **Finalize**.
5. Confirm callback status and the FP-OMS signed attachment and payment
   notification. Use **Check PNPKI Status** if callback failed.
6. Negative tests: reject unsigned/JPEG-stamped PDF, invalid/expired/
   untrusted certificate, wrong RED CN, missing Records release, wrong
   secret, repeated nonce, and duplicate callback. None should notify
   the applicant before verified release.

Do **not** declare real signing/live integration successful from code review
alone: complete the tests with your actual PNPKI_2 and FP-OMS deployments.
