<?php
declare(strict_types=1);

/**
 * Dependency-free, single-page A4 writer for DENR Template No. 1.
 *
 * The geometry follows the approved letter reference: three-part letterhead,
 * addressee block, indented Subject line, six-row particulars table, signatory
 * block, and the fixed Region XIII/SOCOTEC footer. Long dynamic content is
 * compacted proportionally, but it is never allowed to create a second page.
 */
final class FpomsNoticePdf
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;

    private array $pages = [];
    private string $stream = '';
    private ?string $signature = null;
    private array $branding = [];
    private int $signatureWidth = 0;
    private int $signatureHeight = 0;
    private string $footer = '';

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

    private function line(float $x,float $top,string $text,float $size=10,
        bool $bold=false): void
    {
        $font=$bold?'F2':'F1';
        $this->stream.=sprintf(
            "BT /%s %.2F Tf 0 Tw 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $font,$size,$x,self::HEIGHT-$top,self::pdfText($text)
        );
    }

    /** Approximate Adobe Times-Roman metrics closely enough for PDF word spacing. */
    private static function timesWidth(string $text,float $size): float
    {
        $value=self::ascii($text);
        $units=0;
        foreach (str_split($value) as $character) {
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

    private function justifiedLine(float $x,float $top,string $text,float $size,
        float $width): void
    {
        $spaces=substr_count(self::ascii($text),' ');
        $remaining=$width-self::timesWidth($text,$size);
        if ($spaces<1 || $remaining<=0) {
            $this->line($x,$top,$text,$size);
            return;
        }
        $wordSpacing=$remaining/$spaces;
        $this->stream.=sprintf(
            "BT /F1 %.2F Tf %.3F Tw 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $size,$wordSpacing,$x,self::HEIGHT-$top,self::pdfText($text)
        );
    }

    private function centeredLine(float $top,string $text,float $size=10,
        bool $bold=false,float $center=self::WIDTH/2): void
    {
        // Times' average Latin glyph width is close to 0.46 em. The explicit
        // center keeps the official heading/footer stable without a font engine.
        $width=max(0,strlen(self::ascii($text))*$size*0.46);
        $this->line($center-$width/2,$top,$text,$size,$bold);
    }

    private function rule(float $x,float $top,float $width,float $height=0): void
    {
        $this->stream.=sprintf("%.2F %.2F %.2F %.2F re S\n",
            $x,self::HEIGHT-$top-$height,$width,$height);
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

    private function startPage(): void
    {
        $this->stream="0 G 0 g 0.5 w\n";

        // Template No. 1 letterhead artwork and measurements.
        $this->drawImage('denr',65,38,75,75);
        $this->drawImage('bagong',455,38,82,85);
        $this->line(153,72,'Department of Environment and Natural Resources',12,true);
        $this->line(182,88,'Kagawaran ng Kapaligiran at Likas Yaman',10.5);
        $this->line(263,104,'Caraga Region',11);

        $parts=array_values(array_filter(array_map('trim',explode('|',self::ascii($this->footer))),
            static fn(string $part): bool=>$part!==''));
        $this->centeredLine(801,(string)($parts[0]??''),8.2);
        if (count($parts)>1) {
            $this->centeredLine(812,implode(' ',array_slice($parts,1)),7.6);
        }
        $this->drawImage('socotec',458,766,64,40);
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

    private function wrapped(string $text,int $columns): array
    {
        $value=self::ascii($text);
        if ($value==='') return [''];
        return explode("\n",wordwrap($value,max(12,$columns),"\n",true));
    }

    private function drawLines(float $x,float &$y,array $lines,float $size,float $leading,
        bool $bold=false): void
    {
        foreach ($lines as $value) {
            $this->line($x,$y,(string)$value,$size,$bold);
            $y+=$leading;
        }
    }

    private function drawJustifiedLines(float $x,float &$y,array $lines,float $size,
        float $leading,float $width): void
    {
        $last=count($lines)-1;
        foreach ($lines as $index=>$value) {
            if ($index<$last) {
                $this->justifiedLine($x,$y,(string)$value,$size,$width);
            } else {
                $this->line($x,$y,(string)$value,$size);
            }
            $y+=$leading;
        }
    }

    private static function labeledRows(array $input): array
    {
        $rows=is_array($input['notice_rows']??null)?array_values($input['notice_rows']):[];
        $rows=array_slice($rows,0,6);
        while (count($rows)<6) $rows[]=['label'=>'','remarks'=>''];
        $result=[];
        foreach ($rows as $index=>$row) {
            $label=trim((string)($row['label']??''));
            $result[]=[
                'left'=>chr(97+$index).') '.($label!==''?$label:''),
                'right'=>trim((string)($row['remarks']??''))
            ];
        }
        return $result;
    }

    private function layout(array $app,array $input,array $config,float $scale): array
    {
        $bodyColumns=(int)round(89/$scale);
        $subjectColumns=(int)round(58/$scale);
        $tableLeftColumns=(int)round(43/$scale);
        $tableRightColumns=(int)round(42/$scale);
        $recipientColumns=(int)round(80/$scale);

        $address=[];
        foreach ([
            (string)($app['applicant_name']??'NAME OF PROPONENT'),
            (string)($app['representative_position']??'Authorized Representative'),
            (string)($app['company_name']??'COMPANY NAME'),
            (string)($app['company_address']??'COMPANY ADDRESS')
        ] as $value) {
            array_push($address,...$this->wrapped($value,$recipientColumns));
        }

        $company=(string)($app['company_name']??'COMPANY NAME');
        $project=(string)($app['project_title']??'PROJECT NAME');
        $location=rtrim((string)($app['project_location']??'LOCATION OF THE PROJECT')," .\t\n\r\0\x0B");
        $applicant=(string)($app['applicant_name']??'NAME OF PROPONENT');
        $area=trim((string)($input['area_hectares']??''));
        $areaText=$area!==''?$area:'__________';
        $contacts=trim((string)($config['contact_emails']??
            'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com'));
        $contacts=preg_replace('/\s*;\s*/',' and ',$contacts)??$contacts;

        $subject=$this->wrapped('Application for Feasibility Permit of '.$company.
            ' for its '.$project,$subjectColumns);
        $paragraph1=$this->wrapped('This pertains to the application for a Feasibility Permit of '.
            $company.' covering the proposed '.$project.' with an area of '.$areaText.
            ' hectares located in '.$location.'.',$bodyColumns);
        $paragraph2=$this->wrapped('Please be informed that upon review and evaluation of the '.
            'application documents, the assessment status of each documentary requirement is '.
            'reflected below:',$bodyColumns);
        $paragraph3=$this->wrapped('In this regard, kindly comply with and resubmit only the '.
            'documentary requirements marked Lacking or Inappropriate, as the basis of this Office '.
            'in taking further appropriate action on the subject application.',$bodyColumns);
        $paragraph4=$this->wrapped('Should you have any questions or require further assistance, '.
            'please feel free to visit our Office, contact us via email at '.$contacts.
            ', or through teleconferencing at your convenience.',$bodyColumns);
        $paragraph5=$this->wrapped('Please be guided accordingly. Thank you.',$bodyColumns);

        $rows=[];
        foreach (self::labeledRows($input) as $row) {
            $left=$this->wrapped($row['left'],$tableLeftColumns);
            $right=$this->wrapped($row['right'],$tableRightColumns);
            $rows[]=['left'=>$left,'right'=>$right,'lines'=>max(count($left),count($right))];
        }

        $recipientLeading=13*$scale;
        $subjectLeading=13*$scale;
        $bodyLeading=13*$scale;
        $tableLeading=10.5*$scale;
        $rowMinimum=17*$scale;
        $tableHeight=19*$scale;
        foreach ($rows as $row) {
            $tableHeight+=max($rowMinimum,$row['lines']*$tableLeading+6*$scale);
        }

        // Predict the signatory title baseline. The caller reduces scale until
        // the footer-safe limit is satisfied.
        $y=177+count($address)*$recipientLeading+28*$scale;
        $y+=count($subject)*$subjectLeading+17*$scale;
        $y+=27*$scale;
        $y+=count($paragraph1)*$bodyLeading+8*$scale;
        $y+=count($paragraph2)*$bodyLeading+10*$scale;
        $y+=$tableHeight+24*$scale;
        $y+=count($paragraph3)*$bodyLeading+7*$scale;
        $y+=count($paragraph4)*$bodyLeading+6*$scale;
        $y+=count($paragraph5)*$bodyLeading+14*$scale;
        $predictedTitleY=$y+71*$scale;

        return compact('scale','address','subject','applicant','paragraph1','paragraph2',
            'paragraph3','paragraph4','paragraph5','rows','recipientLeading','subjectLeading',
            'bodyLeading','tableLeading','rowMinimum','tableHeight','predictedTitleY');
    }

    public static function render(array $app,array $input,array $config,?string $jpegSignature=null,
        ?string $approvalDate=null,bool $printForManualSigning=false): string
    {
        $pdf=new self();
        $pdf->footer=(string)($config['footer_text']??
            'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 E-Mail: r13@denr.gov.ph');
        $pdf->loadBranding(is_array($config['letterhead_images']??null)
            ?$config['letterhead_images']:[]);
        $pdf->setSignature($jpegSignature);

        $scale=1.0;
        do {
            $layout=$pdf->layout($app,$input,$config,$scale);
            if ($layout['predictedTitleY']<=760 || $scale<=0.72) break;
            $scale=round($scale-0.04,2);
        } while (true);

        $pdf->startPage();
        if ($jpegSignature===null && !$printForManualSigning) {
            $pdf->stream.="0.72 0.02 0.02 rg\n";
            $pdf->line(335,128,'DRAFT - NOT SIGNED / NOT FOR RELEASE',7.2,true);
            $pdf->stream.="0 g\n";
        }

        $today=$approvalDate?:date('F d, Y');
        $pdf->line(72,137,$today,11);
        $dateWidth=max(34,strlen(self::ascii($today))*5.1);
        $pdf->strokeLine(72,139,72+$dateWidth,139);

        $s=(float)$layout['scale'];
        $y=177.0;
        $pdf->drawLines(72,$y,$layout['address'],11*$s,$layout['recipientLeading']);
        $y+=28*$s;

        $pdf->line(108,$y,'Subject:',11*$s,true);
        $subjectY=$y;
        $pdf->drawLines(185,$subjectY,$layout['subject'],11*$s,$layout['subjectLeading'],true);
        $y=$subjectY+17*$s;

        $pdf->line(72,$y,'Dear '.$layout['applicant'].':',11*$s,true);
        $y+=27*$s;

        $bodyWidth=448.0;
        $pdf->drawJustifiedLines(72,$y,$layout['paragraph1'],11*$s,
            $layout['bodyLeading'],$bodyWidth);
        $y+=8*$s;
        $pdf->drawJustifiedLines(72,$y,$layout['paragraph2'],11*$s,
            $layout['bodyLeading'],$bodyWidth);
        $y+=10*$s;

        $tableX=100.0;
        $tableWidth=420.0;
        $tableSplit=200.0;
        $headerHeight=19*$s;
        $pdf->rule($tableX,$y,$tableWidth,$headerHeight);
        $pdf->rule($tableX+$tableSplit,$y,0,$headerHeight);
        $pdf->line(176,$y+13*$s,'Particulars',10.2*$s,true);
        $pdf->line(397,$y+13*$s,'Remarks',10.2*$s,true);
        $y+=$headerHeight;
        foreach ($layout['rows'] as $row) {
            $height=max($layout['rowMinimum'],$row['lines']*$layout['tableLeading']+6*$s);
            $pdf->rule($tableX,$y,$tableWidth,$height);
            $pdf->rule($tableX+$tableSplit,$y,0,$height);
            $leftY=$y+11*$s;
            $rightY=$leftY;
            $pdf->drawLines($tableX+10,$leftY,$row['left'],9.4*$s,$layout['tableLeading']);
            $pdf->drawLines($tableX+$tableSplit+10,$rightY,$row['right'],9.4*$s,
                $layout['tableLeading']);
            $y+=$height;
        }

        $y+=24*$s;
        $pdf->drawJustifiedLines(72,$y,$layout['paragraph3'],11*$s,
            $layout['bodyLeading'],$bodyWidth);
        $y+=7*$s;
        $pdf->drawJustifiedLines(72,$y,$layout['paragraph4'],11*$s,
            $layout['bodyLeading'],$bodyWidth);
        $y+=6*$s;
        $pdf->drawJustifiedLines(72,$y,$layout['paragraph5'],11*$s,
            $layout['bodyLeading'],$bodyWidth);
        $y+=14*$s;

        $pdf->line(72,$y,'Very truly yours,',11*$s);
        if ($jpegSignature!==null) $pdf->drawImage('signature',72,$y+9*$s,132,34*$s);
        $nameY=$y+58*$s;
        $pdf->line(72,$nameY,(string)($config['signatory_name']??'MA. THERESA J. ALLEN'),
            11*$s,true);
        $pdf->line(72,$nameY+13*$s,
            (string)($config['signatory_title']??'OIC, Regional Executive Director'),10.5*$s);

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
                'bytes'=>$this->signature,'width'=>$this->signatureWidth,
                'height'=>$this->signatureHeight
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
        foreach ($this->pages as $stream) {
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
        for ($id=1;$id<=count($objects);$id++) {
            $output.=sprintf("%010d 00000 n \n",$offsets[$id]);
        }
        $output.="trailer\n<< /Size ".(count($objects)+1)." /Root 1 0 R >>\n";
        $output.="startxref\n".$start."\n%%EOF\n";
        return $output;
    }
}
