<?php
pcntl_async_signals(true);

// 为 SIGINT 信号注册信号处理函数
pcntl_signal(SIGINT, function(){
    echo "捕获到了 SIGINT 信号" . PHP_EOL;
});


    $servsock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($servsock, '127.0.0.1', 8888);
    socket_listen($servsock, 1024);

    while(1)
    {
        $connsock = socket_accept($servsock); //如果没有客户端过来连接，这里将一直阻塞
        if ($connsock)
        {
            echo "客户端连接服务器: $connsock\n";
        }
    }
