<?php


namespace MPQueue\Serialize;


class JsonSerialize implements SerializeInterface
{

    public static function serialize($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public static function unSerialize(string $string)
    {
        return json_decode($string, true);
    }
}