# FP-OMS RED Desktop PNPKI Signing Agent (pilot)

**Status: OFF by default. The manual sign/download/upload remains available.**
This is an initial interactive Python Windows-console pilot, not yet a production-approved desktop installer. Do not enable for live government records until RED, RICTU, PNPKI administrators and Records complete testing.

## What it does
Allows RED to sign Template No. 2 (Letter Applicant Complete) or Template No. 3 (Memo Instruction to CENRO) on an authorized Windows PC without manually saving or uploading the PDF. FP-OMS sends the exact unsigned PDF directly into the agent's memory via HTTPS, and the signed PDF is returned directly from memory. The RED's PNPKI .p12/.pfx and private-key password always remain on the workstation.

**No unattended signing.** The RED must review the document within FP-OMS, enter the exact requested SIGN job confirmation, then separately enter the PKCS#12 passphrase **for each document**. The generated PDF signature is cryptographic, not an image.

## Initial setup on TEST/STAGING (not production)
1. Back up the denr_fp_oms database and deploy to a separate testing instance. Apply database/046_pnpki_desktop_agent.sql **after** migrations 041, 042 and 044. Additive tables are OFF by default.
2. Super Admin → Configuration → Desktop PNPKI Agent → enable the pilot switch. Existing manual paths stay visible.
3. RED authorizes the existing PNPKI_2 account. Open the Desktop PNPKI Agent page and click **Issue / Rotate Workstation Token**. Copy the token shown **once**; it expires after 8 hours. Never share it through email, chat, logs or source control.
4. On RED's own Windows PC, install Python 3.11+ and pyHanko dependencies: py -m pip install -r desktop_agent\requirements.txt. Use a dedicated test certificate first.
5. Run: py desktop_agent\agent.py --url https://YOUR-FPOMS-DOMAIN/ --p12 "C:\Secure\RED-PNPKI.p12".
6. RED opens a pending Template No. 2 or No. 3 in FP-OMS and clicks **Queue for Desktop PNPKI Signing**. The running agent receives it, shows application number and document SHA-256, asks the RED for explicit confirmation and the certificate password, then signs in memory.
7. Signed PDF returns directly into existing FP-OMS encrypted storage and PNPKI_2 Records workflow. Records must cryptographically verify the RED signature and certificate before releasing. The final PDF is still withheld until official Records release.

**XAMPP localhost-only test:** In an isolated same-computer development environment, configure FPOMS_DESKTOP_AGENT_ALLOW_LOCAL_HTTP=true and launch with --url http://localhost/denr_fp/denr_fp/public/ and --allow-local-http. Do not use this override for LAN or production traffic.

## Security / known limitations
- HTTPS with a trusted certificate is mandatory for network deployments. The agent does not bypass certificate validation.
- The RED pairing token is 256-bit random, stored as SHA-256 on the server, bound to an authorized active RED account, and automatically expires in eight hours. Rotation invalidates the old token.
- Certificate and passphrase never go to FP-OMS or GitHub. Agent requires interactive per-document authorization; no silent auto-signing. Do not install the private-key certificate on a shared server.
- This pilot supports locally accessible .p12/.pfx files, not USB token / Windows certificate store. It is a console agent, not a Windows installer or background service.
- Checking the signed PDF's original byte prefix and PDF signature markers **does not verify the cryptographic signature**. PNPKI_2 signature verification, certificate identity and trust chain/revocation checks are mandatory before official release. If PNPKI_2 verification is OFF, do not use this pilot for official release.
- This build has not been tested end-to-end with an actual issued PNPKI certificate, production server, or real RED/Records accounts. Conduct an isolated test and security review before enablement.

## Immediate reversible OFF switch
1. Super Admin → Configuration → Desktop PNPKI Agent → turn OFF and Save.
2. This revokes **all** workstation pairing tokens and cancels pending agent jobs immediately. It does not delete signed documents, existing application data, PNPKI authorizations, or audit logs.
3. Stop the desktop agent (Ctrl+C). Use the existing manual signing flow.
4. If code rollback is necessary, revert the feature commit on GitHub after deployment coordination. Do not blindly DROP migration-046 tables: pending/past activity can require audit retention.
5. To re-enable, issue a new RED pairing token and check for already signed documents to prevent duplicate signing.

## Development testing
- php -l app/pnpki_desktop_agent.php
- php -l app/cenro_memo.php
- php -l app/pnpki_authorized_upload.php
- php -l public/index.php
- php tests/pnpki_desktop_agent_test.php
- python -m py_compile desktop_agent/agent.py
- End-to-end: test RED certificate, template 2, template 3, token rotation, disabled switch, invalid/replaced PDF, verification/release and applicant/field office notification.
