<?php
namespace MPQueue\Log\Driver;


interface LogDriverInterface
{

    /**
     * 在DEBUG级别添加一条日志记录。
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function debug($message, array $context = []);

    /**
     * 添加INFO级别的日志记录.
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function info($message, array $context = []);

    /**
     * 添加通知级别的日志记录l.
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function notice($message, array $context = []);

    /**
     * 添加警告级别的日志记录.
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function warning($message, array $context = []);

    /**
     * 添加错误级别的日志记录
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function error($message, array $context = []);

    /**
     * 添加危急级别的日志记录.
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function critical($message, array $context = []);

    /**
     * 添加ALERT级别的日志记录.
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function alert($message, array $context = []);


    /**
     * 添加紧急级别的日志记录.
     *
     * @param string  $message The log message
     * @param mixed[] $context The log context
     */
    public function emergency($message, array $context = []);


}