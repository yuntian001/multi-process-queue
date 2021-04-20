<?php

namespace MPQueue\Client\Process;

use MPQueue\Process\ManageProcess;

/**
 * 管理消息进程客户端(管理进程和master进程及woker进程通信客户端)
 * Class ManageClient
 * @package MPQueue\Socket
 */
class ManageProcessClient extends Client
{
    public function __construct(\Swoole\Coroutine\Socket $socket, ManageProcess $process)
    {
        parent::__construct($socket);
        $this->process = $process;
    }


}