<?php

namespace MPQueue\Process;

use Co\Channel;
use MPQueue\Client\Process\MasterProcessClient;
use MPQueue\Config\BasicsConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\Config\QueueConfig;
use MPQueue\Exception\ClientException;
use MPQueue\Log\Log;
use MPQueue\OutPut\OutPut;
use MPQueue\Queue\Queue;
use MPQueue\Server\MasterConnection;
use MPQueue\Server\MasterServer;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Timer;

class MasterProcess
{
    protected $pid;//当前进程id
    protected $manageClient;//与管理进程通信的客户端
    protected $process;//当前进程对象
    protected $workProcess = [];//工作进程数组
    protected $server = null;
    protected $queue = null;//队列name
    protected $queueDriver = null;
    protected $status = ProcessConfig::STATUS_IDLE;
    protected $workerChannel;
    protected $startTime = null;//启动时间

    public function __construct(Process $process, string $queue)
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);//当前方式允许在创建后生效
        swoole_set_process_name("mpq:$queue:m");
        ProcessConfig::setMaster();
        ProcessConfig::setQueue($queue);
        $this->pid = getmypid();
        $this->process = $process;
        $this->queue = $queue;
        $this->manageClient = new MasterProcessClient($process->exportSocket(), $this);
        $this->queueDriver = new Queue(BasicsConfig::driver(),$queue);
        $this->workerChannel = new Channel(QueueConfig::worker_number());
    }

    public function getPid()
    {
        return $this->pid;
    }

    public function start()
    {
        Log::debug("子进程启动");
        $this->startTime = time();
        //注册子进程信号监听
        $this->registerSignal();
        //监听manage进程消息
        go(function () {
            while (true) {
                $this->manageClient->recvAndExec();
            }
        });
        $this->startServer();
        $this->monitorQueue();
        $this->setOver();
        $this->getStatus();
        Log::debug("子进程启动成功");
        //必须添加阻塞程序，否则异步信号监听不生效(协程socket连接等待时间异步信号监听会被阻塞)
        go(function (){
            while (true) {
               \Swoole\Coroutine\System::sleep(0.1);
            }
        });
    }


    /**
     * 启动woker进程之间的服务
     * @throws \Exception
     */
    protected function startServer()
    {
        $pid = 0;
        //监听worker进程消息
        go(function () {
            try {
                $this->server = new MasterServer($this);
                $this->server->handle(function (MasterConnection $connection) {
                    try {
                        while (true) {
                            $connection->recvAndExec();
                        }
                    } catch (ClientException $e) {
                        if(Process::kill($connection->pid,0)){
                            Log::error($e, [$connection->pid]);
                            Process::kill($connection->pid,ProcessConfig::SIG_RELOAD);
                        }
                        $this->unsetWorker($connection->pid);
                    }
                });
                if (!$this->server->start()) {
                    new \Exception('master服务监听失败:' . $this->server->errCode);
                }
            } catch (\Throwable $e) {
                $this->exceptionHandler($e);
            }

        });
    }

    /**
     * 初始化完成通知manage进程
     */
    protected function setOver()
    {
        //通知manage进程设置完成
        go(function () {
            $this->manageClient->send('masterOver', ['queue' => $this->queue, 'unixSocketPath' => $this->server->getSocketPath()]);
        });
    }

    /**
     * 监听队列
     * @throws \RedisException
     */
    protected function monitorQueue()
    {
        //队列定时执行任务
        $this->queueDriver->timerInterval();
        if(QueueConfig::model() == QueueConfig::MODEl_DISTRIBUTE) {
            //分发队列任务
            go(function () {
                while (true) {
                    $pid = $this->workerChannel->pop();
                    $ids = $this->queueDriver->pop($this->workerChannel->length() + 1);
                    Log::debug("获取到队列任务", $ids);
                    $this->queueDriver->setWorkerNumber(0 - count($ids));
                    foreach ($ids as $id) {
                        do{
                            if (!empty($this->workProcess[$pid])) {
                                //如果进程存在则进行分发
                                $this->setWorkerStatus(ProcessConfig::STATUS_BUSY, $pid);
                                $this->sendToWorker($pid, 'consumeJob', ['id' => $id]);
                                Log::debug('将队列任务分发给woker进程:' . $pid, [$id]);
                                $pid = 0;
                                break;
                            }
                        }while($pid = $this->workerChannel->pop());
                    }
                }
            });
        }
        $this->setStatus(ProcessConfig::STATUS_BUSY);
    }


    /**
     * 注册信号监听
     */
    protected function registerSignal()
    {
        /**
         * 状态查询信号
         */
        pcntl_signal(ProcessConfig::SIG_STATUS, [$this,'getStatus']);
    }

    public function getStatus(){
        $this->sendToManage('setProcessStatus', ['status' => $this->status,'startTime'=>$this->startTime]);
    }

    public function setStatus($status){
        if($status != $this->status){
            $this->status = $status;
            $this->getStatus();
        }
    }

    /**
     * 向manage进程发送信息
     * @param string $type
     * @param null $data
     * @param string $msg
     */
    public function sendToManage(string $type, $data = null, string $msg = '')
    {
        $this->manageClient->send($type, $data, $msg);
    }

    /**
     * 添加worker进程记录
     * @param $status
     * @param $pid
     * @param $connection
     * @throws \Exception
     */
    public function addWorker($status, $pid, $connection)
    {
        //已经创建直接跳过
        if (isset($this->workProcess[$pid])) {
            return false;
        }
        $connection->pid = $pid;
        $info['connection'] = $connection;
        $info['status'] = '';
        $this->workProcess[$pid] = $info;
        Log::debug('worker进程:' . $pid . '记录成功');
        $this->setWorkerStatus($status, $pid);
        $this->sendToManage('workerOver', ['workerPid' => $pid, 'queue' => ProcessConfig::queue()]);
        return true;
    }


    /**
     * 向worker进程发消息
     * @param int $pid worker进程id 0代表所有
     * @param string $type 消息类型
     * @param null|array $data 消息数据数组
     * @param string $msg 消息体
     * @return bool
     */
    public function sendToWorker($pid = 0, string $type, $data = null, string $msg = '')
    {
        if (!$pid) {
            foreach ($this->workProcess as $wPid=>$value) {
                $this->sendToWorker($wPid,$type, $data, $msg);
            }
        } else {
            try{
                $this->workProcess[$pid]['connection']->send($type, $data, $msg);
            }catch (\Exception $e){
                $this->unsetWorker($pid);
                if(Process::kill($pid,0)){
                    Process::kill($pid,ProcessConfig::SIG_RELOAD);
                }
            }
        }
        return true;
    }

    /**
     * 释放worker进程
     * @param $pid
     * @return bool
     */
    public function unsetWorker($workerPid)
    {
        if (!isset($this->workProcess[$workerPid])) {
            return false;
        }
        isset($this->workProcess[$workerPid]['connection']) && $this->workProcess[$workerPid]['connection']->close();
        unset($this->workProcess[$workerPid]);
        return true;
    }


    /**
     * 设置工作进程状态
     * @param $status
     * @param $pid
     */
    public function setWorkerStatus($status, $pid)
    {
        if ($this->workProcess[$pid]['status'] !== $status) {
            $this->workProcess[$pid]['status'] = $status;
            switch ($status) {
                case ProcessConfig::STATUS_IDLE:
                    $count = 1;
                    foreach ($this->workProcess as $value){
                        if($value['status'] === ProcessConfig::STATUS_IDLE){
                            $count++;
                        }
                    }
                    $this->queueDriver->setWorkerNumber($count);
                    $this->workerChannel->push($pid);
                    break;
            }
        }
    }

    /**
     * 结束当前进程
     * @param int $code 结束状态码
     * @param bool $need_idle 是否需要等待空闲时关闭
     * @param int $time 定时时间(毫秒)
     */
    public function stop($code = 0, $need_idle = true, int $time = 500)
    {
        if (!$need_idle || $this->status == ProcessConfig::STATUS_IDLE) {
            Log::debug('结束进程,结束码:' . $code);
            $this->process->exit($code);
        }
        Timer::tick($time, function () use ($code) {
            if ($this->status == ProcessConfig::STATUS_IDLE) {
                Log::debug('结束进程,结束码:' . $code);
                $this->process->exit($code);
            }
        });
    }

    /**
     * 平滑启动
     * @throws \Exception
     */
    public function reload()
    {
        $pid = $this->getPid();
        if (!$pid) {
            throw new \Exception('队列未启动无需平滑重启');
        }
        Process::kill($pid, ProcessConfig::SIG_RELOAD);
        OutPut::normal("平滑重启信号发送成功");
    }

    /**
     * 异常处理函数
     */
    public function exceptionHandler(\Throwable $e)
    {
        //异常监听
        Log::critical($e);
        $this->stop();
    }
}
