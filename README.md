#基于swoole的协程客户端
##示例
```php
Swoole\Coroutine::create(function (){
    $m = new \Wty\Mongodb\Mongodb('mongodb://localhost');
    $m->connect();
    $db = $m->setDatabase('test')->getCollection('test');

    $r = $db->insertOne(['name' => 'test']);

    print_r($r);

    $r = $db->findOne([
        'name' => 'test'
    ]);

    print_r($r->getDocs());

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

    print_r($r->getDocs());

    $r = $db->findOne([
        'name' => 'test2'
    ]);

    print_r($r->getDocs());


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
Array
(
    [ids] => Array
        (
            [0] => MongoDB\BSON\ObjectId Object
                (
                    [oid] => 5f3277dc817fab3971077751
                )

        )

    [count] => 1
)
Array
(
    [0] => stdClass Object
        (
            [_id] => MongoDB\BSON\ObjectId Object
                (
                    [oid] => 5f3277dc817fab3971077751
                )

            [name] => test
        )

)
stdClass Object
(
    [_id] => MongoDB\BSON\ObjectId Object
        (
            [oid] => 5f3277dc817fab3971077751
        )

    [name] => test
)
Array
(
)
Array
(
    [0] => stdClass Object
        (
            [_id] => MongoDB\BSON\ObjectId Object
                (
                    [oid] => 5f3277dc817fab3971077751
                )

            [name] => test2
            [value] => 2222
        )

)
1
```

连接池
```php
    Runtime::enableCoroutine();
    
    const N = 1024;
    
    $s = microtime(true);
    
    Coroutine\run(function () {
            $pool = new \Wty\Mongodb\Pool(10);
        
            $s = microtime(true);
        
            for($i = N; $i--;)
            {
                Coroutine::create(function () use($pool, $s){
                    $db = new Wty\Mongodb\Mongodb(URL, $pool);
        
                    $db->setDatabase(DB);
        
                    $col = $db->getCollection(COLLECTION);
        
                    $db->connect();
        
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
    
    $s = microtime(true) - $s;
    echo 'Use ' . $s . 's for ' . (N*20) . ' insert with connection pool' . PHP_EOL;
    
    $s = microtime(true);
    
    Coroutine\run(function () {
        $s = microtime(true);
        //    $pool = null;
        for($i = N; $i--;)
        {
            Coroutine::create(function () use($s){
                $db = new Wty\Mongodb\Mongodb(URL);
    
                $db->setDatabase(DB);
    
                $col = $db->getCollection(COLLECTION);
    
                $db->connect();
    
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
```

以上输出
```
Use 2.1558599472046s for 20480 insert with connection pool

Use 21.772501945496s for 20480 insert without connection pool
```

使用连接池连接数量稳定在20