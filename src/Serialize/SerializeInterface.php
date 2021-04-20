<?php


namespace MPQueue\Serialize;


interface SerializeInterface
{
    /**
     * 序列化数据并返回对应字符串
     * @param $data
     * @return string
     */
    public static function serialize($data): string;

    /**
     * 反序列化数据
     * @param string $string
     * @return mixed
     */
    public static function unSerialize(string $string);
}