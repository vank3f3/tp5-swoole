<?php
/**
 * 参考think-swoole2.0开发
 */
if (!defined("APP_PATH")){
    define('APP_PATH', __DIR__ . '/../application/');
}


// 注册命令行指令
\think\Console::addDefaultCommands([
    'swoole:http'       => '\\william\\swoole\\command\\Http',
    'swoole:socket'     => '\\william\\swoole\\command\\Socket',
]);
