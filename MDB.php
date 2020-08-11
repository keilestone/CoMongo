<?php
declare(strict_types=1);

namespace App\Mongo;

use MongoDB\BSON\Binary;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\Exception;
use MongoDB\Driver\Query;
use Swoole\Coroutine\Client;
use function MongoDB\BSON\fromPHP;
use function MongoDB\BSON\toPHP;

class MDB
{
    private const opReply = 1;     /* 对客户端请求的响应. */
    private const dbMsg = 1000;    /* 通常的消息命令（跟着字符串） */
    private const dbUpdate = 2001; /* 更新document消息 */
    private const dbInsert = 2002; /* 插入新document消息*/
    //dbGetByOID = 2003,/*保留*/
    private const dbQuery = 2004;  /* 查询一个集合*/
    private const dbGetMore = 2005; /* 从（一个）查询中获取更多数据，参见 Cursors */
    private const dbDelete = 2006; /* 删除一个或多个document*/
    private const dbKillCursors = 2007; /* 通知数据库，客户端已执行完毕，可以关闭该Cursors*/
    private const OP_COMMAND = 2010;    /*表示命令请求的集群内部协议。已过时，将来会弃用*/
    private const OP_COMMANDREPLY = 2011;    /*集群内部协议表示对OP_COMMAND的回复。已过时，将来会弃用*/
    private const OP_MSG = 2013;    /*使用MongoDB 3.6中引入的格式发送消息。*/

    private $config;

    private $connection;

    private $factory;

    private $client;

    public function __construct()
    {

    }

    public function __destruct()
    {
        echo "destroy" . PHP_EOL;
        $this->closeConnection();
        // TODO: Implement __destruct() method.
    }

    public function reconnect()
    {
//        shuffle($this->config['mongos']);
//        $this->connection = $this->factory->get('coroutine_mongodb', $this->config);

        $this->client = new Client(SWOOLE_SOCK_TCP);

        $this->client->set([
            'timeout' => 3,
            'open_length_check' => true,
            'package_length_func' => function($data){
                $arr = unpack('Vlen', $data);

                return $arr['len'];
            },

        ]);
        $this->client->connect('192.168.2.12', 27017, 1.0);

//        foreach ($this->config['mongos'] as $config) {
//            $client = new Client(SWOOLE_SOCK_TCP);
//
//            if(isset($config['username']))
//            {
//                $this->auth($client);
//            }
//
//            if(!$client->connect($config['host'], intval($config['port']), 1.0))
//            {
//                $client->close();
//                continue;
//            }
//
//            $this->client = $client;
//
//            return;
//        }
//
//        throw new ConnectionException();
    }

    private function closeConnection()
    {
        if($this->connection)
            $this->factory->release($this->connection);
    }

    public function count(string $docName, array $where = [])
    {
        if(!empty($where))
            $command = array_merge([
                [
                    '$match' => $where
                ]
            ], [
                [
                    '$count' => 'mycount'
                ]
            ]);
        else
            $command = [
                [
                    '$count' => 'mycount'
                ]
            ];

        $rs = $this->aggregate($docName, $command);

        if(isset($rs[0]) && isset($rs[0]->mycount))
            return $rs[0]->mycount ?? 0;

        return 0;
    }

    /**
     * @param string $docName
     * @param array $command
     * @param array $options
     * @return array
     */
    public function aggregate(string $docName, array $command, array $options = [])
    {
        $namespace = $this->getNameSpace($docName);

        [$db, $collection] = explode('.', $namespace);

        $command = [
            'aggregate' => $collection,
            'pipeline' => $command,
            '$db' => $db,
            'cursor' => new class{}
        ];

        $this->reconnect();

        $this->client->send($this->msg($command));

        $resp = $this->client->recv();

        $rs = $this->parseMsg($resp);

        if($rs->ok)
        {
            return $rs->cursor->firstBatch;
        }

        return [];
    }


    /**
     * @param string $docName
     * @param array $wheres
     * @param array $options
     * @return array
     */
    public function find(string $docName, array $wheres, array $options)
    {
        $this->reconnect();

        $this->client->send($this->opQuery($this->getNameSpace($docName), $wheres, $options));

        $rs = $this->client->recv();

        $rs = $this->parseResponse($rs);

        return $rs;
    }

    protected function getNameSpace(string $docName): string
    {
        return 'weetool' . '.' . ('wt_') . $docName;
    }


    public function update(string $docName, array $wheres, array $data, array $options = []): int
    {
        $this->reconnect();

        $this->client->send($this->opUpdate($this->getNameSpace($docName), $wheres, $data, $options));

        return 1;
    }

    public function insertMany(string $docName, array $values)
    {
        foreach ($values as $value)
        {
            $this->insert($docName, $value);
        }

        return true;
    }

    public function DeleteMany(string $docName, array $wheres)
    {
        return $this->delete($docName, $wheres, ['multi' => true]);
    }

    public function delete(string $docName, array $conditions, array $options = []): int
    {
        $this->reconnect();

        $this->client->send($this->opDelete($this->getNameSpace($docName), $conditions, $options));

        return 1;
    }

    public function insert(string $docName, array $document): bool
    {
        $this->reconnect();

        $this->client->send($this->opInsert($this->getNameSpace($docName), $document));

        return true;
    }

    private function auth(Client $client)
    {
        $client->send($this->isMaster());
        $response = $client->recv();

        $resp = $this->parseResponse($response);

        $auth = new Authorize();

        $clientFM = $auth->getFirstMessage();

        $clusterTime = '$clusterTime';

        $client->send($this->msg([
            'saslStart' => 1,
            'mechanism' => 'SCRAM-SHA-256',
            'payload' => new Binary($clientFM, Binary::TYPE_MD5),
            'autoAuthorize' => 1,
            '$db' => 'admin',
            '$clusterTime' => $resp->$clusterTime
        ]));

        $response = $client->recv();

        $resp = $this->parseMsg($response);

        $serverFM = $resp->payload->getData();

        $binary = explode(',', $serverFM);

        $salt = base64_decode(substr($binary[1], 2));
        $ic = intval(substr($binary[2], 2));
        $serverNonce = substr($binary[0], 2);

        $passwordS = hash_pbkdf2('sha256', 'weetool_mongo_2020', $salt, $ic, 0, true);
        $keyC = hash_hmac('sha256', 'Client Key', $passwordS, true);

        $final = 'c=' . base64_encode('n,,') . ',r=' . $serverNonce;
        $auth = $clientFM . ',' .  $serverFM . ',' . $final;

        $p = base64_encode($keyC ^ hash_hmac('sha256', $auth, hash('sha256', $keyC, true), true));

        $client->send($this->msg([
            'saslContinue' => 1,
            'conversationId' => $resp->conversationId,
            'payload' => new Binary($final . ',p=' . $p, Binary::TYPE_MD5),
            '$db' => 'admin',
            '$clusterTime' => $resp->$clusterTime
        ]));

        $response = $client->recv();
        $resp = $this->parseMsg($response);

        $client->send($this->msg([
            'saslContinue' => 1,
            'conversationId' => $resp->conversationId,
            'payload' => new Binary('', Binary::TYPE_MD5),
            '$db' => 'admin',
            '$clusterTime' => $resp->$clusterTime
        ]));

        $response = $client->recv();

        $resp = $this->parseMsg($response);
    }

    private function parseMsg(string $rs)
    {
        $arr = unpack('Vlen/V3header/Vflags', $rs);

        $bson = substr($rs, 21);
//print_r($arr);
        return toPHP($bson, []);
    }

    private function msg(array $param)
    {
        $flags = 0;
        $query = fromPHP($param);

        [$reqId, $respId, $opCode] = $this->setHeader(self::OP_MSG);

        $body = pack('V4x', $reqId, $respId, $opCode, $flags) . $query;

        $len = strlen($body) + 4;

        return pack('V', $len) . $body;
    }


    private function isMaster()
    {
        $flags = 0;
        $fullCollectionName = 'admin.$cmd';
        $numberToSkip = 0;
        $num = -1;
        $query = fromPHP(['isMaster' => 1]);

        [$reqId, $respId, $opCode] = $this->setHeader();

        $body = pack('V4a' . $this->getStrLen($fullCollectionName) . 'V2', $reqId, $respId, $opCode, $flags, $fullCollectionName, $numberToSkip, $num) . $query;

        $len = strlen($body) + 4;

        return pack('V', $len) . $body;
    }

    private function setHeader($opCode = self::dbQuery, $reqId = 1, $respId = 0)
    {
        return [$reqId, $respId, $opCode];
    }

    private function parseResponse(string $rs, array $result = [], int $offset = 0)
    {
        if (strlen($rs) == 0)
            return $result;

        $arr = unpack('Vlen/V3header/Vflags/Pcursor/Vstart/Vreturn', $rs);

//        $rsLen = strlen($rs);
//
//        if($rsLen < $arr['len'])
//            $rs .= $this->client->recv();

        $bson = substr($rs, 36, $arr['len'] - 36);

        if($arr['return'] == 0)
            return $result;

//        echo strlen($rs) . PHP_EOL;
//        print_r($arr);
        if ($arr['return'] > 1) {
            $boffset = 0;

            for ($count = 0; $count < $arr['return']; $count++) {
                $bArr = unpack('V1len', $bson);
//                print_r($bArr);
                $theBson = substr($bson, $boffset, $bArr['len']);

                array_push($result, toPHP($theBson, []));

                $bson = substr($bson, $bArr['len']);
            }
        } else
            array_push($result, toPHP($bson, []));

        $offset += $arr['len'];

        return $this->parseResponse(substr($rs, $arr['len']), $result, $offset);
    }


    private function opInsert(string $fullCollectionName, array $data)
    {
        $flag = 0;
        $document = fromPHP($data);

        [$reqId, $respId, $opCode] = $this->setHeader(self::dbInsert);

        $body = pack('V4a' . $this->getStrLen($fullCollectionName), $reqId, $respId, $opCode, $flag, $fullCollectionName) . $document;

        $len = strlen($body) + 4;

        return pack('V', $len) . $body;
    }

    private function opDelete(string $fullCollectionName, array $where, array $options)
    {
        $zero = 0;
        $flags = 0;

        if(isset($options['multi']) && $options['multi'])
            $flags = 1;

        $selector = fromPHP($where);

        [$reqId, $respId, $opCode] = $this->setHeader(self::dbDelete);

        $body = pack('V4a' . $this->getStrLen($fullCollectionName) . 'V', $reqId, $respId, $opCode, $zero, $fullCollectionName, $flags) . $selector;

        $len = strlen($body) + 4;

        return pack('V', $len) . $body;
    }

    private function opUpdate(string $fullCollectionName, array $where, array $doc, array $options)
    {
        $zero = 0;
        $flags = 0;

        if(isset($options['multiple']) && $options['multiple'])
        {
            $flags += 2;
        }

        if(isset($options['upsert']) && $options['upsert'])
        {
            $flags += 1;
        }

        $selector = fromPHP($where);
        $update = fromPHP($doc);

        [$reqId, $respId, $opCode] = $this->setHeader(self::dbUpdate);

        $body = pack('V4a' . $this->getStrLen($fullCollectionName) . 'V' , $reqId, $respId, $opCode, $zero, $fullCollectionName, $flags)
            . $selector . $update;

        $len = strlen($body) + 4;

        return pack('V', $len) . $body;
    }

    private function opQuery(string $fullCollectionName, array $document, array $options)
    {
        $flags = 0;
        $numberToSkip = $options['skip'] ?? 0;
        $num = $options['limit'] ?? 0;
        $query = fromPHP($document);

        [$reqId, $respId, $opCode] = $this->setHeader();

        $body = pack('V4a' . $this->getStrLen($fullCollectionName) . 'V2', $reqId, $respId, $opCode, $flags, $fullCollectionName, $numberToSkip, $num) . $query;

        $len = strlen($body) + 4;

        return pack('V', $len) . $body;
    }

    private function getStrLen(string $str): int
    {
        return (strlen($str) + 1);
    }

    public function query(string $docName, array $filter = [], array $options = [])
    {
        $this->reconnect();

        return $this->opQuery($this->getNameSpace($docName), $filter, $options);
        $this->client->send($this->opQuery($this->getNameSpace($docName), $filter, $options));

        $rs = $this->client->recv();

        $rs = $this->parseResponse($rs);

        return $rs;
    }

    public function distinct(string $docName, string $column, array $wheres = [])
    {
        $namespace = $this->getNameSpace($docName);
        [$db, $collection] = explode('.', $namespace);

        $command = [
            // build the 'distinct' command
            'distinct' => $collection, // specify the collection name
            'key' => $column, // specify the field for which we want to get the distinct values
            'query' => (object) $wheres, // criteria to filter documents,
            '$db' => $db,
            'cursor' => new class{}
        ];

        $this->reconnect();

        $this->client->send($this->msg($command));

        $resp = $this->client->recv();

        $rs = $this->parseMsg($resp);

        if($rs->ok)
        {
            return $rs->values;
        }

        return [];
    }
}