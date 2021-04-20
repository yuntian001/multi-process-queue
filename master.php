<?php

use MPQueue\Config\Config;

require_once __DIR__.'/vendor/autoload.php';
Config::set(include(__DIR__.'/src/Config.php'));
(new \MPQueue\Console\Application())->run();
