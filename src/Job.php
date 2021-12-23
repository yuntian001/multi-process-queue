<?php


namespace MPQueue;


use MPQueue\Config\ProcessConfig;
use MPQueue\Config\QueueConfig;

abstract class Job
{
    /**
     * 任务超时时间（单位秒，超时后会直接失败不会重试任务）
     * 0代表永不超时 null代表使用队列超时设置(投递时配置的队列超时时间而不是运行进程加载的配置时间)
     * @var int
     */
    protected $timeout = null;

    /**
     * 最大失败次数 0代表出错不重试 null代表使用队列最大失败次数
     * @var null
     */
    protected $fail_number = null;

    /**
     * 任务失败后延迟几秒重新投递 0代表出错立即投递 null代表使用队列延迟秒数
     * @var int
     */
    protected $fail_expire = null;

    /**
     * 返回队列任务超时秒数/0（0代表不超时）
     * @return int|null
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * 返回允许最大失败次数
     * @return int|null
     */
    public function getFailNumber()
    {
        return $this->fail_number;
    }

    /**
     * 返回失败后延迟重试时间
     * @return int|null
     */
    public function getFailExpire()
    {
        return $this->fail_expire;
    }


    /**
     * 任务执行方法
     * @return mixed
     */
    abstract public function handle();


    /**
     * 任务失败后被调用
     * @param array $jobInfo 任务详细信息数组 $jobInfo['type']标识当前任务类型 1正常任务 2超时的任务
     * @param \Throwable $e 错误异常对象
     * @return mixed (先于队列的handle调用 除非返回false 否则不再调用队列的fail_handle)
     */
    public function fail_handle(array &$jobInfo,\Throwable $e)
    {
        return false;
    }

}