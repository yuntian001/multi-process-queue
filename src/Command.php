<?php
namespace MPQueue;


use MPQueue\OutPut\OutPut;
use MPQueue\Process\ManageProcess;

class Command
{

    /**
     * 开始运行
     */
    public function run()
    {
        global $argv;
        $action = isset($argv[1]) ? $argv[1] : 'help';
        if (isset($argv[2]) && $argv[2] == '-d') {
            $this->setDaemon();
        }
        if(isset($this->commands[$action])){
            $this->{$this->commands[$action]['action']}();
        }else{
            OutPut::normal('请输入正确的指令,help查看帮助信息');
        }
    }

    public function start(){

    }

    public function stop(){
        (new ManageProcess())->stop();
    }

    public function restart(){
        (new ManageProcess())->restart();
    }

    public function reload(){
        (new ManageProcess())->reload();
    }

    public function status(){
        (new ManageProcess())->status();
    }
}