<?php
return [
    'basics'=>[
        'name'=>'mp-queue-1',//多个服务器同时启动时需要分别设置名字
        'pid_path' => null,//主进程pid存放路径(需要可写)
        'driver'=> new \MPQueue\Queue\Driver\Redis('127.0.0.1'),
    ],
    'queue' => [
        [
            'name' => 'test',//队列名称
            'worker_number' => 1,//当前队列工作进程数量
            'memory_limit' => 1024, //当前队列工作进程的最大使用内存，超出则重启。单位 MB
            'sleep_seconds' => 1,//监视进程休眠时间（秒，允许小数最小到0.001）
            'timeout'=>25,//超时时间（投递配置）
            'timeout_handle'=>function(){
                var_dump('超时了');
             },//超时后触发函数
            'fail_handle'=>function(){
                var_dump('失败了');
            },//失败回调函数
            'fail_number'=>3,//允许最大失败次数（投递配置）
            'fail_expire'=>3,//失败重试延迟时间（秒 投递配置）
        ],
        [
            'name' => 'test2',//队列名称
            'worker_number' => 3,//当前队列工作进程数量
            'memory_limit' => 1024, //当前队列工作进程的最大使用内存，超出则重启。单位 MB
            'sleep_seconds' => 1,//监视进程休眠时间（秒，允许小数最小到0.001）
            'timeout'=>60,//超时时间
            'timeout_handle'=>function(){
                var_dump('超时重试');
            },//超时后触发函数
            'fail_handle'=>function(){
                var_dump('失败了');
            },//失败回调函数
            'fail_number'=>3,//失败重试次数
            'fail_expire'=>3,//失败重试延迟时间（秒）
        ]
    ],
];