<?php


namespace william\swoole\command;


use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class Http extends Base
{

    protected function configure($commandName = 'swoole:http',$description = 'Swoole HTTP Server for ThinkPHP')
    {
        parent::configure($commandName,$description);
    }

    protected function init($configFile = 'swoole',$serverType = 'swoole')
    {
        parent::init($configFile, $serverType);
    }

    protected function start($server = 'william\swoole\core\Http')
    {
        return parent::start($server);
    }


}