<?php

namespace MPQueue\Client;

interface ClientInterface
{

    /**
     * 返回 Client 的连接状态
     * @return mixed
     */
    public function isConnected(): bool;

    /**
     * 发送消息
     * @param string $type 消息类型
     * @param null $data 消息数据
     * @param string $msg 消息说明信息
     * @return mixed
     */
    public function send(string $type, $data = null, string $msg = '');

    /**
     * 接收消息
     * @param float $timeout 超时时间（秒）
     * @return mixed
     */
    public function recv(float $timeout = -1);

    /**
     * 接收并执行消息
     * @param float $timeout 接收消息超时时间（秒）
     * @return mixed
     */
    public function recvAndExec(float $timeout = -1);

    /**
     * 关闭连接
     * @return mixed
     */
    public function close(): bool;


    /**
     * peek 方法仅用于窥视内核 socket 缓存区中的数据，不进行偏移。使用 peek 之后，再调用 recv 仍然可以读取到这部分数据
     * peek 方法是非阻塞的，它会立即返回。当 socket 缓存区中有数据时，会返回数据内容。缓存区为空时返回 false，并设置 $client->errCode
     * 连接已被关闭 peek 会返回空字符串
     * @param int $length
     * @return mixed
     */
    public function peek(int $length = 65535);

    /**
     * 导出当前客户端socket对象
     * @return mixed
     */
    public function exportSocket();

    /**
     * 设置当前客户端协议（防止粘包）
     * @return bool
     */
    public function setProtocol(): bool;


}