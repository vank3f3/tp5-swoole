<?php
namespace william\swoole\core;

/**
 * Swoole Http Server 命令行服务类
 * Class Http
 * @package william\swoole
 * Author William
 * Time 2019/12/17 21:35
 */

use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;
use Swoole\Table;
use think\Error;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use think\Config;
use think\Cache;
use think\Loader;

class Http extends Server
{
    protected $app;
    protected $appPath;
    protected $table;
    protected $monitor;
    protected $lastMtime;
    protected static $http;
	protected $server_type;
    protected $fieldType = [
        'int'    => Table::TYPE_INT,
        'string' => Table::TYPE_STRING,
        'float'  => Table::TYPE_FLOAT,
    ];

    protected $fieldSize = [
        Table::TYPE_INT    => 4,
        Table::TYPE_STRING => 32,
        Table::TYPE_FLOAT  => 8,
    ];

    /**
     * 构造函数
     */
    public function __construct($host, $port, $mode = SWOOLE_PROCESS, $sockType = SWOOLE_SOCK_TCP)
    {
        $this->server_type = Config::get('swoole.server_type');
        switch ($this->server_type) {
            case 'websocket':
                $this->swoole = new WebSocketServer($host, $port, $mode, SWOOLE_SOCK_TCP);
                break;
            default:
                $this->swoole = new HttpServer($host, $port, $mode, SWOOLE_SOCK_TCP);
        }
        if ("process"==Config::get('swoole.queue_type')){
            $process=new QueueProcess();
            $process->run($this->swoole);
        }
    }

    public function getSwoole()
    {
        return $this->swoole;
    }

    public function setAppPath($path)
    {
        $this->appPath = $path;
    }

    public function setMonitor($interval = 2, $path = [])
    {
        $this->monitor['interval'] = $interval;
        $this->monitor['path']     = (array)$path;
    }

    public function table(array $option)
    {
        $size        = !empty($option['size']) ? $option['size'] : 1024;
        $this->table = new Table($size);

        foreach ($option['column'] as $field => $type) {
            $length = null;

            if (is_array($type)) {
                list($type, $length) = $type;
            }

            if (isset($this->fieldType[$type])) {
                $type = $this->fieldType[$type];
            }

            $this->table->column($field, $type, isset($length) ? $length : $this->fieldSize[$type]);
        }

        $this->table->create();
    }

    public function option(array $option)
    {
        // 设置参数
        if (!empty($option)) {
            $this->swoole->set($option);
        }
        foreach ($this->event as $event) {
            // 自定义回调
            if (!empty($option[$event])) {
                $this->swoole->on($event, $option[$event]);
            } elseif (method_exists($this, 'on' . $event)) {
                $this->swoole->on($event, [$this, 'on' . $event]);
            }
        }
		if ("websocket" == $this->server_type) {
            foreach ($this->event as $event) {
                if (method_exists($this, 'Websocketon' . $event)) {
                    $this->swoole->on($event, [$this, 'Websocketon' . $event]);
                }
            }
        }
    }

    /**
     * 此事件在Worker进程/Task进程启动时发生,这里创建的对象可以在进程生命周期内使用
     * @function onWorkerStart
     * @param $server
     * @param $worker_id
     * Author William
     * Time 2019/12/17 21:29
     */
    public function onWorkerStart($server, $worker_id)
    {
        // 应用实例化
        $this->app = new Application($this->appPath);
        $this->app->setSwoole($this->swoole);
        $this->lastMtime = time();
        \think\Hook::listen('swoole_on_woker_start', $worker_id);
        if ($this->table) {
            $this->app['swoole_table'] = $this->table;
        }
        Loader::addClassMap([
            'think\\cache\\driver\\Table' => __DIR__ . '/cache/driver/Table.php',
        ]);

        $this->initServer($server, $worker_id);

    }

    /**
     * 自定义初始化Swoole
     * @function initServer
     * @param $server
     * @param $worker_id
     * Author William
     * Time 2019/12/17 21:29
     */
    public function initServer($server, $worker_id)
    {
        $wokerStart = Config::get('swoole.wokerstart');
        if ($wokerStart) {
            if (is_string($wokerStart) && class_exists($wokerStart)) {
                $obj = new $wokerStart($server, $worker_id);
                $obj->run();
                unset($obj);
            } elseif ($wokerStart instanceof \Closure) {
                $wokerStart($server, $worker_id);
            }
        }
    }

    public function getTable()
    {
        return $this->table;
    }

    /**
     * request回调
     * @param $request
     * @param $response
     */
    public function onRequest(SwooleRequest $request, SwooleResponse $response)
    {
        \think\Hook::listen('swoole_on_request', $request);
        $this->app->swooleHttp($request, $response);

    }
	
	/**
     * Message回调
     * @param $server
     * @param $frame
     */
    public function WebsocketonMessage($server, $frame)
    {
        // 执行应用并响应
        $this->app->swooleWebSocket($server, $frame);
    }

    /**
     * Close
     */
    public function WebsocketonClose($server, $fd,$reactorId)
    {
        $data=[$server, $fd,$reactorId];
        $debugclient=Config::get('swoole.debug_client');
        if ($debugclient){
            $debug_client_key=Config::get('swoole.debug_client_key');
            $_fd=Cache::get($debug_client_key);
            if ($_fd==$fd){
                Cache::set($debug_client_key,null);
            }
        }
        \think\Hook::listen('swoole_websocket_on_close',$data);
    }

    public function onTask(HttpServer $serv, $task_id, $fromWorkerId, $data)
    {
        if (is_string($data) && class_exists($data)) {
            $taskObj = new $data;
            if (method_exists($taskObj, 'run')) {
                $taskObj->run($serv, $task_id, $fromWorkerId);
                unset($taskObj);
            }
        }

        if (is_object($data) && method_exists($data, 'run')) {
            $data->run($serv, $task_id, $fromWorkerId);
            unset($data);
        }
        \think\Hook::listen('swoole_on_task', $data);
        if ($data instanceof SuperClosure) {
            return $data($serv, $task_id, $data);
        } else {
            $serv->finish($data);
        }

    }


    public function onFinish(HttpServer $serv, $task_id, $data)
    {
        \think\Hook::listen('swoole_on_finish', $data);
        if ($data instanceof SuperClosure) {
            $data($serv, $task_id, $data);
        }
    }

    protected function exception($response, $e)
    {
        if ($e instanceof \Exception) {
            $handler = Error::getExceptionHandler();
            $handler->report($e);

            $resp    = $handler->render($e);
            $content = $resp->getContent();
            $code    = $resp->getCode();

            $response->status($code);
            $response->end($content);
        } else {
            $response->status(500);
            $response->end($e->getMessage());
        }

        throw $e;
    }

    public function setHttp($http)
    {
        self::$http=$http;
    }

    public static function getHttp()
    {
        return self::$http;
    }
}
