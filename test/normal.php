<?php
//正常执行任务测试
use MPQueue\Config\Config;
use MPQueue\Queue\Queue;

require_once __DIR__ . '/../vendor/autoload.php';
$b = rand(0,99999);
$a = function ()use($b) {
    file_put_contents(__DIR__ . '/' . 'test.log',
        '正常任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
    echo '正常任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL;
    sleep(20);
    file_put_contents(__DIR__ . '/' . 'test.log',
        '正常任务结束' .$b. date('Y-m-d H:i:s'),FILE_APPEND);
    echo '正常任务结束' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL;
};

Config::set(include(__DIR__ . '/Config.php'));
Queue::push('test', $a,10);