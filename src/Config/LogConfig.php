<?php

namespace MPQueue\Config;

use MPQueue\Library\Traits\Config;
use MPQueue\Log\Driver\LogDriverInterface;

/**
 * Class LogConfig
 * @method static path() 获取文件地址
 * @method static level() 获取级别
 * @method static LogDriverInterface driver() 获取日志驱动
 * @package MPQueue\Config
 */
class LogConfig implements ConfigInterface
{
    use Config;

    private static $path;
    private static $level;
    private static $driver;

    public static function set($path, $level, $driver)
    {
        self::checkSet();
        if (MP_QUEUE_CLI && !is_dir($path) && !is_writable($path)) {
            throw new \Exception("log path: $path 必须是一个正确的可读写路径");
        }
        if(is_string($driver) && class_exists($driver)){
            $driver = new $driver;
        }
        if(!$driver instanceof LogDriverInterface){
            throw new \Exception('log dirver 必须是 MPQueue\Log\Driver\LogDriverInterface 的实现');
        }
        self::$path = rtrim($path,'/').'/'.BasicsConfig::name();
        self::$level = $level;
        self::$driver = $driver;
    }

}