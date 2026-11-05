# FP-OMS Generated Deficiency Notice — Official Template and RED Approval

## Installation / Upgrade

1. Back up the denr_fp_oms database, private storage folder, and master encryption key.
2. Pull the latest main branch.
3. Run database/032_generated_deficiency_notices.sql if not applied.
4. Run database/033_deficiency_notice_email_delivery.sql once after 032. This adds SMTP delivery status and retry.
5. In XAMPP enable PHP ZIP and GD extensions and restart Apache. These are needed to read letterhead artwork from DOCX.
6. As Super Administrator open Configuration → Deficiency Notice Template. Upload the official Template No. 1 DOCX, configure the RED name and designation, contact details, footer, and approved RED signature JPEG.
7. Ensure RED and LPDD-FUS staff accounts are active.
8. Configure APP_URL to the real HTTPS public base URL for applicant links (localhost works only locally). Enable and test SMTP.

## Return Workflow

1. LPDD-FUS completes assessment/remarks for all requirements.
2. Return generates an unsigned encrypted A4 PDF using the configured DOCX's DENR seal, Bagong Pilipinas logo, and SOCOTEC mark. The Particulars/Remarks table is automatically filled. No per-application upload is required.
3. Notice is pending_red, LPDD-FUS sees Awaiting RED Approval, and it is excluded from the LPDD-FUS active queue. No official applicant notification is sent before RED approval.
4. RED opens RED – Deficiency Approvals, previews the draft and explicitly selects RED Approve & Sign.
5. An encrypted official PDF with approved JPEG signature is stored; Return is finalized automatically, setting the application to step4_corrections_required. Applicant bell notification and official PDF become available immediately. Email with protected viewing link and PDF attachment is attempted.
6. If SMTP fails, Return stays finalized. Authorized RED or LPDD-FUS may use Retry Applicant Email from the returned application. Migration 033 tracks sent/failed status.
7. Subsequent application revisions require new RED-approved notices.

## Official Letterhead / E-Sign Boundaries

- The exact three images from the supplied Template No. 1 DOCX are used, including its DENR seal, Bagong Pilipinas and SOCOTEC images. The A4 letter is recreated in Times-style text with proponent/address, subject, deficiency table, closing, RED signatory and footer.
- DOCX provides original artwork and structure but arbitrary DOCX fonts and pagination are not converted pixel-for-pixel. Long text/rows can span multiple pages.
- The six sample table rows a) through f) are preserved as a minimum; additional deficiencies generate extra rows.
- A JPEG e-sign graphic is a visible electronic signature mark, NOT a cryptographically verifiable PNPKI signature. It is used only after authenticated RED approval of each notice.
- DOCX, signature and PDFs are encrypted under private storage. Protect the file encryption key.
- Applicant PDF URLs require authenticated access.

## UAT / Local Validation

Run:
- php -l app/notice_pdf.php
- php -l app/notice_template.php
- php -l app/deficiency_notice.php
- php -l app/permit_step4.php
- php -l app/permit_step2_views.php
- php -l public/index.php
- php tests/generated_deficiency_notice_pdf_test.php

UAT: LPDD-FUS Return -> pending RED panel and removal from LPDD-FUS active queue -> RED preview and approval -> Compliance Required status -> client bell notification and PDF link -> SMTP sent/failed result -> retry if necessary.

SMTP acceptance does not prove inbox delivery; check the applicant's inbox/spam and server mail logs. APP_URL must be reachable from the applicant device.

## Test Data Reset

Clear fpoms_deficiency_notices rows before clearing permit_step2_applications or workflow artifacts. Application-only resets must preserve users, roles, template/signature configuration, private master encryption key and system settings.
