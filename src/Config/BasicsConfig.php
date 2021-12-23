<?php


namespace MPQueue\Config;

use MPQueue\Queue\Driver\DriverInterface;
use MPQueue\Library\Traits\Config;

/**
 * @method static name() 获取当前程序标识
 * @method static pid_path() 获取管理进程pid存放路径
 * @method static \MPQueue\Queue\Driver\DriverInterface driver() 获取队列驱动
 * @method static worker_start_handle() worker进程启动后执行函数
 * Class BasicsConfig
 * @package MPQueue\Config
 */
class BasicsConfig implements ConfigInterface
{
    use Config;

    private static $name;
    private static $pid_path = '/temp';
    private static $driver;
    private static $worker_start_handle;

    public static function set($name, $pid_path,DriverInterface $driver,$worker_start_handle)
    {
        self::checkSet();
        self::$name = $name;
        if (MP_QUEUE_CLI && !is_dir($pid_path) && !is_writable($pid_path)) {
            throw new \Exception('pid_path 必须是一个正确的可读写路径');
        }
        self::$pid_path = rtrim($pid_path,'/');
        self::$driver = $driver;
        self::$worker_start_handle = $worker_start_handle;
    }

    /**
     * 获取管理进程pid存放文件地址
     * @return string
     */
    public static function pid_file(): string
    {
        return self::$pid_path . '/mpQueue-'.BasicsConfig::name().'.pid';
    }
}