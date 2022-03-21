<?php
define('MP_QUEUE_CLI', true);

use MPQueue\Config\Config;

require_once __DIR__ . '/vendor/autoload.php';
$config = [
    'basics' => [
        'name' => 'mp-queue-1',//多个服务器同时启动时需要分别设置名字
        'driver' => new \MPQueue\Queue\Driver\Redis('127.0.0.1'),
    ],
    'log'=>[
//      'level'=>\Monolog\Logger::DEBUG
    ],
    'queue' => [
        [
            'name' => 'test',//队列名称
            'fail_handle' => function ($info,$e) {
                var_dump(getmypid());
                var_dump($info);
                var_dump($e);
                var_dump('失败了');
            },//失败回调函数
        ],
        [
            'name' => 'test2',//队列名称
            'worker_number' => 4,//当前队列工作进程数量
            'memory_limit' => 0, //当前队列工作进程的最大使用内存，超出则重启。单位 MB
//            'model'=>\MPQueue\Config\QueueConfig::MODEL_GRAB
        ]
    ]
];
Config::set($config);
(new \MPQueue\Console\Application())->run();