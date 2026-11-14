<?php
declare(strict_types=1);

final class FpomsCenroMemoPdf
{
    private const W=612.0;
    private const H=936.0;
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
    private static function ascii(string $text): string
    {
        $encoded=iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
        if ($encoded===false) $encoded=$text;
        return preg_replace('/\s+/',' ',trim($encoded))??'';
    }
    /** Approximate Adobe Times-Roman metrics for paragraph word spacing. */
    private static function timesWidth(string $text,float $size): float
    {
        $units=0;
        foreach (str_split(self::ascii($text)) as $character) {
            $units+=match (true) {
                $character===' '=>250,
                str_contains('.,', $character)=>250,
                str_contains(':;!', $character)=>333,
                str_contains("'`", $character)=>180,
                str_contains('()[]-', $character)=>333,
                str_contains('/\\', $character)=>278,
                $character==='@'=>921,
                $character==='&'=>778,
                ctype_digit($character)=>500,
                str_contains('ijl', $character)=>278,
                str_contains('fr', $character)=>333,
                $character==='t'=>278,
                $character==='s'=>389,
                str_contains('acez', $character)=>444,
                str_contains('bdghknopquvxy', $character)=>500,
                $character==='m'=>778,
                $character==='w'=>722,
                $character==='I'=>333,
                $character==='J'=>389,
                str_contains('FPS', $character)=>556,
                str_contains('ELTZ', $character)=>611,
                str_contains('BCR', $character)=>667,
                str_contains('ADGHKNOQUVXY', $character)=>722,
                $character==='M'=>889,
                $character==='W'=>944,
                default=>500,
            };
        }
        return $units*$size/1000;
    }
    private function line(float $x,float $top,string $text,float $size=10,bool $bold=false): void
    {
        $this->stream.=sprintf("BT /%s %.2F Tf 0 Tw 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $bold?'F2':'F1',$size,$x,self::H-$top,self::txt($text));
    }
    private function justifiedLine(float $x,float $top,string $text,float $size,
        float $width): void
    {
        $spaces=substr_count(self::ascii($text),' ');
        $remaining=$width-self::timesWidth($text,$size);
        if ($spaces<1 || $remaining<=0) {
            $this->line($x,$top,$text,$size);
            return;
        }
        $this->stream.=sprintf(
            "BT /F1 %.2F Tf %.3F Tw 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $size,$remaining/$spaces,$x,self::H-$top,self::txt($text)
        );
    }
    private function wrap(string $text,int $columns): array
    {
        return explode("\n",wordwrap(trim($text),$columns,"\n",true));
    }
    private function wrapToWidth(string $text,float $size,float $width): array
    {
        $words=preg_split('/\s+/',trim($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
        if (!$words) return [''];
        $lines=[];
        $line='';
        foreach ($words as $word) {
            $candidate=$line===''?$word:$line.' '.$word;
            if ($line!=='' && self::timesWidth($candidate,$size)>$width) {
                $lines[]=$line;
                $line=$word;
            } else {
                $line=$candidate;
            }
        }
        if ($line!=='') $lines[]=$line;
        return $lines;
    }
    private function lines(float $x,float &$y,string $text,int $columns,float $size=10,float $leading=13,bool $bold=false): void
    {
        foreach ($this->wrap($text,$columns) as $line) {
            $this->line($x,$y,$line,$size,$bold);
            $y+=$leading;
        }
    }
    private function justifiedLines(float $x,float &$y,string $text,float $width,
        float $size=10,float $leading=13): void
    {
        $lines=$this->wrapToWidth($text,$size,$width);
        $last=count($lines)-1;
        foreach ($lines as $index=>$line) {
            if ($index<$last) $this->justifiedLine($x,$y,$line,$size,$width);
            else $this->line($x,$y,$line,$size);
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
    /** Official DENR Caraga Template No. 3: instruction to PENRO / CENRO. */
    public static function render(array $app,array $config,?string $date=null,bool $printForSigning=false): string
    {
        $pdf=new self();
        $pdf->footer=(string)($config['footer_text']
            ??'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 E-Mail: r13@denr.gov.ph');
        $pdf->loadImages(is_array($config['letterhead_images']??null)?$config['letterhead_images']:[],null);
        $pdf->stream="0 G 0 g 0.5 w\n";
        $pdf->stream.=sprintf("0.5 w 400 %.2F 116 29 re S\n",self::H-34-29);
        $pdf->line(415,52,'Template No.3',9);
        $pdf->image('denr',67,35,77,75);
        $pdf->image('bagong',458,35,76,77);
        $pdf->line(153,75,'Department of Environment and Natural Resources',12,true);
        $pdf->line(184,91,'Kagawaran ng Kapaligiran at Likas Yaman',10.5);
        $pdf->line(265,107,'Caraga Region',11);
        if (!$printForSigning) $pdf->line(349,126,'DRAFT - NOT SIGNED / NOT FOR RELEASE',7.0,true);
        $pdf->line(72,148,'MEMORANDUM',12,true);
        $penro=trim((string)($app['concerned_penro']??''));
        $cenro=trim((string)($app['concerned_cenro']??''));
        $company=trim((string)($app['company_name']??'COMPANY NAME'));
        $project=trim((string)($app['project_title']??'PROJECT NAME'));
        $area=trim((string)($app['project_area_hectares']??'')) ?: '_____________';
        $location=trim((string)($app['project_location']??'PROJECT LOCATION'));
        $penroLabel=$penro!==''?$penro:'________________';
        $cenroLabel=$cenro!==''?$cenro:'________________';
        $pdf->line(72,181,'TO',10.6,true);
        $pdf->line(157,181,': THE OIC, PENR OFFICER',10.3,true);
        $pdf->line(164,197,'DENR PENRO - '.$penroLabel,10.3);
        $pdf->line(72,238,"ATT'N",10.6,true);
        $pdf->line(157,238,': CENRO - '.$cenroLabel,10.3);
        $pdf->line(72,267,'FROM',10.6,true);
        $pdf->line(157,267,': THE OIC, REGIONAL EXECUTIVE DIRECTOR',10.3,true);
        $pdf->line(72,296,'SUBJECT',10.6,true);
        $subject=': APPLICATION FOR FEASIBILITY PERMIT OF '.mb_strtoupper($company)
            .' FOR ITS '.mb_strtoupper($project);
        $y=296.0;
        $pdf->lines(157,$y,$subject,60,10.0,14,true);
        $y=max(341,$y+8);
        $pdf->line(72,$y,'DATE',10.6,true);
        $pdf->line(157,$y,': '.($date?:date('F d, Y')),10.3);
        $y+=24;
        $pdf->stream.=sprintf("0.6 w 72 %.2F m 540 %.2F l S\n",self::H-$y,self::H-$y);
        $y+=28;
        $paragraphs=[
            'This pertains to the application for the Feasibility Permit (FP) of '.$company
                .' for its '.$project.', with an area of approximately '.$area
                .' hectares to be located in '.$location.'.',
            'Please be informed that pursuant to the Memorandum from the Secretary dated January 28, 2026 (Interim Guidelines relative to the Implementation of Section 14 of DENR Administrative Order No. 2025-22 or the Rules and Regulations Governing Sustainable Forest Land Management Agreement), FP is a non-renewable, one-time permit granted to a qualified applicant, providing sufficient time to undertake feasibility studies within forestlands for a period not exceeding two (2) years, subject on the nature and requirements of the renewable energy project.',
            'As part of the procedure for the processing and approval of the FP application, the concerned DENR Field Office shall conduct the necessary field validation and assessment, and thereafter submit to this Office the corresponding assessment report, including an indicative map, technical description, and shapefile of the applied area.',
            'In this regard, you are hereby instructed to conduct field validation and assessment of the subject area. Submit report of compliance, including the indicative map, technical description, and shapefile, within 5 days upon completion of the activity. This will serve as the basis for further review and evaluation of the subject application and in preparation for the final map.',
            'Kindly coordinate with the proponent accordingly.',
            'For information and strict compliance.'
        ];
        foreach($paragraphs as $index=>$text){
            $pdf->justifiedLines(72,$y,$text,self::W-144,9.75,11.2);
            $y+=($index>=3?15:10);
        }
        if ($y>792) throw new RuntimeException('Template No. 3 content exceeds printable 8.5 x 13 inch body. Check long project information.');
        $signatureY=max($y+25,754);
        $pdf->line(356,$signatureY,(string)($config['signatory_name']??'MA. THERESA J. ALLEN'),10.8,true);
        $pdf->line(356,$signatureY+14,(string)($config['signatory_title']??'OIC, Regional Executive Director'),9.7);
        $parts=array_values(array_filter(array_map('trim',explode('|',$pdf->footer))));
        $pdf->line(220,888,(string)($parts[0]??''),7.7);
        if(count($parts)>1)$pdf->line(201,901,implode(' ',array_slice($parts,1)),7.2);
        $pdf->image('socotec',470,855,69,48);
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
        $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::W.' '.self::H.'] /Resources '.$resources.' /Contents '.$contentId.' 0 R >>';
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
