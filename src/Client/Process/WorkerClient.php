<?php

namespace MPQueue\Client\Process;

use MPQueue\Client\ClientInterface;
use MPQueue\Process\WorkerProcess;

/**
 * worker消息客户端(worker和管理进程通信的客户端)
 * Class WorkerClient
 * @package MPQueue\Socket
 */
class WorkerClient extends Client
{

    public function __construct(\Swoole\Coroutine\Socket $socket, WorkerProcess $process)
    {
        parent::__construct($socket);
        $this->process = $process;
    }


}