<?php


namespace MPQueue\Library\Traits;


use MPQueue\Exception\ConfigException;

trait Config
{
    private static $is_set = false;

    public function __call($name, $arg)
    {
        return $this->{$name};
    }

    public static function __callStatic($name, $arg)
    {
        return self::${$name};
    }

    protected static function checkSet($name = '')
    {
        if ($name) {
            if (!is_null(self::$$name)) {
                throw new ConfigException('只能初始化一次配置');
            }
        } else {
            if (self::$is_set) {
                throw new ConfigException('只能初始化一次配置');
            }
            self::$is_set = true;
        }

    }

}