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
    private array $branding = [];
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
        if ($this->stream !== '') $this->pages[]=$this->stream;
        $this->stream="0 G 0 g 0.5 w\n";
        // Original Template No. 1 three-logo letterhead (loaded from configured DOCX).
        $this->drawImage('denr',60,37,75,75);
        $this->drawImage('bagong',459,37,80,83);
        $this->line(153,68,'Department of Environment and Natural Resources',12,true);
        $this->line(182,85,'Kagawaran ng Kapaligiran at Likas Yaman',10);
        $this->line(263,102,'Caraga Region',11);
        if ($continuation) $this->line(475,129,'(continued)',8);
        $parts=explode('|',self::ascii($this->footer),2);
        $this->line(198,800,trim($parts[0]??''),8);
        if (isset($parts[1])) $this->line(176,811,trim($parts[1]),7);
        $this->drawImage('socotec',461,758,79,42);
        $this->y=137;
    }

    private function loadBranding(array $branding): void
    {
        foreach (['denr','bagong','socotec'] as $name) {
            $bytes=$branding[$name]??null;
            if (!is_string($bytes)) continue;
            $info=@getimagesizefromstring($bytes);
            if (!$info || ($info[2]??null)!==IMAGETYPE_JPEG) {
                throw new RuntimeException('Invalid official '.$name.' letterhead logo.');
            }
            $this->branding[$name]=[
                'bytes'=>$bytes,'width'=>(int)$info[0],'height'=>(int)$info[1]
            ];
        }
    }

    private function drawImage(string $name,float $x,float $top,float $width,float $height): void
    {
        if ($name!=='signature' && !isset($this->branding[$name])) return;
        if ($name==='signature' && $this->signature===null) return;
        $this->stream.=sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im_%s Do Q\n",
            $width,$height,$x,self::HEIGHT-$top-$height,$name);
    }

    private function ensure(float $needed): void
    {
        if ($this->y + $needed > 786) $this->startPage(true);
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
            $this->line(72, $this->y, $line, 11);
            $this->y += $leading;
        }
        $this->y += $after;
    }

    private function heading(string $label): void
    {
        $this->ensure(25);
        $this->line(72, $this->y, $label, 11, true);
        $this->y += 17;
    }

    private function tableRow(string $left,string $right,bool $header=false): void
    {
        $leftLines=$this->wrapped($left,36);
        $rightLines=$this->wrapped($right,34);
        $count=max(count($leftLines),count($rightLines));
        $height=max(19,13*$count+6);
        $this->ensure($height+2);
        $this->rule(100,$this->y,420,$height);
        $this->rule(302,$this->y,0,$height);
        foreach ($leftLines as $i=>$value) {
            $this->line($header?176:107,$this->y+14+13*$i,$value,10,$header);
        }
        foreach ($rightLines as $i=>$value) {
            $this->line($header?392:309,$this->y+14+13*$i,$value,10,$header);
        }
        $this->y+=$height;
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
        $this->drawImage('signature',$x,$top,$width,$height);
    }

    public static function render(array $app,array $input,array $config,?string $jpegSignature=null,
        ?string $approvalDate=null): string
    {
        $pdf=new self();
        $pdf->footer=(string)($config['footer_text']??'DENR-XIII, Ambago, Butuan City, Philippines');
        $pdf->loadBranding(is_array($config['letterhead_images']??null)?$config['letterhead_images']:[]);
        $pdf->setSignature($jpegSignature);
        $pdf->startPage();
        if ($jpegSignature===null) {
            $pdf->stream.="0.75 0.05 0.05 rg\n";
            $pdf->line(346,127,'DRAFT - FOR RED APPROVAL',8,true);
            $pdf->stream.="0 g\n";
        }
        $today=$approvalDate ?: date('F d, Y');
        $pdf->line(72,136,$today,11);
        $pdf->y=171;
        foreach ([
            (string)($app['applicant_name']??'APPLICANT'),
            (string)($app['representative_position']??'Authorized Representative'),
            (string)($app['company_name']??'COMPANY'),
            (string)($app['company_address']??'Address not supplied')
        ] as $addressLine) {
            $pdf->paragraph($addressLine,80,15,0);
        }
        $pdf->y+=24;
        $pdf->heading('Subject: Application for Feasibility Permit of '.
            (string)($app['company_name']??'the applicant'));
        $pdf->paragraph('for its '.(string)($app['project_title']??''),80,13,11);
        $pdf->paragraph('Dear '.(string)($app['applicant_name']??'Proponent').':',80,14,12);

        $area=trim((string)($input['area_hectares']??''));
        $areaText=$area!=='' ? $area.' hectares' : 'the proposed area';
        $pdf->paragraph('This pertains to the application for a Feasibility Permit of '.
            (string)($app['company_name']??'the applicant').' covering the proposed '.
            (string)($app['project_title']??'project').' with an area of '.$areaText.
            ' located in '.(string)($app['project_location']??'the project location').'.',80,14,10);
        $pdf->paragraph('Please be informed that upon review and evaluation of the application documents, '.
            'the application lacks the following documentary requirements, to wit:',80,14,11);

        $pdf->tableRow('Particulars','Remarks',true);
        $rows=$input['notice_rows']??[];
        foreach ($rows as $index=>$row) {
            $label=chr(97+((int)$index%26)).') '.(string)($row['label']??'Requirement');
            $remark=(string)($row['remarks']??'Incomplete requirement');
            $pdf->tableRow($label,$remark);
        }
        for ($i=count($rows);$i<6;$i++) $pdf->tableRow(chr(97+$i).') ','');
        $pdf->y+=14;
        $pdf->paragraph('In this regard, kindly submit the above-mentioned documentary requirements, '.
            'as the basis of this Office in taking further appropriate action on the subject application.',80,14,10);
        $pdf->paragraph('Should you have any questions or require further assistance, please feel free '.
            'to visit our Office, contact us via email at '.
            (string)($config['contact_emails']??'r13@denr.gov.ph').
            ', or through teleconferencing at your convenience.',80,14,8);
        $pdf->paragraph('Please be guided accordingly. Thank you.',80,14,14);
        $pdf->paragraph('Very truly yours,',94,14,7);

        $pdf->ensure(80);
        if ($jpegSignature!==null) $pdf->signatureImage(72,$pdf->y,150,37);
        $pdf->y+=44;
        $pdf->line(72,$pdf->y,(string)($config['signatory_name']??'Regional Executive Director'),11,true);
        $pdf->y+=14;
        $pdf->line(72,$pdf->y,(string)($config['signatory_title']??'Regional Executive Director'),10);
        $pdf->y+=14;
        if ($jpegSignature!==null) {
            $pdf->line(72,$pdf->y,'Electronically approved by RED on '.$today,8);
        } else {
            $pdf->line(72,$pdf->y,'DRAFT - NOT SIGNED / NOT FOR RELEASE',8,true);
        }
        $pdf->pages[]=$pdf->stream;
        return $pdf->finish();
    }

    private function finish(): string
    {
        $objects=[
            1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
            4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>'
        ];
        $images=$this->branding;
        if ($this->signature!==null) {
            $images['signature']=[
                'bytes'=>$this->signature,
                'width'=>$this->signatureWidth,'height'=>$this->signatureHeight
            ];
        }
        $imageIds=[];
        foreach ($images as $name=>$data) {
            $id=count($objects)+1;
            $jpeg=$data['bytes'];
            $info=getimagesizefromstring($jpeg);
            $color=(int)($info['channels']??3)===1?'DeviceGray':'DeviceRGB';
            $objects[$id]='<< /Type /XObject /Subtype /Image /Width '.$data['width'].
                ' /Height '.$data['height'].' /ColorSpace /'.$color.
                ' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg).
                " >>\nstream\n".$jpeg."\nendstream";
            $imageIds[$name]=$id;
        }
        $kids=[];
        foreach ($this->pages as $index=>$stream) {
            if (count($this->pages)>1) {
                $stream.=sprintf("BT /F1 8 Tf 1 0 0 1 515 25 Tm (Page %d) Tj ET\n",$index+1);
            }
            $contentId=count($objects)+1;
            $objects[$contentId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
            $pageId=count($objects)+1;
            $resources='<< /Font << /F1 3 0 R /F2 4 0 R >>';
            if ($imageIds) {
                $resources.=' /XObject <<';
                foreach ($imageIds as $name=>$imageId) {
                    $resources.=' /Im_'.$name.' '.$imageId.' 0 R';
                }
                $resources.=' >>';
            }
            $resources.=' >>';
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89]'.
                ' /Resources '.$resources.' /Contents '.$contentId.' 0 R >>';
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
        for ($id=1;$id<=count($objects);$id++) $output.=sprintf("%010d 00000 n \n",$offsets[$id]);
        $output.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\n";
        $output.="startxref\n".$start."\n%%EOF\n";
        return $output;
    }
}
