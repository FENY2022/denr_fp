<?php
declare(strict_types=1);

// Database-free regression tests: Active-only filtering, safe XLSX content,
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
        'username'=>'sampleactive28','email'=>'staff@example.invalid',
        'contact_number'=>'09123456789','office_station'=>'CENRO Nasipit',
        'role_names'=>'CENRO TWG','status'=>'active',
        'activated_at'=>'2026-10-28 10:30:00'
    ]];
}

export_assert(user_management_export_scope('',''),'All Statuses permits Active-only export');
export_assert(user_management_export_scope('','active'),'Active status permits export');
export_assert(!user_management_export_scope('','pending_setup'),'Pending Setup cannot export');
export_assert(!user_management_export_scope('','suspended'),'Suspended cannot export');
export_assert(!user_management_export_scope('made_up_role',''),'invalid role does not widen results');

$before=count($GLOBALS['export_test_calls']);
export_assert(user_management_export_rows('Nasipit','cenro_twg','pending_setup')===[],
    'Pending Setup export is empty');
export_assert(count($GLOBALS['export_test_calls'])===$before,
    'No query issued for nonactive status filter');

$users=user_management_export_rows('Nasipit','cenro_twg','active');
export_assert(count($users)===1,'Active-only query executes for valid filters');
$call=end($GLOBALS['export_test_calls']);
export_assert(str_contains($call['sql'],"u.user_kind='internal' AND u.status='active'"),
    'SQL restricts to activated DENR internal users');
export_assert(!str_contains(strtoupper($call['sql']),' LIMIT '),
    'export is not limited to displayed 300 users');
export_assert(str_contains($call['sql'],'u.name LIKE ?')
    && str_contains($call['sql'],'u.username LIKE ?')
    && str_contains($call['sql'],'u.office_station LIKE ?')
    && str_contains($call['sql'],'r.code=?'),
    'export shares UI Search and Role predicates');
export_assert(count($call['params'])===6
    && $call['params'][0]==='%Nasipit%'
    && $call['params'][5]==='cenro_twg',
    'role/search parameters bound (no SQL injection)');
export_assert(!preg_match('/SELECT\s+[^;]*password_hash/i',$call['sql'])
    && !preg_match('/SELECT\s+[^;]*otp_hash/i',$call['sql']),
    'no password or OTP columns exported');

$xlsx=fpoms_active_user_workbook($users,'Nasipit','CENRO TWG');
export_assert(substr($xlsx,0,4)==="PK\x03\x04",'binary is a ZIP-based XLSX');
export_assert(str_contains($xlsx,'[Content_Types].xml')
    && str_contains($xlsx,'xl/worksheets/sheet1.xml')
    && str_contains($xlsx,'xl/workbook.xml'),'required OOXML entries present');
export_assert(str_contains($xlsx,'autoFilter ref="A4:H5"')
    && str_contains($xlsx,'pane ySplit="4"'),'filterable/frozen header');
export_assert(str_contains($xlsx,'CENRO Nasipit')
    && str_contains($xlsx,'sampleactive28')
    && str_contains($xlsx,'Activation Date'),'selected data and activation date included');
export_assert(str_contains($xlsx,'=HYPERLINK(&quot;https://example.invalid&quot;'),
    'formula-shaped values are stored as escaped plain text');
export_assert(!str_contains($xlsx,'<f>')
    && !str_contains($xlsx,'<script>'),'no spreadsheet formulas or embedded scripts');
export_assert(str_contains($xlsx,'t="inlineStr"'),'all employee fields are inline strings');
export_assert(!str_contains($xlsx,'password_hash')
    && !str_contains($xlsx,'code_hash'),'password and OTP metadata never exported');

$empty=fpoms_active_user_workbook([],'','');
export_assert(str_contains($empty,'autoFilter ref="A4:H4"')
    && !str_contains($empty,'<row r="5"'),'empty matching set produces header-only XLSX');

$views=file_get_contents(__DIR__.'/../app/user_management_views.php');
$route=file_get_contents(__DIR__.'/../public/index.php');
$handler=file_get_contents(__DIR__.'/../app/user_management_export.php');
export_assert(str_contains($views,"url('user_management_export',[")
    && str_contains($views,"'q'=>\$query,'role'=>\$role,'state'=>\$status"),
    'Download Excel link preserves current search, role and state');
export_assert(str_contains($views,'Download Excel')
    && str_contains($views,'disabled'),'nonactive status filters disable the download');
export_assert(str_contains($route,"case 'user_management_export':")
    && str_contains($handler,"require_role('super_admin')"),
    'export route and handler enforce Super Administrator access');
export_assert(str_contains($handler,'user_management.export_active'),
    'export action is audited');

if (class_exists('ZipArchive')) {
    $file=tempnam(sys_get_temp_dir(),'fpoms-xlsx-');
    file_put_contents($file,$xlsx);
    $zip=new ZipArchive();
    export_assert($zip->open($file)===true,'generated workbook opens in ZIP parser');
    $sheet=(string)$zip->getFromName('xl/worksheets/sheet1.xml');
    export_assert(str_contains($sheet,'autoFilter ref="A4:H5"'),
        'actual worksheet OOXML is readable');
    $zip->close();
    unlink($file);
}

echo "PASS: Active-only filtered Excel export, safe OOXML, authorization and UI.\n";
