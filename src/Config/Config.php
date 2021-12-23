<?php


namespace MPQueue\Config;


use MPQueue\Exception\ConfigException;
use MPQueue\Log\Driver\RotatingFileLogDriver;

class Config
{
    /**
     * 配置数组 null代表必须填写其余类型为默认值
     * @var array[]
     */
    private static $configStructure = [
        'basics' => [//基础配置
            'class' => BasicsConfig::class,
            'value' => [
                'name' => 'queue-1',//当前队列服务名称，多个服务同时启动时需要分别设置名字
                'pid_path' => '/tmp',//进程id存储路径
                'driver' => null,//队列驱动
                'worker_start_handle' => '',//worker进程启动加载函数
            ],
        ],
        'log' => [//日志配置
            'class' => LogConfig::class,
            'value' => [
                'path' => '/tmp',
                'level' => \Monolog\Logger::INFO,
                'driver' => RotatingFileLogDriver::class
            ],
        ],
        'queue' => [//队列配置
            'class' => QueueConfig::class,
            'value' => [
                [
                    'name' => null,
                    'worker_number' => 3,//工作进程数量
                    'memory_limit' => 128,//工作进程最大使用内存数(单位mb)
                    'sleep_seconds' => 1,//监视进程休眠时间（秒，允许小数最小到0.001）
                    'timeout' => 120,//超时时间(s)以投递任务方为准
                    'fail_number' => 3,//最大失败次数以投递任务方为准
                    'fail_expire' => 3,//失败延时投递时间(s)投递任务方为准
                    'fail_handle' => '', //任务失败触发函数
                    'worker_start_handle' => '',//worker进程启动加载函数
                    'model'=> QueueConfig::MODEl_DISTRIBUTE,//MODEl_DISTRIBUTE分发模式 QUEUE_GRAB 抢占模式
                ]
            ],
        ],
    ];

    /**
     * 设置配置项
     * @param array $config
     * @return false|mixed
     * @throws ConfigException
     */
    public static function set(array $config)
    {
        defined('MP_QUEUE_CLI') || define('MP_QUEUE_CLI',false);
        foreach (self::$configStructure as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = [];
            }
            forward_static_call_array([$value['class'], 'set'], self::getArguments($key, $config[$key]));
        }
    }

    /**
     * 获取配置设置参数数组
     * @param $key
     * @param $configs
     * @return array
     * @throws ConfigException
     */
    protected static function getArguments($key, $configs): array
    {
        $structure = self::$configStructure[$key]['value'];
        $arguments = [];
        if (isset($structure[0]) && count($structure) == 1) {
            $argument = [];
            foreach ($configs as $value) {
                foreach ($structure[0] as $k => $v) {
                    if (isset($value[$k])) {
                        $argument[$k] = $value[$k];
                    } elseif (!is_null($v)) {
                        $argument[$k] = $v;
                    } else {
                        throw new ConfigException('缺少配置项' . $key . '.' . $k);
                    }
                }
                $arguments[0][] = $argument;
            }
        } else {
            foreach ($structure as $k => $v) {
                if (isset($configs[$k])) {
                    $arguments[] = $configs[$k];
                } elseif (!is_null($v)) {
                    $arguments[] = $v;
                } else {
                    throw new ConfigException('缺少配置项' . $key . '.' . $k);
                }
            }
        }
        return $arguments;
    }

}