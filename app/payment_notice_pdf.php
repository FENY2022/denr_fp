<?php
declare(strict_types=1);

final class FpomsPaymentNoticePdf
{
    private const W=595.28;
    private const H=841.89;
    private string $stream='';
    private array $images=[];
    private string $footer='';

    private static function txt(string $value): string
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$value);
        if ($encoded===false) $encoded=preg_replace('/[^\x20-\x7e]/','?',$value)??'?';
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],
            preg_replace('/[\x00-\x1f]/',' ',(string)$encoded)??'');
    }
    private function line(float $x,float $top,string $text,float $size=10,bool $bold=false): void
    {
        $this->stream.=sprintf("BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $bold?'F2':'F1',$size,$x,self::H-$top,self::txt($text));
    }
    private function wrap(string $text,int $columns): array
    {
        return explode("\n",wordwrap(trim($text),$columns,"\n",true));
    }
    private function lines(float $x,float &$y,string $text,int $columns,float $size=10,float $leading=13,bool $bold=false): void
    {
        foreach ($this->wrap($text,$columns) as $line) {
            $this->line($x,$y,$line,$size,$bold);
            $y+=$leading;
        }
    }
    private function image(string $name,float $x,float $top,float $w,float $h): void
    {
        if (!isset($this->images[$name])) return;
        $this->stream.=sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im_%s Do Q\n",
            $w,$h,$x,self::H-$top-$h,$name);
    }
    private function loadImages(array $branding,?string $signature): void
    {
        foreach ($branding as $name=>$bytes) {
            if (!is_string($bytes)) continue;
            $info=@getimagesizefromstring($bytes);
            if (!$info || ($info[2]??null)!==IMAGETYPE_JPEG) continue;
            $this->images[$name]=['bytes'=>$bytes,'width'=>(int)$info[0],'height'=>(int)$info[1]];
        }
        if ($signature!==null) {
            $info=@getimagesizefromstring($signature);
            if (!$info || ($info[2]??null)!==IMAGETYPE_JPEG) throw new RuntimeException('Invalid RED signature JPEG.');
            $this->images['signature']=['bytes'=>$signature,'width'=>(int)$info[0],'height'=>(int)$info[1]];
        }
    }
    public static function render(
        array $app,array $config,?string $signature=null,?string $date=null,
        bool $printForSigning=false
    ): string
    {
        $pdf=new self();
        $pdf->footer=(string)($config['footer_text']??'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 E-Mail: r13@denr.gov.ph');
        $pdf->loadImages(is_array($config['letterhead_images']??null)?$config['letterhead_images']:[],$signature);
        $pdf->stream="0 G 0 g 0.5 w\n";
        $pdf->image('denr',65,35,72,72);
        $pdf->image('bagong',458,35,78,82);
        $pdf->line(153,68,'Department of Environment and Natural Resources',12,true);
        $pdf->line(181,84,'Kagawaran ng Kapaligiran at Likas Yaman',10.5);
        $pdf->line(263,100,'Caraga Region',11);
        if ($signature===null && !$printForSigning) {
            $pdf->line(350,126,'DRAFT - NOT SIGNED / NOT FOR RELEASE',7.2,true);
        }
        $y=138.0;
        $pdf->line(72,$y,$date?:date('F d, Y'),10.5); $y+=38;
        foreach ([
            (string)($app['applicant_name']??'NAME OF PROPONENT'),
            (string)($app['representative_position']??'POSITION'),
            (string)($app['company_name']??'COMPANY NAME'),
            (string)($app['company_address']??'COMPANY ADDRESS')
        ] as $value) { $pdf->line(72,$y,$value,10.5); $y+=13; }
        $y+=17;
        $pdf->line(105,$y,'Subject:',10.5,true);
        $subject='Application for Feasibility Permit of '.(string)($app['company_name']??'COMPANY NAME')
            .' for its '.(string)($app['project_title']??'PROJECT NAME');
        $sy=$y;
        $pdf->lines(185,$sy,$subject,58,10.5,13,true);
        $y=max($y+26,$sy+10);
        $pdf->line(72,$y,'Dear '.(string)($app['applicant_name']??'Proponent').':',10.5,true); $y+=26;
        $area=trim((string)($app['project_area_hectares']??'')) ?: '_____________';
        $location=(string)($app['project_location']??'LOCATION OF THE PROJECT');
        $company=(string)($app['company_name']??'COMPANY NAME');
        $project=(string)($app['project_title']??'PROJECT NAME');
        $penro=trim((string)($app['concerned_penro']??''));
        $cenro=trim((string)($app['concerned_cenro']??''));
        $penroLabel=$penro!=='' ? (preg_replace('/^PENRO\s+/i','PENRO-',$penro)??$penro)
            : 'PENRO-________________';
        $cenroLabel=$cenro!=='' ? (preg_replace('/^CENRO\s+/i','CENRO-',$cenro)??$cenro)
            : 'CENRO-________________';
        if ($penro!=='' && $cenro==='') {
            $fieldOfficeSentence='Moreover, we have instructed the '.$penroLabel
                .' to conduct field validation and assessment of the applied area for FP. '
                .'Kindly coordinate with the concerned PENRO regarding the schedule and any other relevant matters.';
        } else {
            $fieldOfficeSentence='Moreover, we have instructed the '.$penroLabel.' and '.$cenroLabel
                .' to conduct field validation and assessment of the applied area for FP. '
                .'Kindly coordinate with them regarding the schedule and any other relevant matters.';
        }
        $paragraphs=[
            'This pertains to the application for a Feasibility Permit of '.$company.' covering the proposed '.$project
                .' with an area of '.$area.' hectares located in '.$location.'.',
            'Please be informed that upon review and evaluation of the application documents, the application is found complete and meritorious, consistent with the existing policy and regulation.',
            'In this regard, kindly submit a hard copy of the complete documentary requirements to this Office and pay the corresponding payment of the Application Fee of PHP 10,000.00 pursuant to Item 4 of the Memorandum of the DENR Secretary dated January 28, 2026 (Interim Guidelines relative to the Implementation of Section 14 of DENR Administrative Order No. 2025-22 or the Rules and Regulations Governing Sustainable Forest Land Management Agreement).',
            $fieldOfficeSentence,
            'Should you have any questions or require further assistance, please feel free to visit our Office, contact us via email at r13@denr.gov.ph and lasangutilizationlpdd@gmail.com, or through a teleconferencing at your convenience.',
            'Please be guided accordingly. Thank you.'
        ];
        foreach ($paragraphs as $idx=>$text) {
            $pdf->lines(72,$y,$text,90,10.3,12.4);
            $y+=($idx===5?10:8);
        }
        $pdf->line(72,$y,'Very truly yours,',10.5); $y+=10;
        if ($signature!==null) $pdf->image('signature',72,$y,132,34);
        $nameY=$y+48;
        $pdf->line(72,$nameY,(string)($config['signatory_name']??'MA. THERESA J. ALLEN'),10.7,true);
        $pdf->line(72,$nameY+13,(string)($config['signatory_title']??'OIC, Regional Executive Director'),10.2);
        $parts=array_values(array_filter(array_map('trim',explode('|',$pdf->footer))));
        $pdf->line(165,798,(string)($parts[0]??''),7.8);
        if (count($parts)>1) $pdf->line(155,810,implode(' ',array_slice($parts,1)),7.4);
        $pdf->image('socotec',458,765,64,40);
        return $pdf->finish();
    }
    private function finish(): string
    {
        $objects=[
            1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>',
            4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>'
        ];
        $imageIds=[];
        foreach ($this->images as $name=>$data) {
            $id=count($objects)+1; $bytes=$data['bytes'];
            $info=getimagesizefromstring($bytes);
            $color=(int)($info['channels']??3)===1?'DeviceGray':'DeviceRGB';
            $objects[$id]='<< /Type /XObject /Subtype /Image /Width '.$data['width'].' /Height '.$data['height']
                .' /ColorSpace /'.$color.' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes)
                ." >>\nstream\n".$bytes."\nendstream";
            $imageIds[$name]=$id;
        }
        $contentId=count($objects)+1;
        $objects[$contentId]='<< /Length '.strlen($this->stream)." >>\nstream\n".$this->stream."endstream";
        $pageId=count($objects)+1;
        $resources='<< /Font << /F1 3 0 R /F2 4 0 R >>';
        if ($imageIds) {
            $resources.=' /XObject <<';
            foreach ($imageIds as $name=>$id) $resources.=' /Im_'.$name.' '.$id.' 0 R';
            $resources.=' >>';
        }
        $resources.=' >>';
        $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources '.$resources.' /Contents '.$contentId.' 0 R >>';
        $objects[2]='<< /Type /Pages /Count 1 /Kids ['.$pageId.' 0 R] >>';
        ksort($objects);
        $out="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[0];
        foreach ($objects as $id=>$obj) { $offsets[$id]=strlen($out); $out.=$id." 0 obj\n".$obj."\nendobj\n"; }
        $start=strlen($out); $out.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objects);$i++) $out.=sprintf("%010d 00000 n \n",$offsets[$i]);
        $out.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n".$start."\n%%EOF\n";
        return $out;
    }
}
