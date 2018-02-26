<?php
namespace MyDBMQ\Mysql;

use MyDBMQ\Mysql\DBMQException;

class DBMQPdoCommon
{
    /**
     * @var PDO
     */
    private static $db = null;

    /**
     * PdoCommon constructor.
     */
    public function __construct($pdo = null)
    {
        if ($pdo) {
            self::$db = $pdo;
        } else {
            self::$db = self::getPdo();
        }
    }

    private static function getPdo()
    {
        if (self::$db) {
            return self::$db;
        }

        $ini = __DIR__ . "/config.ini";
        $parse = parse_ini_file($ini, true);
        $driver = $parse["db_driver"];
        $dsn = "${driver}:";
        $user = $parse["db_user"];
        $password = $parse["db_password"];
        $options = $parse ["db_options"];
        $attributes = $parse["db_attributes"];
        foreach ($parse["dsn"] as $k => $v) {
            $dsn .= "${k}=${v};";
        }
        self::$db = new \PDO($dsn, $user, $password, $options);
        foreach ($attributes as $k => $v) {
            self::$db->setAttribute(constant("PDO::{$k}"), constant("PDO::{$v}"));
        }
        return self::$db;
    }

    public function execute($sql, $params)
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        $stmt->closeCursor();
    }

    public function fetchAll($sql, $params = array())
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        $data = array();
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $data[] = $row;
        }
        return $data;
    }

    public function fetchRow($sql, $params = array())
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        return $row;
    }

    public function fetchOne($sql, $params = array())
    {
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        if (!is_array($row)) {
            return false;
        }
        return $row[0];
    }
}