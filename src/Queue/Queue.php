<?php


namespace MPQueue\Queue;


use MPQueue\Config\BasicsConfig;
use MPQueue\Config\QueueConfig;
use MPQueue\Job;
use MPQueue\Log\Log;
use MPQueue\Queue\Driver\DriverInterface;
use MPQueue\Serialize\JobSerialize;
use Swoole\Coroutine\Channel;

/**
 * 队列类
 * Class Queue
 * @method \MPQueue\Queue\Driver\DriverInterface close() 关闭当前队列
 * @package MPQueue\Queue
 * @see \MPQueue\Queue\Driver\DriverInterface
 */
class Queue
{
    private $driver = null;
    private $idChannel = null;
    const WORKER_POP_TIMEOUT = 1; //弹出任务后，分发worker开始执行之间时间间隔（单位秒，超时后会被重新分发给其他worker）

    /**
     * Queue constructor.
     * @param \MPQueue\Queue\Driver\DriverInterface $driver 队列驱动
     * @param $queue 队列名称
     */
    public function __construct(DriverInterface $driver,$queue)
    {
        $this->driver = $driver;
        $this->driver->setQueue($queue);
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->driver, $name], $arguments);
    }


    /**
     * 队列中添加 job任务
     * @param string $queue 队列名称
     * @param callable|Job $job 任务类型
     * @param int $delay 延时时间（秒）0代表无延时
     * @return bool
     * @throws \Exception
     */
    public static function push(string $queue,$job,int $delay = 0){
        if (is_string($job) && class_exists($job)) {
            $callJob = new $job;
        }else{
            $callJob = $job;
        }
        if($callJob instanceof Job){
            $timeout = is_null($callJob->getTimeout())?QueueConfig::queue($queue)->timeout():$callJob->getTimeout();
            $fail_number = is_null($callJob->getFailNumber())?QueueConfig::queue($queue)->fail_number():$callJob->getFailNumber();
            $fail_expire = is_null($callJob->getFailExpire())?QueueConfig::queue($queue)->fail_expire():$callJob->getFailExpire();
        }elseif(is_callable($job)) {
            $timeout = QueueConfig::queue($queue)->timeout();
            $fail_number = QueueConfig::queue($queue)->fail_number();
            $fail_expire = QueueConfig::queue($queue)->fail_expire();
        }else{
            throw new \Exception('队列任务内容非法，必须是\MPQueue\Job类的实现/合法的可调用结构');
        }
        return  BasicsConfig::driver()->setQueue($queue)->push(JobSerialize::serialize($job),
            $delay,
            max(0,(int)$timeout),
            max(0,(int)$fail_number),
            max(0,(int)$fail_expire)
        );
    }

    /**
     * 添加协程定时任务（内部含有协程，无需重复添加协程）
     */
    public function timerInterval(){
        $this->moveExpired();
        $this->moveExpiredReserve();
        $this->moveExpiredRetry();
    }

    /**
     * 移动到期任务到等待队列
     */
    private function moveExpired(){
        go(function (){
            while (true){
                $ids = $this->driver->moveExpired(50);
                Log::debug('移动到期的延时任务到等待队列',(array)$ids);
                if(count($ids) == 50){
                    continue;
                }
                $this->sleep();
            }
        });
    }

    /**
     * 移动分发超时到等待队列
     */
    private function moveExpiredReserve(){
        go(function (){
            while (true){
                $ids = $this->driver->moveExpiredReserve(50);
                Log::debug('移动分发超时任务到等待队列',(array)$ids);
                if(count($ids) == 50){
                    continue;
                }
                $this->sleep();
            }
        });
    }

    /**
     * 移动失败重试到等待队列
     */
    private function moveExpiredRetry()
    {
        go(function () {
            while (true) {
                $ids = $this->driver->moveExpiredRetry(50);
                Log::debug('移动重试任务到等待队列',(array)$ids);
                if (count($ids) == 50) {
                    continue;
                }
                $this->sleep();
            }
        });
    }

    /**
     * 定时循环提取可执行id
     * @throws \RedisException
     */
    public function popInterval(){
        $this->idChannel = new Channel(1);
        $this->popJob();
        $this->popTimeoutJob();
    }



    /**
     * 获取队列id放入idChannel中
     * @return mixed|null
     * @throws \RedisException
     */
    private function popJob(){
        go(function (){
            do{
                $result = $this->driver->popJob();
                Log::debug('查询任务',[$result]);
                $result && $this->idChannel->push(['type'=>'job','id'=>$result]);
                $this->sleep();
            }while(true);
        });
    }

    /**
     * 获取执行超时任务id放入idChannel中
     * @return mixed
     * @throws \RedisException
     */
    private function popTimeoutJob(){
        go(function (){
            do{
                $result = $this->driver->popTimeoutJob();
                Log::debug('查询超时任务',[$result]);
                $result && $this->idChannel->push(['type'=>'timeoutJob','id'=>$result]);
                $this->sleep();
            }while(true);
        });
    }

    /**
     * 阻塞获取可执行id(需要放在协程中执行)
     * @return array ['type'=>'','id'=>'']
     */
    public function pop(){
        return $this->idChannel->pop();
    }

    /**
     * 消费任务，在移除等待队列对应任务并设置执行信息
     * @param $id
     * @return mixed
     * @throws \RedisException
     */
    public function consumeJob($id){
        $info =  $this->driver->reReserve($id);
        if($info){
           $info['job'] = JobSerialize::unSerialize($info['job']);
        }
        return $info;
    }

    /**
     * 消费超时任务
     * @param $id
     * @return mixed
     * @throws \RedisException
     */
    public function consumeTimeoutJob($id){
        $info = $this->driver->consumeTimeoutWorking($id);
        if($info){
            $info['job'] = JobSerialize::unSerialize($info['job']);
        }
        return $info;
    }

    /**
     * 重新发布一遍执行失败的任务
     * @param int $id 任务id
     * @param string $error 任务出错信息
     * @param int $delay 重新执行延时时间
     * @return bool
     * @throws \RedisException
     */
    public function retry(int $id, string $error,int $delay = 0){
        return $this->driver->setErrorInfo($id,$error."\n") && $this->driver->retry($id,time()+$delay);
    }

    /**
     * 删除执行成功的任务信息
     * @param int $id
     * @return mixed
     * @throws \RedisException
     */
    public function remove(int $id){
        return $this->driver->remove($id);
    }

    /**
     * 任务失败，记录并留存
     * @param int $id
     * @param array $info
     * @param string $error 错误信息
     * @return bool
     */
    public function failed(int $id, array $info,string $error){
        $info['error_info'] .= $error."\n";
        return $this->driver->failed($id,JobSerialize::serialize($info));
    }



    /**
     * 获取特定队列的任务数量
     * @param $queue
     * @param $type
     * all 全部
     * waiting 等待执行 包括已投递未分配，已分配未执行，延时投递，失败重试
     * working 执行中
     * failed  失败
     * over   已完成
     * @return int
     */
    static public function getCount($queue,$type): int
    {
        return  BasicsConfig::driver()->setQueue($queue)->getCount($type);
    }

    /**
     * 移除失败任务
     * @param $id
     */
    static public function removeFailedJob($queue,$id){
        return BasicsConfig::driver()->setQueue($queue)->removeFailedJob($id);
    }

    /**
     * 清空队列所有任务
     */
    static public function cleanJob($queue){
        return BasicsConfig::driver()->setQueue($queue)->clean();
    }

    /**
     * 获取失败任务列表
     * @param $queue
     * @return array
     */
    static public function failedList($queue){
        $result = BasicsConfig::driver()->setQueue($queue)->failedList();
        foreach ($result as &$value){
            $value = JobSerialize::unSerialize($value);
        }
        return $result;
    }

    /**
     * 设置执行中任务的执行超时时间
     * @param $id
     * @param null|int $timeout
     */
    public function setWorkingTimeout($id,$timeout = null){
        return $this->driver->setWorkingTimeout($id,$timeout);
    }

    /**
     * 协程sleep会让出协程
     */
    private function sleep(){
        \Swoole\Coroutine\System::sleep(QueueConfig::queue()->sleep_seconds());
    }


}