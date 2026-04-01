<?php

namespace Nece\Hound\Cloud\Storage;

class OpenListObject implements IObject
{
    /**
     * OpenList客户端
     *
     * @var OpenList
     */
    private $client;

    /**
     * 文件信息数组
     *
     * @var array
     */
    private $info;

    /**
     * 创建文件对象
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:20:46
     *
     * @param OpenList $client OpenList客户端
     * @param string $path 文件路径
     * @param string $key 文件路径键值
     * @param int $size 文件大小
     * @param string $mtime 文件修改时间
     * @param string $ctime 文件创建时间
     * @param bool $is_dir 是否为目录文件
     * @return OpenListObject
     */
    public static function createFile(OpenList $client, $path, $key, $size, $mtime, $ctime, $is_dir, $url): OpenListObject
    {
        $info = [
            'path' => $path,
            'key' => $key,
            'size' => $size,
            'mtime' => strtotime($mtime),
            'ctime' => strtotime($ctime),
            'is_dir' => $is_dir,
            'url' => $url,
        ];

        return new self($client, $info);
    }

    /**
     * 构造
     *
     * @author nece001@163.com
     * @create 2026-03-30 14:22:11
     *
     * @param OpenList $client OpenList客户端
     * @param array $info 文件信息数组
     */
    public function __construct(OpenList $client, array $info)
    {
        $this->info = $info;
        $this->client = $client;
    }

    /**
     * @inheritDoc
     */
    public function getAccessTime(): int
    {
        return $this->getModifyTime();
    }

    /**
     * @inheritDoc
     */
    public function getCreateTime(): int
    {
        if (isset($this->info['ctime'])) {
            return $this->info['ctime'];
        }
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getModifyTime(): int
    {
        if (isset($this->info['mtime'])) {
            return $this->info['mtime'];
        }
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getBasename(string $suffix = ""): string
    {
        if (isset($this->info['key'])) {
            return basename($this->info['key'], $suffix);
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getExtension(): string
    {
        if (isset($this->info['key'])) {
            return pathinfo($this->info['key'], PATHINFO_EXTENSION);
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getFilename(): string
    {
        if (isset($this->info['key'])) {
            return $this->info['key'];
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getPath(): string
    {
        if (isset($this->info['key'])) {
            return dirname($this->info['key']);
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getRealname(): string
    {
        if (isset($this->info['key'])) {
            return $this->info['key'];
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        if (isset($this->info['key'])) {
            return $this->info['key'];
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getSize(): int
    {
        if (isset($this->info['size'])) {
            return $this->info['size'];
        }
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getMimeType(): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function isDir(): bool
    {
        if (isset($this->info['is_dir'])) {
            return $this->info['is_dir'];
        } else {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function isFile(): bool
    {
        return !$this->isDir();
    }

    /**
     * @inheritDoc
     */
    public function getContent(): string
    {
        $tmp_file = tempnam(sys_get_temp_dir(), 'openlist_');
        if ($this->isFile()) {
            if (isset($this->info['url']) && $this->info['url']) {
                $this->client->getClient()->downloadTo($this->info['url'], $tmp_file);
                $content = file_get_contents($tmp_file);
                unlink($tmp_file);
                return $content !== false ? $content : '';
            }
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function putContent(string $content, bool $append = false): bool
    {
        $tmp_file = tempnam(sys_get_temp_dir(), 'openlist_');
        if ($append) {
            $state = false;
            if (isset($this->info['url']) && $this->info['url']) {
                $state = $this->client->getClient()->downloadTo($this->info['url'], $tmp_file);
                if ($state) {
                    file_put_contents($tmp_file, $content, FILE_APPEND);
                }
            }

            if (!$state) {
                file_put_contents($tmp_file, $content);
            }
        } else {
            file_put_contents($tmp_file, $content);
        }

        $this->client->getClient()->uploadPost($tmp_file, $this->info['path']);
        unlink($tmp_file);
        return true;
    }

    /**
     * @inheritDoc
     */
    public function delete(): bool
    {
        return $this->client->delete($this->getKey());
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        return $this->getKey();
    }
}
