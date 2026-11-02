<?php
declare(strict_types=1);
require_once __DIR__.'/../app/datetime_display.php';
require_once __DIR__.'/../app/records_archive.php';
function records_assert(bool $pass,string $message): void {
    if (!$pass) throw new RuntimeException('Records test: '.$message);
}
records_assert(records_archive_eligible('forwarded_to_lpdd',true,false),'completed handoff eligible');
records_assert(!records_archive_eligible('ro_received',false,false),'pending handoff blocked');
records_assert(!records_archive_eligible('forwarded_to_lpdd',true,true),'duplicate archive blocked');
records_assert(!records_archive_eligible('submitted',true,false),'Step 2 application blocked');
records_assert(fpoms_display_datetime('2026-11-01 21:24:06')==='November 1, 2026 09:24 PM','evening');
records_assert(fpoms_display_datetime('2026-11-02 09:10:23')==='November 2, 2026 09:10 AM','morning');
$sample='<p>2026-11-02 09:10:23</p><input value="2026-11-02 09:10:23">'
    .'<textarea>2026-11-02 09:10:23</textarea>';
$output=fpoms_format_visible_dates($sample);
records_assert(str_contains($output,'<p>November 2, 2026 09:10 AM</p>'),'visible date');
records_assert(str_contains($output,'value="2026-11-02 09:10:23"'),'attribute unchanged');
records_assert(str_contains($output,'<textarea>2026-11-02 09:10:23</textarea>'),'editable field unchanged');
$router=(string)file_get_contents(__DIR__.'/../public/index.php');
$nav=(string)file_get_contents(__DIR__.'/../app/views.php');
$tracker=(string)file_get_contents(__DIR__.'/../app/permit_tracking.php');
records_assert(str_contains($router,"case 'records_archive_action':"),'archive POST route');
records_assert(str_contains($nav,"shell_nav_item('records_archive'"),'records sidebar');
records_assert(str_contains($tracker,'permit_records_archives'),'archived actions filtered');
echo "Records archiving / date display tests passed.\n";
