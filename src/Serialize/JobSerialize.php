<?php
namespace MPQueue\Serialize;
use Opis\Closure\SerializableClosure;

/**
 * 任务序列化类
 * Class JobSerialize
 * @package MPQueue\Serialize
 */
class JobSerialize implements SerializeInterface
{

    /**
     * 序列化数据并返回对应字符串
     * @param $data
     * @return string
     */
    public static function serialize($data): string{
        return \serialize(is_callable($data)?(new SerializableClosure($data)):$data);
    }

    /**
     * 反序列化数据
     * @param string $string
     * @return mixed
     */
    public static function unSerialize(string $string){
        $data = \unserialize($string);
        if(is_string($data)){
            return new $data();
        }
        if($data instanceof SerializableClosure){
            return $data->getClosure();
        }
        return $data;
    }
}