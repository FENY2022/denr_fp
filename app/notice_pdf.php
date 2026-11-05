<?php
declare(strict_types=1);

/**
 * Small dependency-free A4 PDF writer for the official deficiency letter.
 * Generates text/vector tables server-side without shell execution or public files.
 * A JPEG signature is embedded only after an authenticated RED approval action.
 */
final class FpomsNoticePdf
{
    private const HEIGHT = 841.89;
    private array $pages = [];
    private string $stream = '';
    private float $y = 0.0;
    private ?string $signature = null;
    private int $signatureWidth = 0;
    private int $signatureHeight = 0;
    private string $footer = '';

    private static function pdfText(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) $encoded = preg_replace('/[^\x20-\x7e]/', '?', $text) ?? '?';
        $encoded = preg_replace('/[\x00-\x1f]/', ' ', $encoded) ?? '';
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $encoded);
    }

    private static function ascii(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) $encoded = $text;
        return preg_replace('/\s+/', ' ', trim($encoded)) ?? '';
    }

    private function line(float $x, float $top, string $text, int $size=10, bool $bold=false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->stream .= sprintf("BT /%s %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font, $size, $x, self::HEIGHT - $top, self::pdfText($text));
    }

    private function rule(float $x,float $top,float $width,float $height=0): void
    {
        $this->stream .= sprintf("%.2F %.2F %.2F %.2F re S\n",
            $x, self::HEIGHT-$top-$height, $width, $height);
    }

    private function startPage(bool $continuation=false): void
    {
        if ($this->stream !== '') $this->pages[] = $this->stream;
        $this->stream = "0 G 0 g 0.65 w\n";
        $this->line(50, 49, 'Department of Environment and Natural Resources', 12, true);
        $this->line(50, 64, 'Kagawaran ng Kapaligiran at Likas Yaman', 9);
        $this->line(50, 78, 'Caraga Region', 10);
        if ($continuation) $this->line(475, 78, '(continued)', 8);
        $this->stream .= "0.20 0.42 0.26 RG 1 w\n";
        $this->rule(50, 89, 495);
        $this->stream .= "0 G 0 g 0.65 w\n";
        $this->y = 113;
    }

    private function ensure(float $needed): void
    {
        if ($this->y + $needed > 785) $this->startPage(true);
    }

    private function wrapped(string $text,int $columns): array
    {
        $s = self::ascii($text);
        if ($s === '') return [''];
        return explode("\n", wordwrap($s, $columns, "\n", true));
    }

    private function paragraph(string $text,int $columns=94,float $leading=14,float $after=9): void
    {
        foreach ($this->wrapped($text,$columns) as $line) {
            $this->ensure($leading);
            $this->line(50, $this->y, $line, 10);
            $this->y += $leading;
        }
        $this->y += $after;
    }

    private function heading(string $label): void
    {
        $this->ensure(25);
        $this->line(50, $this->y, $label, 10, true);
        $this->y += 17;
    }

    private function tableRow(string $left,string $right,bool $header=false): void
    {
        $leftLines = $this->wrapped($left, 44);
        $rightLines = $this->wrapped($right, 32);
        $count = max(count($leftLines),count($rightLines));
        $height = 13 * $count + 11;
        $this->ensure($height + 2);
        $this->rule(50, $this->y, 495, $height);
        $this->rule(335, $this->y, 0, $height);
        foreach ($leftLines as $i=>$line) {
            $this->line(57,$this->y+17+13*$i,$line,9,$header);
        }
        foreach ($rightLines as $i=>$line) {
            $this->line(343,$this->y+17+13*$i,$line,9,$header);
        }
        $this->y += $height;
    }

    private function setSignature(?string $jpeg): void
    {
        if ($jpeg===null) return;
        $info=@getimagesizefromstring($jpeg);
        if (!$info || ($info[2]??null)!==IMAGETYPE_JPEG) {
            throw new RuntimeException('Configured RED signature must be a valid JPEG image.');
        }
        $this->signature=$jpeg;
        $this->signatureWidth=(int)$info[0];
        $this->signatureHeight=(int)$info[1];
    }

    private function signatureImage(float $x,float $top,float $width,float $height): void
    {
        if ($this->signature===null) return;
        $this->stream .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n",
            $width,$height,$x,self::HEIGHT-$top-$height);
    }

    public static function render(array $app,array $input,array $config,?string $jpegSignature=null,
        ?string $approvalDate=null): string
    {
        $pdf=new self();
        $pdf->footer=(string)($config['footer_text']??'DENR-XIII, Ambago, Butuan City, Philippines');
        $pdf->setSignature($jpegSignature);
        $pdf->startPage();
        if ($jpegSignature===null) {
            $pdf->stream.="0.75 0.05 0.05 rg\n";
            $pdf->line(320,110,'DRAFT - FOR RED APPROVAL',10,true);
            $pdf->stream.="0 g\n";
        }
        $today=$approvalDate ?: date('F d, Y');
        $pdf->line(50,135,$today,10);
        $pdf->y=164;
        foreach ([
            (string)($app['applicant_name']??'APPLICANT'),
            (string)($app['representative_position']??'Authorized Representative'),
            (string)($app['company_name']??'COMPANY'),
            (string)($app['company_address']??'Address not supplied')
        ] as $addressLine) {
            $pdf->paragraph($addressLine,94,13,0);
        }
        $pdf->y+=16;
        $pdf->heading('Subject: Application for Feasibility Permit of '.
            (string)($app['company_name']??'the applicant'));
        $pdf->paragraph('For its project: '.(string)($app['project_title']??''),94,13,12);
        $pdf->paragraph('Dear '.(string)($app['applicant_name']??'Proponent').':',94,14,12);

        $area=trim((string)($input['area_hectares']??''));
        $areaText=$area!=='' ? $area.' hectares' : 'the proposed area';
        $pdf->paragraph('This pertains to the application for a Feasibility Permit of '.
            (string)($app['company_name']??'the applicant').' covering the proposed '.
            (string)($app['project_title']??'project').' with an area of '.$areaText.
            ' located in '.(string)($app['project_location']??'the project location').'.',94,14,11);
        $pdf->paragraph('Please be informed that upon review and evaluation of the application documents, '.
            'the application lacks the following documentary requirements, to wit:',94,14,13);

        $pdf->tableRow('Particulars','Remarks',true);
        $rows=$input['notice_rows']??[];
        foreach ($rows as $index=>$row) {
            $label=chr(97+((int)$index%26)).') '.(string)($row['label']??'Requirement');
            $remark=(string)($row['remarks']??'Incomplete requirement');
            $pdf->tableRow($label,$remark);
        }
        $pdf->y+=17;
        $pdf->paragraph('In this regard, kindly submit the above-mentioned documentary requirements, '.
            'as the basis of this Office in taking further appropriate action on the subject application.',94,14,12);
        $pdf->paragraph('Should you have any questions or require further assistance, please feel free '.
            'to visit our Office, contact us via email at '.
            (string)($config['contact_emails']??'r13@denr.gov.ph').
            ', or through teleconferencing at your convenience.',94,14,10);
        $pdf->paragraph('Please be guided accordingly. Thank you.',94,14,14);
        $pdf->paragraph('Very truly yours,',94,14,7);

        $pdf->ensure(100);
        if ($jpegSignature!==null) $pdf->signatureImage(49,$pdf->y,175,49);
        $pdf->y+=55;
        $pdf->line(50,$pdf->y,(string)($config['signatory_name']??'Regional Executive Director'),10,true);
        $pdf->y+=14;
        $pdf->line(50,$pdf->y,(string)($config['signatory_title']??'Regional Executive Director'),9);
        $pdf->y+=14;
        if ($jpegSignature!==null) {
            $pdf->line(50,$pdf->y,'Electronically approved by RED on '.$today,8);
        } else {
            $pdf->line(50,$pdf->y,'DRAFT - NOT SIGNED / NOT FOR RELEASE',8,true);
        }
        $pdf->pages[]=$pdf->stream;
        return $pdf->finish();
    }

    private function finish(): string
    {
        $objects=[
            1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $imageId=null;
        if ($this->signature!==null) {
            $imageId=5;
            $info=getimagesizefromstring($this->signature);
            $color=(int)($info['channels']??3)===1?'DeviceGray':'DeviceRGB';
            $objects[$imageId]='<< /Type /XObject /Subtype /Image /Width '.$this->signatureWidth
                .' /Height '.$this->signatureHeight.' /ColorSpace /'.$color
                .' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($this->signature)
                ." >>\nstream\n".$this->signature."\nendstream";
        }

        $kids=[];
        foreach ($this->pages as $index=>$stream) {
            // The private document footer is repeated on every page.
            $pageNumber=$index+1;
            $footer=self::pdfText($this->footer);
            $stream.=sprintf("BT /F1 7 Tf 1 0 0 1 50 41 Tm (%s) Tj ET\n",$footer);
            $stream.=sprintf("BT /F1 8 Tf 1 0 0 1 515 41 Tm (Page %d) Tj ET\n",$pageNumber);
            $contentId=count($objects)+1;
            $objects[$contentId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
            $pageId=count($objects)+1;
            $resources='<< /Font << /F1 3 0 R /F2 4 0 R >>';
            if ($imageId!==null) $resources.=' /XObject << /Im1 '.$imageId.' 0 R >>';
            $resources.=' >>';
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
                .' /Resources '.$resources.' /Contents '.$contentId.' 0 R >>';
            $kids[]=$pageId.' 0 R';
        }
        $objects[2]='<< /Type /Pages /Count '.count($kids).' /Kids ['.implode(' ',$kids).'] >>';
        ksort($objects);
        $output="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets=[0];
        foreach ($objects as $id=>$object) {
            $offsets[$id]=strlen($output);
            $output.=$id." 0 obj\n".$object."\nendobj\n";
        }
        $start=strlen($output);
        $output.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for ($id=1;$id<=count($objects);$id++) {
            $output.=sprintf("%010d 00000 n \n",$offsets[$id]);
        }
        $output.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\n";
        $output.="startxref\n".$start."\n%%EOF\n";
        return $output;
    }
}
