<?php
namespace MyDBMQ\Mysql;

use MyDBMQ\Mysql\DBMQException;
use MyDBMQ\Mysql\DBMQPdoCommon;
use MyDBMQ\Mysql\DBMQMessageConsumResponse;
use MyDBMQ\Mysql\DBMQMessageStatus;
use MyDBMQ\Mysql\DBMQFileLock;
use \Exception;

class DBMQConsumer
{
    private $pdoCommon;
    private $fileLock;
    private $lastOutSysLoadAverageTime;
    private $logConfig;
    private $logDir;
    private $consumerKey;
    private $consumerData;
    private $consumerTags;
    private $noConsumerTags;
    private $excptionTags;
    private $closeTags;
    private $onConsumeCallback;
    private $onLoggingCallback;
    private $lastRunCloseMessageTime;
    private $processSize;
    private $processIndex;

    /**
     * DBMQConsumer constructor.
     * @param string $consumerKey 消费者KEY
     * @param null $pdo
     * @param array $logConfig
     * @param int $processSize 进程数量（如果只开一个线程，填写0）
     * @param int $processIndex 进程索引
     */
    public function __construct($consumerKey, $pdo = null, $logConfig = array('dir' => ''), $processSize = 0, $processIndex = 0)
    {
        register_shutdown_function(array($this, 'shutdown'));
        $this->processSize = intval($processSize);
        $this->processIndex = intval($processIndex);
        $this->consumerKey = $consumerKey;
        $this->pdoCommon = new DBMQPdoCommon($pdo);
        if (empty($logConfig['dir'])) {
            $logConfig['dir'] = __DIR__ . '/storage';
        }
        $this->logDir = $logConfig['dir'] . '/DBMQ/log';
        if ($this->processSize > 0 && $this->processIndex >= 0) {
            $this->fileLock = new DBMQFileLock($logConfig['dir'] . '/DBMQ/lock', $consumerKey . '_' . $processSize . '_' . $processIndex, 300);
        } else {
            $this->fileLock = new DBMQFileLock($logConfig['dir'] . '/DBMQ/lock', $consumerKey, 300);
        }
        $this->fileLock->mkdirs($this->logDir);
    }

    public function __destruct()
    {
        //$this->updateConsumer($this->consumerData['consumerid'],array('status'=>ConsumerStatus::waitRun));
    }

    public function shutdown()
    {
        $error = error_get_last();
        $fatalErrorTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR);
        if (in_array($error['type'], $fatalErrorTypes)) {
            $this->log('消费者退出，异常：' . print_r($error, true), 'mq_' . $this->consumerKey . '_exit');
        }

        $this->updateConsumer($this->consumerData['consumerid'], array('processid' => 0, 'status' => ConsumerStatus::waitRun));
        if ($this->fileLock->isLocked()) {
            $this->fileLock->unlock();
        }
    }

    /**
     * Function run
     * @desc
     * @param $onConsumeCallback 消费回调
     */
    public function run($onConsumeCallback)
    {
        if ($this->fileLock->isLocked()) {
            echo $this->consumerKey . ' is running.';
            return;
        }
        $this->fileLock->lock();

        //获取消费者
        $this->consumerData = $this->getConsumer();
        if (empty($this->consumerData)) {
            $errrormsg = '消费者key:' . $this->consumerKey . ',不存在.';
            echo $errrormsg;
            $this->log($errrormsg, 'consumer_runtime_error');
            return;
        } else if ($this->consumerData['status'] == ConsumerStatus::stop) {
            $errrormsg = '消费者key:' . $this->consumerKey . ',状态为停止运行.';
            echo $errrormsg;
            $this->log($errrormsg, 'consumer_stop_log');
            return;
        }

        $this->onConsumeCallback = $onConsumeCallback;

        $errrormsg = '消费者key:' . $this->consumerKey . ',状态为启动中.';
        echo $errrormsg;
        $this->log($errrormsg, 'consumer_running_log');

        // 消费者 tag 类型 为自选
        if ($this->consumerData['tag_type'] != TagType::all) {
            $this->consumerTags = $this->getConsumerTags();
            $noConsumerTags = $this->getNoConsumerTags();
            if (!empty($noConsumerTags)) {
                foreach ($noConsumerTags as $noTag) {
                    if (in_array($noTag, $this->consumerTags)) {
                        $errrormsg = '消费者key:' . $this->consumerKey . '的tag:' . $noTag . '与其他消费者冲突.';
                        echo $errrormsg;
                        $this->log($errrormsg, 'consumer_error_log');
                        return;
                    }
                }
            }
        } else {
            $this->noConsumerTags = $this->getNoConsumerTags();
        }

        // 更新 消费者的状态为运行中
        $pid = getmypid();
        $this->updateConsumer($this->consumerData['consumerid'], array('processid' => $pid, 'status' => ConsumerStatus::running));

        //消费消息
        while (true) {
            $this->fileLock->lock();
            if ($this->checkSysLoadAverageOut()) {
                sleep(60);
                $lockpid = $this->fileLock->getlockpid();
                if ($lockpid != getmypid()) {
                    break;
                }

                continue;
            } else {
                $this->consumeMessage();
                usleep(1000 * 100);
                $lockpid = $this->fileLock->getlockpid();
                if ($lockpid != getmypid()) {
                    break;
                }
            }
        }
    }

    /**
     * Function onLogging
     * @desc 记录日志事件
     * @param $onLoggingCallback
     */
    public function onLogging($onLoggingCallback)
    {
        $this->onLoggingCallback = $onLoggingCallback;
    }

    /**
     * Function log
     * @desc 记录日志
     * @param $message
     * @param $messageKey
     */
    private function log($message, $messageKey)
    {
        $message = date('Y-m-d H:i:s') . PHP_EOL . $message . PHP_EOL . PHP_EOL;
        error_log($message, 3, $this->logDir . '/' . date('Y-m-d') . $messageKey . '.log');
        if (empty($this->onLoggingCallback) == false) {
            $onLoggingCallback = $this->onLoggingCallback;
            $onLoggingCallback($message, $messageKey);
        }
    }

    /**
     * Function consumeMessage
     * @desc 消费消息
     */
    private function consumeMessage()
    {
        // 从数据库获取数据
        while ($rows = $this->getMessage()) {
            foreach ($rows as $data) {
                $this->fileLock->lock();

                $consumer = $this->getConsumer();
                /*if($consumer['processid'] != getmypid()){
                    throw new Exception('同时多个相同消费者在运行，db中为：'.$consumer['processid'].'，当前为：'.getmypid());
                }*/
                if ($consumer['status'] == ConsumerStatus::stop) {
                    die('消费者正常停止退出.');
                }

                //$errrormsg = 'key:'.$data['key'].'  tag:'.$data['tag'].'  messageid:'.$data['messageid'].' pid:'.getmypid().' time:'.microtime();
                //$this->log($errrormsg,'consumer_call_'.$this->consumerKey);

                // 消费消息
                try {
                    $onConsumeCallback = $this->onConsumeCallback;
                    $result = $onConsumeCallback($data);
                    if (($result instanceof DBMQMessageConsumResponse) == false) {
                        $result = DBMQMessageConsumResponse::isException('dbmq消费响应结果未知.');
                    }
                } catch (Exception $e) {
                    $result = DBMQMessageConsumResponse::isException($e->getMessage() . PHP_EOL . $e->getTraceAsString());
                }

                $this->processConsumedMessage($data, $result);
                $lockpid = $this->fileLock->getlockpid();
                if ($lockpid != getmypid()) {
                    break;
                }
            }

            if ($this->checkSysLoadAverageOut()) {
                break;
            }

            usleep(1000 * 10);
        }

        if (empty($this->closeTags) == false && (time() - $this->lastRunCloseMessageTime) > 60) {
            $this->lastRunCloseMessageTime = time();
            $closeTags = $this->closeTags;
            foreach ($closeTags as $closeTag) {
                $this->fileLock->lock();

                $consumer = $this->getConsumer();
                /*if($consumer['processid'] != getmypid()){
                    throw new Exception('同时多个相同消费者在运行，db中为：'.$consumer['processid'].'，当前为：'.getmypid());
                }*/
                if ($consumer['status'] == ConsumerStatus::stop) {
                    die('消费者正常停止退出.');
                }

                $data = $this->getCloseMessage($closeTag);
                if (empty($data)) {
                    unset($this->closeTags[$closeTag]);
                } else {

                    // 消费消息
                    try {
                        $onConsumeCallback = $this->onConsumeCallback;
                        $result = $onConsumeCallback($data);
                        if (($result instanceof DBMQMessageConsumResponse) == false) {
                            $result = DBMQMessageConsumResponse::isException('dbmq消费响应结果未知.');
                        }
                    } catch (Exception $e) {
                        $result = DBMQMessageConsumResponse::isException($e->getMessage() . PHP_EOL . $e->getTraceAsString());
                    }

                    $this->processConsumedMessage($data, $result);
                    $lockpid = $this->fileLock->getlockpid();
                    if ($lockpid != getmypid()) {
                        return;
                    }
                }
            }
        }
    }

    /**
     * Function checkSysLoadAverageOut
     * @desc 检查系统负载
     * @return bool
     */
    private function checkSysLoadAverageOut()
    {
        $cpuCores = $this->getCPUCores();
        $sysLoadAverage = $this->getSysLoadAverage();
        if ($sysLoadAverage > $cpuCores * $this->consumerData['max_sys_load_average']) {
            if ((time() - $this->lastOutSysLoadAverageTime) > 1 * 60) {
                $this->log('系统负载率超出，当前负载率为：' . $sysLoadAverage, 'outSysLoadAverage');
                $this->lastOutSysLoadAverageTime = time();
            }

            return true;
        }

        return false;
    }

    /**
     * Function processConsumedMessage
     * @desc 处理消费消息结果
     * @param array $message
     * @param \MyDBMQ\Mysql\DBMQMessageConsumResponse $result
     */
    private function processConsumedMessage(array $message, DBMQMessageConsumResponse $result)
    {
        $tag = $message['tag'];
        switch ($result->messageConsumStatus) {
            case DBMQMessageConsumStatus::success:
                // 处理成功消息
                $this->excptionTags[$tag] = 0;
                unset($this->closeTags[$tag]);
                $this->deleteMessage($message['messageid']);
                $this->insertMessageLog($tag, $message['key'], $message['body']);
                break;
            case DBMQMessageConsumStatus::fail;
                // 处理失败消息
                $this->excptionTags[$tag] = 0;
                unset($this->closeTags[$tag]);

                if ($message['fail_times'] >= 3) {
                    $updateRow = array('status' => DBMQMessageStatus::fail, 'note' => $result->message, 'timestamp' => '');
                } else {
                    $updateRow = array('fail_times' => $message['fail_times'] + 1, 'note' => $result->message);
                }

                $this->updateMessage($message['messageid'], $updateRow, -30);
                break;
            case DBMQMessageConsumStatus::exception:
                // 处理异常消息
                $this->excptionTags[$tag]++;
                if ($this->excptionTags[$tag] > 10) {
                    $this->closeTags[$tag] = $tag;
                }

                if ($message['exception_times'] >= 16) {
                    $updateRow = array('status' => DBMQMessageStatus::fail, 'note' => $result->message);
                } else {
                    $updateRow = array('exception_times' => $message['exception_times'] + 1, 'note' => $result->message);
                }

                $this->updateMessage($message['messageid'], $updateRow, -30);
                break;
        }
    }

    /**
     * Function getMessage
     * @desc 获取消息
     * @return array
     */
    private function getMessage()
    {
        $condition = '`status`=0 and channel=? and `timestamp`<=now()';
        $bindParams = array($this->consumerData['channel']);
        if ($this->consumerData['tag_type'] != TagType::all) {
            $condition .= " and tag in({$this->bindParamsForInOperator($bindParams,$this->consumerTags)})";
        } elseif (empty($this->noConsumerTags) == false) {
            $condition .= " and tag not in({$this->bindParamsForInOperator($bindParams,$this->noConsumerTags)})";
        }

        if (empty($this->closeTags) == false) {
            $condition .= " and tag not in({$this->bindParamsForInOperator($bindParams,$this->closeTags)})";
        }

        if ($this->processSize > 0 && $this->processIndex >= 0) {
            $bindParams[] = $this->processSize;
            $bindParams[] = $this->processIndex;
            $condition .= " and MOD(messageid,?) = ?";
        }

        $sql = 'select * from mq_message where ' . $condition . ' order by `timestamp` limit 10;';
        return $this->pdoCommon->fetchAll($sql, $bindParams);
    }

    private function bindParamsForInOperator(& $bindParams, $data)
    {
        $params = array();
        foreach ($data as $val) {
            $params[] = '?';
            $bindParams[] = $val;
        }
        return join(',', $params);
    }

    /**
     * Function getCloseMessage
     * @desc
     * @param $tag
     * @return bool|mixed
     */
    private function getCloseMessage($tag)
    {
        $condition = '`status`=0 and channel=? and tag=?';
        $bindParams = array($this->consumerData['channel'], $tag);
        return $this->pdoCommon->fetchRow('select * from mq_message where ' . $condition . ' order by `timestamp` limit 1;', $bindParams);
    }

    /**
     * Function getConsumer
     * @desc 获取消费者
     * @return bool|mixed
     */
    private function getConsumer()
    {
        $sql = 'select * from mq_consumer where consumer_key = ?;';
        $bindParams = array($this->consumerKey);
        return $this->pdoCommon->fetchRow($sql, $bindParams);
    }

    /**
     * @return array
     */
    private function getConsumerTags()
    {
        $sql = 'select tag from mq_consumer_tag where consumer_key = ?;';
        $bindParams = array($this->consumerKey);
        $rows = $this->pdoCommon->fetchAll($sql, $bindParams);
        $data = array();
        foreach ($rows as $row) {
            $data[] = $row['tag'];
        }

        return $data;
    }

    /**
     * @return array
     */
    private function getNoConsumerTags()
    {
        $sql = 'select tag from mq_consumer_tag where consumer_key != ? and channel = ?;';
        $bindParams = array($this->consumerKey, $this->consumerData['channel']);
        $rows = $this->pdoCommon->fetchAll($sql, $bindParams);
        $data = array();
        foreach ($rows as $row) {
            $data[] = $row['tag'];
        }

        return $data;
    }

    /**
     * Function updateMessage
     * @desc 更新消息
     * @param $messageid
     * @param $row
     * @param int $timestamp
     */
    private function updateMessage($messageid, $row, $timestamp = 0)
    {
        $col = array();
        $bindParams = array();
        foreach ($row as $k => $v) {
            $col[] = $k . '=?';
            $bindParams[] = $v;
        }
        if ($timestamp != 0) {
            $timestamp = intval($timestamp);
            $col[] = 'timestamp=DATE_SUB(NOW(),INTERVAL ' . $timestamp . ' SECOND)';
        }
        $bindParams[] = $messageid;
        $colstr = implode(',', $col);
        $sql = 'update mq_message set ' . $colstr . ' where messageid=?';
        $this->pdoCommon->execute($sql, $bindParams);
    }

    /**
     * Function updateConsumer
     * @desc 更新消费者
     * @param $consumerid
     * @param $row
     */
    private function updateConsumer($consumerid, $row)
    {
        $col = array();
        $bindParams = array();
        foreach ($row as $k => $v) {
            $col[] = $k . '=?';
            $bindParams[] = $v;
        }

        $bindParams[] = $consumerid;
        $colstr = implode(',', $col);
        $sql = 'update mq_consumer set ' . $colstr . ' where consumerid=?';
        $this->pdoCommon->execute($sql, $bindParams);
    }

    private function deleteMessage($messageid)
    {
        $sql = 'delete from mq_message where messageid=?;';
        $bindParams = array($messageid);
        $this->pdoCommon->execute($sql, $bindParams);
    }

    private function insertMessageLog($tag, $key, $body)
    {
        $sql = 'insert into mq_message_log(`channel`,`tag`,`key`,`body`) values(?,?,?,?);';
        $bindParams = array($this->consumerData['channel'], $tag, $key, $body);
        $this->pdoCommon->execute($sql, $bindParams);
    }

    /**
     * @return int
     */
    private function getCPUCores()
    {
        if (preg_match('/linux/i', PHP_OS) || preg_match('/Unix/i', PHP_OS)) {
            if ($str = @file("/proc/cpuinfo")) {
                $str = implode("", $str);
                @preg_match_all("/model\s+name\s{0,}\:+\s{0,}([\w\s\)\(\@.-]+)([\r\n]+)/s", $str, $model);
                if (false !== is_array($model[1])) {
                    return sizeof($model[1]);
                }
            }
        }

        return 1;
    }

    /**
     * @return int|mixed
     */
    private function getSysLoadAverage()
    {
        if (preg_match('/linux/i', PHP_OS) || preg_match('/Unix/i', PHP_OS)) {
            exec('uptime', $out);
            if (empty($out) == false && count($out) > 0) {
                $arr = explode('load average:', $out[0]);
                $arr = explode(',', end($arr));
                return end($arr);
            }
        }

        return 0;
    }

    /**
     * @param $pid
     * @return array|bool
     */
    private function getpidinfo($pid)
    {
        $ps = shell_exec("ps p " . $pid);
        $ps = explode("\n", $ps);

        if (count($ps) < 2) {
            return false;
        }

        $psTempKeys = explode(" ", $ps[0]);
        $psTempValues = explode(" ", $ps[1]);
        $psKeys = array();
        foreach ($psTempKeys as $val) {
            if ($val != '') {
                $psKeys[] = $val;
            }
        }

        $psValues = array();
        foreach ($psTempValues as $val) {
            if ($val != '') {
                $psValues[] = $val;
            }
        }
        $pidinfo = array();
        foreach ($psKeys as $key => $val) {
            $pidinfo[$val] = $psValues[$key];
        }

        if (count($psValues > count($psKeys))) {
            $pidinfo[end($psKeys)] = implode(" ", array_slice($psValues, count($psKeys) - 1));
        }

        return $pidinfo;
    }
}

class TagType
{
    /**
     *全部
     */
    const all = 0;
    /**
     *自选
     */
    const select = 1;
}

/**
 * 消费者状态
 */
class ConsumerStatus
{
    /**
     * 等待运行
     */
    const waitRun = 0;
    /**
     * 运行中
     */
    const running = 1;
    /**
     * 停止
     */
    const stop = 2;
}