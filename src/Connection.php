<?php
namespace Wty\Mongodb;

use MongoDB\Driver\Exception\AuthenticationException;
use RuntimeException;
use Swoole\Coroutine\Client;
use Wty\Mongodb\Authenticate\Scram;
use Wty\Mongodb\interfaces\PoolInterface;
use function MongoDB\BSON\fromPHP;
use function MongoDB\BSON\toPHP;

/**
 * Class Connection
 * @package Wty\Mongodb
 *
 *
 * @See https://elemefe.gitbooks.io/mongodb/content/reference/write-protocol.html
 */
class Connection
{
    private ?Client $client = null;

    private int $id;

    private ?string $error = null;

    private $recieve;

    private bool $auth = false;

    /**
     * @return bool
     */
    public function isAuth(): bool
    {
        return $this->auth;
    }

    private string $host;
    private int $port;
    private int $timeout;

    private ?array $dbVersion = null;

    private ?string $username = null;
    private ?string $password;
    private string $db;
    private string $authAlgo;

    public function __construct(string $host, int $port, int $timeout = 3000)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;

        $this->id = 0;
    }


    public function __destruct()
    {
//        $this->pool->close();
    }

    public function isConnected()
    {
        if(is_null($this->client))
            return false;

        return $this->client->isConnected();
    }

    /**
     * @return array | null
     */
    public function getDbVersion(): ?array
    {
        return $this->dbVersion;
    }

    /**
     * @param array $dbVersion
     */
    public function setDbVersion(array $dbVersion): void
    {
        $this->dbVersion = $dbVersion;
    }

    public function setAuth(?string $username, ?string $password, string $db, string $authAlgo): bool
    {
        $this->username = $username;
        $this->password = $password;
        $this->authAlgo = $authAlgo;
        $this->db = $db;

        if(is_null($username) || $this->auth)
        {
            $this->auth = true;
            return true;
        }

        $ret = $this->runCmd($db, [
            'isMaster' => 1,
        ], [
            'saslSupportedMechs' => $db . '.' . $username
        ]);
//
        if(is_null($ret))
        {
            return false;
        }

        $doc = $ret->getFirstDoc();

        if($doc->ismaster)
        {
            $allowMechs = $doc->saslSupportedMechs;

            if(is_null($authAlgo))
            {
                if(in_array('SCRAM-SHA-256', $allowMechs))
                {
                    $authAlgo = 'SCRAM-SHA-256';
                }
                elseif (in_array('SCRAM-SHA-1', $allowMechs))
                {
                    $authAlgo = 'SCRAM-SHA-1';
                }
            }
        }
        elseif($doc->primary)
        {

        }

        $s = new Scram($this, $db, $username, $password);

        if($s->auth($authAlgo))
        {
            $this->auth = true;
            return true;
        }

        return false;
    }


    public function connect()
    {
        if(is_null($this->client))
        {
            $this->client = new Client(SWOOLE_SOCK_TCP);

            $this->client->set([
                'timeout' => $this->timeout / 1000,
                'open_length_check' => true,
                'package_length_func' => function($data){
                    $arr = unpack('Vlen', $data);

                    return $arr['len'];
                },

            ]);

            $retry = 0;

            while($this->client->connect($this->host, intval($this->port)) == false)
            {
                $this->client->close();
                if(++$retry > 3)
                {
                    throw new RuntimeException('can not connect to server');
                }
            }
        }

//        return $this->client;
    }

    public function reconnect()
    {
        if(is_null($this->client))
        {
            throw new RuntimeException('can not reconnect after close or before connect');
        }
        $this->connect();
        $this->setAuth($this->username, $this->password, $this->db, $this->authAlgo);
    }

    public function close()
    {
        $this->client->close();
        $this->client = null;
    }

    public function send($data)
    {
//        $this->connect();
        $this->client->send($data);
        $this->recieve = $this->client->recv(3);
//        $this->close();
    }

    public function receive(): ?string
    {
        $rs = $this->recieve;

        if($rs === false)
        {
            $this->error = $this->client->errMsg;
            return null;
        }
        elseif (strlen($rs) === 0)
        {
            $this->close();

            $this->error = '服务端主动关闭连接';
            return null;
        }

        return $rs;
    }

    public function parseResponse(): ?Reply
    {
        $data = $this->receive();

        if(empty($data))
            return null;

        $arr = unpack('Vlen/Vrid/Vrto/Vopcode/Vflags/Pcid/Vstart/Vnum', $data);

        if($this->id != $arr['rto'])
            return null;

        $docs = [];

        switch ($arr['opcode'])
        {
            case OPCode::opReply:
                $bson = substr($data, 36, $arr['len'] - 36);

                for ($count = 0; $count < $arr['num']; $count++)
                {
                    $bArr = unpack('Vlen', $bson);

                    $theBson = substr($bson, 0, $bArr['len']);

                    array_push($docs, toPHP($theBson, []));

                    $bson = substr($bson, $bArr['len']);
                }

                return new Reply($arr['flags'], $arr['cid'], $arr['start'], $arr['num'], $docs, OPCode::opReply);
            case OPCode::opMSG:
                $sectionsLen = $arr['len'] - 20;

                $len = 20;
                do
                {
                    $sections = substr($data, 20, $sectionsLen);
                    $info = unpack('Ctype/Vlen', $sections);

                    $len += $info['len'];

                    $sections = substr($sections, 1, $info['len']);

                    switch ($info['type'])
                    {
                        case 0: //body
                            array_push($docs, toPHP($sections, []));
                            break;
                        case 1: //Document Sequence
                            $docs = array_merge($docs, toPHP($sections, []));
                            break;
                    }
                }while($len >= $arr['len']);

                return new Reply($arr['flags'], 0, 0, 0, $docs, OPCode::opMSG);
            default:
                return null;
        }
    }

    public function setHeader(int $opCode, int $size): string
    {
        $size = $size + 16;

        $reqId = ++$this->id;
        $respId = 0;

        return pack('V4', $size, $reqId, $respId, $opCode);
    }

    public function query(string $collection, array $query, int $toSkip = 0, int $num = 255, array $selector = [], array $flags = []): ?Reply
    {
        $queryBson = fromPHP($query);
        $selectorBson = empty($selector) ? '' : fromPHP($selector);

        $flagSet = 0;

        $offset = [
            'tailable' => 1,
            'slaveok' => 2,
            'notimeout' => 4,
            'await' => 5,
            'exhaust' => 6,
            'partial' => 7
        ];

        foreach ($flags as $key => $value)
        {
            if($value == 1)
            {
                $flagSet = $flagSet | (1 << $offset[$key]);
            }
        }

        $body = pack('Va' . $this->getStrLen($collection) . 'V2', $flagSet, $collection, $toSkip, $num) . $queryBson . $selectorBson;

        $header = $this->setHeader(OPCode::opQuery, strlen($body));

        $this->send($header . $body);

        return $this->parseResponse();
    }

    public function insert(string $collection, array $docs, array $flags = []): bool
    {
        if(!is_array($docs[0]))
            $documents = fromPHP($docs);
        else
        {
            $documents = '';

            foreach ($docs as $doc)
            {
                $documents .= fromPHP($doc);
            }
        }

        if(isset($flags['continue_on_error']) && $flags['continue_on_error'])
        {
            $flag = 1;
        }
        else
            $flag = 0;

        $body = pack('Va' . $this->getStrLen($collection), $flag, $collection) . $documents;

        $header = $this->setHeader(OPCode::opInsert, strlen($body));

        $this->send($header . $body);

        return true;
    }

    public function killCursors(int $id): bool
    {
        $zero = 0;
        $num = 1;

        $body = pack('V2P', $zero, $num, $id);

        $header = $this->setHeader(OPCode::opKillCursors, strlen($body));

        $this->send($header . $body);

        return true;
    }

    public function getMore(string $collection, int $number, int $cid)
    {
        $zero = 0;
        $body = pack('Va' . $this->getStrLen($collection) . 'VP', $zero, $collection, $number, $cid);

        $header = $this->setHeader(OPCode::opGetMore, strlen($body));

        $this->send($header . $body);

        return $this->parseResponse();
    }

    public function msg(array $body, array $sequence = [])
    {
        $flags = 0;

        $sections = pack('C', 0) . fromPHP($body);

        if(!empty($sequence))
        {
            $docs = '';

            foreach ($sequence as $item)
            {
                $docs .= fromPHP($item);
            }

            $sid = 'documents';

            $size = 4 + $this->getStrLen($sid) + strlen($docs);

            $sections = $sections . pack('CVa' . $this->getStrLen($sid), 1, $size, $sid) . $docs;
        }

        $body = pack('V', $flags) . $sections;

        $header = $this->setHeader(OPCode::opMSG, strlen($body));

        $this->send($header . $body);

        return $this->parseResponse();
    }

    public function runCmd(string $db, array $cmd, array $params = [])
    {
        $command = array_merge($cmd, $params);

        $reply = $this->query($db . '.$cmd', $command,0, 1);

        if(is_null($reply) || empty($reply->getDocs()))
            return null;

        if($reply->hasFirstDoc() && $reply->getFirstDoc()->ok != 1)
        {
            $this->error = $reply->getFirstDoc()->errmsg;
            return null;
        }

        return $reply;
    }

    public function getError()
    {
        return $this->error;
    }

    private function getStrLen(string $str): int
    {
        return (strlen($str) + 1);
    }
}