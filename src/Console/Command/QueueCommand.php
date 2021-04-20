<?php

namespace MPQueue\Console\Command;

use MPQueue\Config\ProcessConfig;
use MPQueue\Config\QueueConfig;
use MPQueue\Console\BaseCommand;
use MPQueue\OutPut\OutPut;
use MPQueue\Queue\Queue;

class QueueCommand extends BaseCommand
{
    static protected $signature=[
      'queue:clean'=>'clean',
      'queue:status'=>'status',
      'queue:failed'=>'failedList',
    ];

    static protected $description = [
        'queue:clean'=>'清空队列内容 --queue test 清空指定队列:test',
        'queue:status'=>'查看队列信息 --queue test 指定队列:test',
        'queue:failed'=>'打印失败信息详情 必须指定队列 --queue test 指定队列:test'
    ];

    /**
     * 清空队列
     */
    public function clean(){
        $this->cmd->option('queue')->describedAs('设置对应队列');
        if($this->cmd['queue']){
            OutPut::info('清空队列：'.$this->cmd['queue'].PHP_EOL);
            \MPQueue\Queue\Queue::cleanJob($this->cmd['queue']);
        }else{
            foreach (QueueConfig::queues() as $key=>$value){
                OutPut::info('清空队列：'.$key.PHP_EOL);
                \MPQueue\Queue\Queue::cleanJob($key);
            }
        }
        return true;
    }

    /**
     * 获取队列信息
     */
    public function status(){
        $this->cmd->option('queue')->describedAs('设置对应队列');
        if($this->cmd['queue']){
           $queues = [$this->cmd['queue']=>''];
        }else{
            $queues = QueueConfig::queues();
        }
        OutPut::normal("------队列------总数量------待执行------执行中------已失败------已完成------".PHP_EOL);
        foreach ($queues as $key=>$value){
            OutPut::normal('   ');
            OutPut::normal($key, 10);
            OutPut::normal(Queue::getCount($key,'all'),12);
            OutPut::normal(Queue::getCount($key,'waiting'),12);
            OutPut::normal(Queue::getCount($key,'working'),12);
            OutPut::normal(Queue::getCount($key,'failed'),12);
            OutPut::normal(Queue::getCount($key,'over'),12);
            OutPut::normal("   ".PHP_EOL);
        }
    }

    public function failedList(){
        $this->cmd->option('queue')->require(true)->describedAs('设置对应队列');
        var_dump(\MPQueue\Queue\Queue::failedList($this->cmd['queue']));
    }

}