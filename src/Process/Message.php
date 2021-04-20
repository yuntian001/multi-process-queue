<?php

namespace MPQueue\Process;

use MPQueue\Exception\MessageException;
use \MPQueue\Serialize\JsonSerialize;
use MPQueue\Serialize\PhpSerialize;

/**
 * 进程间通信消息类
 * Class Message
 * @package MPQueue\Socket
 */
class Message
{
    protected $type;

    protected $data;

    protected $msg;

    protected $pid;

    /**
     * Message constructor.
     * @param string $type 消息类型
     * @param null|array $data 消息数据
     * @param string $msg 信息
     * @param null $pid 消息clientPid
     */
    public function __construct(string $type, $data = null, $msg = '', $pid = null)
    {
        $this->type = $type;
        $this->data = $data;
        $this->msg = $msg;
        $this->pid = $pid ?: getmypid();
    }

    public function __call($name, $arg)
    {
        if (isset($this->{$name})) {
            return $this->{$name};
        }
        return null;
    }

    public function serialize(): string
    {
        $str = PhpSerialize::serialize(['type' => $this->type, 'pid' => $this->pid, 'data' => $this->data, 'msg' => $this->msg]);
        return pack('N', strlen($str)) . $str;
    }

    public static function unSerialize(string $string)
    {
//        $pack = unpack('N', substr($string, 0, 4));
//        $len = $pack[1];//客户端所发送数据的长度字节
        $string = substr($string, 4);
//        if ($len != strlen($string)) throw new MessageException('错误的消息格式:' . $string);
        $data = PhpSerialize::unSerialize($string);
        if (is_array($data) && isset($data['type']) && !empty($data['pid'])) {
            return new self($data['type'], isset($data['data']) ? $data['data'] : null, isset($data['msg']) ? $data['msg'] : '', $data['pid']);
        }
        throw new MessageException('错误的消息格式:' . $string);
    }

    /**
     * 获取消息通信协议
     * @return array
     */
    public static function protocolOptions(): array
    {
        return [
            'open_length_check' => true,
            'package_max_length' => 2 * 1024 * 1024,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ];
    }

    /**
     * 获取数组
     * @return array
     */
    public function toArray(){
        return ['type'=>$this->type,'data'=>$this->data,'msg'=>$this->msg,'pid'=>$this->pid];
    }
}