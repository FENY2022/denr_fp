<?php
declare(strict_types=1);

function permit_payment_status_badge(?array $payment): string
{
    $paid=$payment && (string)($payment['status']??'')==='verified';
    return $paid
        ? '<span class="badge text-bg-success">Payment Verified</span>'
        : '<span class="badge text-bg-warning">Awaiting LPDD-FUS Verification</span>';
}

/** Applicant and LPDD-FUS see the same authoritative payment state. */
function permit_payment_panel_html(array $app,array $viewer,array $errors=[]): string
{
    if (!permit_payment_available_for_application($app)) return '';
    $payment=permit_payment_record((int)$app['id']);
    $verified=$payment && (string)$payment['status']==='verified';
    $isLpddFus=($viewer['user_kind']??'')==='internal'
        && ($viewer['status']??'')==='active' && has_role($viewer,'lpdd_fus');
    $id=(int)$app['id'];

    $html='<section class="panel soft mt-3" id="fp-payment-verification" tabindex="-1">'
        .'<div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">'
        .'<h3 class="h6 mb-0"><i class="bi bi-receipt me-2"></i>Application Fee / LPDD-FUS Payment Verification</h3>'
        .permit_payment_status_badge($payment).'</div>';
    if (!permit_payment_schema_ready()) {
        return $html.'<div class="alert alert-warning small mb-0">'
            .'Payment tracking requires database migration 028. Contact RICTU.</div></section>';
    }

    if ($verified) {
        $html.='<p class="small text-success">Cashier payment was verified by an authorized LPDD-FUS account.</p>'
            .'<dl class="row small mb-3">'
            .'<dt class="col-sm-5">Official Receipt No.</dt><dd class="col-sm-7">'
            .e((string)$payment['receipt_number']).'</dd>'
            .'<dt class="col-sm-5">Amount Paid</dt><dd class="col-sm-7">₱'
            .e(number_format((float)$payment['amount_paid'],2)).'</dd>'
            .'<dt class="col-sm-5">Payment Date</dt><dd class="col-sm-7">'
            .e((string)$payment['payment_date']).'</dd>'
            .'<dt class="col-sm-5">Verified By</dt><dd class="col-sm-7">'
            .e((string)($payment['verifier_name']??'LPDD-FUS')).'</dd>'
            .'<dt class="col-sm-5">Verified At</dt><dd class="col-sm-7">'
            .e(fpoms_display_datetime((string)$payment['verified_at'])).'</dd></dl>'
            .'<div class="d-flex gap-2 flex-wrap">'
            .'<a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="'
            .e(url('permit_payment_receipt',['id'=>$id,'preview'=>1])).'">'
            .'<i class="bi bi-eye me-1"></i>View Official Receipt</a>'
            .'<a class="btn btn-sm btn-outline-success" href="'
            .e(url('permit_payment_receipt',['id'=>$id])).'">'
            .'<i class="bi bi-download me-1"></i>Download Receipt</a></div>';
        if (trim((string)($payment['notes']??''))!=='') {
            $html.='<p class="small text-secondary mt-3 mb-0"><strong>LPDD-FUS Notes:</strong> '
                .nl2br(e((string)$payment['notes'])).'</p>';
        }
        return $html.'</section>';
    }

    $html.='<p class="small mb-2"><strong>Payment Instructions for Applicant:</strong></p>'
        .'<p class="small">'.e(permit_payment_instructions($id)).'</p>'
        .'<div class="border rounded-3 p-3 mb-3 bg-body">'
        .'<h4 class="h6 mb-3">Your Next Steps</h4>'
        .'<ol class="mb-0 small">'
        .'<li class="mb-2"><strong>Go to DENR CARAGA Regional Office Cashier</strong> and pay the assessed application fee.</li>'
        .'<li class="mb-2"><strong>Proceed directly to LPDD-FUS</strong> with the original Cashier-issued Official Receipt.</li>'
        .'<li><strong>Wait for LPDD-FUS verification.</strong> The LPDD-FUS personnel records payment details and uploads the receipt in FP-OMS.</li>'
        .'</ol></div>'
        .'<p class="small text-secondary">You cannot confirm payment yourself. '
        .'Only authorized LPDD-FUS personnel may verify and upload the Official Receipt.</p>';
    if (!$isLpddFus) return $html.'</section>';

    $html.=errors_html($errors)
        .'<hr><h4 class="h6">LPDD-FUS – Verify Payment & Upload Official Receipt</h4>'
        .'<p class="small text-secondary">Compare the original Cashier Official Receipt with '
        .'the transaction details before submitting. This action is recorded in the audit trail.</p>'
        .'<form method="post" enctype="multipart/form-data" '
        .'id="lpdd-fus-payment-form-'.$id.'" action="'
        .e(url('permit_payment_verify',['id'=>$id])).'">'
        .csrf_field()
        .'<div class="mb-3"><label class="form-label" for="lpdd-fus-or-'.$id.'">'
        .'Official Receipt Number <span class="text-danger">*</span></label>'
        .'<input class="form-control" id="lpdd-fus-or-'.$id.'" name="receipt_number" '
        .'maxlength="90" required value="'.e((string)($_POST['receipt_number']??'')).'"></div>'
        .'<div class="row g-2"><div class="col-md-6 mb-3">'
        .'<label class="form-label" for="lpdd-fus-amount-'.$id.'">Amount Paid (PHP) *</label>'
        .'<input class="form-control" id="lpdd-fus-amount-'.$id.'" name="amount_paid" '
        .'type="text" inputmode="decimal" data-payment-amount maxlength="18" required '
        .'value="'.e((string)($_POST['amount_paid']??'')).'"></div>'
        .'<div class="col-md-6 mb-3">'
        .'<label class="form-label" for="lpdd-fus-date-'.$id.'">Cashier Payment Date *</label>'
        .'<input class="form-control" id="lpdd-fus-date-'.$id.'" name="payment_date" '
        .'type="date" max="'.e(date('Y-m-d')).'" required '
        .'value="'.e((string)($_POST['payment_date']??'')).'"></div></div>'
        .'<div class="mb-3"><label class="form-label" for="lpdd-fus-receipt-'.$id.'">'
        .'Upload Official Receipt (PDF/JPG/PNG) *</label>'
        .'<input class="form-control" id="lpdd-fus-receipt-'.$id.'" type="file" '
        .'name="official_receipt" accept=".pdf,.jpg,.jpeg,.png" required>'
        .'<div class="form-text">The LPDD-FUS account uploads the scanned Cashier-issued OR. '
        .'File is encrypted in private storage.</div></div>'
        .'<div class="mb-3"><label class="form-label" for="lpdd-fus-notes-'.$id.'">'
        .'Verification Notes (Optional)</label>'
        .'<textarea class="form-control" id="lpdd-fus-notes-'.$id.'" name="notes" '
        .'maxlength="2000" rows="3">'.e((string)($_POST['notes']??'')).'</textarea></div>'
        .'<button type="button" class="btn btn-success w-100" data-bs-toggle="modal" '
        .'data-bs-target="#lpdd-fus-payment-confirm-'.$id.'">'
        .'<i class="bi bi-shield-check me-1"></i>Review Payment & Upload OR</button></form>'
        .'<div class="modal fade" id="lpdd-fus-payment-confirm-'.$id.'" tabindex="-1" '
        .'aria-labelledby="lpdd-fus-payment-confirm-title-'.$id.'" aria-hidden="true">'
        .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        .'<div class="modal-header"><h2 class="modal-title h5" id="lpdd-fus-payment-confirm-title-'.$id.'">'
        .'Confirm Cashier Payment Verification?</h2>'
        .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div>'
        .'<div class="modal-body"><p>Confirm that the Official Receipt was issued by the '
        .'DENR CARAGA Cashier and its amount, date and receipt number have been checked.</p>'
        .'<p class="small text-danger mb-0">After confirmation the record becomes '
        .'<strong>Payment Verified</strong> and cannot be verified twice.</p></div>'
        .'<div class="modal-footer">'
        .'<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        .'<button type="submit" class="btn btn-success" name="confirmation" value="verify_payment" '
        .'form="lpdd-fus-payment-form-'.$id.'">Confirm Payment & Upload OR</button>'
        .'</div></div></div></div>';
    return $html.'</section>';
}

/** LPDD-FUS payment-verification queue; applicants cannot self-verify payment. */
function view_lpdd_fus_payment_queue(): void
{
    permit_payment_require_lpdd_fus();
    $tab=(string)($_GET['tab']??'awaiting');
    if (!in_array($tab,['awaiting','verified'],true)) show_error('Invalid payment queue tab.',400);

    $rows=query_all(
        "SELECT a.id,a.project_title,a.last_submitted_at,c.name AS applicant_name,
                COALESCE(p.status,'awaiting_payment') AS payment_status,
                p.receipt_number,p.verified_at
         FROM permit_step2_applications a
         JOIN user_client c ON c.id=a.client_id
         LEFT JOIN permit_payment_verifications p ON p.application_id=a.id
         LEFT JOIN fpoms_complete_notices cn
           ON cn.application_id=a.id AND cn.revision=a.revision AND cn.status='released'
         WHERE (a.status='step4_complete' OR (a.flow_version=2 AND cn.id IS NOT NULL))
           AND COALESCE(p.status,'awaiting_payment')=?
         ORDER BY COALESCE(p.verified_at,a.last_submitted_at) DESC,a.id DESC LIMIT 300",
        [$tab==='verified'?'verified':'awaiting_payment']
    );

    $html='<div class="panel">'
        .'<h2 class="h5">LPDD-FUS – Payment Verification</h2>'
        .'<p class="small text-secondary">After paying at the DENR Regional Office Cashier, '
        .'the applicant proceeds to LPDD-FUS and presents the original Official Receipt. '
        .'LPDD-FUS verifies the payment and securely uploads the OR.</p>'
        .'<div class="alert alert-info small">'
        .'<strong>LPDD-FUS action:</strong> When the applicant presents the original Cashier OR, '
        .'click <strong>Verify Payment &amp; Upload OR</strong>, check the details, upload the scanned OR, '
        .'and confirm. Do not mark as verified before checking the Cashier transaction.</div>'
        .'<nav class="nav nav-pills gap-2 mb-3">'
        .'<a class="nav-link'.($tab==='awaiting'?' active':'').'" href="'
        .e(url('lpdd_fus_payment_queue',['tab'=>'awaiting'])).'">Awaiting LPDD-FUS Verification</a>'
        .'<a class="nav-link'.($tab==='verified'?' active':'').'" href="'
        .e(url('lpdd_fus_payment_queue',['tab'=>'verified'])).'">Payment Verified</a></nav>'
        .'<div class="table-responsive"><table class="table table-hover align-middle">'
        .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th>'
        .'<th>Payment Status</th><th>Action</th></tr></thead><tbody>';
    if (!$rows) $html.='<tr><td colspan="5" class="text-secondary">No matching applications.</td></tr>';
    foreach ($rows as $row) {
        $html.='<tr><td>#'.(int)$row['id'].'</td><td>'
            .e((string)$row['applicant_name']).'</td><td>'
            .e((string)$row['project_title']).'</td><td>'
            .permit_payment_status_badge(['status'=>$row['payment_status']]).'</td>'
            .'<td><a class="btn btn-sm btn-outline-primary" href="'
            .e(url('permit_application',['id'=>(int)$row['id']]).'#fp-payment-verification').'">'
            .($tab==='verified'?'View Verified Receipt':'Verify Payment & Upload OR').'</a></td></tr>';
    }
    $html.='</tbody></table></div></div>';
    render_page('LPDD-FUS Payment Verification',$html);
}
