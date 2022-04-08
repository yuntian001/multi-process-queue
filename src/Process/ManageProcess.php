<?php

namespace MPQueue\Process;

use MPQueue\Client\Process\ManageProcessClient;
use MPQueue\Config\BasicsConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\Config\QueueConfig;
use MPQueue\Library\Helper;
use MPQueue\Log\Log;
use MPQueue\OutPut\OutPut;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;

class ManageProcess
{

    private $queueProcess = [];
    private $stop = false;
    private $processes;
    private $daemon = false;


    public function __construct()
    {
        swoole_set_process_name('mpq:manage');
    }

    /**
     * 获取状态内容存储文件位置
     * @return string
     */
    public function getStatusFile()
    {
        return BasicsConfig::pid_path() . '/mpQueue_status.log';
    }


    /**
     * 获取已运行队列manage进程pid
     */
    public function getPid()
    {
        if (file_exists(BasicsConfig::pid_file())) {
            $pid = file_get_contents(BasicsConfig::pid_file());
            if ($pid && Process::kill($pid, 0)) {
                return $pid;
            }
        }
        return false;
    }


    /**
     * 运行
     * @throws \Exception
     */
    public function run()
    {
        //关闭协程
        ini_set('swoole.enable_coroutine', false);
        //设置信号异步
        pcntl_async_signals(true);
        //设置当前进程类型
        ProcessConfig::setManage();
        //注册错误监听
        $this->registerHandle();
        //写入pid
        if ($this->getPid()) {
            Log::error('已启动不可重复启动');
            exit();
        }
        file_put_contents(BasicsConfig::pid_file(), getmypid());
        //启动信号监听
        $this->registerSignal();
        //创建master进程
        foreach (QueueConfig::queues() as $queue) {
            $this->queueProcess[$queue->name()] = [
                'need_master' => 1,
                'need_worker' => $queue->worker_number(),
                'master_number' => 0,
                'worker_number' => 0,
                'worker' => [],
                'master' => 0
            ];
            $this->createMasterProcess($queue->name());
        }
        $this->overMonitor();
    }


    /**
     * 停止当前进程及其子进程
     */
    public function stop()
    {
        if (empty($this->processes)) {
            exit();
        }
        $this->stop = true;
        foreach ($this->queueProcess as $queueProcess) {
            if ($queueProcess['master']) {
                if (@!Process::kill($queueProcess['master'], SIGKILL)) {
                    unset($this->processes[$queueProcess['master']]);
                }
                foreach ($queueProcess['worker'] as $pid) {
                    if (@!Process::kill($pid, SIGKILL)) {
                        unset($this->processes[$pid]);
                    }
                }
            }
        }
        foreach ($this->processes as $pid => $value) {
            if (@!Process::kill($pid, SIGKILL)) {
                unset($this->processes[$pid]);
            }
        }
    }


    /**
     * 安全重启当前进程的WORKER子进程
     */
    public function reload()
    {
        Log::info("平滑重启worker进程");
        foreach ($this->queueProcess as $queueProcess) {
            //安全退出worker进程
            foreach ($queueProcess['worker'] as $pid) {
                if (@!Process::kill($pid, ProcessConfig::SIG_RELOAD)) {
                    unset($this->processes[$pid]);
                }
            }
        }
    }

    /**
     * 注册监听函数
     */
    protected function registerHandle()
    {
        //注册错误捕捉捕捉警告信息
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline, array $errcontext) {
            switch ($errno){
                case E_NOTICE:
                    Log::notice($errno . ':' . $errstr . ' in ' . $errfile . $errline, $errcontext);
                    break;
                case E_WARNING:
                    Log::warning($errno . ':' . $errstr . ' in ' . $errfile . $errline, $errcontext);
                    break;
                case E_STRICT:
                    Log::debug('e_script:'.$errno . ':' . $errstr . ' in ' . $errfile . $errline, $errcontext);
                    break;
            }
            return true;
        }, E_NOTICE | E_WARNING | E_STRICT);
        //注册全局中断函数
        register_shutdown_function(function () {
            $error = error_get_last();
            if (ProcessConfig::getType() == 'manage') {
                getmypid() == $this->getPid() && @unlink(BasicsConfig::pid_file());
            }
            if ($error) {
                Log::emergency($error['type'] . ':' . $error['message']);
                exit($error['type']);
            }
            exit();
        });
    }

    /**
     * 注册信号监听
     */
    protected function registerSignal()
    {
        //子进程的退出
        Process::signal(SIGCHLD, function ($sig) {
            while ($ret = Process::wait(false)) {
                if (isset($this->processes[$ret['pid']])) {
                    $this->processes[$ret['pid']]['status'] = 2;
                    Log::info($this->processes[$ret['pid']]['type'] . "子进程：{$ret['pid']}退出",$ret);
                    switch ($this->processes[$ret['pid']]['type']) {
                        case 'worker':
                            $queue = $this->processes[$ret['pid']]['queue'];
                            unset($this->queueProcess[$queue]['worker'][$ret['pid']]);
                            unset($this->processes[$ret['pid']]);
                            $this->queueProcess[$queue]['worker_number']--;
                            if ($this->stop) {
                                empty($this->processes) && exit(0);
                            } else {
                                //通知matser进程释放对应worker进程
                                $this->processes[$this->queueProcess[$queue]['master']]['socket']->send('unsetWorker', ['workerPid' => $ret['pid']]);
                                Log::info("重启worker子进程");
                                $this->createWorkerProcess($queue, 1);
                            }
                            break;
                        case 'master':
                            $queue = $this->processes[$ret['pid']]['queue'];
                            $this->queueProcess[$queue]['master'] = 0;
                            @unlink($this->processes[$ret['pid']]['unixSocketPath']);
                            unset($this->processes[$ret['pid']]);
                            $this->queueProcess[$queue]['master_number']--;
                            if ($this->stop) {
                                empty($this->processes) && exit(0);
                            } else {
                                //通知worker进程释放matster进程
                                foreach ($this->queueProcess[$queue]['worker'] as $pid) {
                                    $this->processes[$pid]['socket']->send('unSetMaster', ['masterPid' => $ret['pid']]);
                                }
                                Log::info("重启master子进程");
                                $this->createMasterProcess($queue);
                            }
                    }
                }
            }
        });
        //停止
        Process::signal(ProcessConfig::SIG_STOP, function () {
            $this->stop();
        });
        //平滑重启worker进程
        Process::signal(ProcessConfig::SIG_RELOAD, function () {
            $this->reload();
        });
        //状态查询信号
        Process::signal(ProcessConfig::SIG_STATUS, function () {
            foreach ($this->processes as $pid => $value) {
                !Process::kill($pid, ProcessConfig::SIG_STATUS) && $this->processes[$pid]['status'] = ProcessConfig::STATUS_ERROR;
            }
            \Swoole\Timer::after(1000,[$this,'outputStatus']);
            //$this->outputStatus();
        });
        //中断信号
        Process::signal(SIGINT, function () {
            if (ProcessConfig::getType() == 'manage') {
                $this->stop();
            }
        });
    }

    /**
     * 创建队列的master监听进程
     * @param string $queue 队列名称
     * @return array
     * @throws \Exception
     */
    protected function createMasterProcess(string $queue)
    {
        $process = new Process(function ($process) use ($queue) {
            (new MasterProcess($process, $queue))->start();
        }, false, SOCK_STREAM, true);
        $pid = $process->start();
        if (!$pid) {
            throw new \Exception('无法创建子进程');
        }
        $processInfo = $this->listenMessage(['status' => ProcessConfig::STATUS_ERROR, 'process' => $process, 'queue' => $queue, 'type' => 'master', 'startTime' => null]);
        $this->processes[$pid] = $processInfo;//正在运行
        $this->queueProcess[$queue]['master'] = $pid;
        return $pid;
    }

    /**
     * 创建队列的消费worker进程
     * @param string $queue 队列名称
     * @param int $number
     * @return array
     * @throws \Exception
     */
    protected function createWorkerProcess(string $queue, int $number = 1)
    {
        $pids = [];
        $masterPid = $this->queueProcess[$queue]['master'];
        for ($i = 0; $i < $number; $i++) {
            $process = new Process(function ($process) use ($queue) {
                (new WorkerProcess($process, $queue))->start();
            }, false, SOCK_STREAM, true);
            $pid = $process->start();
            if (!$pid) {
                throw new \Exception('无法创建子进程');
            }
            //监听worker进程消息
            $processInfo = $this->listenMessage(['status' => ProcessConfig::STATUS_ERROR, 'process' => $process, 'queue' => $queue, 'type' => 'worker', 'startTime' => null]);
            $this->processes[$pid] = $processInfo;
            $this->queueProcess[$queue]['worker'][$pid] = $pid;
            $pids[] = $pid;
            //将master进程的unixSocketPath广播给worker进程
            $this->processes[$pid]['socket']->send('setMaster', ['masterPid' => $masterPid, 'unixSocketPath' => $this->processes[$masterPid]['unixSocketPath']]);
        }
        return $pids;
    }


    /**
     * 监听子进程的消息
     * @param $processInfo
     */
    protected function listenMessage($processInfo)
    {
        $processInfo['socket'] = new ManageProcessClient($processInfo['process']->exportSocket(), $this);
        \Swoole\Event::add($processInfo['process']->pipe, function () use ($processInfo) {
            $processInfo['socket']->recvAndExec();
        });
        return $processInfo;
    }


    /**
     * 监听启动完成状态
     */
    protected function overMonitor()
    {
        //定时监听启动完成
        Timer::tick(500, function ($timer) {
            foreach ($this->queueProcess as $key => $value) {
                if ($value['master_number'] < $value['need_master'] || $value['worker_number'] < $value['need_worker']) {
                    return;
                }
                Timer::clear($timer);
                Log::info($key . '启动成功');
                if ($this->daemon) {
                    OutPut::normal($key . "启动成功...\n");
                }
            }
        });
    }


    /**
     * 输出状态信息到文件
     */
    public function outputStatus()
    {
        $processes = [];
        foreach ($this->queueProcess as $queue => $queueProcess) {
            if ($queueProcess['master']) {
                $processes[] = [
                    'queue' => $queue,
                    'status' => $this->processes[$queueProcess['master']]['status'],
                    'pid' => $queueProcess['master'],
                    'type' => 'master',
                    'startTime' => $this->processes[$queueProcess['master']]['startTime'],
                ];
                foreach ($queueProcess['worker'] as $pid) {
                    $processes[] = [
                        'queue' => $queue,
                        'status' => $this->processes[$pid]['status'],
                        'pid' => $pid,
                        'type' => 'worker',
                        'startTime' => $this->processes[$pid]['startTime'],
                    ];
                }
            }
        }
        file_put_contents($this->getStatusFile(), json_encode($processes));
    }

    /**
     * 格式化输出状态信息
     * @param array $processes 进程状态信息
     */
    public function outPutStatusInfo(array $processes)
    {
        OutPut::normal("------队列------类型---------pid--------状态---------启动时间---------运行时间------\n");
        $time = time();
        foreach ($processes as $value) {
            OutPut::normal('   ');
            OutPut::normal($value['queue'], 10);
            OutPut::normal($value['type'], 10);
            OutPut::normal($value['pid'], 14);
            switch ($value['status']) {
                case ProcessConfig::STATUS_ERROR:
                    OutPut::error(ProcessConfig::getStatusLang($value['status']), 10);
                    break;
                case ProcessConfig::STATUS_BUSY:
                    OutPut::warning(ProcessConfig::getStatusLang($value['status']), 10);
                    break;
                case ProcessConfig::STATUS_IDLE:
                    OutPut::normal(ProcessConfig::getStatusLang($value['status']), 10);
                    break;
            }
            OutPut::normal($value['startTime'] ? date('ymd H:i:s', $value['startTime']) : '--', 20);
            OutPut::normal($value['startTime'] ? Helper::humanSeconds($time - $value['startTime']) : '--', 14);
            OutPut::normal("   " . PHP_EOL);
        }
    }


    /**
     * master进程建立成功
     * @param $queue 队列名称
     * @param $unixSocketPath socket通信路径
     * @param $pid master进程id
     * @throws \Exception
     */
    public function masterOver($queue, $unixSocketPath, $pid)
    {
        Log::info("$queue:master进程:{$pid}创建完成");
        $this->queueProcess[$queue]['master_number']++;
        $this->processes[$pid]['unixSocketPath'] = $unixSocketPath;
        if ($this->queueProcess[$queue]['master'] == $pid) {
            //让老worker进程添加新的master
            foreach ($this->queueProcess[$queue]['worker'] as $workerPid) {
                $this->processes[$workerPid]['socket']->send('setMaster', ['masterPid' => $pid, 'unixSocketPath' => $unixSocketPath]);
            }
            //创建新worker进程
            $this->createWorkerProcess($queue, $this->queueProcess[$queue]['need_worker'] - count($this->queueProcess[$queue]['worker']));
        }
    }

    /**
     * 工作进程建立成功
     * @param $queue
     * @param $workerPid
     */
    public function workerOver($queue, $workerPid)
    {
        $this->queueProcess[$queue]['worker_number']++;
        Log::info("$queue:worker进程:{$workerPid}创建完成");
    }

    /**
     * 设置进程工作状态
     * @param $pid 子进程id
     * @param $status 子进程状态
     * @param $startTime 子进程运行时间
     */
    public function setProcessStatus($pid, $status, $startTime)
    {
        if (isset($this->processes[$pid])) {
            $this->processes[$pid]['status'] = $status;
            $this->processes[$pid]['startTime'] = $startTime;
        }
    }


}
