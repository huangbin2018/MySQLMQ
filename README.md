# MySQLMQ
利用mysql简单实现的消息队列，用来同步消息

## 安装
> composer require huangbin2018/my_dbmq
引入 
```PHP
require 'vendor/autoload.php';
```

##使用
1. 导入 sql/sql.sql 脚本，创建数据表
2. 手动添加一个消费者 
```PHP
-- 添加测试 消费者 test_consumer_key
INSERT INTO `mq_consumer` (`consumer_key`, `channel`, `max_sys_load_average`) VALUES ('test_consumer_key', 'test_channel', 10);
```
3. 消息生产者
```PHP
require dirname(__DIR__) .'/vendor/autoload.php';
use MyDBMQ\Mysql\DBMQPublisher;

//消息生产者 添加一条消息 
$publisher = new DBMQPublisher('test_channel');
$params = ['app_code'=>'test_code', 'refer_no'=>'test_refer_no'];
$body = json_encode($params);
$key = 'test_consumer_key';//消费者key
$tag = 'test_tag';
$publisher->send($tag,$key,$body);
```
4. 消息消费者
```PHP
require dirname(__DIR__) .'/vendor/autoload.php';
use MyDBMQ\Mysql\DBMQConsumer;
use MyDBMQ\Mysql\DBMQMessageConsumResponse;

//解决windows CMD cli 输出乱码
$sapi_type = php_sapi_name();
define('IS_WIN',strstr(PHP_OS, 'WIN') ? 1 : 0 );
if($sapi_type == 'cli' && IS_WIN) {
	exec('chcp 65001');
}

register_shutdown_function("errorCheck");
function errorCheck(){
    $error=error_get_last();
    $fatalErrorTypes = array(E_ERROR,E_PARSE,E_CORE_ERROR);
    if (in_array($error['type'],$fatalErrorTypes)){
        print_r($error);
    }
}

$consumerKey = 'test_consumer_key'; //消费者key， 注意要与生产者的key保持一致
$processSize = 0;
$processIndex = 0;
$consumer = new DBMQConsumer($consumerKey, null, [], $processSize,$processIndex);
$consumer->run(function ($message) {
	//执行消费逻辑
	print_r($message);

	try {
		if(1) {
			$result = DBMQMessageConsumResponse::isSuccess('测试消费成功');
		} else {
			$result = DBMQMessageConsumResponse::isFail('测试消费失败');
		}
	} catch(\Exception $e) {
		$result = DBMQMessageConsumResponse::isException($e->getMessage());
	}
	return $result;
});
```

  参考 "demo" 目录下的 test文件
  启动消费者后，会一直循环执行，当有消息产生时，会自动消费
  
