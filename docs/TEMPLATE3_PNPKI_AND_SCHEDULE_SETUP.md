# Template No. 3 PNPKI_2 signing and field validation date range

Applies to DENR CARAGA FP-OMS /permit-flow?id=N, flow_version 2, Step 4.

## Database migration (one-time)

1. Back up denr_fp_oms and retain existing applications, signed PDFs, and upload folders.
2. Ensure migrations 039, 041, and 042 are already applied.
3. Run database/043_field_validation_schedule_range.sql once using phpMyAdmin SQL in denr_fp_oms.
4. Run database/044_cenro_memo_pnpki.sql (idempotent CREATE TABLE IF NOT EXISTS).
5. Deploy companion PNPKI_2 fpoms_bridge_signed.php change so Records sees Template No. 3 – Memo to CENRO. Existing signed documents remain intact.

## Schedule

LPDD-FUS verifies Cashier Official Receipt and awaits official RED-signed Template No. 2 release. It then selects From Date / From Time and To Date / To Time in the two-month booking-style date picker. To must be later than From; From must be in the future. Both are saved and sent in applicant and field-office notifications. Existing single-date schedules remain readable.

## Memo signing

LPDD-FUS selects Request RED PNPKI Signature instead of existing manual upload. An immutable clean 8.5 x 13 inch Template No. 3 PDF is created from application details and follows the official one-page memorandum header, TO, ATT'N, FROM, SUBJECT, DATE, instructions and signatory. RED can authorize PNPKI_2 directly from the memorandum card and is returned to the same application after authorization. RED reviews, downloads a clean copy, signs locally with the RED PNPKI certificate, and uploads the signed PDF in FP-OMS. Existing RED PNPKI_2 account authorization is reused.

FP-OMS securely submits the PDF to PNPKI_2 Records. Records uploads its final signed-for-release PDF and performs official release. Only then does FP-OMS retrieve that PDF, store it encrypted under the cenro_memo document category, and notify LPDD-FUS/PENRO/CENRO. Complete Step 4 & Forward is blocked until release; manual upload cannot bypass a pending RED signing request. Existing manual upload remains available when no PNPKI request exists.

Retries preserve the encrypted pending signed PDF. Template No. 3 has separate source keys from Template No. 2; PNPKI never auto-signs.

## Verification

- php tests/field_validation_schedule_test.php
- php tests/cenro_memo_pnpki_test.php
- PHP syntax checks, node --check public/assets/field-validation-range.js
- Test with separate LPDD-FUS, RED, PNPKI_2 Records, PENRO, and CENRO accounts before production rollout.
