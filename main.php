<?php
define('MP_QUEUE_CLI', true);

use MPQueue\Config\Config;

require_once __DIR__ . '/vendor/autoload.php';
$config = [
    'basics' => [
        'name' => 'mp-queue-1',//多个服务器同时启动时需要分别设置名字
        'driver' => new \MPQueue\Queue\Driver\Redis('127.0.0.1'),
    ],
    'queue' => [
        [
            'name' => 'test',//队列名称
            'timeout_handle' => function () {
                var_dump('超时了');
            },//超时后触发函数
            'fail_handle' => function () {
                var_dump('失败了');
            },//失败回调函数
        ],
        [
            'name' => 'test2',//队列名称
            'worker_number' => 4,//当前队列工作进程数量
            'memory_limit' => 0, //当前队列工作进程的最大使用内存，超出则重启。单位 MB
        ]
    ]
];
Config::set($config);
(new \MPQueue\Console\Application())->run();