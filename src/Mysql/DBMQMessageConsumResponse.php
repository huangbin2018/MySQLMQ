<?php
namespace MyDBMQ\Mysql;

use MyDBMQ\Mysql\DBMQException;

/**
 * Class DBMQMessageConsumResponse
 */
class DBMQMessageConsumResponse
{
    /**
     * @var DBMQMessageConsumStatus
     */
    public $messageConsumStatus;
    /**
     * @var string
     */
    public $message;

    /** 消费成功
     * @return DBMQMessageConsumResponse
     */
    public static function isSuccess($message = '')
    {
        $mcr = new DBMQMessageConsumResponse();
        $mcr->messageConsumStatus = DBMQMessageConsumStatus::success;
        $mcr->message = $message;
        return $mcr;
    }

    /** 消费失败（指第三方接口返回的失败）
     * @return DBMQMessageConsumResponse
     */
    public static function isFail($message)
    {
        $mcr = new DBMQMessageConsumResponse();
        $mcr->messageConsumStatus = DBMQMessageConsumStatus::fail;
        $mcr->message = $message;
        return $mcr;
    }

    /** 消费成功（指第三方接口或内部消费时出现的异常）
     * @return DBMQMessageConsumResponse
     */
    public static function isException($message)
    {
        $mcr = new DBMQMessageConsumResponse();
        $mcr->messageConsumStatus = DBMQMessageConsumStatus::exception;
        $mcr->message = $message;
        return $mcr;
    }
}

/**
 * Class DBMQMessageConsumStatus
 */
class DBMQMessageConsumStatus
{
    const fail = 0;
    const success = 1;
    const exception = 500;
}