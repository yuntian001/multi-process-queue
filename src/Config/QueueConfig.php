<?php

namespace MPQueue\Config;

use MPQueue\Library\Traits\Config;

/**
 * Class QueueConfig
 * @method static name() 获取当前队列的配置对象的名称
 * @method static worker_number() 获取当前队列的配置对象的工作进程数量
 * @method static memory_limit() 获取当前队列的配置对象的内存限制数（mb）
 * @method static sleep_seconds() 获取当前队列的配置对象的间隔等待时间
 * @method static timeout() 获取当前队列的任务超时时间 秒
 * @method static fail_expire() 获取当前队列的配置对象的间隔等待时间 秒
 * @method static fail_number() 获取当前队列的允许最大失败次数
 * @method static fail_handle() 获取失败后需要执行的fail_handle
 * @method static worker_start_handle() 当前队列的worker进程启动后执行函数
 * @method static model() 队列运行模式分发/抢占
 * @package MPQueue\Config
 */
class QueueConfig implements ConfigInterface
{
    use Config;

    const MODEl_DISTRIBUTE = 1;//分发模式
    const MODEL_GRAB = 2;//抢占模式

    protected static $queues = [];
    protected $name;
    protected $worker_number;
    protected $memory_limit;
    protected $sleep_seconds;
    protected $timeout;
    protected $fail_number;
    protected $fail_expire;
    protected $fail_handle;
    protected $worker_start_handle;
    protected $model;

    public function __construct($name, $worker_number, $memory_limit, $sleep_seconds,$timeout,$fail_number,$fail_expire,$fail_handle,$worker_start_handle,$model)
    {
        $this->name = $name;
        $this->worker_number = $worker_number;
        $this->memory_limit = $memory_limit;
        $this->sleep_seconds = $sleep_seconds;
        $this->timeout = $timeout;
        $this->fail_number = $fail_number;
        $this->fail_expire = $fail_expire;
        $this->fail_handle = $fail_handle;
        $this->worker_start_handle = $worker_start_handle;
        $this->model = $model;
    }

    public static function set($queues)
    {
        self::checkSet();
        foreach ($queues as $value) {
            self::$queues[$value['name']] = new self(
                $value['name'],
                $value['worker_number'],
                $value['memory_limit']*1024*1024,
                $value['sleep_seconds'],
                $value['timeout'],
                $value['fail_number'],
                $value['fail_expire'],
                $value['fail_handle'],
                $value['worker_start_handle'],
                $value['model']
            );
        }
    }

    /**
     * 获取队列配置对象数组
     * @return QueueConfig[]
     */
    public static function queues(){
        return self::$queues;
    }

    /**
     * 获取特定队列的配置信息
     * @param null $queue
     * @return self()
     */
    public static function queue($queue = null)
    {
        $queue = $queue?:ProcessConfig::queue();
        return self::$queues[$queue];
    }

    /**
     * @param $name
     * @param $arg
     * @return mixed
     */
    public static function __callStatic($name, $arg)
    {
        return self::$queues[ProcessConfig::queue()]->{$name}();
    }


}