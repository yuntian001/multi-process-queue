<?php

namespace MPQueue\Server;


use MPQueue\Config\BasicsConfig;
use MPQueue\Config\ProcessConfig;
use MPQueue\Process\MasterProcess;
use MPQueue\Process\Message;

/**
 * master进程unixSocket服务端（用于监听worker进程消息）
 * Class MasterServer
 * @package MPQueue\Server
 */
class MasterServer
{
    private $server;

    private $process;

    private $socketPath;

    public function __construct(MasterProcess $process)
    {
        $this->process = $process;
        $this->socketPath = ProcessConfig::unixSocketPath().'/'.$process->getPid().'.sock';
        $this->server = new \Swoole\Coroutine\Server('unix:'.$this->socketPath);
        $this->server->set(Message::protocolOptions());
    }

    public function getSocketPath(){
        return $this->socketPath;
    }

    /**
     * 发送消息
     * @param $type
     * @param null $data
     * @param string $msg
     * @return mixed
     */
    public function send(string $type, $data = null, string $msg = '')
    {
        return $this->server->send((new Message($type, $data, $msg))->serialize());
    }

    /**
     * 设置连接后的回调
     * @param callable $callBack
     */
    public function handle(callable $callBack){
        $this->server->handle(function (\Swoole\Coroutine\Server\Connection $conn)use($callBack){
            call_user_func_array($callBack,[new MasterConnection($conn,$this->process)]);
        });
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->server, $name], $arguments);
    }


}