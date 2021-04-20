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
        SerializableClosure::enterContext();
        SerializableClosure::wrapClosures($data);
        $data = \serialize($data);
        SerializableClosure::exitContext();
        return $data;
    }

    /**
     * 反序列化数据
     * @param string $string
     * @return mixed
     */
    public static function unSerialize(string $string, array $options = null){
        SerializableClosure::enterContext();
        $data = ($options === null || \PHP_MAJOR_VERSION < 7)
            ? \unserialize($string)
            : \unserialize($string, $options);
        SerializableClosure::unwrapClosures($data);
        SerializableClosure::exitContext();
        return $data;
    }
}