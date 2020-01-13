<?php

namespace william\swoole\command;

use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * Soole socket启动
 * Class Socket
 * @package xavier\swoole\command
 * Author William
 * Time 2019/12/20 14:49
 */

class Socket extends Base
{
    protected function configure($commandName = 'swoole:socket',$description = 'Swoole Socket Server for ThinkPHP')
    {
        parent::configure($commandName,$description);
    }

    protected function execute(Input $input, Output $output)
    {
        parent::execute($input, $output);
    }

    protected function init($configFile = 'swooleSocket',$serverType = 'swooleSocket')
    {
        parent::init($configFile,$serverType);
    }

    protected function start($server = 'william\swoole\core\WebSocket')
    {
        return parent::start($server);
    }
}