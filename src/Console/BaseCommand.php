<?php
namespace MPQueue\Console;

class BaseCommand
{
    protected $cmd;

    static protected $signature = [];
    static protected $description = [];

    public function __construct()
    {
        $this->cmd = new \Commando\Command();
    }

    public static function signature(){
        return static::$signature;
    }

    public static function description(){
        return static::$description;
    }

}