<?php
declare(strict_types=1);

/** An Active-only export; a Pending Setup or Suspended filter cannot widen it. */
function user_management_export_scope(string $role,string $state): bool
{
    return ($state==='' || $state==='active')
        && ($role==='' || user_management_valid_role($role));
}

/**
 * The export reuses the list's query/role predicates but has no 300-row UI limit.
 * This query exposes only approved display fields: no passwords, reset codes,
 * session metadata, hidden files or audit history.
 */
function user_management_export_rows(string $query,string $role,string $state): array
{
    if (!user_management_export_scope($role,$state)) return [];
    $params=[];
    $sql="SELECT u.name,u.username,u.email,u.contact_number,u.office_station,
                 u.status,u.activated_at,
                 (SELECT GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ')
                    FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id
                   WHERE ur.user_id=u.id) AS role_names
            FROM users u WHERE u.user_kind='internal' AND u.status='active'";
    $sql.=user_management_search_role_sql($query,$role,$params);
    $sql.=' ORDER BY u.name ASC,u.id ASC';
    return query_all($sql,$params);
}

/** Send an authenticated and auditable Excel file to the user's browser. */
function user_management_export_download(): never
{
    $admin=require_role('super_admin');
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
        show_error('Excel download requires a GET request.',405);
    }
    $query=substr(trim((string)($_GET['q']??'')),0,120);
    $role=trim((string)($_GET['role']??''));
    $state=trim((string)($_GET['state']??''));
    $rows=user_management_export_rows($query,$role,$state);
    $roleOptions=user_management_internal_roles();
    $roleLabel=($role!=='' && isset($roleOptions[$role]))
        ? (string)$roleOptions[$role][0] : '';
    $file=fpoms_active_user_workbook($rows,$query,$roleLabel);

    audit((int)$admin['id'],'user_management.export_active','user',null,[
        'role'=>$role,'state'=>$state,'search_applied'=>$query!=='',
        'records'=>count($rows),'format'=>'xlsx'
    ]);
    $filename='FPOMS_Active_Users_'.date('Y-m-d_His').'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($file));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $file;
    exit;
}
