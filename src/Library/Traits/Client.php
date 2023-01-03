<?php

namespace MPQueue\Library\Traits;

use MPQueue\Exception\ClientException;
use MPQueue\Exception\MessageException;
use MPQueue\Library\Helper;
use MPQueue\Log\Log;
use MPQueue\Process\Message;
use MPQueue\Library\Coroutine\Lock;

trait Client
{
    protected $socket = null;
    protected $lock = null;

    /**
     * 获取当前客户端协程锁
     * @return Lock
     */
    public function getLock(): Lock
    {
        if (!$this->lock) {
            $this->lock = new Lock();
        }
        return $this->lock;
    }

    /**
     * 返回 Client 的连接状态
     * @return mixed
     */
    public function isConnected(): bool
    {
        return $this->socket->checkLiveness();
    }

    /**
     * 导出当前\Swoole\Coroutine\Socket对象
     * @return mixed|\Swoole\Coroutine\Socket|null
     */
    public function exportSocket(): \Swoole\Coroutine\Socket
    {
        return $this->socket;
    }

    /**
     * 发送消息
     * 尽可能完整地发送数据，直到成功发送全部数据或遇到错误中止。
     * 当 send 系统调用返回错误 EAGAIN 时，底层将自动监听可写事件，并挂起当前协程。
     * @param $type
     * @param null $data
     * @param string $msg
     * @return mixed
     */
    public function send(string $type, $data = null, string $msg = ''): bool
    {
        $str = (new Message($type, $data, $msg))->serialize();
        //发送数据时加协程锁，防止缓冲区写满后协程挂起send冲突
        if(\Swoole\Coroutine::getCid() != -1){
            $this->getLock()->lock();
            $len = $this->socket->sendAll($str);
            $this->getLock()->unLock();
        }else{
            $len = $this->socket->sendAll($str);
        }
        return strlen($str) === $len;
    }

    /**
     * 接收完整的消息包并返回对应消息对象
     * @param float $timeout
     * @return Message|null
     * @throws \MPQueue\Exception\MessageException
     */
    public function recv(float $timeout = -1)
    {
        $data = $this->socket->recvPacket($timeout);
        //发生错误或对端关闭连接，本端也需要关闭
        if (!$data) {
            // 可以自行根据业务逻辑和错误码进行处理，例如：
            // 如果超时时则不关闭连接，其他情况直接关闭连接
            if ($this->socket->errCode !== SOCKET_ETIMEDOUT) {
                $this->close();
                throw new ClientException('连接已关闭:'.$this->socket->errCode, SOCKET_ECONNREFUSED);
            }
            return $data;
        }
        return Message::unSerialize($data);
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
            if($message){
                Log::debug('收到消息',$message->toArray());
                $data = $message->data();
                is_null($data) && $data = [];
                $params = is_array($data) ? $data : [$data];
                $params['pid'] = $message->pid();
                return call_user_func_array([$this->process, $message->type()], Helper::getMethodParams($params,$this->process,$message->type()));
            }
        }catch (MessageException $e){
            Log::error('消息解析失败：'.$e->getCode().'|'.$e->getMessage(),$e->getTrace());
        }catch (\Throwable $e){
            if(method_exists($this->process,'exceptionHandler')){
                $this->process->exceptionHandler($e);
            }else{
                throw $e;
            }
        }
        return false;
    }

    /**
     * 关闭连接
     * @return mixed
     */
    public function close(): bool
    {
        return $this->socket->close();
    }

    /**
     * peek 方法仅用于窥视内核 socket 缓存区中的数据，不进行偏移。使用 peek 之后，再调用 recv 仍然可以读取到这部分数据
     * peek 方法是非阻塞的，它会立即返回。当 socket 缓存区中有数据时，会返回数据内容。缓存区为空时返回 false，并设置 $client->errCode
     * 连接已被关闭 peek 会返回空字符串
     * @param int $length
     * @return mixed
     */
    public function peek(int $length = 65535)
    {
        return $this->socket->peek($length);
    }

    /**
     * 设置协议参数（处理粘包问题）
     * @return bool
     */
    public function setProtocol(): bool
    {
        return $this->socket->setProtocol(Message::protocolOptions());
    }}
