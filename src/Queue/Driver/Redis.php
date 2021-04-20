<?php


namespace MPQueue\Queue\Driver;


use MPQueue\Config\BasicsConfig;
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

    const TASK_NUMBER = 'task_number'; //队列任务数量key名 string类型

    const TASK_OVER = 'task_over';     //已完成任务

    const INFO = 'info:'; //队列详情key名（hash类型）

    const DELAYED = 'delayed';//延时队列key名（zset类型）存储延迟执行的任务id 和对应时间戳

    const WAITING = 'waiting';//待执行队列key名（list类型）存储待执行任务的id

    const RESERVE = 'reserve';//已分配保留队列key名（zset类型）存储已分配worker任务id和worker超时接收时间戳

    const WORKING = 'working';//执行中队列名（zset类型）存储worker已执行任务id和worker执行超时时间

    const RETRY = 'retry';//重试队列（zset类型）存储失败需重试任务id和重新投递时间戳

    const FAILED = 'failed';//失败任务队列（hash类型存储失败任务的序列化信息）


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
    public function setQueue($queue): DriverInterface
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * 获取redis连接
     * @return \Redis
     */
    public function getConnect()
    {
        //在协程环境中，则一个协程一个连接防止共用连接数据错乱
        if (class_exists('Swoole\Coroutine') && Coroutine::getCid() > 0) {
            return isset(Coroutine::getContext()[\Redis::class])
                ? Coroutine::getContext()[\Redis::class] : (Coroutine::getContext()[\Redis::class] = $this->connection());
        }
        if (!$this->connect) {
            $this->connect = $this->connection();
        }
        return $this->connect;
    }

    /**
     * 连接到redis
     * @return \Redis
     */
    private function connection()
    {
        $connect = new \Redis();
        $connect->connect($this->config['host'], $this->config['port']);
        $this->config['password'] && $connect->auth($this->config['password']);
        return $connect;
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
        } else {
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
        $redis = $this->getConnect();
        $id = $redis->incr($this->getKey(self::TASK_NUMBER));
        $redis->hMset($this->getKey(self::INFO) . $id, [
            'job' => $job,//任务对应信息序列化后的字符串
            'create_time' => time(),//任务创建时间
            'exec_number' => 0,//已执行次数（失败再次执行后会加一）
            'worker_id' => '',//执行进程id
            'start_time' => 0,//开始执行时间
            'timeout' => $timeout,//执行超时时间
            'fail_number'=>$fail_number,//最大失败次数
            'fail_expire'=>$fail_expire,//失败后重试延时(秒)
            'error_info' => '',//出错信息
        ]);
        if ($delay > 0) {
            $redis->zAdd($this->getKey(self::DELAYED), time() + $delay, $id);
        } else {
            $redis->rPush($this->getKey(self::WAITING), $id);
        }
        return true;
    }


    /**
     * 从等待队列中弹出一个可执行任务
     * @throws \RedisException
     */
    public function popJob()
    {
        $script = <<<SCRIPT
-- 从等待队列 WAITING头部 弹出一个任务
local id = redis.call('lpop', KEYS[1])
if (id) then
    -- 添加到保留集合RESERVE中并设置超时重新分发时间戳
    redis.call('zAdd', KEYS[2], ARGV[1], id)
    -- 任务详情INFO设置任务状态为被取出并设置取出时间
    redis.call('hSet',KEYS[3]..id,'prop_time',ARGV[2])
end  
return id
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::WAITING),
            $this->getKey(self::RESERVE),
            $this->getKey(self::INFO),
            time() + Queue::WORKER_POP_TIMEOUT,
            time()
        ], 3);
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
            time(),
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
    -- 将任务id提交到推入list WAITING尾部
    redis.call('rPush',KEYS[2],unpack(ids))
    -- 从延时集合DELAYED中移除对应id
    redis.call('zRem',KEYS[1],unpack(ids))
end  
return ids
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::RESERVE),
            $this->getKey(self::WAITING),
            time(),
            $number
        ], 2);
    }

    /**
     * 移动超时重试任务到等待队列
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
            time(),
            $number
        ], 2);
    }


    /**
     * 获取执行超时id
     * @return mixed
     * @throws \RedisException
     */
    public function popTimeoutJob()
    {
        $script = <<<SCRIPT
-- 从执行中集合WORKING中弹出number个任务
local ids = redis.call('zRangeByScore', KEYS[1], 1 , ARGV[1], 'LIMIT' , 0 ,1)
if (#ids ~= 0) then
    -- 设置新的超时重试时间
    redis.call('zAdd',KEYS[1], ARGV[2], ids[1])
    return ids[1]
end  
return false
SCRIPT;
        return $this->eval($script, [
            $this->getKey(self::WORKING),
            time(),
            time() + Queue::WORKER_POP_TIMEOUT
        ], 1);
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
    info['exec_number'] = info['exec_number']+1
    -- 设置当前执行的程序、开始执行时间及状态为执行中
    redis.call('hMSet',KEYS[3]..ARGV[1], 'worker_id', ARGV[2], 'start_time', ARGV[3],'exec_number',info['exec_number'])
    -- 将任务添加到执行集合 WORKING重试时间戳
    if (tonumber(info['timeout']) > 0) then
        redis.call('zAdd',KEYS[2],ARGV[3]+info['timeout'],ARGV[1])
    else
        redis.call('zAdd',KEYS[2],0,ARGV[1])
    end
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
            time(),
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
     * 开始消费执行超时任务返回对应任务详情信息
     * @param $id
     * @return mixed
     * @throws \RedisException
     */
    public function consumeTimeoutWorking($id)
    {
        $script = <<<SCRIPT
--从执行集合WORKING中获取任务
local score = redis.call('zScore', KEYS[1], ARGV[1])
if (score ~= -1) then
    --设置超时时间为-1 表示已被worker进程接收去执行timeout
    redis.call('zAdd', KEYS[1], -1, ARGV[1])
    -- 获取当前任务的详情
    local info_array = redis.call('HGetAll',KEYS[2]..ARGV[1])
    if (info_array) then
        local number = #info_array
        local info = {}
        for i=1,number,2 do
            info[info_array[i]] = info_array[i+1]
        end
        return cjson.encode(info)
    end
end  
return false
SCRIPT;
        $info = $this->eval($script, [
            $this->getKey(self::WORKING),
            $this->getKey(self::INFO),
            $id,
        ], 2);
        $info && $info = json_decode($info, true);
        return $info;
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
     * @param int $delay 重试时间戳
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
            $delay,
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
        ], 3,);
    }

    /**
     * 设置执行中任务的执行超时时间
     * @param $id
     * @param int $timeout
     * @return int Number of values added
     */
    public function setWorkingTimeout($id, $timeout = 0): int
    {
        $this->getConnect()->zAdd($this->getKey(self::WORKING), ['XX'], $timeout ? (time() + $timeout) : 0, $id);
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
     * @return mixed
     */
    protected function getScriptHash($script)
    {
        $scriptKey = md5($script);
        if (!array_key_exists($scriptKey, [])) {
            if (!$this->scriptHash[$scriptKey] = $this->getConnect()->script('load', $script)) {
                throw new \RedisException($this->getConnect()->getLastError());
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
        $redis = $this->getConnect();
        $scriptHash = $this->getScriptHash($script);
        $result = $redis->evalSha($scriptHash, $args, $num_keys);
        if ($err = $redis->getLastError()) {
            $redis->clearLastError();
            //相同脚本同一协程中出错两次则抛出异常
//            if (array_key_exists($scriptHash . '-' . Coroutine::getCid(), $this->scriptError)) {
            throw new \RedisException($err);
//            }
            //出错一次后删除脚本hash重新重试
            unset($this->scriptHash[$scriptHash]);
            $this->scriptError[$scriptHash . '-' . Coroutine::getCid()] = $err;
            return $this->eval($script, $args, $num_keys);
        }
        unset($this->scriptError[$scriptHash . '-' . Coroutine::getCid()]);
        return $result;
    }
}
