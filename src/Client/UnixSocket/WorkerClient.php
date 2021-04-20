<?php

namespace MPQueue\Client\UnixSocket;

use MPQueue\Process\WorkerProcess;

class WorkerClient extends Client
{

    public function __construct(string $unixSocketPath, WorkerProcess $process)
    {
        parent::__construct($unixSocketPath);
        $this->process = $process;
    }

}