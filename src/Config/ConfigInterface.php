<?php


namespace MPQueue\Config;


interface ConfigInterface
{
    /**
     * 静态获取配置变量
     * @param $name
     * @param $arg
     * @return mixed
     */
    public static function __callStatic($name,$arg);
}