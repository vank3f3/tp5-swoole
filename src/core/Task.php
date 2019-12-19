<?php


namespace william\swoole\core;

use think\Exception;

/**
 * 异步task任务投递
 * Class Task
 * @package xavier\swoole
 * Author William
 * Time 2019/12/19 15:28
 */

class Task
{
    public static function async($task,$finishCallback = null,$taskWorkerId = -1)
    {
        if($task instanceof \Closure){
            try{
                $task = new SuperClosure($task);
            }catch (\Throwable $throwable){
                \think\Hook::listen('swoole_task_init_err');
                //Trigger::throwable($throwable);
                echo $throwable->getMessage();
                exit();
                return false;
            }
        }

        Application::getSwoole()->task($task,$taskWorkerId,$finishCallback);
    }
}