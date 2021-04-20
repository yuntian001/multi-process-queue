<?php

namespace MPQueue\Client\Process;

use MPQueue\Process\MasterProcess;

/**
 * master消息进程客户端(master进程和管理端进程通信客户端)
 * Class MasterClient
 * @package MPQueue\Socket
 */
class MasterProcessClient extends Client
{
    public function __construct(\Swoole\Coroutine\Socket $socket, MasterProcess $process)
    {
        parent::__construct($socket);
        $this->process = $process;
    }


}