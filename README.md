#基于swoole的协程客户端
##示例
```php
Swoole\Coroutine::create(function (){
    $m = new \Wty\Mongodb\Mongodb('mongodb://localhost');

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