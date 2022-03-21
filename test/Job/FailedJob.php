<?php
namespace MPQueueTest\Job;

class FailedJob extends \MPQueue\Job{

//    protected $fail_expire = null;//失败重试时间按配置
    protected $fail_expire = 3;

    //protected $fail_number = null; //最大失败次数按配置
    protected $fail_number = 4; //最大失败次数4次

    public function handle()
    {
        $b = rand(0,999);
        var_dump('异常任务执行');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '异常任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
        throw new \Exception('直接抛出异常');
    }

    public function fail_handle(array &$jobInfo, \Throwable $e)
    {
        var_dump('执行失败回调');
        var_dump($jobInfo);
        var_dump($e->getMessage());
        return true;//不会调用配置handle
//        return false;//会调用配置handle
    }
}