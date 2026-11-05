# FP-OMS Generated Deficiency Notice - Migration 032

## Deployment

1. Back up the database, storage directory and encryption master key.
2. Pull main from GitHub.
3. In phpMyAdmin select denr_fp_oms, then execute database/032_generated_deficiency_notices.sql.
4. As active Super Administrator open Configuration -> Deficiency Notice Template.
5. Set signatory, footer and contact emails. Upload the official DOCX as a controlled reference and upload the RED electronic-signature image as JPEG.
6. Assign an active staff user the RED role and retain active LPDD-FUS reviewers.
7. Set APP_URL to an accessible HTTPS base URL for emailed links. Localhost is only for local tests.
8. Configure and test SMTP.

## Workflow

- LPDD-FUS assesses all requirements and enters remarks.
- Return -> Generate Draft for RED Approval creates an encrypted, revision-scoped A4 PDF. The application remains under evaluation, and applicant is NOT notified yet.
- RED opens RED - Deficiency Approvals, previews the draft, explicitly confirms approval, and signs.
- The system embeds the authorized signature graphic in a new encrypted official PDF, records an audited artifact, returns the application for compliance, attempts applicant email with secure PDF link, and adds a bell notification.
- The applicant logs in and views the PDF under Official Notices / Approved Documents.
- Revised applications need a new notice; older signed notices are not reused.

## Important boundaries

- The DOCX is a controlled template reference. The server PDF generator follows the Template No. 1 structure, but does not convert arbitrary DOCX fonts, headers, images or pagination exactly.
- Uploaded JPEG signatures are visible electronic marks, NOT PNPKI cryptographic digital signatures. Explicit RED-account approval is required for every issuance.
- Protect the master encryption key; losing it makes encrypted PDFs unreadable.
- Email delivery depends on SMTP; verify logs and delivery.
- Secure view links are session-authenticated, not public files.
- Database test resets after migration 032 must clear fpoms_deficiency_notices before clearing permit_step2_applications.

## Local checks

php -l app/notice_pdf.php
php -l app/deficiency_notice.php
php tests/generated_deficiency_notice_pdf_test.php

Perform UAT of all stages before using this for official DENR notices.
