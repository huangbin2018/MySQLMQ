<?php
namespace MyDBMQ\Mysql;

use MyDBMQ\Mysql\DBMQException;

class DBMQFileLock
{

    private $file = '';

    private $timeout = 0;

    /**
     * FileLock constructor.
     */
    public function __construct($dir, $filename, $timeout)
    {
        $this->mkdirs($dir);
        $this->file = $dir . '/' . $filename;
        $this->timeout = $timeout;
    }

    public function isLocked()
    {
        if (file_exists($this->file)) {
            //如果锁文件存在时间过长删除锁文件
            if ($this->timeout > 0 && time() - filemtime($this->file) > $this->timeout) {
                @unlink($this->file);
                return false;
            }

            return true;
        }

        return false;
    }

    public function lock()
    {
        //加锁,创建锁文件
        file_put_contents($this->file, getmypid());
        //touch($this->file);
        if (preg_match('/linux/i', PHP_OS) || preg_match('/Unix/i', PHP_OS)) {
            chmod($this->file, 0777);
        }
    }

    public function unlock()
    {
        @unlink($this->file);//解锁,删除锁文件
    }

    public function getlockpid()
    {
        return file_get_contents($this->file);
    }

    /**
     * 递归创建路径
     */
    public function mkdirs($path)
    {
        if (empty($path) == false && !file_exists($path)) {
            $this->mkdirs(dirname($path));
            mkdir($path, 0777);
            chmod($path, 0777);
        }
    }
}