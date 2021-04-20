<?php

namespace MPQueue\Process;

use Co\WaitGroup;
use MPQueue\Config\BasicsConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\Config\QueueConfig;
use MPQueue\Exception\ClientException;
use MPQueue\Job;
use MPQueue\Log\Log;
use MPQueue\Queue\Queue;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Process;
use Swoole\Timer;

/**
 * 不要定义耗时阻塞操作，以免队列任务执行产生协程调度时长时间无法那会协程控制权
 * Class WorkerProcess
 * @package MPQueue\Process
 */
class WorkerProcess
{
    protected $pid;
    protected $client;
    protected $masterProcess = [];//master进程
    protected $queue = null;
    protected $status = ProcessConfig::STATUS_IDLE; //当前进程状态
    protected $queueDriver = null;//队列驱动
    protected $stop = false;//队列配置信息
    protected $startTime = null;//启动时间

    /**
     * worker进程类
     * @param $process 当前worker进程process
     * @param $queue 队列名称
     */
    public function __construct(Process $process, $queue)
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);//当前方式允许在创建后生效
        swoole_set_process_name("mpq:$queue:w");
        ProcessConfig::setWorker();
        ProcessConfig::setQueue($queue);
        $this->queue = $queue;
        $this->pid = getmypid();
        $this->process = $process;
        $this->manageClient = new \MPQueue\Client\Process\WorkerClient($process->exportSocket(), $this);
        $this->queueDriver = new Queue(BasicsConfig::driver(), $queue);

    }

    public function start()
    {
        Log::debug('子进程开始启动');
        $this->startTime = time();
        //注册子进程信号监听接收协程调度，会被同步代码阻塞
        $this->registerSignal();
        if (is_callable(BasicsConfig::worker_start_handle())) {
            call_user_func(BasicsConfig::worker_start_handle());
        }
        if (is_callable(QueueConfig::worker_start_handle())) {
            call_user_func(QueueConfig::worker_start_handle());
        }
        //阻塞监听manage进程消息
        $this->listenManageMessage();
        $this->getStatus();
        Log::debug('子进程启动完成');
        //必须添加阻塞程序，否则信号异步监听不生效(协程等待时间异步信号监听会被阻塞)
        while (true) {
            Coroutine::sleep(0.001);
        }


    }

    /**
     * 监听管理进程消息
     */
    private function listenManageMessage()
    {
        go(function () {
            try {
                while (true) {
                    $this->manageClient->recvAndExec();
                }
            } catch (\Swoole\ExitException $e) {

            }
        });

    }

    /**
     * 监听master进程消息(会阻塞当前程序)
     * @param \MPQueue\Client\UnixSocket\WorkerClient $client
     */
    private function listenMasterMessage(\MPQueue\Client\UnixSocket\WorkerClient $client)
    {
        $wg = new WaitGroup();
        $wg->add();
        go(function () use ($client, $wg) {
            try {
                while (true) {
                    $client->recvAndExec();
                }
            } catch (ClientException $e) {
                $wg->done();
            } catch (\Swoole\ExitException $e) {

            }
        });
        $wg->wait();
        //消息获取失败1s后重试
        Coroutine::sleep(1);
        $this->connectMaster($client->getUnixSocketPath());

    }


    /**
     * 添加master进程记录
     * @param $masterPid  master进程pid
     * @param $connection
     * @throws \Exception
     */
    public function setMaster($masterPid, $unixSocketPath): bool
    {
        //已经创建直接跳过
        if (isset($this->masterProcess['pid']) && $this->masterProcess['pid'] == $masterPid) {
            return false;
        }
        $this->masterProcess['pid'] = $masterPid;
        $this->masterProcess['unixSocketPath'] = $unixSocketPath;
        $this->masterProcess['connectNumber'] = 0;
        $this->connectMaster($unixSocketPath);
        Log::debug('master进程:' . $masterPid . '设置完成');
        return true;
    }

    /**
     * 连接到master进程server并监听消息
     * @param $unixSocketPath
     * @return mixed
     */
    protected function connectMaster($unixSocketPath)
    {
        if (empty($this->masterProcess['unixSocketPath']) || $unixSocketPath != $this->masterProcess['unixSocketPath']) {
            return false;
        }
        if (empty($this->masterProcess['connectNumber'])) {
            Log::debug('连接到master server', [$unixSocketPath]);
        } else {
            Log::warning('断线重连master server', [$unixSocketPath]);
        }
        $this->masterProcess['client'] = null;
        $this->masterProcess['connectNumber']++;
        try {
            $this->masterProcess['client'] = new \MPQueue\Client\UnixSocket\WorkerClient($this->masterProcess['unixSocketPath'], $this);
        } catch (ClientException $e) {
            //连接失败1s后重试
            return Timer::after(1000, function () use ($unixSocketPath) {
                $this->connectMaster($unixSocketPath);
            });
        }
        $this->masterProcess['client']->send('addWorker', ['status' => $this->status]);
        $this->listenMasterMessage($this->masterProcess['client']);
    }

    /**
     * 获取master进程
     *
     * @return array
     * @throws \Exception
     */
    private function getMaster(): array
    {
        return $this->masterProcess;
    }

    /**
     * 移除master进程信息
     * @param $masterPid
     * @return bool
     * @throws \Exception
     */
    public function unSetMaster($masterPid)
    {
        if ($this->masterProcess['pid'] = $masterPid) {
            if ($this->masterProcess['client']) {
                $this->masterProcess['client']->close();
            }
            $this->masterProcess = [];
            return true;
        }
        return false;
    }


    /**
     * 注册信号监听
     */
    public function registerSignal()
    {
        //监听状态查询信号
        pcntl_signal(ProcessConfig::SIG_STATUS, function () {
            $this->getStatus();
        }, true);
        //平滑重启worker进程
        pcntl_signal(ProcessConfig::SIG_RELOAD, function () {
            $this->stop();
        }, true);
    }


    /**
     * 向master进程发消息
     * @param string $type 消息类型
     * @param null|array $data 消息数据数组
     * @param string $msg 消息体
     * @return bool
     */
    public function sendToMaster(string $type, $data = null, string $msg = '')
    {
        $this->getMaster()['client']->send($type, $data, $msg);
        return true;
    }

    /**
     * 向manage进程发送信息
     * @param string $type
     * @param null $data
     * @param string $msg
     */
    public function sendToManage(string $type, $data = null, string $msg = '')
    {
        $this->manageClient->send($type, $data, $msg = '');
    }

    /**
     * 获取当前进程状态（发送给master和manage进程）
     */
    public function getStatus()
    {
        $this->sendToMaster('setWorkerStatus', ['status' => $this->status, 'startTime' => $this->startTime]);
        $this->sendToManage('setProcessStatus', ['status' => $this->status, 'startTime' => $this->startTime]);
    }

    /**
     * 消费任务
     * @param $id 任务id
     */
    public function consumeJob($id)
    {
        //如果当前进程正在执行任务则拒绝执行新任务
        if ($this->status == ProcessConfig::STATUS_BUSY) {
            return false;
        }
        Log::debug('开始执行任务', [$id]);
        $info = $this->queueDriver->consumeJob($id);
        if (!$info) {
            Log::debug('没有获取到对应详情', [$id]);
            $this->getStatus();
            return false;
        }
        $this->status = ProcessConfig::STATUS_BUSY;
        $this->getStatus();
        try {
            $failNumber = $info['fail_number'];
            $failExpire = $info['fail_expire'];
            $timeout = $info['timeout'];
            if ($timeout > 0) {
                $this->registerTimeSig();
                pcntl_alarm($timeout);
            }
        } catch (\Throwable $e) {
            Log::error($e);
            $this->status = ProcessConfig::STATUS_IDLE;
            $this->getStatus();
            return false;
        }
        try {
            $job = $info['job'];
            if (is_string($job) && class_exists($job)) {
                $job = new $job;
            }
            if ($job instanceof Job) {
                $job->handle();
            } else {
                call_user_func($info['job']);
            }
            $this->delTimeSig();
            $this->queueDriver->remove($id);
        } catch (\Throwable $e) {
            $this->delTimeSig();
            if ($this->queueDriver->setWorkingTimeout($id, -2)) {
                Log::error($e);
                $error = BasicsConfig::name() . ':' . getmypid() . ':' . $e->getCode() . ':' . $e->getMessage();
                try {
                    $handle_result = false;
                    if ($info['job'] instanceof Job) {
                        $handle_result = $info['job']->fail_handle($info, $e);
                    }
                    if ($handle_result === false && QueueConfig::fail_handle()) {
                        call_user_func(QueueConfig::fail_handle(), $info, $e);
                    }
                } catch (\Throwable $exception) {
                    Log::error($exception);
                    $error .= "\nfail_handle:" . $exception->getCode() . ':' . $exception->getMessage();
                }
                if ($info['exec_number'] < $failNumber) {
                    $this->queueDriver->retry($id, $error, $failExpire);
                } else {
                    $this->queueDriver->failed($id, $info, $error);
                }
            }
        }
        $this->queueDriver->close();
        $this->status = ProcessConfig::STATUS_IDLE;
        if(QueueConfig::queue()->memory_limit() && memory_get_usage() > (QueueConfig::queue()->memory_limit()*1024)){
            Log::error('内存占用过多：'.memory_get_usage());
            $this->process->exit();
        }
        $this->getStatus();
    }

    /**
     * 超时任务处理
     * @param $id
     * @throws \RedisException
     */
    public function consumeTimeoutJob($id)
    {
        //如果当前进程正在执行任务则拒绝执行新任务
        if ($this->status == ProcessConfig::STATUS_BUSY) {
            return false;
        }
        $this->status = ProcessConfig::STATUS_BUSY;
        if ($info = $this->queueDriver->consumeTimeoutJob($id)) {
            $error = '执行超时';
            try {
                $handle_result = false;
                if ($info['job'] instanceof Job) {
                    $handle_result = $info['job']->timeout_handle($info);
                }
                if ($handle_result === false && QueueConfig::timeout_handle()) {
                    call_user_func(QueueConfig::timeout_handle(), $info);
                }
            } catch (\Throwable $exception) {
                Log::error($exception);
                $error .= "\nfail_handle:" . $exception->getCode() . ':' . $exception->getMessage();
            }
            $this->queueDriver->failed($id, $info, $error);
        }
        $this->status = ProcessConfig::STATUS_IDLE;
        if(QueueConfig::queue()->memory_limit() && memory_get_usage() > (QueueConfig::queue()->memory_limit()*1024)){
            Log::error('内存占用过多：'.memory_get_usage());
            $this->process->exit();
        }
        $this->getStatus();
    }

    /**
     * 设置当前时钟信号
     */
    private function registerTimeSig()
    {
        pcntl_signal(SIGALRM, function () {
            $this->process->exit();
        });
    }

    /**
     * 取消时钟信号
     */
    private function delTimeSig()
    {
        pcntl_signal(SIGALRM, SIG_IGN);
    }

    /**
     * 结束当前进程
     * @param int $code 结束状态码
     * @param bool $need_idle 是否需要等待空闲时关闭
     * @param int $time 定时时间(毫秒)
     */
    public function stop($code = 0, $need_idle = true, int $time = 500)
    {
        $this->unSetMaster($this->masterProcess['pid']);
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
}