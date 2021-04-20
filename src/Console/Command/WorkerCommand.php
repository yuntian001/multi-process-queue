<?php


namespace MPQueue\Console\Command;

use MPQueue\Config\BasicsConfig;
use MPQueue\Config\LogConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\Console\BaseCommand;
use MPQueue\Log\Log;
use MPQueue\OutPut\OutPut;
use MPQueue\Process\ManageProcess;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;

class WorkerCommand extends BaseCommand
{

    private $manage;
    private $wait = false;

    static protected $signature=[
        'worker:start'=>'start',
        'worker:stop'=>'stop',
        'worker:restart'=>'restart',
        'worker:reload'=>'reload',
        'worker:status'=>'status',

    ];

    static protected $description = [
        'worker:start'=>'启动 携带参数-d 后台启动',
        'worker:stop'=>'停止',
        'worker:restart'=>'重启 携带参数-d 后台启动',
        'worker:reload'=>'平滑重启',
        'worker:status'=>'查看进程运行状态',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->manage = new ManageProcess();
    }

    /**
     * 启动
     */
    public function start()
    {
        $this->cmd->option('d')->boolean()->describedAs('携带此参数代表以守护进程模式启动');
        if ($this->manage->getPid()) {
            Log::error('已启动不可重复启动');
            exit();
        }
        if($this->cmd['d']){
            OutPut::normal("以守护进程方式启动,请去日志中查询详细信息,日志目录地址:" . realpath(LogConfig::path()) . "\n");
            Process::daemon();
            ProcessConfig::setDaemon(true,false);
        }
        Log::info('正在启动中,请稍后....');
        $this->manage->run();
        $this->wait();
    }

    /**
     * 重启
     * @throws \Exception
     */
    public function restart()
    {
       $this->stop();
       $this->start();
    }


    /**
     * 获取进程状态信息
     * @param $timer
     */
    public function status()
    {
        OutPut::normal("正在查询mpQueue运行状态...\n");
        $pid = $this->manage->getPid();
        if (!$pid) {
            OutPut::normal("队列未启动\n");
            exit();
        }
        @unlink($this->manage->getStatusFile());
        Process::kill($pid, ProcessConfig::SIG_STATUS);
        Timer::tick(1600, function ($timer) {
            if (file_exists($this->manage->getStatusFile())) {
                Timer::clear($timer);
                $processes = json_decode(file_get_contents($this->manage->getStatusFile()), true);
                $this->manage->outPutStatusInfo($processes);
                @unlink($this->manage->getStatusFile());
            }
        });
        $this->wait();
    }

    /**
     * 停止
     * @param false $is_start
     * @throws \Exception
     */
    public function stop()
    {
        $pid = $this->manage->getPid();
        if($pid){
            OutPut::normal("正在停止....\n");
            Process::kill($pid, ProcessConfig::SIG_STOP);
            while (true){
                usleep(500000);
                if (!$this->manage->getPid()) {
                    OutPut::normal("已停止...\n");
                    return true;
                }
            }
        }
        OutPut::normal('队列未启动无需停止'.PHP_EOL);
        return ;
    }

    /**
     * 平滑重启
     */
    public function reload(){
        $pid = $this->manage->getPid();
        if (!$pid) {
            OutPut::normal('队列未启动无需平滑重启'.PHP_EOL);
            exit();
        }
        Process::kill($pid, ProcessConfig::SIG_RELOAD);
        OutPut::normal("平滑重启信号发送成功");
    }

    /**
     *
     */
    private function wait()
    {
        if (!$this->wait) {
            $this->wait = true;
            Event::wait();
        }
    }

}