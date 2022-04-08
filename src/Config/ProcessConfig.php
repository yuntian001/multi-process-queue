<?php

namespace MPQueue\Config;

use MPQueue\Library\Traits\Config;

/**
 * Class ProcessConfig
 * @method static string unixSocketPath() 获取unixSocket目录
 * @method static queue() 获取当前进程处理队列名称
 * @method static daemon() 获取当前进程是否守护模式运行
 * @package MPQueue\Config
 */
class ProcessConfig implements ConfigInterface
{
    use Config;

    //进程状态码
    const STATUS_ERROR = -1;//异常
    const STATUS_IDLE = 0;//空闲
    const STATUS_BUSY = 1;//繁忙

    //进程信号
    const SIG_STOP = SIGTERM;//停止
    const SIG_STATUS = SIGUSR1;//获取进程状态
    const SIG_RELOAD = SIGUSR2;//平滑重启

    //进程结束错误状态码
    const CODE_TIMEOUT = 1;//超时
    const CODE_MEMORY_OVERFLOW = 2;//内存溢出

    private static $type='manage';
    private static $unixSocketPath = '/tmp';
    private static $queue = null;
    private static $daemon = false;

    public static function set($unixSocketPath){
        self::checkSet();
        self::$unixSocketPath = rtrim($unixSocketPath,'/').'/'.'mpQueue_'.BasicsConfig::name().'_master';
        !file_exists(self::$unixSocketPath) && mkdir(self::$unixSocketPath,0744,true);
    }

    /**
     * 设置当前进程类型为manage
     */
    public static function setManage(){
        self::$type = 'manage';
    }

    /**
     * 设置当前进程类型为master
     */
    public static function setMaster(){
        self::$type = 'master';
    }

    /**
     * 设置当前进程类型为worker
     */
    public static function setWorker(){
        self::$type = 'worker';
    }

    /**
     * 获取进程类型
     * @return string
     */
    public static function getType(){
        return self::$type;
    }

    /**
     * 设置当前进程所属队列
     * @param $queue
     * @throws \MPQueue\Exception\ConfigException
     */
    public static function setQueue($queue){
        self::checkSet('queue');
        self::$queue = $queue;
    }

    /**
     * 设置进程运行状态为后台模式
     */
    public static function setDaemon(){
        self::$daemon = true;
    }

    public static function getStatusLang($status,$lan = 'zh'){
        switch ($status){
            case self::STATUS_ERROR:
                return $lan=='zh'?'异常':'error';
            case self::STATUS_IDLE:
                return $lan=='zh'?'空闲':'idle';
            case self::STATUS_BUSY:
                return $lan=='zh'?'繁忙':'busy';
        }
        return $lan=='zh'?'错误状态':'error status';
    }

}
