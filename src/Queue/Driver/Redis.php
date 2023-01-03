<?php


namespace MPQueue\Queue\Driver;


use MPQueue\Config\BasicsConfig;
use MPQueue\Log\Log;
use MPQueue\Queue\Queue;
use Swoole\Coroutine;

/**
 * redis队列驱动 redis 版本必须>=3.0.2
 * Class Redis
 * @package MPQueue\Queue\Driver
 */
class Redis implements DriverInterface
{
    protected $connect = null;

    protected $config = [];

    protected $scriptHash = array();

    protected $scriptError = [];

    protected $queue = null;

    private $pingNumber = 0;

    const TASK_NUMBER = 'task_number'; //队列任务数量key名 string类型

    const TASK_OVER = 'task_over';     //已完成任务

    const INFO = 'info:'; //队列详情key名（hash类型）

    const DELAYED = 'delayed';//延时队列key名（zset类型）存储延迟执行的任务id 和对应时间戳

    const WAITING = 'waiting';//待执行队列key名（list类型）存储待执行任务的id

    const RESERVE = 'reserve';//已分配保留队列key名（zset类型）存储已分配worker任务id和worker超时接收时间戳

    const WORKING = 'working';//执行中队列名（zset类型）存储worker已执行任务id和worker执行超时时间

    const RETRY = 'retry';//重试队列（zset类型）存储失败需重试任务id和重新投递时间戳

    const FAILED = 'failed';//失败任务队列（hash类型存储失败任务的序列化信息）

    /**
     * Redis constructor.
     * @param $host redis地址
     * @param int $port 端口
     * @param string $password 密码
     * @param string $database 数据库 默认为0
     * @param string $prefix  前缀
     */
    public function __construct($host, $port = 6379, $password = '', $database = "0", $prefix = 'mpq')
    {
        $this->config = [
            'host' => $host,
            'port' => $port,
            'password' => $password,
            'database' => $database,
            'prefix' => $prefix,
        ];
    }

    /**
     * 设置当前操作队列
     * @param $queue
     * @return Redis
     */
    public function setQueue( String $queue): DriverInterface
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * 获取redis连接
     * @return \Redis
     */
    public function getConnect($isPing = false)
    {
        //在协程环境中，则一个协程一个连接防止共用连接数据错乱
        if (class_exists('Swoole\Coroutine') && Coroutine::getCid() > 0) {
            if(isset(Coroutine::getContext()[\Redis::class])){
                if($isPing){
                    while (!$this->ping(Coroutine::getContext()[\Redis::class],10)){
                        Coroutine::getContext()[\Redis::class] = $this->connection(true);
                    }
                }
                return Coroutine::getContext()[\Redis::class];
            }else{
                 return Coroutine::getContext()[\Redis::class] = $this->connection();
            }
        }
        if (!$this->connect) {
            $this->connect = $this->connection();
        }else{
            if($isPing) {
                while (!$this->ping($this->connect,10)){
                    $this->connect = $this->connection(true);
                }
            }
        }
        return $this->connect;
    }

    /**
     * 连接到redis
     * @return \Redis
     */
    private function connection(bool $isReconnect = false)
    {
        $connect = new \Redis();
        try{
            $connect->connect($this->config['host'], $this->config['port']);
            $this->config['password'] && $connect->auth($this->config['password']);
            $this->config['database'] && $connect->select($this->config['database']);
        }catch (\RedisException $e){
            Log::warning("redis连接出错2s后重试");
            sleep(2);
            return $this->connection(true);
        }
        $isReconnect  && Log::info("redis重连成功");
        return $connect;
    }

    /**
     * @param $redis
     * @return bool
     */
    private function ping($redis,$max=0){
        $this->pingNumber++;
        try{
            if($redis->ping()){
                $this->pingNumber = 0;
                return true;
            }
        }catch (\RedisException $e){
        }
        if($max && $this->pingNumber > $max){
            Log::warning("redis ping失败 $max 次，2s后重试");
            sleep(2);
        }
        return false;
    }

    /**
     * 关闭当前连接实例
     * @return mixed|void
     */
    public function close()
    {
        $this->getConnect()->close();
        if (class_exists('Swoole\Coroutine') && Coroutine::getCid() > 0) {
            unset(Coroutine::getContext()[\Redis::class]);
        } else{
            $this->connect = null;
        }
    }

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
redis.call('hMset',KEYS[2]..id,'job',ARGV[1],'create_time',ARGV[2],'exec_number',0,'worker_id','','start_time',0,'timeout',ARGV[3],'fail_number',ARGV[4],'fail_expire',ARGV[5],'error_info','','type',1)
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


    /**
     * 从等待队列中弹出一个可执行任务
     * @return array
     * @throws \RedisException
     */
    public function popJob($number)
    {
        $script = <<<SCRIPT
-- 从等待队列 WAITING头部 弹出一个任务
local ids = redis.call('lRange', KEYS[1],0,ARGV[3])
if (#ids > 0) then
    redis.call('lTrim', KEYS[1],ARGV[3]+1,-1)
    for i=1,#ids,1 do
        -- 添加到保留集合RESERVE中并设置超时重新分发时间戳
        redis.call('zAdd', KEYS[2], ARGV[1], ids[i])
        -- 任务详情INFO设置任务状态为被取出并设置取出时间
        redis.call('hSet',KEYS[3]..ids[i],'pop_time',ARGV[2])
    end
end  
return ids
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::WAITING),
            $this->getKey(self::RESERVE),
            $this->getKey(self::INFO),
            microtime(true) + Queue::WORKER_POP_TIMEOUT,
            microtime(true),
            $number-1,
        ], 3)?:[];
    }

    /**
     * 移动过期任务到等待队列
     * @param int $number 移除的数量 数量越大redis阻塞时间越长
     * @return mixed
     * @throws \RedisException
     */
    public function moveExpired($number = 50)
    {
        $script = <<<SCRIPT
-- 从延时集合DELAYED中弹出number个任务
local ids = redis.call('zRangeByScore', KEYS[1], '-inf' , ARGV[1], 'LIMIT' , 0 ,ARGV[2])
if (#ids ~= 0) then
   -- 将任务id提交到推入list WAITING尾部
   redis.call('rPush',KEYS[2], unpack(ids))
   -- 从延时集合DELAYED中移除对应id
   redis.call('zRem',KEYS[1], unpack(ids))
end  
return ids
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::DELAYED),
            $this->getKey(self::WAITING),
            microtime(true),
            $number
        ], 2);
    }


    /**
     * 移动超时分配任务到等待队列
     * @param int $number
     */
    public function moveExpiredReserve($number = 50)
    {
        $script = <<<SCRIPT
-- 从分配延时集合RESERVE中弹出number个任务
local ids = redis.call('zRangeByScore', KEYS[1], 1 , ARGV[1], 'LIMIT' , 0 ,ARGV[2])
if (#ids ~= 0) then
    -- 将任务id提交到推入list WAITING头部让其优先弹出
    redis.call('lPush',KEYS[2],unpack(ids))
    -- 从延时集合DELAYED中移除对应id
    redis.call('zRem',KEYS[1],unpack(ids))
end  
return ids
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::RESERVE),
            $this->getKey(self::WAITING),
            microtime(true),
            $number
        ], 2);
    }

    /**
     * 移动到时间的重试任务到等待队列
     * @param int $number
     * @return mixed
     * @throws \RedisException
     */
    public function moveExpiredRetry($number = 50)
    {
        $script = <<<SCRIPT
-- 从重新分发集合RETRY中弹出number个任务
local ids = redis.call('zRangeByScore', KEYS[1], 1 , ARGV[1], 'LIMIT' , 0 ,ARGV[2])
if (#ids ~= 0) then
    -- 将任务id提交到推入list WAITING尾部
    redis.call('rPush',KEYS[2], unpack(ids))
    -- 从延时集合DELAYED中移除对应id
    redis.call('zRem',KEYS[1], unpack(ids))
end  
return ids
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::RETRY),
            $this->getKey(self::WAITING),
            microtime(true),
            $number
        ], 2);
    }

    /**
     * 移动执行超时任务到等待队列
     */
    public function moveTimeoutJob(int $number = 50){
        $script = <<<SCRIPT
--- 从执行中集合WORKING中弹出number个任务
local ids = redis.call('zRangeByScore', KEYS[1], 1 , ARGV[1], 'LIMIT' , 0 ,ARGV[2])
if (#ids ~= 0) then
    -- 将任务id推入list WAITING尾部
    redis.call('rPush',KEYS[2], unpack(ids))
    -- 从执行集合WORKING中移除对应id
    redis.call('zRem',KEYS[1], unpack(ids))
    -- 设置info
    for i=1,#ids,1 do
        -- 标记类型为超时
        redis.call('hSet',KEYS[3]..ids[i], 'type', 2)
    end
end  
return ids
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::WORKING),
            $this->getKey(self::WAITING),
            $this->getKey(self::INFO),
            microtime(true),
            $number
        ], 3)?:[];
    }

    /**
     * 开始执行任务，在等待队列移除任务并设置执行信息
     * @param $id
     * @return mixed
     * @throws \RedisException
     */
    public function reReserve($id)
    {
            $script = <<<SCRIPT
--从延时集合RESERVE中移除任务
local number = redis.call('zRem', KEYS[1], ARGV[1])
if (number > 0) then
    -- 获取当前任务的详情
    local info_array = redis.call('HGetAll',KEYS[3]..ARGV[1])
    number = #info_array
    local info = {}
    for i=1,number,2 do
	    info[info_array[i]] = info_array[i+1]
    end
    -- 将任务添加到执行集合 WORKING重试时间戳
    if (tonumber(info['timeout']) > 0) then
        redis.call('zAdd',KEYS[2],ARGV[3]+info['timeout'],ARGV[1])
    else
        redis.call('zAdd',KEYS[2],0,ARGV[1])
    end
    -- 设置当前执行的程序、开始执行时间及状态为执行中
    info['exec_number'] = info['exec_number']+1
    redis.call('hMSet',KEYS[3]..ARGV[1], 'worker_id', ARGV[2], 'start_time', ARGV[3],'exec_number',info['exec_number'])
    return cjson.encode(info)
end  
return false
SCRIPT;
        $info = $this->eval($script, [
            $this->getKey(self::RESERVE),
            $this->getKey(self::WORKING),
            $this->getKey(self::INFO),
            $id,
            BasicsConfig::name() . ':' . getmypid(),
            microtime(true),
        ], 3);
        $info && $info = json_decode($info, true);
        return $info;
    }

    /**
     * 删除执行成功的任务
     * @param $id
     * @return mixed
     * @throws \RedisException
     */
    public function remove($id)
    {
        $script = <<<SCRIPT
--从执行集合 WORKING中移除任务
local number = redis.call('zRem', KEYS[1], ARGV[1])
if (number > 0) then
--删除任务详情
redis.call('del', KEYS[2]..ARGV[1])
--完成数量加1
redis.call('incr',KEYS[3])
end
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::WORKING),
            $this->getKey(self::INFO),
            $this->getKey(self::TASK_OVER),
            $id,
        ], 3);
    }

    /**
     * 在详情中追加错误信息
     * @param int $id
     * @param string $error
     * @return mixed|string
     * @throws \RedisException
     */
    public function setErrorInfo(int $id, string $error)
    {
        $script = <<<SCRIPT
--从详情hash中获取错误信息
local info = redis.call('hGet', KEYS[1], 'error_info')
--追加错误信息到详情hash
if (info ~= nil) then
redis.call('hSet', KEYS[1], 'error_info', info..ARGV[1])
return true
end
return false
SCRIPT;
        $res = $this->eval($script, [
            $this->getKey(self::INFO) . $id,
            $error,
        ], 1);
        return $res;
    }

    /**
     * 重新发布一遍执行失败的任务
     * @param int $id
     * @param int $delay 重试延时时间（s 支持小数精度到0.001）
     * @return mixed|string
     * @throws \RedisException
     */
    public function retry(int $id, int $delay = 0)
    {
        $script = <<<SCRIPT
--从执行中WORKING集合中删除
local number = redis.call('zRem', KEYS[1], ARGV[1])
--添加到重试延时执行RETRY集合
if (number > 0) then
redis.call('zAdd', KEYS[2], ARGV[2], ARGV[1])
return true
end
return false
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::WORKING),
            $this->getKey(self::RETRY),
            $id,
            microtime(true)+$delay,
        ], 2);
    }


    /**
     * 失败任务记录
     * @param int $id
     * @param string $info
     * @return mixed|void
     */
    public function failed(int $id, string $info)
    {
        $script = <<<SCRIPT
local info = redis.call('HGetAll',KEYS[1]..ARGV[1])
if (#info > 0) then
    --详细信息转移到失败记录FAILED表
    redis.call('hSet', KEYS[2],ARGV[1],ARGV[2])
    --从执行集合 WORKING 中移除任务
    redis.call('zRem', KEYS[3], ARGV[1])
    --删除详情
    redis.call('del', KEYS[1]..ARGV[1])
end
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::INFO),
            $this->getKey(self::FAILED),
            $this->getKey(self::WORKING),
            $id,
            $info
        ], 3);
    }

    /**
     * 设置执行中任务的执行超时时间
     * @param $id
     * @param int $timeout(s 支持小数精度到0.001)
     * @return int Number of values added
     */
    public function setWorkingTimeout($id, $timeout = 0): int
    {
        $this->getConnect()->zAdd($this->getKey(self::WORKING), ['XX'], $timeout ? (microtime(true) + $timeout) : 0, $id);
        return true;
    }

    /**
     * 删除失败任务信息
     * @param $id
     * @return bool|int 0删除失败 1成功
     */
    public function removeFailedJob($id): int
    {
        return (int)$this->getConnect()->hDel($this->getKey(self::FAILED), $id);
    }


    /**
     * 获取任务数量
     * @return int
     */
    public function getCount($type = 'all'): int
    {
        switch ($type) {
            case 'all'://所有
                return (int)$this->getConnect()->get($this->getKey(self::TASK_NUMBER));
            case 'waiting'://等待执行 包括已投递未分配，已分配未执行，延时投递，失败重试
                return $this->getConnect()->llen($this->getKey(self::WAITING))
                    + $this->getConnect()->zCard($this->getKey(self::RESERVE))
                    + $this->getConnect()->zCard($this->getKey(self::DELAYED))
                    + $this->getConnect()->zCard($this->getKey(self::RETRY));
            case 'working'://执行中
                return $this->getConnect()->zCard($this->getKey(self::WORKING));
            case 'failed'://失败队列
                return (int)$this->getConnect()->hLen($this->getKey(self::FAILED));
            case 'over'://已完成
                return (int)$this->getConnect()->get($this->getKey(self::TASK_OVER));

        }
    }

    /**
     * 获取所有失败任务列表失败任务过多时会阻塞
     * @return array
     */
    public function failedList(): array
    {
        return $this->getConnect()->hGetAll($this->getKey(self::FAILED));
    }

    /**
     * 清空队列所有信息
     */
    public function clean()
    {
        $connect = $this->getConnect();
        while (false !== ($keys = $connect->scan($iterator, $this->getKey(self::INFO) . '*', 50))) {
            $keys && $connect->del($keys);
        }
        return $connect->del($this->getKey(self::TASK_NUMBER),
            $this->getKey(self::TASK_OVER),
            $this->getKey(self::DELAYED),
            $this->getKey(self::WAITING),
            $this->getKey(self::RESERVE),
            $this->getKey(self::WORKING),
            $this->getKey(self::RETRY),
            $this->getKey(self::FAILED)
        );
    }

    /**
     * 获取redis中的实际key
     * @param $key
     * @param null $queue
     * @return string
     */
    protected function getKey($key)
    {
        return $this->config['prefix'] . '{' . $this->queue . '}:' . $key;
    }

    /**
     * 获取脚本的sha1 hash值
     * @param $script
     * @param bool $force
     * @return mixed
     * @throws \Exception
     */
    protected function getScriptHash($script,$force = false)
    {
        $scriptKey = md5($script);
        if ($force || !array_key_exists($scriptKey, $this->scriptHash)) {
            try{
                if (!$this->scriptHash[$scriptKey] = $this->getConnect()->script('load', $script)) {
                    throw new \Exception('redis script error:'.$this->getConnect()->getLastError());
                }
            }catch (\Exception $e){
                if (!$this->scriptHash[$scriptKey] = $this->getConnect(true)->script('load', $script)) {
                    throw new \Exception('redis script error:'.$this->getConnect()->getLastError().'|'.$e->getMessage());
                }
            }
        }
        return $this->scriptHash[$scriptKey];
    }

    /**
     * 执行lua脚本
     * @param $script
     * @param array $args
     * @param int $num_keys
     * @throws \RedisException
     */
    protected function eval($script, $args = [], $num_keys = 0)
    {
        $scriptHash = $this->getScriptHash($script);
        $redis = $this->getConnect();
        try{
            $result = $redis->evalSha($scriptHash, $args, $num_keys);
        }catch (\Exception $exception){
            $scriptHash = $this->getScriptHash($script,true);
            $redis = $this->getConnect(true);
            $result = $redis->evalSha($scriptHash, $args, $num_keys);
        }

        if ($err = $redis->getLastError()) {
            $redis->clearLastError();
            throw new \RedisException($err);
        }
        unset($this->scriptError[$scriptHash . '-' . Coroutine::getCid()]);
        return $result;
    }
}
