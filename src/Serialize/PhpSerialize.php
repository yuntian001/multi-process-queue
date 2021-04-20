<?php

namespace MPQueue\Serialize;

class PhpSerialize implements SerializeInterface
{

    public static function serialize($data): string
    {
        return serialize($data);
    }

    public static function unSerialize(string $string)
    {
        return unserialize($string);
    }
}