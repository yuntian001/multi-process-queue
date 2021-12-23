<?php
namespace MPQueueTest\Job;


class NormalJob extends \MPQueue\Job{

    public $id;
    public function __construct($id)
    {
        $this->id = $id;
    }

    public function handle()
    {
        var_dump('正常任务开始执行');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '正常任务开始' .$this->id.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
        sleep(15);
        var_dump('正常任务结束');
        file_put_contents(__DIR__ . '/../' . 'test.log',
            '正常任务结束' .$this->id. date('Y-m-d H:i:s'),FILE_APPEND);
    }
}