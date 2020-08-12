<?php


namespace Wty\Mongodb;


use Swoole\Coroutine;
use Wty\Mongodb\Authenticate\Scram;
use Wty\Mongodb\Exceptions\ConnectException;

class Mongodb
{
    private ?Connection $connection = null;
    public int $w = 1;
    public int $wtimeout = 1000;
    public bool $journal = false;
    public ?int $stimeout = null;
    public array $hosts;


    private bool $connected = false;
    private string $defaultDb;
    private ?string $username = null;
    private ?string $password = null;
    private ?string $authAlgo = null;
    private bool $ssl = false;
    private ?array $version = null;

    private ?string $database = null;

    private $pool;

    private array $connectionArr = [];

    private Manager $manager;

    public function __construct(string $url, $pool = null)
    {
        $config = Utils::parseUrl($url);

        foreach ($config['query'] as $k => $v)
        {
            switch ($k)
            {
                case 'authMechanism':
                    $this->authAlgo = $v;
                    break;
                case 'w':
                    $this->w = $v;
                    break;
                case 'wtimeoutMS':
                    $this->wtimeout = $v;
                    break;
                case 'journal':
                    $this->journal = $v;
                    break;
                case 'ssl':
                    $this->ssl = $v;
                    break;
            }
        }

        $this->hosts = $config['hosts'];
        $this->username = $config['user'] ?? null;
        $this->password = $config['password'] ?? null;
        $this->defaultDb = $config['database'];

        $this->manager = new Manager($this);

        $this->pool = $pool;
    }

    /**
     * @param string $database
     */
    public function setDatabase(string $database): self
    {
        $this->database = $database;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * @return array|null
     */
    public function getVersion(): ?array
    {
        if(is_null($this->version))
        {
            $this->connect();
        }

        return $this->version;
    }

    private function auth(): bool
    {
        if(is_null($this->username))
            return true;

        $s = new Scram($this->connection, $this->defaultDb, $this->username, $this->password);

        return $s->auth($this->authAlgo);
    }

    public function getCollection(string $name): Collection
    {
        if(!isset($this->connectionArr[$name]))
            $this->connectionArr[$name] = new Collection($this, $name);

        return $this->connectionArr[$name];
    }

    public function connect()
    {
        if($this->connected)return;

        shuffle($this->hosts);

        foreach ($this->hosts as $host)
        {
            $this->connection = new Connection($host['host'], $host['port'],  $this->pool);

            if(is_null($this->version))
            {
                $ret = $this->connection->runCmd($this->defaultDb, [
                    'buildInfo' => true
                ], []);

                $this->version = $ret->versionArray;
            }

            $ret = $this->connection->runCmd($this->defaultDb, [
                'isMaster' => 1,
            ], [
                'saslSupportedMechs' => $this->defaultDb . '.' . $this->username
            ]);
//
            if(is_null($ret))
            {
                continue;
            }

            if($ret->ismaster)
            {
                if(!is_null($this->username))
                {
                    $allowMechs = $ret->saslSupportedMechs;

                    if(is_null($this->authAlgo))
                    {
                        if(in_array('SCRAM-SHA-256', $allowMechs))
                        {
                            $this->authAlgo = 'SCRAM-SHA-256';
                        }
                        elseif (in_array('SCRAM-SHA-1', $allowMechs))
                        {
                            $this->authAlgo = 'SCRAM-SHA-1';
                        }
                    }

                    $this->connected = $this->auth();
                }
                else
                {
                    $this->connected = true;
                }

                return;
            }
            elseif($ret->primary)
            {

            }
        }

        throw new ConnectException('can not connect to server');
    }

    public function getManager()
    {
        return $this->manager;
    }

    public function getConnection()
    {
        return $this->connection;
    }


    public function close()
    {
        $this->connection->close();
    }
}