<?php
declare(strict_types=1);

/** Reusable action in the RO completed-handoff case details. */
function records_archive_action_panel_html(array $application): string
{
    if (!records_archive_schema_ready()) return '';
    $id=(int)$application['id'];
    $entry=records_archive_entry($id);
    if ($entry) {
        return '<div class="panel soft"><div class="alert alert-success mb-2">'
            .'<i class="bi bi-archive-fill me-2"></i>Records action archived on '
            .e(fpoms_display_datetime((string)$entry['archived_at'])).'.</div>'
            .'<a class="btn btn-outline-primary btn-sm" href="'
            .e(url('records_archive')).'">Open Records Archiving</a></div>';
    }
    if (($application['status'] ?? '')!=='forwarded_to_lpdd') return '';
    $modal='archive-fp-'.$id;
    $form='archive-fp-form-'.$id;
    return '<div class="panel soft"><h3 class="h6">'
        .'<i class="bi bi-archive me-2"></i>Records Archiving</h3>'
        .'<p class="small text-secondary">Archive the completed RO Step 3 action. '
        .'The FP application will remain active for LPDD-FUS Step 4.</p>'
        .'<button type="button" class="btn btn-outline-success w-100" '
        .'data-bs-toggle="modal" data-bs-target="#'.$modal.'">'
        .'<i class="bi bi-archive me-1"></i>Archive Records Action</button>'
        .'<div class="modal fade" id="'.$modal.'" tabindex="-1" aria-hidden="true" '
        .'aria-labelledby="'.$modal.'-title"><div class="modal-dialog modal-dialog-centered">'
        .'<div class="modal-content"><div class="modal-header">'
        .'<h2 class="modal-title h5" id="'.$modal.'-title">Confirm Records Archiving</h2>'
        .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>'
        .'</div><div class="modal-body"><p>Archive RO handling record for '
        .'<strong>FP Application #'.$id.'</strong>?</p>'
        .'<p class="small text-secondary">This record disappears from the Records RO inbox '
        .'and Records My Recent Actions, but documents, historical events and Step 4 '
        .'processing are preserved.</p>'
        .'<form method="post" id="'.$form.'" action="'
        .e(url('records_archive_action',['id'=>$id])).'">'.csrf_field()
        .'<input type="hidden" name="archive_confirm" value="1">'
        .'<label for="archive-remarks-'.$id.'" class="form-label">Archive Notes (optional)</label>'
        .'<textarea class="form-control" id="archive-remarks-'.$id.'" '
        .'name="remarks" maxlength="2000" rows="3"></textarea></form>'
        .'</div><div class="modal-footer">'
        .'<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        .'<button type="submit" form="'.$form.'" class="btn btn-success">'
        .'Confirm Archive</button></div></div></div></div></div>';
}

/** Pending handoffs and completed archive search in one Records-only menu. */
function view_records_archive(): void
{
    records_archive_require_officer();
    $q=trim((string)($_GET['q'] ?? ''));
    $from=trim((string)($_GET['from'] ?? ''));
    $to=trim((string)($_GET['to'] ?? ''));
    if (strlen($q)>120 || ($from!=='' && !records_archive_valid_date($from))
        || ($to!=='' && !records_archive_valid_date($to))
        || ($from!=='' && $to!=='' && $from>$to)) {
        show_error('Invalid archive search criteria.',400);
    }
    $pending=query_all(
        "SELECT a.id,a.project_title,c.name AS applicant_name,m.forwarded_at
         FROM permit_step2_applications a
         JOIN user_client c ON c.id=a.client_id
         JOIN permit_step3_movements m ON m.application_id=a.id
         LEFT JOIN permit_records_archives ra ON ra.application_id=a.id
         WHERE a.status='forwarded_to_lpdd' AND m.forwarded_at IS NOT NULL
           AND ra.application_id IS NULL
         ORDER BY m.forwarded_at DESC,a.id DESC LIMIT 200");
    $where=["1=1"];$params=[];
    if ($q!=='') {
        $where[]='(CAST(a.id AS CHAR) LIKE ? OR a.project_title LIKE ? OR c.name LIKE ?)';
        $search='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';
        $params=array_merge($params,[$search,$search,$search]);
    }
    if ($from!=='') {$where[]='ra.archived_at>=?';$params[]=$from.' 00:00:00';}
    if ($to!=='') {$where[]='ra.archived_at<?';$params[]=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
    $archived=query_all(
        'SELECT ra.application_id,ra.archived_at,ra.archive_remarks,
                a.project_title,c.name AS applicant_name,u.name AS archived_by_name
         FROM permit_records_archives ra
         JOIN permit_step2_applications a ON a.id=ra.application_id
         JOIN user_client c ON c.id=a.client_id
         LEFT JOIN users u ON u.id=ra.archived_by_user_id
         WHERE '.implode(' AND ',$where).'
         ORDER BY ra.archived_at DESC,ra.application_id DESC LIMIT 200',$params);
    $html='<section class="panel"><h2 class="h5"><i class="bi bi-archive-fill me-2"></i>'
        .'Records Archiving</h2><p class="small text-secondary">'
        .'Records archive of completed Step 3 handoffs. Archiving does not close '
        .'or delete the applicant permit; it remains with LPDD-FUS Step 4.</p>'
        .'<h3 class="h6 mt-4">Ready for Records Archiving '
        .'<span class="badge text-bg-info">'.count($pending).'</span></h3>';
    if (!$pending) $html.='<p class="small text-secondary">No completed RO handoffs awaiting archiving.</p>';
    else {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th>'
            .'<th>Forwarded to LPDD-FUS</th><th>Action</th></tr></thead><tbody>';
        foreach ($pending as $item) {
            $html.='<tr><td>#'.(int)$item['id'].'</td>'
                .'<td>'.e((string)$item['applicant_name']).'</td>'
                .'<td>'.e((string)$item['project_title']).'</td>'
                .'<td>'.e(fpoms_display_datetime((string)$item['forwarded_at'])).'</td>'
                .'<td><a class="btn btn-sm btn-outline-success" href="'
                .e(url('permit_application',['id'=>(int)$item['id']]))
                .'">Review / Archive</a></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    $html.='<hr class="my-4"><h3 class="h6">Archived Records</h3>'
        .'<form method="get" action="'.e(url('records_archive')).'" '
        .'class="row g-2 align-items-end mb-3">'
        .'<div class="col-md-6"><label class="form-label" for="archive-query">'
        .'Search FP No., applicant or project</label><input class="form-control" '
        .'id="archive-query" name="q" maxlength="120" value="'.e($q).'"></div>'
        .'<div class="col-md-2"><label class="form-label" for="archive-from">From</label>'
        .'<input class="form-control" type="date" name="from" id="archive-from" value="'.e($from).'"></div>'
        .'<div class="col-md-2"><label class="form-label" for="archive-to">To</label>'
        .'<input class="form-control" type="date" name="to" id="archive-to" value="'.e($to).'"></div>'
        .'<div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Filter</button></div>'
        .'</form>';
    if (!$archived) $html.='<p class="text-secondary">No archived records match this filter.</p>';
    else {
        $html.='<div class="table-responsive"><table class="table table-hover align-middle">'
            .'<thead><tr><th>FP No.</th><th>Applicant</th><th>Project</th>'
            .'<th>Archived At</th><th>Archived By</th><th>Documents / Audit</th></tr></thead><tbody>';
        foreach ($archived as $item) {
            $id=(int)$item['application_id'];
            $html.='<tr><td>#'.$id.'</td>'
                .'<td>'.e((string)$item['applicant_name']).'</td>'
                .'<td>'.e((string)$item['project_title']).'</td>'
                .'<td>'.e(fpoms_display_datetime((string)$item['archived_at'])).'</td>'
                .'<td>'.e((string)($item['archived_by_name'] ?? 'DENR Personnel')).'</td>'
                .'<td><div class="d-flex gap-2 flex-wrap">'
                .'<a class="btn btn-sm btn-outline-primary" href="'
                .e(url('permit_application',['id'=>$id,'view'=>'files'])).'">View Files</a>'
                .'<a class="btn btn-sm btn-outline-secondary" href="'
                .e(url('permit_application',['id'=>$id,'view'=>'audit']))
                .'">View Audit Trail</a></div></td></tr>';
        }
        $html.='</tbody></table></div>';
    }
    render_page('Records Archiving',$html.'</section>');
}
function records_archive_valid_date(string $value): bool
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date!==false && $date->format('Y-m-d')===$value;
}
