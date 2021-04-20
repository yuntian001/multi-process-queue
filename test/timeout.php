<?php
//超时任务测试
use MPQueue\Config\Config;
use MPQueue\Queue\Queue;
require_once __DIR__ . '/../vendor/autoload.php';
$b = rand(0,99999);
$a = function ()use($b) {
    var_dump('超时任务开始');
    file_put_contents(__DIR__ . '/' . 'test.log',
        '超时任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
    sleep(30);
    var_dump('超时任务结束');
    file_put_contents(__DIR__ . '/' . 'test.log',
        '超时任务结束' .$b. date('Y-m-d H:i:s'),FILE_APPEND);};
Config::set(include(__DIR__ . '/Config.php'));
Queue::push('test', $a);