<?php
use MPQueue\Config\Config;
use MPQueue\Queue\Queue;
require_once __DIR__ . '/../../vendor/autoload.php';
Config::set(include(__DIR__ . '/../Config.php'));

Queue::push('test', \MPQueueTest\Job\TimeoutJob::class);