<?php


namespace MPQueue\Log;

use MPQueue\Config\LogConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\Log\Driver\LogDriverInterface;
use MPQueue\OutPut\OutPut;
use MPQueue\Serialize\JsonSerialize;

/**
 * 日志类
 * @method static debug(string $message, array $context = []);调试信息
 * @method static info(string $message, array $context = []);信息
 * @method static notice(string $message, array $context = []);通知
 * @method static warning(string $message, array $context = []);警告
 * @method static error(string $message, array $context = []);一般错误
 * @method static critical(string $message, array $context = []);危险错误
 * @method static alert(string $message, array $context = []);警戒错误
 * @method static emergency(string $message, array $context = []);紧急错误
 *
 * @see LogDriverInterface
 * @package MPQueue\Log
 */
class Log
{
    private static $driver;

    private static $levelOut=[
        'debug'=>'info',
        'info'=>'normal',
        'notice'=>'warning',
        'warning'=>'warning',
        'error'=>'error',
        'critical'=>'error',
        'alert'=>'error',
        'emergency'=>'error'
    ];

    /**
     * 获取日志驱动
     * @return LogDriverInterface
     */
    public static function getDriver(): LogDriverInterface
    {
        if(!self::$driver){
            self::$driver = LogConfig::driver();
        }
        return self::$driver;
    }

    public static function __callStatic($name, $arguments)
    {
        $arguments[0] = '['.ProcessConfig::queue().':'.ProcessConfig::getType().':'.getmypid().']'.":'".$arguments[0];
        if(call_user_func_array([self::getDriver(),$name],$arguments) && !ProcessConfig::daemon()){
            //记录成功并且非后台运行则打印对应信息到屏幕
            OutPut::{self::$levelOut[$name]}(
                (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v')
                ."【{$name}】".$arguments[0]
                .(!empty($arguments[1])?JsonSerialize::serialize($arguments[1]):'')
                ."'\n");
        }
    }

}