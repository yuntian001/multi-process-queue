<?php

namespace MPQueue\Log\Driver;


use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use MPQueue\Config\LogConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\OutPut\OutPut;
use MPQueue\Serialize\JsonSerialize;

class RotatingFileLogDriver implements LogDriverInterface
{

    /**
     * Detailed debug information
     */
    public const DEBUG = Logger::DEBUG;

    /**
     * Interesting events
     *
     * Examples: User logs in, SQL logs.
     */
    public const INFO = Logger::INFO;

    /**
     * Uncommon events
     */
    public const NOTICE = Logger::NOTICE;

    /**
     * Exceptional occurrences that are not errors
     *
     * Examples: Use of deprecated APIs, poor use of an API,
     * undesirable things that are not necessarily wrong.
     */
    public const WARNING = Logger::WARNING;

    /**
     * Runtime errors
     */
    public const ERROR = Logger::ERROR;

    /**
     * Critical conditions
     *
     * Example: Application component unavailable, unexpected exception.
     */
    public const CRITICAL = Logger::CRITICAL;

    /**
     * Action must be taken immediately
     *
     * Example: Entire website down, database unavailable, etc.
     * This should trigger the SMS alerts and wake you up.
     */
    public const ALERT = Logger::ALERT;

    /**
     * Urgent alert.
     */
    public const EMERGENCY = Logger::EMERGENCY;


    protected static $logger;


    /**
     * 获取当前log实例
     * @return mixed
     */
    protected function getLogger(): Logger
    {
        if (!static::$logger) {
            !static::$logger = new Logger('log');
            static::$logger->useLoggingLoopDetection(false)->pushHandler(new RotatingFileHandler(LogConfig::path() . '/mpQueue.log', 30, LogConfig::level()));
        }
        return static::$logger;
    }

    /**
     * 在DEBUG级别添加一条日志记录。
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function debug($message, array $context = [])
    {
        return $this->addRecord(self::DEBUG, $message . "\n", $context);
    }

    /**
     * 添加INFO级别的日志记录.
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function info($message, array $context = [])
    {
        return $this->addRecord(self::INFO, $message . "\n", $context);
    }

    /**
     * 添加通知级别的日志记录l.
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function notice($message, array $context = [])
    {
        return $this->addRecord(self::NOTICE, $message . "\n", $context);
    }

    /**
     * 添加警告级别的日志记录.
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function warning($message, array $context = [])
    {
        return $this->addRecord(self::WARNING, $message . "\n", $context);
    }

    /**
     * 添加错误级别的日志记录
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function error($message, array $context = [])
    {
        return $this->addRecord(self::ERROR, $message . "\n", $context);
    }

    /**
     * 添加危急级别的日志记录.
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function critical($message, array $context = [])
    {
        return $this->addRecord(self::CRITICAL, $message . "\n", $context);
    }

    /**
     * 添加ALERT级别的日志记录.
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function alert($message, array $context = [])
    {
        return $this->addRecord(self::ALERT, $message . "\n", $context);
    }

    /**
     * 添加紧急级别的日志记录.
     *
     * @param string $message 日志信息
     * @param mixed[] $context 日志上下文数组
     */
    public function emergency($message, array $context = [])
    {
        return $this->addRecord(self::EMERGENCY, $message . "\n", $context);
    }

    /**
     * 记录日志（会重置通道缓存）
     * @param int $level 日志级别
     * @param string $message 日志信息
     * @param array $context 日志上下文数组
     */
    protected function addRecord(int $level, string $message, array $context = [])
    {
        $result = self::getLogger()->addRecord($level, $message, $context);
        self::getLogger()->reset();
        return $result;
    }

}
