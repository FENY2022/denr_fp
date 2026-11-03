<?php
declare(strict_types=1);

/** Validate the same Role and Status scope accepted by the User Management list. */
function user_management_export_scope(string $role,string $state): bool
{
    return ($role==='' || user_management_valid_role($role))
        && in_array($state,['','active','suspended','pending_setup'],true);
}

/**
 * Export the complete matching User Management result set without the UI's
 * 300-row display limit. Only approved account/profile fields are selected.
 */
function user_management_export_rows(string $query,string $role,string $state): array
{
    if (!user_management_export_scope($role,$state)) return [];

    $params=[];
    $sql="SELECT u.name,u.username,u.email,u.reference_email,u.contact_number,
                 u.office_station,u.status,u.activated_at,u.last_login_at,
                 (SELECT GROUP_CONCAT(r.label ORDER BY r.label SEPARATOR ', ')
                    FROM user_roles ur INNER JOIN roles r ON r.id=ur.role_id
                   WHERE ur.user_id=u.id) AS role_names
            FROM users u WHERE u.user_kind='internal'";
    $sql.=user_management_search_role_sql($query,$role,$params);
    if ($state!=='') {
        $sql.=' AND u.status=?';
        $params[]=$state;
    }
    $sql.=" ORDER BY (u.status='pending_setup') DESC,u.name ASC,u.id ASC";
    return query_all($sql,$params);
}

function user_management_export_status_label(string $state): string
{
    return match ($state) {
        'active'=>'Active',
        'suspended'=>'Suspended',
        'pending_setup'=>'Pending Setup',
        default=>'All Statuses'
    };
}

function user_management_export_filename(string $roleLabel,string $state): string
{
    $parts=['FPOMS','Users'];
    if ($roleLabel!=='') $parts[]=$roleLabel;
    $parts[]=$state==='' ? 'All' : user_management_export_status_label($state);
    $safe=array_map(
        static fn(string $part): string => trim((string)preg_replace('/[^A-Za-z0-9]+/','_',$part),'_'),
        $parts
    );
    return implode('_',array_filter($safe,static fn(string $part): bool => $part!==''))
        .'_'.date('Y-m-d').'.xlsx';
}

/** Send an authenticated, filtered, and auditable Excel file to the browser. */
function user_management_export_download(): never
{
    $admin=require_role('super_admin');
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
        show_error('Excel download requires a GET request.',405);
    }

    $query=substr(trim((string)($_GET['q']??'')),0,120);
    $role=trim((string)($_GET['role']??''));
    $state=trim((string)($_GET['state']??''));
    if (!user_management_export_scope($role,$state)) {
        show_error('Invalid User Management export filter.',400);
    }

    $rows=user_management_export_rows($query,$role,$state);
    $roleOptions=user_management_internal_roles();
    $roleLabel=($role!=='' && isset($roleOptions[$role]))
        ? (string)$roleOptions[$role][0] : '';
    $statusLabel=user_management_export_status_label($state);
    $file=fpoms_user_management_workbook($rows,$query,$roleLabel,$statusLabel);

    audit((int)$admin['id'],'user_management.export_users','user',null,[
        'role'=>$role,'state'=>$state,'search_applied'=>$query!=='',
        'records'=>count($rows),'format'=>'xlsx'
    ]);

    $filename=user_management_export_filename($roleLabel,$state);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($file));
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $file;
    exit;
}
