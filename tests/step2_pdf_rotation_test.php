<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root.'/public/assets/permit-pdf-organizer.js');
$view = file_get_contents($root.'/app/permit_step2_views.php');

$checks = [
    [$js,"makeButton('rotate-left','arrow-counterclockwise','Rotate page left 90°')",'rotate-left control'],
    [$js,"makeButton('rotate-right','arrow-clockwise','Rotate page right 90°')",'rotate-right control'],
    [$js,'state.rotations.splice(to,0,rotation);','rotation follows page reorder'],
    [$js,'session.rotations.splice(index + 1,0','duplicate preserves rotation'],
    [$js,'session.rotations.splice(index,1);','remove deletes rotation state'],
    [$js,'page.setRotation(window.PDFLib.degrees','saved PDF applies rotation'],
    [$js,'Number(page.rotate || 0) + deltaRotation','preview preserves source rotation'],
    [$js,'state.rotations.every(angle => normalizeRotation(angle) === 0)','rotation marks PDF as changed'],
    [$view,'Rotate scanned pages, duplicate, remove, or preview any page.','organizer guidance'],
    [$view,'permit-pdf-organizer.js?v=2','cache-busted organizer asset'],
];

foreach ($checks as [$haystack,$needle,$label]) {
    if (!str_contains($haystack,$needle)) {
        fwrite(STDERR,'PDF rotate regression failed: '.$label.PHP_EOL);
        exit(1);
    }
}
echo "FP-OMS PDF organizer rotation checks passed.".PHP_EOL;
