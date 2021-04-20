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

    public static function set($path, $level, LogDriverInterface $driver)
    {
        self::checkSet();
        self::$path = rtrim($path,'/').'/'.BasicsConfig::name();
        self::$level = $level;
        self::$driver = $driver;
    }

}