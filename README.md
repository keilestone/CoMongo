#基于swoole的协程客户端
##示例
```php
Swoole\Coroutine::create(function (){
    $m = new \Wty\Mongodb\Mongodb('mongodb://localhost');
    $db = $m->setDatabase('test')->getCollection('test');
    $m->connect();

    $r = $db->insertOne(['name' => 'test']);

    print_r($r);

    $r = $db->findOne([
        'name' => 'test'
    ]);

    print_r($r);

    $r = $db->findOneAndUpdate([
        'name' => 'test'
    ], [
        'name' => 'test2',
        'value' => 2222
    ]);

    print_r($r);

    $r = $db->findOne([
        'name' => 'test'
    ]);

    var_dump($r);

    $r = $db->findOne([
        'name' => 'test2'
    ]);

    print_r($r);


    $r = $db->update(['name' => 'test2'], [
        '$set' => [
            'name' => 30
        ]
    ]);

    print_r($r);

    $m->close();
});
```

以上输出
```
MongoDB\BSON\ObjectId Object
(
    [oid] => 5f3c922bec2c3a16535cbf11
)
stdClass Object
(
    [_id] => MongoDB\BSON\ObjectId Object
        (
            [oid] => 5f3c922bec2c3a16535cbf11
        )

    [name] => test
)
stdClass Object
(
    [_id] => MongoDB\BSON\ObjectId Object
        (
            [oid] => 5f3c922bec2c3a16535cbf11
        )

    [name] => test
)
NULL
stdClass Object
(
    [_id] => MongoDB\BSON\ObjectId Object
        (
            [oid] => 5f3c922214c3b6525356bf91
        )

    [name] => test2
    [value] => 2222
)
1
```

连接池
```php
const URL = 'mongodb://localhost';

const DB = 'test';

const COLLECTION = 'test';

$s = microtime(true);
Coroutine\run(function () use($s){
    $pool = new \Wty\Mongodb\Pool( 10);

//    $pool = null;
    for($i = N; $i--;)
    {
        Coroutine::create(function () use($pool, $s){
            $db = new Wty\Mongodb\Mongodb(URL, $pool);

            $db->setDatabase(DB);

            $col = $db->getCollection(COLLECTION);

            $db->connect();

//           print_r($r);

            $ret = $col->insertOne([
                'cid' => Coroutine::getCid()
            ]);

            $ret = $col->findOne([
                '_id' => $ret
            ]);

            $col->update([
                '_id' => $ret->_id
            ], [
                '$set' => [
                    'time' => microtime(true) - $s
                ]
            ]);

            $db->close();
        });
    }
});
echo "use " . (microtime(true) - $s) . "s for " . N . " times operations with connection pool" . PHP_EOL;

$s = microtime(true);

Coroutine\run(function () use($s){
    //    $pool = null;
    for($i = N; $i--;)
    {
        Coroutine::create(function () use($s){
            $db = new Wty\Mongodb\Mongodb(URL);

            $db->setDatabase(DB);

            $col = $db->getCollection(COLLECTION);

            $db->connect();

//           print_r($r);

            $ret = $col->insertOne([
                'cid' => Coroutine::getCid()
            ]);

            $ret = $col->findOne([
                '_id' => $ret
            ]);

            $col->update([
                '_id' => $ret->_id
            ], [
                '$set' => [
                    'time' => microtime(true) - $s
                ]
            ]);

            $db->close();
        });
    }
});


echo "use " . (microtime(true) - $s) . "s for " . N . " times operations without connection pool" . PHP_EOL;
```

以上输出
```
use 0.3707070350647s for 1024 times operations with connection pool

use 1.1790390014648s for 1024 times operations without connection pool
```

使用连接池连接数量稳定在10个