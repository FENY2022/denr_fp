<?php
declare(strict_types=1);
require_once __DIR__.'/../app/permit_flow.php';

function compact_schedule_assert(string $actual,string $expected,string $case): void
{
    if ($actual!==$expected) {
        throw new RuntimeException($case."\nExpected: ".$expected."\nActual: ".$actual);
    }
}
$hours=['start'=>'08:00','end'=>'17:00'];
$fourDays=[
    '2026-11-25'=>$hours,'2026-11-26'=>$hours,
    '2026-11-27'=>$hours,'2026-11-28'=>$hours
];
compact_schedule_assert(
    permit_flow_daily_schedule_summary($fourDays),
    'November 25–28, 2026, from 8:00 AM to 5:00 PM daily',
    'four consecutive equal-hour days condense into one date range'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary(['2026-11-25'=>$hours]),
    'November 25, 2026, from 8:00 AM to 5:00 PM',
    'one day is not labeled daily'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary([
        '2026-11-25'=>$hours,'2026-11-26'=>$hours,
        '2026-11-27'=>['start'=>'09:00','end'=>'15:00'],
        '2026-11-28'=>$hours
    ]),
    'November 25–26, 2026, from 8:00 AM to 5:00 PM daily; '
    .'November 27, 2026, from 9:00 AM to 3:00 PM; '
    .'November 28, 2026, from 8:00 AM to 5:00 PM',
    'different hours are preserved and not presented as a full date range'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary([
        '2026-11-25'=>$hours,'2026-11-27'=>$hours
    ]),
    'November 25, 2026, from 8:00 AM to 5:00 PM; '
    .'November 27, 2026, from 8:00 AM to 5:00 PM',
    'nonconsecutive dates remain separate'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary([
        '2026-11-30'=>$hours,'2026-12-01'=>$hours,'2026-12-02'=>$hours
    ]),
    'November 30–December 2, 2026, from 8:00 AM to 5:00 PM daily',
    'range crossing month boundary'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary([
        '2026-12-31'=>$hours,'2027-01-01'=>$hours
    ]),
    'December 31, 2026–January 1, 2027, from 8:00 AM to 5:00 PM daily',
    'range crossing year boundary'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary([
        '2026-11-27'=>$hours,'2026-11-25'=>$hours,'2026-11-26'=>$hours
    ]),
    'November 25–27, 2026, from 8:00 AM to 5:00 PM daily',
    'nonordered stored entries are sorted chronologically'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary(['2026-11-25'=>['start'=>'17:00','end'=>'08:00']]),
    '',
    'invalid times fail closed'
);
compact_schedule_assert(
    permit_flow_daily_schedule_summary(['2026-11-25'=>$hours,'invalid'=>$hours]),
    '',
    'invalid dates fail closed'
);
$flow=(string)file_get_contents(__DIR__.'/../app/permit_flow.php');
$views=(string)file_get_contents(__DIR__.'/../app/permit_flow_views.php');
$memo=(string)file_get_contents(__DIR__.'/../app/cenro_memo.php');
$pdf=(string)file_get_contents(__DIR__.'/../app/cenro_memo_pdf.php');
if (!str_contains($flow,'$display=permit_flow_daily_schedule_summary($daily)')
    || !str_contains($views,'$dailySummary=is_array($daily)?permit_flow_daily_schedule_summary($daily)')
    || !str_contains($memo,'permit_flow_daily_schedule_summary($daily)')
    || !str_contains($pdf,'The field validation is scheduled for ')) {
    throw new RuntimeException('Compact field schedule is not consistently used in notifications, views and memorandum.');
}
echo "PASS: compact field validation schedule formatting and render integrations.\n";
