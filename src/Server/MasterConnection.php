<?php


namespace MPQueue\Server;


use MPQueue\Exception\ClientException;
use MPQueue\Exception\MessageException;
use MPQueue\Library\Helper;
use MPQueue\Library\Traits\Client;
use MPQueue\Log\Log;
use MPQueue\Process\MasterProcess;
use MPQueue\Process\Message;

/**
 * master进程与worker进程之间的Connection连接类
 * Class MasterConnection
 * @package MPQueue\Server
 */
class MasterConnection
{
    use Client;
    protected $process;
    protected $connection;
    protected $socket;
    public $pid;//当前连接对应pid

    /**
     * MasterConnection constructor.
     * @param \Swoole\Coroutine\Server\Connection $connection worker Connection进程连接
     * @param MasterProcess $process
     */
    public function __construct(\Swoole\Coroutine\Server\Connection $connection, MasterProcess $process){
        $this->connection = $connection;
        $this->process = $process;
        $this->socket = $connection->exportSocket();
    }


    /**
     * 接收消息并处理
     * @param float $timeout 超时时间
     * @return mixed
     * @throws \MPQueue\Exception\MessageException
     */
    public function recvAndExec(float $timeout = -1)
    {
        try {
            $message = $this->recv($timeout);
            if($message) {
                Log::debug('收到消息', $message->toArray());
                $data = $message->data();
                is_null($data) && $data = [];
                $params = is_array($data) ? $data : [$data];
                $params['pid'] = $message->pid();
                $params['connection'] = $this;
                return call_user_func_array([$this->process, $message->type()], Helper::getMethodParams($params, $this->process, $message->type()));
            }
        }catch (MessageException $e){
            Log::error('消息解析失败：'.$e->getCode().'|'.$e->getMessage(),$e->getTrace());
        }
        return false;
    }


    /**
     * 导出对应的 \Swoole\Coroutine\Server\Connection
     * @return \Swoole\Coroutine\Server\Connection
     */
    public function exportConnection(){
        return $this->connection;
    }
}