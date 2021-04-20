<?php
class Normal extends \MPQueue\Job{

    public function handle()
    {
        $b =rand(1,999);
        var_dump('异常任务开始执行');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '正常任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
        sleep(20);
        var_dump('正常任务结束');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '正常任务结束' .$b. date('Y-m-d H:i:s'),FILE_APPEND);
    }
}