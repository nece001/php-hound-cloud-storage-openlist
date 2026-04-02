<?php

namespace Nece\Hound\Cloud\Storage;

/**
 * 使用OpenList的网盘文件系统
 *
 * @author nece001@163.com
 * @create 2026-03-29 21:36:24
 */
class OpenList extends Storage implements IStorage
{
    /**
     * OpenList客户端
     *
     * @var OpenListClient
     */
    private $client;

    /**
     * 网盘对应的路径
     *
     * @var string
     */
    private $base_path;

    /**
     * 文件信息缓存
     *
     * @var array
     */
    private $file_info = array();

    /**
     * 构造函数
     *
     * @author nece001@163.com
     * @create 2026-03-29 22:14:10
     *
     * @param string $api_base_uri OpenListAPI地址
     * @param string $username OpenList登录用户名
     * @param string $password OpenList登录密码
     * @param string $base_path OpenList网盘对应的路径
     * @param integer $timeout HTTP请求超时时间，单位秒
     * @param string $proxy HTTP代理地址，格式为http://ip:port
     * @param integer $token_ttl 访问令牌过期时间，单位秒
     */
    public function __construct($api_base_uri, $username, $password, $base_path, $timeout = 30, $proxy = null, $token_ttl = 3600)
    {
        $this->base_path = trim(str_replace('\\', '/', $base_path), '/');
        $this->client = new OpenListClient($api_base_uri, $username, $password, $timeout, $proxy, $token_ttl);
    }

    /**
     * @inheritDoc
     */
    public function exists(string $path): bool
    {
        try {
            $this->getObjectInfo($path);
            return true;
        } catch (StorageException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function isDir(string $path): bool
    {
        try {
            $info = $this->getObjectInfo($path);
            if (isset($info['is_dir'])) {
                return $info['is_dir'];
            } else {
                return false;
            }
        } catch (StorageException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function isFile(string $path): bool
    {
        try {
            $info = $this->getObjectInfo($path);
            if (isset($info['is_dir'])) {
                return !$info['is_dir'];
            } else {
                return false;
            }
        } catch (StorageException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function copy(string $from, string $to): bool
    {
        if (!$this->exists($from)) {
            throw new StorageException('源文件或目录不存在', Consts::ERROR_CODE_NOT_FOUND);
        }

        $from_file = $this->isFile($from);
        if ($from_file) {
            $names = [basename($from)];
            $from_dir = dirname($from);
            $to_dir = dirname($to);
        } else {
            $list = $this->list($from);
            $names = array_column($list, 'name');
            $from_dir = $from;
            $to_dir = $to;
        }

        if (!$this->exists($to_dir)) {
            $this->mkdir($to_dir);
        }

        $from_dir = $this->fullPath($from_dir);
        $to_dir = $this->fullPath($to_dir);

        if ($names) {
            $body = [
                'src_dir' => $from_dir,
                'dst_dir' => $to_dir,
                'names' => $names,
            ];

            $api = '/api/fs/copy';
            $this->client->jsonPost($api, $body);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): bool
    {
        if (!$this->exists($from)) {
            throw new StorageException('源文件或目录不存在', Consts::ERROR_CODE_NOT_FOUND);
        }

        $from_file = $this->isFile($from);
        if ($from_file) {
            $names = [basename($from)];
            $from_dir = dirname($from);
            $to_dir = dirname($to);
        } else {
            $list = $this->list($from);
            $names = array_column($list, 'name');
            $from_dir = $from;
            $to_dir = $to;
        }

        if (!$this->exists($to_dir) && $names) {
            $this->mkdir($to_dir);
        }

        $from_dir = $this->fullPath($from_dir);
        $to_dir = $this->fullPath($to_dir);
        if ($names) {
            $body = [
                'src_dir' => $from_dir,
                'dst_dir' => $to_dir,
                'names' => $names,
            ];
            $api = '/api/fs/move';
            $this->client->jsonPost($api, $body);
        } else {
            $this->rename($from, $to);
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $path): bool
    {
        $path = $this->fullPath($path);

        $pathInfo = pathinfo($path);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['basename'];

        $body = [
            'dir' => $directory,
            'names' => [
                $filename
            ],
        ];

        $api = '/api/fs/remove';
        $this->client->jsonPost($api, $body);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function mkdir(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        $path = $this->fullPath($path);

        $body = [
            'path' => $path,
        ];

        $api = '/api/fs/mkdir';
        $this->client->jsonPost($api, $body);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function rmdir(string $path): bool
    {
        return $this->delete($path);
    }

    /**
     * @inheritDoc
     */
    public function list(string $path, int $order = Consts::SCANDIR_SORT_ASCENDING, $page = 1, $per_page = 100): array
    {
        $directory = $this->fullPath($path);

        $body = [
            'path' => $directory,
            'password' => '',
            'refresh' => false,
            'page' => $page,
            'per_page' => $per_page,
        ];

        $list = array();
        $api = '/api/fs/list';

        try {
            $data = $this->client->jsonPost($api, $body);
            if (isset($data['content']) && $data['content']) {
                foreach ($data['content'] as $row) {

                    $name = $row['name'];
                    $size = $row['size'];
                    $is_dir = $row['is_dir'];
                    $ctime = strtotime($row['created']);
                    $mtime = strtotime($row['modified']);
                    $atime = $mtime;

                    $list[] = $this->buildObjectListItem($name, $size, $is_dir, $ctime, $mtime, $atime);
                }
            }
        } catch (StorageException $e) {
            return [];
        }
        return $list;
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local_src, string $to): bool
    {
        if (!file_exists($local_src)) {
            throw new StorageException('源文件不存在', Consts::ERROR_CODE_NOT_FOUND);
        }

        if (is_file($local_src)) {
            $to = $this->fullPath($to);
            return $this->client->uploadPost($local_src, $to);
        } elseif (is_dir($local_src)) {
            $files = scandir($local_src);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $this->upload($local_src . DIRECTORY_SEPARATOR . $file, $to . '/' . $file);
                }
            }
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function download(string $src, string $local_dst): bool
    {
        $info = $this->getObjectInfo($src);
        if (isset($info['is_dir'])) {
            if ($info['is_dir']) {
                if (!file_exists($local_dst)) {
                    mkdir($local_dst, 0755, true);
                }

                $list = $this->list($src);
                $files = array_column($list, 'name');
                foreach ($files as $file) {
                    $path =  $src . '/' . $file;
                    $dir = $local_dst . DIRECTORY_SEPARATOR . $file;
                    $this->download($path, $dir);
                }
            } else {

                return $this->client->downloadTo($info['raw_url'], $local_dst);
            }
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function file(string $path): IObject
    {
        $key = trim(str_replace('\\', '/', $path), '/');
        $path = $this->fullPath($path);
        try {
            $info = $this->getObjectInfo($key);
            $info['key'] = $key;
            return OpenListObject::createFile($this, $path, $key, $info['size'], $info['modified'], $info['created'], $info['is_dir'], $info['raw_url']);
        } catch (StorageException $e) {
            return new OpenListObject($this, ['key' => $key, 'path' => $path]);
        }
    }

    /**
     * @inheritDoc
     */
    public function uri(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return $this->base_path . '/' . $path;
    }

    /**
     * @inheritDoc
     */
    public function url(string $path): string
    {
        $info = $this->getObjectInfo($path);
        if (isset($info['raw_url'])) {
            return $info['raw_url'];
        } else {
            return '';
        }
    }

    /**
     * 获取文件信息
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:42:27
     *
     * @param string $path
     * @return array|null
     */
    private function getObjectInfo(string $path): ?array
    {
        $path = $this->fullPath($path);
        if (!isset($this->file_info[$path])) {
            $body = [
                'path' => $path,
                'password' => '',
            ];

            $api = '/api/fs/get';
            $data = $this->client->jsonPost($api, $body);
            $this->file_info[$path] = $data;
        }
        return  $this->file_info[$path];
    }

    /**
     * 重命名文件或目录
     *
     * @author nece001@163.com
     * @create 2026-03-30 15:40:30
     *
     * @param string $old_name 旧名称
     * @param string $new_name 新名称
     * @return bool
     */
    private function rename(string $old_name, string $new_name): bool
    {
        $old_name = $this->fullPath($old_name);

        $body = [
            'path' => $old_name,
            'name' => basename($new_name),
        ];

        $api = '/api/fs/rename';
        $this->client->jsonPost($api, $body);
        return true;
    }

    /**
     * 生成完整路径
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:40:29
     *
     * @param string $path
     * @return string
     */
    private function fullPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return $this->base_path . '/' . $path;
    }

    /**
     * 获取OpenList客户端
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:13:50
     *
     * @return OpenListClient
     */
    public function getClient(): OpenListClient
    {
        return $this->client;
    }
}
