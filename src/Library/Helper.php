<?php
namespace MPQueue\Library;

use ReflectionMethod;

/**
 * 工具函数类
 * Class Helper
 * @package MPQueue\Library
 */
class Helper
{

    /**
     * 返回对应方法的可用参数
     * @param array $params 传入的参数的关联数组
     * @param string|object $objectOrMethod Classname, object
     * (instance of the class) that contains the method or class name and
     * method name delimited by ::.
     * @param string|null $method Name of the method if the first argument is a
     * classname or an object.
     * @return array
     * @throws \ArgumentCountError|\ReflectionException
     */
    static function getMethodParams(array $params,$objectOrMethod,$method = null): array
    {
        $newParams = [];
        $reflectionMethod = new ReflectionMethod($objectOrMethod,$method);
        foreach ($reflectionMethod->getParameters() as $reflectionParameter){
            if(array_key_exists($reflectionParameter->getName(),$params)){
                $newParams[] = $params[$reflectionParameter->getName()];
            }elseif ($reflectionParameter->isDefaultValueAvailable()){
                $newParams[] = $reflectionParameter->getDefaultValue();
            }else{
                throw new \ArgumentCountError("缺少必要参数：params:".json_encode($params,JSON_UNESCAPED_UNICODE));
            }
        }
        return $newParams;
    }

    /**
     * 将秒数转换为天、小时、分、秒
     * @param int $seconds
     * @return string
     */
    static function humanSeconds(int $seconds){
        $day = $seconds > 86400 ? floor($seconds / 86400) : 0;
        $seconds -= $day * 86400;
        $hour = $seconds > 3600 ? floor($seconds / 3600) : 0;
        $seconds -= $hour * 3600;
        $minute = $seconds > 60 ? floor($seconds / 60) : 0;
        $seconds -= $minute * 60;
        $second = $seconds;
        $dayText = $day ? $day . '天' : '';
        $hourText = $hour ? $hour . '小时' : '';
        $minuteText = $minute ? $minute . '分钟' : '';
        return $dayText . $hourText . $minuteText . $second . '秒';
    }

}