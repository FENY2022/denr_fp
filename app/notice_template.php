<?php
declare(strict_types=1);

/**
 * Read the three official header/footer images embedded in Template No. 1.
 * The uploaded DOCX is not executed, converted by a shell, or served publicly.
 * Each image is converted in memory to a bounded white-backed JPEG for the PDF.
 */
function fpoms_notice_template_branding(array $config): array
{
    $path=(string)($config['template_path']??'');
    if ($path==='') {
        throw new RuntimeException('Upload the official Template No. 1 DOCX in Configuration before generating a deficiency notice.');
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZIP extension is required to read the uploaded official DOCX template.');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new RuntimeException('PHP GD extension is required to render the official letterhead logos.');
    }

    $bytes=fpoms_notice_safe_read($path);
    if (strlen($bytes)>5242880 || !str_starts_with($bytes,'PK')) {
        throw new RuntimeException('Configured DOCX reference is invalid or too large.');
    }
    $temp=tempnam(sys_get_temp_dir(),'fpoms_tpl_');
    if ($temp===false || file_put_contents($temp,$bytes)===false) {
        throw new RuntimeException('Could not read the configured official DOCX template.');
    }
    $zip=new ZipArchive();
    $opened=false;
    try {
        if ($zip->open($temp)!==true) {
            throw new RuntimeException('The official DOCX template is damaged.');
        }
        $opened=true;
        // Original DENR Region XIII Template No. 1 embedded assets.
        $sources=[
            'denr'=>'word/media/image1.png',
            'bagong'=>'word/media/image3.png',
            'socotec'=>'word/media/image2.jpg'
        ];
        $branding=[];
        foreach ($sources as $key=>$mediaPath) {
            $raw=$zip->getFromName($mediaPath);
            if (!is_string($raw) || strlen($raw)<50 || strlen($raw)>2097152) {
                throw new RuntimeException('Official DOCX template is missing required '.$key.' logo image.');
            }
            $source=@imagecreatefromstring($raw);
            if ($source===false) {
                throw new RuntimeException('Official DOCX '.$key.' logo cannot be decoded.');
            }
            try {
                $width=imagesx($source);
                $height=imagesy($source);
                if ($width<10 || $height<10 || $width>4000 || $height>4000) {
                    throw new RuntimeException('Official DOCX '.$key.' logo has invalid dimensions.');
                }
                $scale=min(1.0, 360/$width, 360/$height);
                $newWidth=max(1,(int)round($width*$scale));
                $newHeight=max(1,(int)round($height*$scale));
                $image=imagecreatetruecolor($newWidth,$newHeight);
                if ($image===false) {
                    throw new RuntimeException('Could not prepare the '.$key.' logo.');
                }
                try {
                    $white=imagecolorallocate($image,255,255,255);
                    imagefilledrectangle($image,0,0,$newWidth,$newHeight,$white);
                    imagealphablending($image,true);
                    imagecopyresampled($image,$source,0,0,0,0,
                        $newWidth,$newHeight,$width,$height);
                    ob_start();
                    imagejpeg($image,null,88);
                    $jpeg=ob_get_clean();
                    if (!is_string($jpeg) || !str_starts_with($jpeg,"\xFF\xD8")) {
                        throw new RuntimeException('Could not encode '.$key.' official logo.');
                    }
                    $branding[$key]=$jpeg;
                } finally {
                    imagedestroy($image);
                }
            } finally {
                imagedestroy($source);
            }
        }
        return $branding;
    } finally {
        if ($opened) $zip->close();
        @unlink($temp);
    }
}
