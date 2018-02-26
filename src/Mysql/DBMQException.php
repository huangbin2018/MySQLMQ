<?php
namespace MyDBMQ\Mysql;
class DBMQException extends \Exception
{
    public function __construct($msg = '', $code = 0, \Exception $previous = null)
    {
        parent::__construct($msg, (int)$code, $previous);
    }
}
