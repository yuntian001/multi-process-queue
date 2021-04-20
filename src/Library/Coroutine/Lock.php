<?php
namespace MPQueue\Library\Coroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * 协程锁
 * 允许同一协程多次加锁
 * lock和unlock必须成对存在 否则会死锁
 * Class Lock
 * @package MPQueue\Library\Coroutine
 */
class Lock
{
    protected $cid = null;//协程cid
    protected $waitGroup;

    public function __construct(){
        $this->waitGroup = new WaitGroup();
    }

    /**
     * 锁等待并加锁
     * @return bool
     * @throws \Exception
     */
    public function lock(){
        $cid = Coroutine::getCid();
        if($cid == -1){
            throw new \Exception('请在协程环境中使用');
        }
        if($this->cid && $this->cid != $cid){
            $this->waitGroup->wait();
        }
        $this->cid = $cid;
        $this->waitGroup->add();
        return true;
    }

    /**
     * 解锁
     * @return bool
     * @throws \Exception
     */
    public function unLock(){
        if(!$this->cid){
            return true;
        }
        $cid = Coroutine::getCid();
        if($cid == -1){
            throw new \Exception('请在协程环境中使用');
        }
        if($this->cid != $cid){
            return false;
        }
        $this->cid = null;
        $this->waitGroup->done();
        return true;
    }

    /**
     * 进行锁等待
     * @return bool
     * @throws \Exception
     */
    public function wait(){
        $cid = Coroutine::getCid();
        if($cid == -1){
            throw new \Exception('请在协程环境中使用');
        }
        if($this->cid == $cid || !$this->cid){
            return true;
        }
        return $this->waitGroup->wait();
    }

}