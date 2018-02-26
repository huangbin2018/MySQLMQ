<?php
namespace MyDBMQ\Mysql;

use MyDBMQ\Mysql\DBMQPdoCommon;
use MyDBMQ\Mysql\DBMQException;

class DBMQPublisher
{
    private $pdoCommon = null;
    private $channel = null;

    /**
     * DBMQPublisher constructor.
     */
    public function __construct($channel, $pdo = null)
    {
        $this->pdoCommon = new DBMQPdoCommon($pdo);
        $this->channel = $channel;
    }

    /** 添加消息
     * @param $tag 类型
     * @param $key 参考号
     * @param string $body 消息体
     * @throws DBMQException
     */
    public function send($tag, $key, $body = '')
    {
        if (empty($tag)) {
            throw new DBMQException('发布消息异常，tag不能为空.');
        }
        if (empty($key)) {
            throw new DBMQException('发布消息异常，key不能为空.');
        }
        if (empty($body)) {
            $body = '';
        }

        $this->insertMessag($tag, $key, $body);
    }

    private function insertMessag($tag, $key, $body)
    {
        $sql = 'insert into mq_message(`channel`,`tag`,`key`,`body`,`create_date`) values(?,?,?,?,now());';
        $bindParams = array($this->channel, $tag, $key, $body);
        $this->pdoCommon->execute($sql, $bindParams);
    }

    /** 批量添加消息
     * @param array $params （tag,key,body）
     */
    public function sendBatch(array $params)
    {
        $this->batchInsertMessag($params);
    }

    private function batchInsertMessag($params)
    {
        $sql = 'insert into mq_message(`channel`,`tag`,`key`,`body`,`create_date`) values ';
        $val = '(?,?,?,?,now())';
        $values = array();
        $bindParams = array();
        foreach ($params as $item) {
            $values[] = $val;
            if (empty($item['tag'])) {
                throw new DBMQException('发布消息异常，tag不能为空.');
            }
            if (empty($item['key'])) {
                throw new DBMQException('发布消息异常，key不能为空.');
            }
            $bindParams[] = $this->channel;
            $bindParams[] = $item['tag'];
            $bindParams[] = $item['key'];
            $bindParams[] = empty($item['body']) ? '' : $item['body'];
        }
        $sql .= implode(",", $values);
        $sql .= ";";
        $this->pdoCommon->execute($sql, $bindParams);
    }
}