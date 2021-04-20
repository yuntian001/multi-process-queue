<?php

class Failed extends \MPQueue\Job{

    protected $fail_expire = 1;
    protected $fail_number = null;

    public function handle()
    {
        $b = rand(0,999);
        var_dump('异常任务执行');
        file_put_contents(__DIR__ . '/' . 'test.log',
            '异常任务开始' .$b.'-'. date('Y-m-d H:i:s').PHP_EOL,FILE_APPEND);
        throw new \Exception('直接抛出异常');
    }
}