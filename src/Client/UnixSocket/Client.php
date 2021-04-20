<?php

namespace MPQueue\Client\UnixSocket;

use MPQueue\Client\ClientInterface;
use MPQueue\Exception\ClientException;
use Swoole\Coroutine\Socket;

abstract class Client implements ClientInterface
{
    use \MPQueue\Library\Traits\Client;

    protected $socket;
    protected $process;
    protected $unixSocketPath;

    public function __construct(string $unixSocketPath)
    {
        $this->unixSocketPath = $unixSocketPath;
        $this->socket = new Socket(AF_UNIX, SOCK_STREAM);
        if (!$this->socket->connect($unixSocketPath)) {
            throw new ClientException('连接失败', $this->socket->errCode);
        }
        $this->setProtocol();
    }

    public function getUnixSocketPath(){
        return $this->unixSocketPath;
    }
}