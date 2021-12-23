<?php

namespace MPQueue\Queue\Driver;
use MPQueue\Job;

/**
 * Interface DriverInterface
 * 驱动抽象接口类
 */
interface DriverInterface
{
    /**
     * 设置当前操作队列
     * @param $queue
     */
    public function setQueue(String $queue):DriverInterface;

    /**
     * 获取连接
     */
    public function getConnect();


    /**
     * 关闭当前连接实例
     * @return mixed|void
     */
    public function close();

    /**
     * 添加队列任务
     * @param string $job 任务对应信息序列化后的字符串
     * @param int $delay 延时投递时间
     * @param int $timeout 超时时间（s 支持小数 精度到0.001） 0代表不超时
     * @param int $fail_number 最大失败次数
     * @param int $fail_expire 失败重新投递延时
     * @return bool
     */
    public function push(string $job, int $delay = 0, int $timeout = 0, int $fail_number = 0, $fail_expire = 3): bool;


    /**
     * 从等待队列中弹出一个可执行任务
     * @param $number
     * @return array
     */
    public function popJob($number);

    /**
     * 移动过期任务到等待队列
     * @param int $number 移除的数量 数量越大redis阻塞时间越长
     * @return mixed
     */
    public function moveExpired($number = 50);


    /**
     * 移动超时分配任务到等待队列
     * @param int $number
     */
    public function moveExpiredReserve($number = 50);

    /**
     * 移动超时重试任务到等待队列
     * @param int $number
     * @return mixed
     */
    public function moveExpiredRetry($number = 50);


    /**
     * 移动执行超时任务到等待队列
     */
    public function moveTimeoutJob(int $number = 50);

    /**
     * 开始执行任务，在等待队列移除任务并设置执行信息
     * @param $id
     * @return mixed
     */
    public function reReserve($id);


    /**
     * 删除执行成功的任务
     * @param $id
     * @return mixed
     */
    public function remove($id);

    /**
     * 在详情中追加错误信息
     * @param int $id
     * @param string $error
     * @return mixed|string
     */
    public function setErrorInfo(int $id, string $error);

    /**
     * 重新发布一遍执行失败的任务
     * @param int $id
     * @param int $delay 重试延时时间（s 支持小数 精度到0.001）
     * @return mixed|string
     */
    public function retry(int $id, int $delay = 0);

    /**
     * 记录失败任务（已达到失败重试次数失败）
     * @param int $id
     * @param string $info 任务集合信息
     */
    public function failed(int $id,string $info);

    /**
     * 设置执行中任务的执行超时时间
     * @param $id
     * @param int $timeout 0代表不超时（s 支持小数 精度到0.001）
     * @return int Number of values added
     */
    public function setWorkingTimeout($id, $timeout = 0):int;

    /**
     * 删除失败任务信息
     * @param $id
     * @return int 0删除失败 1成功
     */
    public function removeFailedJob($id):int;


    /**
     * 获取任务数量
     * @param string $type
     * all 全部
     * waiting 等待执行 包括已投递未分配，已分配未执行，延时投递，失败重试
     * working 执行中
     * failed  失败
     * over    已完成
     * @return int
     */
    public function getCount($type = 'all'):int;


    /**
     * 获取所有失败任务列表失败任务过多时会阻塞
     * @return array
     */
    public function failedList():array;


    /**
     * 清空队列所有信息
     */
    public function clean();

}