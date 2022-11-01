<?php


namespace MPQueue\Console;

use MPQueue\OutPut\OutPut;

class Application
{
    protected $commands;

    public function __construct($commandClasses = array())
    {
        foreach (scandir(__DIR__.'/Command') as $value){
            if($value != '.' && $value !='..'){
                $commandClasses[] = '\MPQueue\Console\Command\\'.str_replace('.php','',$value);
            }
        }
        foreach ($commandClasses as $value){
            foreach ($value::signature() as $k=>$v){
                $this->commands[$k] = ['class'=>$value,'function'=>$v,'description'=>isset($value::description()[$k])?$value::description()[$k]:''];
            }
        }
    }

    public function run(){
        global $argv;
        if(!isset($argv[1])){
           return OutPut::error('请输入指令help查看指令列表'.PHP_EOL);
        }
        $action = $argv[1];
        switch ($action){
            case 'list':
                foreach ($this->commands as $key=>$value){
                    OutPut::normal($key.' '.$value['description'].PHP_EOL);
                }
                return;
            case 'help':
                foreach ($this->commands as $key=>$value){
                    OutPut::normal($key.' '.$value['description'].PHP_EOL);
                }
                return;
            default:
                if(isset($this->commands[$action])){
                    return call_user_func([new $this->commands[$action]['class'],$this->commands[$action]['function']]);
                }
                break;
        }
        OutPut::error('错误的指令,请输入指令help查看指令列表'.PHP_EOL);
    }

}