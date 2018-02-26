<?php
require dirname(__DIR__) .'/vendor/autoload.php';
use MyDBMQ\Mysql\DBMQPublisher;

//消息生产者 添加一条消息
$publisher = new DBMQPublisher('test_channel');
$params = ['app_code'=>'test_code', 'refer_no'=>'test_refer_no'];
$body = json_encode($params);
$key = 'test_consumer_key';//消费者key
$tag = 'test_tag';
$publisher->send($tag,$key,$body);

