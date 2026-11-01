<?php
declare(strict_types=1);

/**
 * Minimal OOXML XLSX writer; no Composer/ZipArchive dependency.
 * All employee-entered text is encoded as inline strings (not spreadsheet formulas).
 */
function fpoms_xlsx_escape(string $value): string
{
    $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    return htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function fpoms_xlsx_col(int $n): string
{
    $s='';
    while ($n>0) {
        $n--;
        $s=chr(65+$n%26).$s;
        $n=intdiv($n,26);
    }
    return $s;
}

function fpoms_xlsx_cell(int $c,int $r,string $value,int $style=0): string
{
    return '<c r="'.fpoms_xlsx_col($c).$r.'" s="'.$style.'" t="inlineStr"><is>'
        . '<t xml:space="preserve">'.fpoms_xlsx_escape($value).'</t></is></c>';
}

/** Encode a small set of static trusted OOXML paths as a standards-compliant ZIP. */
function fpoms_xlsx_archive(array $entries): string
{
    $data='';
    $central='';
    foreach ($entries as $name=>$xml) {
        if (!preg_match('~^[A-Za-z0-9_./\[\]-]+$~D',$name) || str_contains($name,'..')) {
            throw new RuntimeException('Invalid XLSX archive entry.');
        }
        $length=strlen($xml);
        $nameLength=strlen($name);
        $crc=crc32($xml);
        $offset=strlen($data);
        $data.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,0,$crc,$length,$length,$nameLength,0)
            .$name.$xml;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,0,
            $crc,$length,$length,$nameLength,0,0,0,0,0,$offset).$name;
    }
    $count=count($entries);
    return $data.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,
        strlen($central),strlen($data),0);
}

/**
 * Produce a genuine XLSX, with frozen/filterable headers and text-only user data.
 *
 * @param list<array<string,mixed>> $users Active DENR staff filtered on the server
 */
function fpoms_active_user_workbook(array $users,string $query,string $roleLabel): string
{
    $headers=['Full Name','Username','Recovery Email','Contact Number',
        'Office / Station','User Role','Account Status','Activation Date'];
    $lastRow=max(4,count($users)+4);
    $title='DENR CARAGA FP-OMS | ACTIVE ACCOUNTS';
    $details='Exported: '.date('Y-m-d H:i')
        .' | Search: '.($query==='' ? 'All' : $query)
        .' | Role: '.($roleLabel==='' ? 'All Roles' : $roleLabel)
        .' | Status: Active only';
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<dimension ref="A1:H'.$lastRow.'"/>'
        .'<sheetViews><sheetView workbookViewId="0">'
        .'<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>'
        .'</sheetView></sheetViews><sheetFormatPr defaultRowHeight="17"/>'
        .'<cols>';
    foreach ([29,23,34,21,32,44,18,23] as $i=>$width) {
        $sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
    }
    $sheet.='</cols><sheetData><row r="1" ht="30" customHeight="1">'
        .fpoms_xlsx_cell(1,1,$title,1).'</row>'
        .'<row r="2" ht="22" customHeight="1">'
        .fpoms_xlsx_cell(1,2,$details).'</row><row r="3" ht="9" customHeight="1"/>'
        .'<row r="4" ht="26" customHeight="1">';
    foreach ($headers as $col=>$label) $sheet.=fpoms_xlsx_cell($col+1,4,$label,2);
    $sheet.='</row>';
    foreach ($users as $index=>$user) {
        $row=$index+5;
        $values=[
            (string)($user['name']??''),(string)($user['username']??''),
            (string)($user['email']??''),(string)($user['contact_number']??''),
            (string)($user['office_station']??''),
            (string)($user['role_names']??'Unassigned'),
            'Active',(string)($user['activated_at']??'')
        ];
        $sheet.='<row r="'.$row.'">';
        foreach ($values as $col=>$value) $sheet.=fpoms_xlsx_cell($col+1,$row,$value);
        $sheet.='</row>';
    }
    $sheet.='</sheetData><autoFilter ref="A4:H'.$lastRow.'"/>'
        .'<mergeCells count="2"><mergeCell ref="A1:H1"/>'
        .'<mergeCell ref="A2:H2"/></mergeCells>'
        .'<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5"'
        .' header="0.3" footer="0.3"/></worksheet>';

    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<fonts count="3">'
        .'<font><sz val="11"/><name val="Calibri"/></font>'
        .'<font><b/><sz val="16"/><color rgb="FF08783F"/><name val="Calibri"/></font>'
        .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .'</fonts><fills count="3"><fill><patternFill patternType="none"/></fill>'
        .'<fill><patternFill patternType="gray125"/></fill>'
        .'<fill><patternFill patternType="solid"><fgColor rgb="FF08783F"/>'
        .'<bgColor indexed="64"/></patternFill></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="3">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"/>'
        .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0"'
        .' builtinId="0"/></cellStyles></styleSheet>';
    return fpoms_xlsx_archive([
        '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>',
        '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>',
        'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Active Users" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>',
        'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>',
        'xl/styles.xml'=>$styles,
        'xl/worksheets/sheet1.xml'=>$sheet
    ]);
}
