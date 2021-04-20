# multi-process-queue

基于swoole的多进程队列系统，支持一键化协程、超时控制、失败重试、毫秒级延时
当前以实现redis
### 配置说明

| 配置项 | 类型 | 是否必填 | 默认值 | 说明 |
| --- | --- | --- | --- | --- |
| basics | array | 是 | 无 | 基础配置项 |
| basics.name | string | 是 | 无 | 当前队列服务名称，多个服务同时启动时需要分别设置名字 |
| basics.pid_path | string | 否 | /tmp | 主进程pid文件存放路径 |
| basics.driver | string | 是 | 无 | 队列驱动必须是MPQueue\Queue\Driver\DriverInterface的实现类 |
| worker_start_handle | callable | 否 | 空字符串 |worker进程启动后会调用(当前服务所有队列有效) |
| log | array | 是 | 无 | 日志配置 | 
| log.path | string | 否 | /tmp | 日志存放路径 | 
| log.level | int | 否 | Monolog\Logger::INFO |Monolog\Logger::DEBUG/Monolog\Logger::INFO |
| log.dirver | string/class | 否 | RotatingFileLogDriver | 日志驱动，必须是MPQueue\Log\Driver\LogDriverInterface的实现 |
| queue | 二维数组 | 是 | 无 | 队列配置 |
|queue[0].name | string | 是 | 无 |队列名称 | 
|queue[0].worker_number | int | 否 | 3 | 工作进程数量 |
|queue[0].memory_limit | int | 否 | 128 | 工作进程最大使用内存数(单位mb)(0无限制)|
|queue[0].sleep_seconds | floot | 否 | 1 | 监视进程休眠时间（秒，最小到0.001） |
|queue[0].timeout | int | 否 | 120 | 超时时间(s)以投递任务方为准 |
|queue[0].fail_number | int | 否 | 3 | 最大失败次数以投递任务方为准 |
|queue[0].fail_expire | int | 否 | 3 | 失败延时投递时间(s)以投递任务方为准 |
|queue[0].timeout_handle | callable | 否 | 空 | 任务超时触发函数 | |queue[0].fail_handle | callable | 否 | 空 | 任务失败触发函数 | |queue[0]
|queue[0].worker_start_handle | callable | 否 | 空 | worker进程启动加载函数（当前队列有效） |

### 配置示例
```
[
    'basics'=>[
        'name'=>'mp-queue-1',//多个服务器同时启动时需要分别设置名字
        'driver'=> new \MPQueue\Queue\Driver\Redis('127.0.0.1'),
    ],
    'queue' => [
        [
            'name' => 'test',//队列名称
            'timeout_handle'=>function(){
                var_dump('超时了');
             },//超时后触发函数
            'fail_handle'=>function(){
                var_dump('失败了');
            },//失败回调函数
        ],
        [
            'name' => 'test2',//队列名称
            'worker_number' => 4,//当前队列工作进程数量
            'memory_limit' => 0, //当前队列工作进程的最大使用内存，超出则重启。单位 MB
        ]
    ]
]
```

## 快速上手
 ### 1.安装

 ```
   //即将发布到composer，请稍后
 ```
 ### 2. 启动队列
 - 新建 main.php 内容如下
 ```
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
 ```
 - 执行命令 worker:start 启动队列
 ```
    php master.php worker:start
 ```
 - 后台运行
 ```
   php master.php worker:start -d
 ``` 
 - 支持的命令（list命令可查看）
 ```
  php master.php list
```
```
  queue:clean 清空队列内容 --queue test 清空指定队列:test
  queue:status 查看队列信息 --queue test 指定队列:test
  queue:failed
  worker:start 启动 携带参数-d 后台启动
  worker:stop 停止
  worker:restart 重启 -d 后台启动
  worker:reload 平滑重启
  worker:status 状态

 ```
 ### 3. 投递任务
  - 执行以下代码投递任务（可web调用，调用进程无需加载swoole扩展）
```
    require_once __DIR__ . '/vendor/autoload.php';//require composer 加载文件 如果已加载则无需重复require
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
     $job = function(){
        var_dump('hello wordQ!');
     }
     MPQueue\Queue\Queue::push('test', $job,10);
```
MPQueue\Queue\Queue::push 接收三个参数依次分别为：
-  $queue 队列名称
- $job   投递的任务
 
  $job 允许的类型为callable 或 \MPQueue\Job的子类 具体测试可参考test文件夹
  
  $job为匿名函数时队列执行进程 和 投递任务进程无需再同一项目下。

  $job为静态方法/\MPQueue\Job的实现类/函数时 队列执行进程需要含有对应静态方法/\MPQueue\Job的实现类/函数。
  
  强烈建议投递$job使用\MPQueue\Job子类，子类中可自定义 超时时间、允许失败次数、延时重试时间、超时句柄、失败句柄。具体参数说明请进入[src/Job.php](src/Job.php)进行查看

- $delay 延时投递时间(s) 默认为0（立即投递）

## 注意事项
- worker:reload会让worker进程执行完当前任务后进程重启，worker:restart会暴力重启manage、master、worker进程，可能会造成任务执行一半被断掉。 
- 因进程是在启动后直接加载到内存中的，更改代码后不会立即生效 需要执行worker:reload 或者 worker:start使其生效。
- worker:reload 只会重启worker进程，不会重新加载配置文件，更改配置文件后需要worker:restart后才有效。

## 在laravel中使用

## 在thinkphp中使用