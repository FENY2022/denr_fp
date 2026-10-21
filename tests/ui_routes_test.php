<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/core.php';
$assertions = [
    ['login', 'login'],
    ['register', 'register'],
    ['my_registration', 'my-registration'],
    ['review_queue', 'review-queue'],
    ['download_reg', 'download-reg'],
    ['change_password', 'change-password'],
];
foreach ($assertions as [$input, $expected]) {
    if (url($input) !== $expected) throw new RuntimeException("Unexpected URL for {$input}");
}
if (url('review', ['id' => 9]) !== 'review?id=9') throw new RuntimeException('URL query lost');
if (str_contains(url('login'), '.php')) throw new RuntimeException('.php leaked into URL');
echo 'PASS: clean route URL tests.' . PHP_EOL;
