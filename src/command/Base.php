<?php


namespace william\swoole\command;


use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Swoole\Process;

class Base extends Command
{
    protected $config = [];

    // 可支持的操作
    protected $option = "start|stop|restart|reload";

    // 可支持的操作数组
    protected $optionArr = ['start', 'stop', 'reload', 'restart'] ;

    // 定义命令
    protected function configure($commandName='',$description='')
    {
        if( empty($commandName) ){
            print_r("Please define the command Name !!!!!!!! \n");
            exit();
        }

        $this->setName($commandName)
            ->addArgument('action', Argument::OPTIONAL, $this->option, 'start')
            ->addOption('host', 'H', Option::VALUE_OPTIONAL, 'the host of swoole server.', null)
            ->addOption('port', 'p', Option::VALUE_OPTIONAL, 'the port of swoole server.', null)
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the swoole server in daemon mode.')
            ->setDescription($description);
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');

        $this->init();

        if (in_array($action, $this->optionArr)) {
            $this->$action();
        } else {
            $output->writeln("<error>{$action} 为无效参数方法, 目前仅仅支持 \"{$this->option}\" .</error>");
        }
    }

    protected function init($configFile, $serverType)
    {
        //配置文件需要在application/extra下
        $this->config = Config::get($configFile);

        if (empty($this->config['pid_file'])) {
            $this->config['pid_file'] = APP_PATH . $serverType.'.pid';
        }

        // 避免pid混乱
        $this->config['pid_file'] .= '_' . $this->getPort();
    }

    /**
     * Host配置
     * @function getHost
     * @return mixed|string
     * Author William
     * Time 2019/12/23 15:43
     */
    protected function getHost()
    {
        if ($this->input->hasOption('host')) {
            $host = $this->input->getOption('host');
        } else {
            $host = !empty($this->config['host']) ? $this->config['host'] : '0.0.0.0';
        }

        return $host;
    }

    /**
     * Port配置
     * @function getPort
     * @return int|mixed
     * Author William
     * Time 2019/12/23 15:43
     */
    protected function getPort()
    {
        if ($this->input->hasOption('port')) {
            $port = $this->input->getOption('port');
        } else {
            $port = !empty($this->config['port']) ? $this->config['port'] : 9501;
        }

        return $port;
    }

    /**
     * 服务启动
     * @function start
     * @param $server
     * @return bool
     * Author William
     * Time 2019/12/23 15:44
     */
    protected function start($server)
    {
        $pid = $this->getMasterPid();
        \think\Hook::listen('swoole_before_start', $pid);
        if ($this->isRunning($pid)) {
            $this->output->writeln('<error>swoole http server process 已在运行.</error>');
            return false;
        }

        $this->output->writeln('Starting swoole http server...');

        $host = $this->getHost();
        $port = $this->getPort();
        $mode = !empty($this->config['mode']) ? $this->config['mode'] : SWOOLE_PROCESS;
        $type = !empty($this->config['sock_type']) ? $this->config['sock_type'] : SWOOLE_SOCK_TCP;

        $ssl = !empty($this->config['ssl']) || !empty($this->config['open_http2_protocol']);
        if ($ssl) {
            $type = SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }

        // 定义Http服务还是socket服务
        $swoole = new $server($host, $port, $mode, $type);
        $swoole->setHttp($swoole);
        // 开启守护进程模式
        if ($this->input->hasOption('daemon')) {
            $this->config['daemonize'] = 1;
        }


        // 设置应用目录
        $swoole->setAppPath($this->config['app_path']);

        // 创建内存表
        if (!empty($this->config['table'])) {
            $swoole->table($this->config['table']);
            unset($this->config['table']);
        }

        // 设置文件监控 调试模式自动开启
        if (Config::get('app_debug') || !empty($this->config['file_monitor'])) {
            $interval = isset($this->config['file_monitor_interval']) ? $this->config['file_monitor_interval'] : 2;
            $paths    = isset($this->config['file_monitor_path']) ? $this->config['file_monitor_path'] : [];
            $swoole->setMonitor($interval, $paths);
            unset($this->config['file_monitor'], $this->config['file_monitor_interval'], $this->config['file_monitor_path']);
        }

        // 设置服务器参数
        if (isset($this->config['pid_file'])) {

        }
        $swoole->option($this->config);

        $this->output->writeln("Swoole http server started: <http://{$host}:{$port}>");

        $swoole->start();
    }

    /**
     * 柔性重启服务
     * @function reload
     * @return bool
     * Author William
     * Time 2019/12/23 15:45
     */
    protected function reload()
    {
        $pid = $this->getMasterPid();

        if (!$this->isRunning($pid)) {
            $this->output->writeln('<error>no swoole http server process running.</error>');
            return false;
        }

        $this->output->writeln('Reloading swoole http server...');
        Process::kill($pid, SIGUSR1);
        $this->output->writeln('> success');
        \think\Hook::listen('swoole_reload', $pid);
    }

    /**
     * 停止服务
     * @function stop
     * @return bool
     * Author William
     * Time 2019/12/23 15:45
     */
    protected function stop()
    {
        $pid = $this->getMasterPid();
        \think\Hook::listen('swoole_before_stop', $pid);
        if (!$this->isRunning($pid)) {
            $this->output->writeln('<error>no swoole http server process running.</error>');
            return false;
        }

        $this->output->writeln('Stopping swoole http server...');

        Process::kill($pid, SIGTERM);
        $this->removePid();

        $this->output->writeln('> success');
    }

    /**
     * 停止服务
     * @function restart
     * Author William
     * Time 2019/12/23 15:45
     */
    protected function restart()
    {
        $pid = $this->getMasterPid();
        \think\Hook::listen('swoole_before_restart', $pid);
        if ($this->isRunning($pid)) {
            $this->stop();
        }

        $this->start();
    }

    /**
     * 获取主进程PID
     * @function getMasterPid
     * @return int
     * Author William
     * Time 2019/12/23 15:45
     */
    protected function getMasterPid()
    {
        $pidFile = $this->config['pid_file'];

        if (is_file($pidFile)) {
            $masterPid = (int)file_get_contents($pidFile);
        } else {
            $masterPid = 0;
        }

        return $masterPid;
    }

    /**
     * 删除PID文件
     * @function removePid
     * Author William
     * Time 2019/12/23 15:45
     */
    protected function removePid()
    {
        $masterPid = $this->config['pid_file'];

        if (is_file($masterPid)) {
            unlink($masterPid);
        }
    }

    /**
     * 判断PID是否在运行
     * @function isRunning
     * @param $pid
     * @return bool
     * Author William
     * Time 2019/12/23 15:46
     */
    protected function isRunning($pid)
    {
        if (empty($pid)) {
            return false;
        }

        return Process::kill($pid, 0);
    }

}