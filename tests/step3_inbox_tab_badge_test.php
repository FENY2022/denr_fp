<?php
declare(strict_types=1);

require_once __DIR__.'/../app/permit_step3_views.php';

$cases=[
    [1,true,'text-bg-danger'],
    [1,false,'text-bg-danger'],
    [0,true,'text-bg-light'],
    [0,false,'text-bg-success'],
];

foreach ($cases as [$count,$active,$expected]) {
    $actual=step3_inbox_tab_badge_class($count,$active);
    if ($actual!==$expected) {
        throw new RuntimeException(
            "Unexpected RO inbox badge class for count {$count}: {$actual}"
        );
    }
}

echo "RO inbox tab badge color checks passed.\n";
