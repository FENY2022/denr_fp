<?php
declare(strict_types=1);

/**
 * Display only. MySQL DATETIME values already represent the configured
 * Asia/Manila local clock; this must not rewrite stored timestamps.
 */
function fpoms_display_datetime(?string $raw): string
{
    $value=trim((string)$raw);
    if ($value==='') return '—';
    $zone=new DateTimeZone('Asia/Manila');
    foreach (['!Y-m-d H:i:s','!Y-m-d H:i','!Y-m-d\TH:i:s','!Y-m-d\TH:i'] as $format) {
        $date=DateTimeImmutable::createFromFormat($format,$value,$zone);
        if ($date!==false && $date->format(substr($format,1))===$value) {
            return $date->format('F j, Y h:i A');
        }
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);
    if ($date!==false && $date->format('Y-m-d')===$value) {
        return $date->format('F j, Y');
    }
    return $value;
}

/**
 * Format visible HTML text nodes only. Never rewrite input values, HTML
 * attributes, timestamps used in form submissions, SQL, or audit metadata.
 * Keep editable textarea, script, style, code and pre contents untouched.
 */
function fpoms_format_visible_dates(string $html): string
{
    $chunks=preg_split(
        '~(<(?:textarea|script|style|pre|code)\b[^>]*>.*?</(?:textarea|script|style|pre|code)\s*>)~is',
        $html,-1,PREG_SPLIT_DELIM_CAPTURE
    );
    if ($chunks===false) return $html;
    foreach ($chunks as $i=>$chunk) {
        if ($i%2===1) continue;
        $chunks[$i]=preg_replace_callback('~(?<=>)[^<>]+(?=<)~u',
            static function(array $node): string {
                $formatted=preg_replace_callback(
                    '~(?<![0-9-])\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?(?![0-9-])~',
                    static fn(array $m): string => fpoms_display_datetime($m[0]),
                    $node[0]
                ) ?? $node[0];
                // Existing and newly formatted visible dates wrap into a
                // date line and time line; attributes/input values are intact.
                return preg_replace_callback(
                    '~\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4})\s+(\d{2}:\d{2}\s+(?:AM|PM))\b~',
                    static fn(array $m): string =>
                        '<span class="fp-date-time-wrap"><span class="fp-date-part">'
                        . $m[1] . '</span><span class="fp-time-part">' . $m[2]
                        . '</span></span>',
                    $formatted
                ) ?? $formatted;
            },$chunk) ?? $chunk;
    }
    return implode('',$chunks);
}
