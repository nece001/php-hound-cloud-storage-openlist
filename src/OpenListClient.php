<?php

namespace Nece\Hound\Cloud\Storage;

use Exception;
use GuzzleHttp\Client;

/**
 * OpenList客户端
 *
 * @author nece001@163.com
 * @create 2026-03-30 13:22:34
 */
class OpenListClient
{
    /**
     * HTTP客户端
     *
     * @var Client
     */
    private $http_client;
    private $http_config;

    private $token;
    private $token_ttl;
    private $api_base_uri;
    private $username;
    private $password;

    /**
     * 构造
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:27:42
     *
     * @param string $api_base_uri OpenListAPI地址
     * @param string $username OpenList登录用户名
     * @param string $password OpenList登录密码
     * @param integer $timeout HTTP请求超时时间，单位秒
     * @param string $proxy HTTP代理地址，格式为http://ip:port
     * @param integer $token_ttl 访问令牌过期时间，单位秒
     */
    public function __construct($api_base_uri, $username, $password, $timeout = 30, $proxy = null, $token_ttl = 3600)
    {
        $this->api_base_uri = $api_base_uri;
        $this->username = $username;
        $this->password = $password;
        $this->token_ttl = $token_ttl;

        $this->http_config = [
            'base_uri' => $this->api_base_uri,
            'timeout' => $timeout,
            'verify' => false,
        ];

        if ($proxy) {
            $this->http_config['proxy'] = $proxy;
        }

        $this->initHttpClient();
    }

    /**
     * 发送JSON POST请求
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:15:45
     *
     * @param string $api API路径
     * @param array $body JSON请求体
     * @return array
     */
    public function jsonPost($api, $body)
    {
        $data = [
            'json' => $body,
        ];

        try {
            $response = $this->http_client->post($api, $data);
            $json = $response->getBody()->getContents();
            return $this->fetchResponseData($json);
        } catch (StorageException $e) {
            if ($e->getCode() == Consts::ERROR_CODE_TOKEN_INVALIDATED) {

                $this->refreshToken();
                $this->initHttpClient();

                $response = $this->http_client->post($api, $data);
                $json = $response->getBody()->getContents();
                return $this->fetchResponseData($json);
            } else {
                throw $e;
            }
        }
    }

    /**
     * 上传文件
     *
     * @author nece001@163.com
     * @create 2026-03-30 13:53:02
     *
     * @param string $local_src 本地文件路径
     * @param string $key OpenList文件路径
     * @return bool
     */
    public function uploadPost($local_src, $key)
    {
        $data = [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($local_src, 'r'),
                    'filename' => basename($local_src)
                ]
            ],
            'headers' => [
                'File-Path' => $key,
                'As-Task' => '',
                'Overwrite' => '',
                'Last-Modified' => '',
                'X-File-Md5' => '',
                'X-File-Sha1' => '',
                'X-File-Sha256' => '',
            ],
        ];

        $api = '/api/fs/form';
        try {
            $response = $this->http_client->put($api, $data);
            $content = $response->getBody()->getContents();
            $this->fetchResponseData($content);
        } catch (StorageException $e) {
            if ($e->getCode() == Consts::ERROR_CODE_TOKEN_INVALIDATED) {
                $this->refreshToken();
                $this->initHttpClient();

                $response = $this->http_client->put($api, $data);
                $content = $response->getBody()->getContents();
                $this->fetchResponseData($content);
            } else {
                throw $e;
            }
        }
        return true;
    }

    /**
     * 下载文件
     *
     * @author nece001@163.com
     * @create 2026-03-30 13:57:45
     *
     * @param string $url OpenList文件URL
     * @param string $local_dst 本地文件路径
     * @return bool
     */
    public function downloadTo(string $url, string $local_dst)
    {
        try {
            $client = new Client([
                'headers' => [
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36',
                ],
            ]);

            $client->get($url, [
                'sink' => $local_dst,
            ]);
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * 初始化HTTP客户端
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:15:36
     *
     * @return void
     */
    private function initHttpClient()
    {
        $headers = [
            'Authorization' => $this->getToken(),
        ];
        $config = array_merge($this->http_config, ['headers' => $headers]);
        $this->http_client = new Client($config);
    }

    /**
     * 获取访问令牌
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:16:23
     *
     * @return string
     */
    private function getToken()
    {
        if (!$this->token) {
            $cache_file = $this->getCacheFilename();
            if (file_exists($cache_file)) {
                $content = file_get_contents($cache_file);
                if ($content) {
                    $data = json_decode($content, true);
                    if (isset($data['expire']) && $data['expire'] > time() && isset($data['token'])) {
                        $this->token = $data['token'];
                    }
                }
            }

            if (!$this->token) {
                $this->refreshToken();
            }
        }

        return $this->token;
    }

    /**
     * 刷新访问令牌
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:16:30
     *
     * @return void
     */
    private function refreshToken()
    {
        $cache_file = $this->getCacheFilename();
        $this->token = $this->login();
        $expire = time() + $this->token_ttl;
        file_put_contents($cache_file, json_encode(['token' => $this->token, 'expire' => $expire]));
    }

    /**
     * 获取缓存文件名
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:16:38
     *
     * @return string
     */
    private function getCacheFilename()
    {
        return sys_get_temp_dir() . '/openlist_token.json';
    }

    /**
     * 登录OpenList
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:16:44
     *
     * @return string
     */
    private function login()
    {
        $client = new Client($this->http_config);

        $api = '/api/auth/login';

        $body = [
            'username' => $this->username,
            'password' => $this->password,
            'otp_code' => ''
        ];

        $response = $client->post($api, [
            'json' => $body,
        ]);

        $json = $response->getBody()->getContents();
        $data = $this->fetchResponseData($json);
        $token = isset($data['token']) ? $data['token'] : '';
        if (!$token) {
            throw new StorageException('Login failed', Consts::ERROR_CODE_TOKEN_INVALIDATED);
        }
        return $token;
    }

    /**
     * 解析响应数据
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:16:58
     *
     * @param string $json JSON响应体
     * @return array
     */
    private function fetchResponseData($json)
    {
        $data = json_decode($json, true);
        if (isset($data['message'])) {
            if ($data['message'] == 'success') {
                return isset($data['data']) ? $data['data'] : [];
            }

            throw new StorageException($data['message'], $this->codeToErrorCode($data['code']));
        }

        throw new StorageException('Unknown error', Consts::ERROR_CODE_UNKNOWN);
    }

    /**
     *  将OpenList错误码转换为Brawl错误码
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:36:00
     *
     * @param int $code OpenList错误码
     * @return int Brawl错误码
     */
    private function codeToErrorCode($code)
    {
        switch ($code) {
            case 400:
                return Consts::ERROR_CODE_NOT_FOUND;
            case 401:
                return Consts::ERROR_CODE_TOKEN_INVALIDATED;
            case 403:
                return Consts::ERROR_CODE_AUTH_FAILED;
            case 404:
                return Consts::ERROR_CODE_NOT_FOUND;
            case 500:
                return Consts::ERROR_CODE_SERVER_ERROR;
            default:
                return Consts::ERROR_CODE_UNKNOWN;
        }
    }
}
