<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'app/messenger.php',
    'public/assets/messenger.js',
    'public/assets/messenger.css',
    'database/035_fpoms_messenger.sql',
];
foreach ($files as $file) {
    if (!is_file($root.'/'.$file)) {
        fwrite(STDERR,"Missing messenger file: ".$file.PHP_EOL);
        exit(1);
    }
}
$bootstrap = file_get_contents($root.'/app/bootstrap.php');
$views = file_get_contents($root.'/app/views.php');
$index = file_get_contents($root.'/public/index.php');
$pusher = file_get_contents($root.'/public/assets/fpoms-pusher.js');

$checks = [
    [$bootstrap,"require_once __DIR__ . '/messenger.php';",'bootstrap messenger include'],
    [$views,'messenger_widget_html($user)','global floating widget'],
    [$views,'assets/messenger.js?v=1','messenger JS asset'],
    [$index,"case 'messenger_send':",'messenger send route'],
    [$index,"case 'messenger_file':",'secure attachment route'],
    [$pusher,"channel.bind('chat-message'",'realtime chat message binding'],
    [$pusher,"channel.bind('chat-typing'",'realtime typing binding'],
];
foreach ($checks as [$haystack,$needle,$label]) {
    if (!str_contains($haystack,$needle)) {
        fwrite(STDERR,"Messenger regression failed: ".$label.PHP_EOL);
        exit(1);
    }
}
$messengerJs = file_get_contents($root.'/public/assets/messenger.js');
if (str_contains($messengerJs,'.style.')) {
    fwrite(STDERR,"Messenger regression failed: inline style assignment violates FP-OMS CSP.".PHP_EOL);
    exit(1);
}
echo "Messenger module static checks passed.".PHP_EOL;
