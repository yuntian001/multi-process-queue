<?php
define('MP_QUEUE_CLI',true);
use MPQueue\Config\Config;
require_once __DIR__.'/../vendor/autoload.php';
Config::set(include(__DIR__.'/Config.php'));
(new \MPQueue\Console\Application())->run();
