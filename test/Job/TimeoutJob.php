<?php

class TimeoutJob extends \MPQueue\Job
{
    /**
     * 任务超时时间（超时后会直接失败不会重试任务）
     * 0代表永不超时 null代表使用队列超时设置
     * @var int
     */
    protected $timeout = null;//10

    public function handle()
    {
        // TODO: Implement handle() method.
        var_dump('超时任务开始');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '超时任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
        sleep(30);
        var_dump('超时任务结束');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '超时任务结束' .$b. date('Y-m-d H:i:s'),FILE_APPEND);
    }

    public function timeout_handle(array $jobInfo)
    {
        var_dump($jobInfo);
        return false;//执行完后会执行队列超时handle
    }
}