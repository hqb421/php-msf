<?php

/**
 * Rpc 客户端
 * 使用方式如：RpcClient::serv('user')->handler('mobile')->getByUid($this, $uid);
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */
namespace PG\MSF\Client;

use PG\Helper\SecurityHelper;
use PG\MSF\Base\Base;
use PG\MSF\Base\SwooleException;

class RpcClient
{
    /**
     * @var string 当前版本
     */
    public static $version = '0.9';
    /**
     * @var array json 错误码
     */
    protected static $jsonErrors = [
        JSON_ERROR_NONE => null,
        JSON_ERROR_DEPTH => 'Maximum stack depth exceeded',
        JSON_ERROR_STATE_MISMATCH => 'Underflow or the modes mismatch',
        JSON_ERROR_CTRL_CHAR => 'Unexpected control character found',
        JSON_ERROR_SYNTAX => 'Syntax error, malformed JSON',
        JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded'
    ];
    /**
     * @var array 所有服务
     */
    protected static $services;

    /**
     * @var string host地址，支持 http/https/tcp 协议，支持域名地址，支持带端口.
     * eg:
     * http://127.0.0.1 | http://hostname | http://127.0.0.1:80 | http://hostname:80
     * https://127.0.0.1 | https://hostname | https://127.0.0.1:443 | https://hostname:443
     * tcp://127.0.0.1 | tcp://hostname | tcp://127.0.0.1:8080 | tcp://hostname:8080
     */
    protected $host = 'http://127.0.0.1';
    /**
     * @var int 超时时间(单位毫秒)
     */
    protected $timeout = 0;
    /**
     * @var string 服务加密秘钥
     */
    protected $secret = null;
    /**
     * 调用是否使用 rpc 方式，为了兼容还未 rpc 改造的内部服务；
     * 已经支持 rpc 调用的服务，可设置为 true，默认为 false
     */
    protected $useRpc = false;
    /**
     * @var string 使用协议，http/tcp
     */
    protected $scheme = 'http';
    /**
     * @var null url path，如 /path，当不使用 rpc 模式时，应设置本参数；
     */
    protected $urlPath = '/';
    /**
     * @var string 动作，如 GET/POST
     */
    protected $verb = 'POST';
    /**
     * @var string 服务接收句柄，一般为控制器名称
     */
    protected $handler = null;

    /**
     * RpcClient constructor.
     * @param $service 服务名称，如 'user.1'
     * @throws SwooleException
     */
    public function __construct($service)
    {
        // 获得配置信息
        /**
         * 'user' = [
         *     'host' => 'http://10.1.90.10:80', <必须>
         *     'timeout' => 1000, <选填，可被下级覆盖>
         *     'secret' => 'xxxxxx', <选填，可被下级覆盖>
         *     'useRpc' => false, <选填，可被下级覆盖>
         *     '1' => [
         *         'useRpc' => false, // 是否真的使用 rpc 方式，为了兼容非 rpc 服务 <选填，如果填写将会覆盖上级的 useRpc>
         *         'urlPath' => '/path', <选填>
         *         'verb' => 'GET', <选填>
         *         'timeout' => 2000, <选填，如果填写将会覆盖上级的 timeout>
         *         'secret' => 'yyyyyy', <选填，如果填写将会覆盖上级的 secret>
         *     ]
         * ]
         */
        $config = getInstance()->config->get('params.service.' . $service, []);
        list($root,) = explode('.', $service);
        $config['host'] = getInstance()->config->get('params.service.' . $root . '.host', '');
        if ($config['host'] === '') {
            throw new SwooleException('Host configuration not found.');
        }
        if (! isset($config['useRpc'])) {
            $config['useRpc'] = getInstance()->config->get('params.service.' . $root . '.useRpc', false);
        }
        if (! isset($config['timeout'])) {
            $config['timeout'] = getInstance()->config->get('params.service.' . $root . '.timeout', 0);
        }
        if (!isset($config['secret'])) {
            $config['secret'] = getInstance()->config->get('params.service.' . $root . '.secret', '');
        }
        // 赋值到类属性中.
        $this->host = $config['host'];
        $this->useRpc = $config['useRpc'] ?? false;
        $this->urlPath = $config['urlPath'] ?? '/';
        $scheme = substr($this->host, 0, strpos($this->host, ':'));
        if (!in_array($scheme, ['http', 'https', 'tcp'])) {
            throw new SwooleException('Host configuration invalid.');
        }
        // 非 Rpc 模式.
        if (!$this->useRpc) {
            if ($this->scheme === 'tcp') {
                throw new SwooleException('Non-rpc mode does not support tcp scheme.');
            }
        }
        $this->verb = $config['verb'] ?? 'POST';
        $this->timeout = $config['timeout'];
        $this->secret = $config['secret'];
    }

    /**
     * 指定服务名称，如 user.1
     * @param string $service
     * @return RpcClient
     */
    public static function serv($service)
    {
        if (! isset(static::$services[$service])) {
            static::$services[$service] = new static($service);
        }

        return static::$services[$service];
    }

    /**
     * 指定服务句柄，一般为控制器
     * @param string $handler
     * @return RpcClient
     */
    public function handler($handler)
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * 指定一个远程方法，如控制器名
     * @param string $method
     * @param mixed $args
     * @return mixed
     * @throws \Exception
     */
    public function __call($method, $args)
    {
        if (!is_object($args[0]) || !($args[0] instanceof CoreBase)) {
            throw new SwooleException('The first argument of ' . $method . ' must be instanceof CoreBase.');
        }
        $obj = $args[0];
        array_shift($args);
        if ($this->scheme == 'tcp') { // 使用 tcp 方式调用
            $response = static::remoteTcpCall($obj, $method, $args, $this);
        } else {
            $response = static::remoteHttpCall($obj, $method, $args, $this);
        }

        return $response;
    }

    /**
     * 非 Rpc 模式执行远程调用
     * @param string $url
     * @param mixed $args
     */
    public static function remoteTcpCall(CoreBase $obj, $method, array $args, RpcClient $rpc)
    {
        $reqParams = [
            'version' => static::$version,
            'args' => $args,
            'time' => microtime(true),
            'handler' => $rpc->handler,
            'method' => $method
        ];
        $reqParams['X-RPC'] = 1;
        $reqParams['sig'] = static::genSig($reqParams, $rpc->secret);

        $tcpClient = yield $obj->tcpClient->coroutineGetTcpClient($rpc->host);
        $response = yield $tcpClient->coroutineSend(['path' => '/', 'data' => $reqParams]);

        return static::parseResponse($response);
    }

    /**
     * 生产秘钥
     * @param $params
     * @param $secret
     * @return bool|string
     */
    public static function genSig($params, $secret)
    {
        if ($secret === '') {
            return '';
        }
        $sig = SecurityHelper::sign($params, $secret);

        return $sig;
    }

    /**
     * 解析返回值，可被继承覆盖，用于根据自己具体业务进行分析
     * @param mixed $response
     * @return mixed
     */
    protected static function parseResponse($response)
    {
        return $response;
    }

    /**
     * Rpc 模式执行远程调用
     * @param string $pack_data 打包好的数据
     */
    public static function remoteHttpCall(CoreBase $obj, $method, array $args, RpcClient $rpc)
    {
        $headers = [];
        if ($rpc->useRpc) {
            $reqParams = [
                'version' => static::$version,
                'args' => $args,
                'time' => microtime(true),
                'handler' => $rpc->handler,
                'method' => $method
            ];
            $sendData = [
                'data' => getInstance()->pack->pack($reqParams),
                'sig' => static::genSig($reqParams, $rpc->secret),
            ];
            $headers = [
                'X-RPC' => 1,
            ];
        } else {
            $sendData = $args;
            $sendData['sig'] = static::genSig($args, $rpc->secret);
        }

        $httpClient = yield $obj->client->coroutineGetHttpClient($rpc->host, $rpc->timeout, $headers);
        if ($rpc->verb == 'POST') {
            $response = yield $httpClient->coroutinePost($rpc->urlPath, $sendData, $rpc->timeout);
        } else {
            $response = yield $httpClient->coroutineGet($rpc->urlPath, $sendData, $rpc->timeout);
        }
        if (!isset($response['body'])) {
            throw new SwooleException('The response of body is not found');
        }
        $body = json_decode($response['body'], true);
        if ($body === null) {
            $error = static::jsonLastErrorMsg();
            throw new SwooleException('json decode failure: ' . $error . ' caused by ' . $response['body']);
        }

        return static::parseResponse($body);
    }

    /**
     * 拿到 json 解析最后出现的错误信息
     * @return mixed|string
     */
    protected static function jsonLastErrorMsg()
    {
        $error = json_last_error();
        return array_key_exists($error, static::$jsonErrors) ? static::$jsonErrors[$error] : "Unknown error ({$error})";
    }
}
