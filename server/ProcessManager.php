<?php
/**
 * 多进程消费者管理实例
 * 功能 : 使用多进程，启动多个rabbitMQ消费者，消费队列数据
 */

namespace module\server;

use Exception;
use module\lib\PdoClient;
use module\lib\RabbitMQClient;
use Swoole\Process;

class ProcessManager
{

    const STATUS_ENABLE = 1;
    const STATUS_DISABLE = 2;
    /**
     * @var array
     */
    private $rabbitMQConfig = [];
    /**
     * @var array
     */
    protected $queueConfig = [];
    /**
     * @var int master进程id
     */
    private $pid;
    /**
     * @var int
     */
    private $renamePid;
    /**
     * @var array
     */
    private $childPid;
    /**
     * @var string
     */
    private $master;
    /**
     * @var string
     */
    private $table = 'yibai_async_config';
    /**
     * @var string
     */
    private $queue;
    private $timerId;
    private $id;

    /**
     * @param array $argv
     * @return bool
     */
    public function run($argv = [])
    {
        try {
            $this->queue = $argv[1] ?? 'XooX';
            $this->master = 'async-master-' . $this->queue;
            $cmd = $argv[2] ?? 'status';
            $this->checkQueue();
            $this->initRabbitMQConfig($this->checkMQConfigFile());
            switch ($cmd) {
                case 'start':
                    $this->start();
                    break;
                case 'stop':
                    $this->stop();
                    break;
                case 'status':
                    $this->status();
                    break;
                default:
                    break;
            }
        } catch (Exception $e) {
            $msg = 'Exception :' . $e->getMessage();
            $this->writeLog($msg);
            echo $msg . PHP_EOL;
        }
        return true;
    }

    /**
     * @param $rabbitMQConfig
     * @return array
     */
    private function initRabbitMQConfig($rabbitMQConfig)
    {
        $this->rabbitMQConfig = [
            'host' => $rabbitMQConfig['host'] ?? '192.168.71.91',
            'port' => $rabbitMQConfig['port'] ?? '5672',
            'vhost' => $rabbitMQConfig['vhost'] ?? '/',
            'login' => $rabbitMQConfig['login'] ?? 'admin',
            'password' => $rabbitMQConfig['password'] ?? 'admin123.',
            'connect_timeout' => $mqConfig['password'] ?? 30,
            'heartbeat' => $mqConfig['heartbeat'] ?? 30,
        ];
        return $this->rabbitMQConfig;
    }

    /**
     * @param $processName
     * @return bool|mixed
     */
    protected function renameProcessName($processName)
    {
        if (function_exists('cli_set_process_title')) {
            return cli_set_process_title($processName);
        } else {
            return swoole_set_process_name($processName);
        }
    }

    /**
     * @return bool
     * @throws \module\FluentPDO\Exception
     */
    private function start()
    {
        $query = (new PdoClient())->getQuery();
        $process = $query->from($this->table)->where('queue', $this->queue)->fetch();
        if ($process['status'] == self::STATUS_ENABLE) {
            echo 'process has start' . PHP_EOL;
            $query->close();
            return;
        }
        $query->close();
        //默认定时器在执行回调函数时会自动创建协程，可单独设置定时器关闭协程。
        //swoole_async_set(['enable_coroutine' => false]);
        //第四个参数，是否在 callback function 中启用协程，开启后可以直接在子进程的函数中使用协程 API
        //蜕变为守护进程时，该进程的 PID 将发生变化，可以使用 getmypid() 来获取当前的 PID
        \Swoole\Process::daemon();
        //先获取master pid，再重命名进程名
        $this->pid = getmypid();
        //当前进程重命名
        $this->renameProcessName($this->master);
        for ($n = 1; $n <= $this->queueConfig['num']; $n++) {
            $process = new Process(function (Process $process) use ($n) {
                $process->name("async-worker-" . $this->queueConfig['queue'] . "-{$n}");
                $rabbitMQClient = new RabbitMQClient($this->rabbitMQConfig);
                $rabbitMQClient->consume(
                    [$this, 'consumeHandler'],
                    $this->queueConfig['queue'],
                    $this->queueConfig['exchange'],
                    AMQP_EX_TYPE_DIRECT,
                    $this->queueConfig['queue']
                );
            });
            //在 callback function 中启用协程，开启后可以直接在子进程的函数中使用协程 API
            $process->set(['enable_coroutine' => true]);
            $this->childPid[] = $process->start();
            //$this->childPid[] = $process->pid;
        }
        //$this->pid = posix_getpid();
        $this->renamePid = getmypid();
        $this->writeLog('start pid:' . $this->pid . ', renamePid:' . $this->renamePid);
        $this->saveAsyncRecord();
        $this->registerSignal();
        $this->registerTimer();
        return true;
    }

    /**
     * @throws \module\FluentPDO\Exception
     */
    private function stop()
    {
        $query = (new PdoClient())->getQuery();
        $process = $query->from($this->table)->where('queue', $this->queue)->fetch();
        if ($process['status'] == self::STATUS_DISABLE) {
            echo 'process has stop' . PHP_EOL;
            $query->close();
            return;
        }
        //检测父进程并发送停止信号
        //$signo=0，可以检测进程是否存在，不会发送信号
        if (\Swoole\Process::kill($process['pid'], 0)) {
            if (empty($process['pid'])) {
                return;
            }
            //主进程为定时器常驻监听，退出需处理清理定时器进程
            $this->writeLog('send pid stop:' . $process['pid']);
            //Swoole\Timer::clear 不能用于清除其他进程的定时器，只作用于当前进程
            //向进程发送信号
            \Swoole\Process::kill($process['pid'], SIGTERM);
        } else {
            //不存在，清理子进程
            $this->childPid = explode(',', $process['child_pid']);
            foreach ($this->childPid as $childPid) {
                $this->writeLog('pid not exist, stop child pid');
                \Swoole\Process::kill($childPid, SIGTERM);
            }
        }
        $query->update($this->table)->where('queue', $this->queue)->set(['status' => self::STATUS_DISABLE])->execute();
        $query->close();
    }

    /**
     * @return array|mixed
     * @throws Exception
     */
    private function status()
    {
        $this->queueConfig = $this->checkQueue();
        echo print_r($this->queueConfig, true) . PHP_EOL;
        return $this->queueConfig;
    }

    /**
     * @return bool|int
     * @throws \module\FluentPDO\Exception
     */
    private function saveAsyncRecord()
    {
        $data = [
            'name' => $this->queueConfig['queue'],
            'master' => $this->master,
            'prefetch' => $this->queueConfig['prefetch'],
            'host' => $this->rabbitMQConfig['host'],
            'port' => $this->rabbitMQConfig['port'],
            'login' => $this->rabbitMQConfig['login'],
            'password' => $this->rabbitMQConfig['password'],
            'vhost' => $this->queueConfig['vhost'],
            'exchange' => $this->queueConfig['exchange'],
            'queue' => $this->queueConfig['queue'],
            'route_key' => $this->queueConfig['route_key'],
            'url' => $this->queueConfig['url'],
            'pid' => $this->pid,
            'child_pid' => implode(',', $this->childPid),
            'status' => self::STATUS_ENABLE,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        $query = (new PdoClient())->getQuery();
        $result = $query->from($this->table)->where('queue', $data['queue'])->fetch();
        if (!empty($result)) {
            $query->update($this->table)->where(['id' => $result['id']])->set($data)->execute();
            $this->id = $result['id'];
        } else {
            $this->id = $query->insertInto($this->table)->values($data)->execute();
        }
        $query->close();
        return $this->id;
    }

    /**
     * 注册master信号监听
     */
    private function registerSignal()
    {
        //SIGTERM 终止，Termination
        \Swoole\Process::signal(SIGTERM, function ($sig) {
            //注册接收父进程终止信号，kill命令（此时，父进程尚未终止）
            $this->stopChildProcess($sig);
        });
        //SIGKILL 杀死
        \Swoole\Process::signal(SIGKILL, function ($sig) {
            //注册接收父进程强制终止信号，kill -9 命令
            $this->stopChildProcess($sig);
        });
        //SIGUSR1
        \Swoole\Process::signal(SIGUSR1, function ($sig) {
            $this->writeLog("receive signal: {$sig}");
        });
        //SIGUSR2
        \Swoole\Process::signal(SIGUSR2, function ($sig) {
            $this->writeLog("receive signal: {$sig}");
        });
        //SIGCHLD 子进程状态改变
        //每个子进程结束后，父进程必须都要执行一次 wait() 进行回收，否则子进程会变成僵尸进程，会浪费操作系统的进程资源。
        //如果父进程有其他任务要做，没法阻塞 wait 在那里，父进程必须注册信号 SIGCHLD 对退出的进程执行 wait。
        //SIGCHILD 信号发生时可能同时有多个子进程退出；必须将 wait() 设置为非阻塞，循环执行 wait 直到返回 false。
        \Swoole\Process::signal(SIGCHLD, function ($sig) {
            /**
             * wait(false)，非阻塞模式
             * 阻塞等待子进程退出，并回收
             * 成功返回一个数组包含子进程的PID和退出状态码
             * 如 array('code' => 0, 'pid' => 15001)，失败返回false
             */
            while ($ret = \Swoole\Process::wait(false)) {
                $this->writeLog("wait child {$sig}: {$ret['pid']}");
            }
        });
    }

    /**
     * 停止子进程
     * @param $sig
     * @param bool $masterExit
     */
    public function stopChildProcess($sig, $masterExit = true)
    {
        foreach ($this->childPid as $childPid) {
            if ($childPid && \Swoole\Process::kill($childPid, 0)) {
                \Swoole\Process::kill($childPid, $sig);
                $this->writeLog("child exit:{$childPid}");
            }
        }
        if ($masterExit) {
            $this->writeLog('master exit');
            //直接终止退出父进程
            exit(0);
        }
    }

    /**
     * 注册定时器，阻塞进程
     */
    private function registerTimer()
    {
        //让父进程变成常驻进程，或为守护进程: \Swoole\Process::daemon(true);
        //注册的信号不再作为维持事件循环的条件，如程序只注册了信号而未进行其他工作将被视为空闲并随即退出 （此时可通过注册一个定时器防止进程退出）
        $this->timerId = \Swoole\Timer::tick(60 * 1000, function ($timerId) {
        });
    }

    /**
     * @return array|mixed
     * @throws \module\FluentPDO\Exception
     */
    private function checkQueue()
    {
        $query = (new PdoClient())->getQuery();
        $this->queueConfig = $query->from($this->table)->where('queue', $this->queue)->fetch();
        if (empty($this->queueConfig)) {
            throw new Exception('queue [' . $this->queue . '] record not exist');
        }
        $fields = ['host', 'port', 'login', 'name', 'password', 'vhost', 'exchange', 'queue', 'route_key', 'url'];
        if ($this->checkEmptyParams($this->queueConfig, $fields)) {
            throw new Exception('queue config can not empty');
        }
        $query->close();
        return $this->queueConfig;
    }

    private function checkMQConfigFile()
    {
        $configDir = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
        $rabbitMQPath = $configDir . 'rabbitmq.php';
        $mqConfig = file_exists($rabbitMQPath) ? include($rabbitMQPath) : [];
        return $mqConfig;
    }

    /**
     * @param $params
     * @param $fields
     * @return bool
     */
    protected function checkEmptyParams($params, $fields)
    {
        foreach ($fields as $v) {
            if (!isset($params[$v])) {
                return true;
            } else if (isset($params[$v]) && empty($params[$v])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $body
     * @return bool
     */
    public function consumeHandler($body)
    {
        go(function () use ($body) {
            $this->writeCallbackLog($body);
        });
        go(function () use ($body) {
            try {
                if (empty($this->queueConfig['url'])) {
                    throw new Exception('empty url: ' . $this->queueConfig['queue']);
                }
                $parse = parse_url($this->queueConfig['url']);
                if (!isset($parse['scheme'], $parse['host'])) {
                    throw new Exception('error url config :' . $this->queueConfig['url']);
                }
                //1, Coroutine Client
//                $host = $parse['host'];
//                $ssl = $parse['scheme'] == 'https' ? true : false;
//                $port = $parse['scheme'] == 'https' ? 443 : (isset($parse['port']) ? $parse['port'] : 80);
//                $path = isset($parse['path']) && !empty($parse['path']) ? $parse['path'] : '/';
//                $path = isset($parse['query']) && !empty($parse['query']) ? $path . '?' . $parse['query'] : $path;
//                $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
//                $client->setHeaders([
//                    'Host' => $host,
//                    'Content-Type' => 'application/json;charset=utf-8',
//                    'Accept' => 'application/json;text/html,application/xhtml+xml,application/xml',
//                ]);
//                $client->set(['timeout' => 600]);
//                $client->post($path, $body);
//                $responseBody = $client->body;
//                $client->close();
                //2, 直接使用php-curl
                $responseBody = $this->curlPost($this->queueConfig['url'], $body, 300, ['Content-Type:application/json; charset=utf-8']);
                $this->writeCallbackLog('responseBody:' . $responseBody);
            } catch (Exception $e) {
                $this->writeCallbackLog('Exception:body:' . json_encode($body, JSON_UNESCAPED_UNICODE) . '; message:' . $e->getMessage());
            }
        });
        return true;
    }

    protected function curlPost($url, $data = array(), $timeout = 10, $header = array(), $cookie = "")
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        if (!empty($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }
        $https = substr($url, 0, 8) == "https://" ? true : false;
        if ($https) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $res;
    }

    /**
     * @param string $logData
     */
    private function writeLog($logData = '')
    {
        $logFile = MODULE_DIR . '/logs/server-' . date('Y-m') . '.log';
        $logData = (is_array($logData) || is_object($logData)) ? json_encode($logData, JSON_UNESCAPED_UNICODE) : $logData;
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . $logData . PHP_EOL, FILE_APPEND);
    }

    /**
     * @param string $logData
     */
    private function writeCallbackLog($logData = '')
    {
        $logFile = MODULE_DIR . '/logs/callback-' . date('Y-m') . '.log';
        $logData = (is_array($logData) || is_object($logData)) ? json_encode($logData, JSON_UNESCAPED_UNICODE) : $logData;
        file_put_contents($logFile, date('[Y-m-d H:i:s]') . $logData . PHP_EOL, FILE_APPEND);
    }

}