<?php
use MPQueue\Config\Config;
use MPQueue\Queue\Queue;
require_once __DIR__ . '/../vendor/autoload.php';
Config::set(include(__DIR__ . '/Config.php'));
echo "use " . microtime(true). "s\n";
for($i=1;$i<5000;$i++){
    Queue::push('test',  function (){
        echo(microtime(true)."\n");
    });
}
echo "use " . microtime(true). "s\n";