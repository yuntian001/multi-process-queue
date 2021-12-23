<?php

namespace MPQueue\Queue\Driver;


class PubRedis extends Redis{

    /**
     * 添加队列任务
     * @param string $job 任务对应信息序列化后的字符串
     * @param int $delay 延时投递时间
     * @param int $timeout 超时时间 0代表不超时
     * @param int $fail_number 失败重试次数
     * @param int $fail_expire 失败重新投递延时
     * @return bool
     */
    public function push(string $job, int $delay = 0, int $timeout = 0, int $fail_number = 0, $fail_expire = 3): bool
    {
        $script = <<<SCRIPT
local id = redis.call('incr', KEYS[1])
redis.call('hMset',KEYS[2]..id,'job',ARGV[1],'create_time',ARGV[2],'exec_number',0,'worker_id','','start_time',0,'timeout',ARGV[3],'fail_number',ARGV[4],'fail_expire',ARGV[5],'error_info','')
if (ARGV[6] > ARGV[2]) then
redis.call('zAdd',KEYS[3],ARGV[6],id)
else
redis.call('rPush',KEYS[4],id)
end
return id
SCRIPT;
        $time = microtime(true);
        return $this->eval($script, [
            $this->getKey(self::TASK_NUMBER),
            $this->getKey(self::INFO),
            $this->getKey(self::DELAYED),
            $this->getKey(self::WAITING),
            $job,
            $time,
            $timeout,
            $fail_number,
            $fail_expire,
            $delay+$time
        ], 4);
        return true;
    }

}