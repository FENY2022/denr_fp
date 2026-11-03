<?php
declare(strict_types=1);

// Database-free regression tests: User Management filter parity, safe XLSX content,
// no 300-record display limit, and Super Admin-only download routing.
require_once __DIR__.'/../app/domain.php';
require_once __DIR__.'/../app/user_management.php';
require_once __DIR__.'/../app/user_management_export.php';
require_once __DIR__.'/../app/xlsx_export.php';

function export_assert(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException('User Management XLSX export: '.$message);
}

$GLOBALS['export_test_calls']=[];
function query_all(string $sql,array $params=[]): array
{
    $GLOBALS['export_test_calls'][]=['sql'=>$sql,'params'=>$params];
    return [[
        'name'=>'=HYPERLINK("https://example.invalid","Click") & <script>',
        'username'=>'sampleuser28',
        'email'=>'staff@example.invalid',
        'reference_email'=>'office@example.invalid',
        'contact_number'=>'09123456789',
        'office_station'=>'CENRO Nasipit',
        'role_names'=>'CENRO TWG',
        'status'=>'pending_setup',
        'activated_at'=>'',
        'last_login_at'=>''
    ]];
}

export_assert(user_management_export_scope('',''),'All Statuses is a valid export scope');
export_assert(user_management_export_scope('','active'),'Active status is valid');
export_assert(user_management_export_scope('','pending_setup'),'Pending Setup status is valid');
export_assert(user_management_export_scope('','suspended'),'Suspended status is valid');
export_assert(!user_management_export_scope('','made_up_status'),'invalid status is rejected');
export_assert(!user_management_export_scope('made_up_role',''),'invalid role is rejected');

$users=user_management_export_rows('Nasipit','cenro_twg','pending_setup');
export_assert(count($users)===1,'Pending Setup filter executes');
$call=end($GLOBALS['export_test_calls']);
export_assert(str_contains($call['sql'],"u.user_kind='internal'"),
    'SQL restricts export to DENR internal users');
export_assert(str_contains($call['sql'],'u.status=?'),
    'selected Status filter is applied');
export_assert(!str_contains(strtoupper($call['sql']),' LIMIT '),
    'export is not limited to displayed 300 users');
export_assert(str_contains($call['sql'],'u.name LIKE ?')
    && str_contains($call['sql'],'u.username LIKE ?')
    && str_contains($call['sql'],'u.office_station LIKE ?')
    && str_contains($call['sql'],'u.reference_email LIKE ?')
    && str_contains($call['sql'],'r.code=?'),
    'export shares UI Search and Role predicates');
export_assert(count($call['params'])===7
    && $call['params'][0]==='%Nasipit%'
    && $call['params'][5]==='cenro_twg'
    && $call['params'][6]==='pending_setup',
    'search, role, and status parameters are safely bound');
export_assert(str_contains($call['sql'],'u.reference_email')
    && str_contains($call['sql'],'u.last_login_at'),
    'Office Email reference and Last Login are selected for workbook parity');
export_assert(!preg_match('/SELECT\s+[^;]*password_hash/i',$call['sql'])
    && !preg_match('/SELECT\s+[^;]*otp_hash/i',$call['sql']),
    'no password or OTP columns exported');

$before=count($GLOBALS['export_test_calls']);
$all=user_management_export_rows('','','');
export_assert(count($all)===1,'All Statuses export executes');
$allCall=$GLOBALS['export_test_calls'][$before];
export_assert(!str_contains($allCall['sql'],'u.status=?'),
    'All Statuses does not impose an account-status restriction');

$xlsx=fpoms_user_management_workbook(
    $users,'Nasipit','CENRO TWG','Pending Setup'
);
export_assert(substr($xlsx,0,4)==="PK\x03\x04",'binary is a ZIP-based XLSX');
export_assert(str_contains($xlsx,'[Content_Types].xml')
    && str_contains($xlsx,'xl/worksheets/sheet1.xml')
    && str_contains($xlsx,'xl/workbook.xml'),'required OOXML entries present');
export_assert(str_contains($xlsx,'autoFilter ref="A4:J5"')
    && str_contains($xlsx,'pane ySplit="4"'),'10-column filterable/frozen header');
export_assert(str_contains($xlsx,'Office Email (Reference)')
    && str_contains($xlsx,'Last Login')
    && str_contains($xlsx,'office@example.invalid')
    && str_contains($xlsx,'Pending Setup'),
    'workbook follows User Management column and status flow');
export_assert(str_contains($xlsx,'=HYPERLINK(&quot;https://example.invalid&quot;'),
    'formula-shaped values are stored as escaped plain text');
export_assert(!str_contains($xlsx,'<f>')
    && !str_contains($xlsx,'<script>'),'no spreadsheet formulas or embedded scripts');
export_assert(str_contains($xlsx,'t="inlineStr"'),'all employee fields are inline strings');
export_assert(!str_contains($xlsx,'password_hash')
    && !str_contains($xlsx,'code_hash'),'password and OTP metadata never exported');

$empty=fpoms_user_management_workbook([],'','','All Statuses');
export_assert(str_contains($empty,'autoFilter ref="A4:J4"')
    && !str_contains($empty,'<row r="5"'),'empty matching set produces header-only XLSX');

export_assert(str_starts_with(
    user_management_export_filename('','active'),
    'FPOMS_Users_Active_'
),'Active filename is contextual');
export_assert(str_starts_with(
    user_management_export_filename('LPDD-FUS',''),
    'FPOMS_Users_LPDD_FUS_All_'
),'role/all-status filename is contextual and safe');

$views=file_get_contents(__DIR__.'/../app/user_management_views.php');
$route=file_get_contents(__DIR__.'/../public/index.php');
$handler=file_get_contents(__DIR__.'/../app/user_management_export.php');
export_assert(str_contains($views,"url('user_management_export',[")
    && str_contains($views,"'q'=>$query,'role'=>$role,'state'=>$status"),
    'Download Excel link preserves current Search, Role and Status');
export_assert(str_contains($views,'Search → Role → Status → Filter → Download Excel'),
    'User Management explains the export flow');
export_assert(!str_contains($views,'Only Active accounts can be exported'),
    'non-Active statuses are no longer blocked from export');
export_assert(str_contains($route,"case 'user_management_export':")
    && str_contains($handler,"require_role('super_admin')"),
    'export route and handler enforce Super Administrator access');
export_assert(str_contains($handler,'user_management.export_users'),
    'general filtered export action is audited');

if (class_exists('ZipArchive')) {
    $file=tempnam(sys_get_temp_dir(),'fpoms-xlsx-');
    file_put_contents($file,$xlsx);
    $zip=new ZipArchive();
    export_assert($zip->open($file)===true,'generated workbook opens in ZIP parser');
    $sheet=(string)$zip->getFromName('xl/worksheets/sheet1.xml');
    export_assert(str_contains($sheet,'autoFilter ref="A4:J5"'),
        'actual worksheet OOXML is readable');
    $zip->close();
    unlink($file);
}

echo "PASS: filtered User Management Excel export follows Search, Role and Status flow.\n";
