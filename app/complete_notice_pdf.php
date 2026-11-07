<?php
declare(strict_types=1);

final class FpomsCompleteNoticePdf
{
    private const WIDTH=595.28;
    private const HEIGHT=841.89;
    private array $pages=[];
    private string $stream='';
    private ?string $signature=null;
    private array $branding=[];
    private int $signatureWidth=0;
    private int $signatureHeight=0;
    private string $footer='';

    private static function pdfText(string $text): string
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
        if ($encoded===false) $encoded=preg_replace('/[^\x20-\x7e]/','?',$text)??'?';
        $encoded=preg_replace('/[\x00-\x1f]/',' ',$encoded)??'';
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$encoded);
    }

    private static function ascii(string $text): string
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
        if ($encoded===false) $encoded=$text;
        return preg_replace('/\s+/',' ',trim($encoded))??'';
    }

    private function line(float $x,float $top,string $text,float $size=10,bool $bold=false): void
    {
        $font=$bold?'F2':'F1';
        $this->stream.=sprintf(
            "BT /%s %.2F Tf 0 Tw 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font,$size,$x,self::HEIGHT-$top,self::pdfText($text)
        );
    }

    private static function timesWidth(string $text,float $size): float
    {
        $value=self::ascii($text);
        $units=0;
        foreach (str_split($value) as $c) {
            $units+=match (true) {
                $c===' '=>250,
                str_contains('.,',$c)=>250,
                str_contains(':;!',$c)=>333,
                $c==="'"=>180,
                str_contains('()[]-',$c)=>333,
                str_contains('/\\',$c)=>278,
                $c==='@'=>921,
                $c==='&'=>778,
                ctype_digit($c)=>500,
                str_contains('ijl',$c)=>278,
                str_contains('fr',$c)=>333,
                $c==='t'=>278,
                $c==='s'=>389,
                str_contains('acez',$c)=>444,
                str_contains('bdghknopquvxy',$c)=>500,
                $c==='m'=>778,
                $c==='w'=>722,
                $c==='I'=>333,
                $c==='J'=>389,
                str_contains('FPS',$c)=>556,
                str_contains('ELTZ',$c)=>611,
                str_contains('BCR',$c)=>667,
                str_contains('ADGHKNOQUVXY',$c)=>722,
                $c==='M'=>889,
                $c==='W'=>944,
                default=>500,
            };
        }
        return $units*$size/1000;
    }

    private function justifiedLine(float $x,float $top,string $text,float $size,float $width): void
    {
        $spaces=substr_count(self::ascii($text),' ');
        $remaining=$width-self::timesWidth($text,$size);
        if ($spaces<1 || $remaining<=0) {
            $this->line($x,$top,$text,$size);
            return;
        }
        $this->stream.=sprintf(
            "BT /F1 %.2F Tf %.3F Tw 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $size,$remaining/$spaces,$x,self::HEIGHT-$top,self::pdfText($text)
        );
    }

    private function centeredLine(float $top,string $text,float $size=10,bool $bold=false,float $center=self::WIDTH/2): void
    {
        $width=max(0,strlen(self::ascii($text))*$size*0.46);
        $this->line($center-$width/2,$top,$text,$size,$bold);
    }

    private function strokeLine(float $x1,float $top1,float $x2,float $top2): void
    {
        $this->stream.=sprintf("%.2F %.2F m %.2F %.2F l S\n",
            $x1,self::HEIGHT-$top1,$x2,self::HEIGHT-$top2);
    }

    private function loadBranding(array $branding): void
    {
        foreach (['denr','bagong','socotec'] as $name) {
            $bytes=$branding[$name]??null;
            if (!is_string($bytes)) continue;
            $info=@getimagesizefromstring($bytes);
            if (!$info || ($info[2]??null)!==IMAGETYPE_JPEG) continue;
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

    private function startPage(): void
    {
        $this->stream="0 G 0 g 0.5 w\n";
        $this->drawImage('denr',65,38,75,75);
        $this->drawImage('bagong',455,38,82,85);
        $this->line(153,72,'Department of Environment and Natural Resources',12,true);
        $this->line(182,88,'Kagawaran ng Kapaligiran at Likas Yaman',10.5);
        $this->line(263,104,'Caraga Region',11);
        $parts=array_values(array_filter(array_map('trim',explode('|',self::ascii($this->footer))),
            static fn(string $part): bool=>$part!==''));
        $this->centeredLine(801,(string)($parts[0]??''),8.2);
        if (count($parts)>1) $this->centeredLine(812,implode(' ',array_slice($parts,1)),7.6);
        $this->drawImage('socotec',458,766,64,40);
    }

    private function wrapped(string $text,int $columns): array
    {
        $value=self::ascii($text);
        if ($value==='') return [''];
        return explode("\n",wordwrap($value,max(12,$columns),"\n",true));
    }

    private function drawLines(float $x,float &$y,array $lines,float $size,float $leading,bool $bold=false): void
    {
        foreach ($lines as $value) {
            $this->line($x,$y,(string)$value,$size,$bold);
            $y+=$leading;
        }
    }

    private function drawJustifiedLines(float $x,float &$y,array $lines,float $size,float $leading,float $width): void
    {
        $last=count($lines)-1;
        foreach ($lines as $index=>$value) {
            if ($index<$last) $this->justifiedLine($x,$y,(string)$value,$size,$width);
            else $this->line($x,$y,(string)$value,$size);
            $y+=$leading;
        }
    }

    public static function render(
        array $app,array $input,array $config,?string $jpegSignature=null,
        ?string $approvalDate=null,bool $printForSigning=false
    ): string {
        $pdf=new self();
        $pdf->footer=(string)($config['footer_text']??
            'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 | E-Mail: r13@denr.gov.ph');
        $pdf->loadBranding(is_array($config['letterhead_images']??null)?$config['letterhead_images']:[]);
        $pdf->setSignature($jpegSignature);
        $pdf->startPage();
        if ($jpegSignature===null && !$printForSigning) {
            $pdf->stream.="0.72 0.02 0.02 rg\n";
            $pdf->line(350,128,'DRAFT - NOT SIGNED / NOT FOR RELEASE',7.2,true);
            $pdf->stream.="0 g\n";
        }

        $today=$approvalDate?:date('F d, Y');
        $pdf->line(72,137,$today,11);
        $dateWidth=max(34,strlen(self::ascii($today))*5.1);
        $pdf->strokeLine(72,139,72+$dateWidth,139);

        $address=[];
        foreach ([
            (string)($app['applicant_name']??'NAME OF PROPONENT'),
            (string)($app['representative_position']??'POSITION'),
            (string)($app['company_name']??'COMPANY NAME'),
            (string)($app['company_address']??'COMPANY ADDRESS')
        ] as $value) array_push($address,...$pdf->wrapped($value,80));
        $y=177.0;
        $pdf->drawLines(72,$y,$address,10.8,12.5);
        $y+=24;

        $company=(string)($app['company_name']??'COMPANY NAME');
        $project=(string)($app['project_title']??'PROJECT NAME');
        $location=rtrim((string)($app['project_location']??'LOCATION OF THE PROJECT')," .\t\n\r\0\x0B");
        $applicant=(string)($app['applicant_name']??'Applicant');
        $area=trim((string)($input['area_hectares']??''));
        $areaText=$area!==''?$area:'_____________';
        $penro=trim((string)($input['penro_name']??'')) ?: '________________';
        $cenro=trim((string)($input['cenro_name']??'')) ?: '___________________';
        $contacts=trim((string)($config['contact_emails']??
            'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com'));
        $contacts=preg_replace('/\s*;\s*/',' and ',$contacts)??$contacts;

        $pdf->line(108,$y,'Subject:',10.8,true);
        $subjectY=$y;
        $pdf->drawLines(185,$subjectY,
            $pdf->wrapped('Application for Feasibility Permit of '.$company.' for its '.$project,58),
            10.8,12.5,true);
        $y=$subjectY+30;
        $pdf->line(72,$y,'Dear '.$applicant.':',10.8,true);
        $y+=24;

        $bodyWidth=448.0;
        $paragraphs=[
            'This pertains to the application for a Feasibility Permit of '.$company.
                ' covering the proposed '.$project.' with an area of '.$areaText.
                ' hectares located in '.$location.'.',
            'Please be informed that upon review and evaluation of the application documents, the application is found complete and meritorious, consistent with the existing policy and regulation.',
            'In this regard, kindly submit a hard copy of the complete documentary requirements to this Office and pay the corresponding Application Fee of PHP 10,000.00 pursuant to Item 4 of the Memorandum of the DENR Secretary dated January 28, 2026 (Interim Guidelines relative to the Implementation of Section 14 of DENR Administrative Order No. 2025-22 or the Rules and Regulations Governing Sustainable Forest Land Management Agreement).',
            'Moreover, we have instructed the PENRO-'.$penro.' and CENRO-'.$cenro.
                ' to conduct field validation and assessment of the applied area for FP. Kindly coordinate with them regarding the schedule and any other relevant matters.',
            'Should you have any questions or require further assistance, please feel free to visit our Office, contact us via email at '.$contacts.', or through teleconferencing at your convenience.',
            'Please be guided accordingly. Thank you.'
        ];
        foreach ($paragraphs as $index=>$paragraph) {
            $lines=$pdf->wrapped($paragraph,$index===2?91:89);
            $pdf->drawJustifiedLines(72,$y,$lines,10.5,12.3,$bodyWidth);
            $y+=($index===5?16:9);
        }
        $pdf->line(72,$y,'Very truly yours,',10.8);
        if ($jpegSignature!==null) $pdf->drawImage('signature',72,$y+8,132,34);
        $nameY=$y+56;
        $pdf->line(72,$nameY,(string)($config['signatory_name']??'MA. THERESA J. ALLEN'),10.8,true);
        $pdf->line(72,$nameY+13,(string)($config['signatory_title']??'OIC, Regional Executive Director'),10.2);
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
        if ($this->signature!==null) $images['signature']=[
            'bytes'=>$this->signature,'width'=>$this->signatureWidth,'height'=>$this->signatureHeight
        ];
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
        foreach ($this->pages as $stream) {
            $contentId=count($objects)+1;
            $objects[$contentId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
            $pageId=count($objects)+1;
            $resources='<< /Font << /F1 3 0 R /F2 4 0 R >>';
            if ($imageIds) {
                $resources.=' /XObject <<';
                foreach ($imageIds as $name=>$imageId) $resources.=' /Im_'.$name.' '.$imageId.' 0 R';
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
