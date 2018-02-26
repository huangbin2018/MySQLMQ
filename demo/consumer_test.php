<?php
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

$consumerKey = 'test_consumer_key'; //消费者key
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
