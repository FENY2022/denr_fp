<?php
declare(strict_types=1);

/**
 * Read the three official header/footer images embedded in Template No. 1.
 * The uploaded DOCX is not executed, converted by a shell, or served publicly.
 * Each image is converted in memory to a bounded white-backed JPEG for the PDF.
 * A bundled copy of the approved Template No. 1 artwork is the fallback so a
 * notice never loses its official letterhead merely because no DOCX is set.
 */
function fpoms_notice_builtin_branding(): array
{
    $directory=__DIR__.'/assets/deficiency-notice';
    $branding=[];
    foreach (['denr','bagong','socotec'] as $key) {
        $path=$directory.'/'.$key.'.jpg';
        $bytes=is_file($path) ? file_get_contents($path) : false;
        $info=is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        if (!is_string($bytes) || strlen($bytes)<50 || !$info
            || (int)($info[2]??0)!==IMAGETYPE_JPEG) {
            error_log('FP-OMS notice branding: bundled '.$key.' artwork is unavailable.');
            continue;
        }
        $branding[$key]=$bytes;
    }
    return $branding;
}

function fpoms_notice_template_branding(array $config): array
{
    $path=(string)($config['template_path']??'');
    $branding=fpoms_notice_builtin_branding();
    // If an official DOCX is configured, its original artwork is preferred.
    // Missing optional artwork must never block draft generation.
    if ($path==='') return $branding;
    if (!class_exists(ZipArchive::class)) {
        error_log('FP-OMS notice branding: PHP ZIP unavailable; using built-in text letterhead.');
        return $branding;
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        error_log('FP-OMS notice branding: PHP GD unavailable; using built-in text letterhead.');
        return $branding;
    }

    try {
        $bytes=fpoms_notice_safe_read($path);
    } catch (Throwable $e) {
        error_log('FP-OMS notice branding: configured DOCX unavailable; using built-in letterhead. '.$e->getMessage());
        return $branding;
    }
    if (strlen($bytes)>5242880 || !str_starts_with($bytes,'PK')) {
        error_log('FP-OMS notice branding: configured DOCX invalid; using built-in letterhead.');
        return $branding;
    }
    $temp=tempnam(sys_get_temp_dir(),'fpoms_tpl_');
    if ($temp===false || file_put_contents($temp,$bytes)===false) {
        throw new RuntimeException('Could not read the configured official DOCX template.');
    }
    $zip=new ZipArchive();
    $opened=false;
    try {
        if ($zip->open($temp)!==true) {
            error_log('FP-OMS notice branding: official DOCX damaged; using built-in letterhead.');
            return $branding;
        }
        $opened=true;
        // Original DENR Region XIII Template No. 1 embedded assets.
        $sources=[
            'denr'=>'word/media/image1.png',
            'bagong'=>'word/media/image3.png',
            'socotec'=>'word/media/image2.jpg'
        ];
        foreach ($sources as $key=>$mediaPath) {
            $raw=$zip->getFromName($mediaPath);
            if (!is_string($raw) || strlen($raw)<50 || strlen($raw)>2097152) {
                error_log('FP-OMS notice branding: optional '.$key.' artwork missing from DOCX.');
                continue;
            }
            $source=@imagecreatefromstring($raw);
            if ($source===false) {
                error_log('FP-OMS notice branding: optional '.$key.' artwork cannot be decoded.');
                continue;
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
