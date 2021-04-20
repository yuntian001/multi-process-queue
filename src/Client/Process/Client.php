<?php

namespace MPQueue\Client\Process;

use MPQueue\Client\ClientInterface;

/**
 * 进程自建UNIXSocket客户端基类（基于协程客户端必须在协程中使用）
 * Class Client
 * @package MPQueue\Socket
 */
abstract class Client implements ClientInterface
{
    use \MPQueue\Library\Traits\Client;

    protected $socket = null;
    protected $process;

    public function __construct(\Swoole\Coroutine\Socket $socket)
    {
        $this->socket = $socket;
        $this->setProtocol();
    }

}