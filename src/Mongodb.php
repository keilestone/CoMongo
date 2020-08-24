<?php


namespace Wty\Mongodb;


use Swoole\Coroutine;
use Wty\Mongodb\Authenticate\Scram;
use Wty\Mongodb\Exceptions\ConnectException;
use Wty\Mongodb\interfaces\PoolInterface;

class Mongodb
{
    private ?Connection $connection = null;
    public int $w = 1;
    public int $wtimeout = 1000;
    public bool $journal = false;
    public ?int $stimeout = null;
    public array $hosts;

    private string $defaultDb;
    private ?string $username = null;
    private ?string $password = null;
    private string $authAlgo = 'SCRAM-SHA-256';
    private bool $ssl = false;

    private ?string $database = null;

    private ?PoolInterface $pool;

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
        if(is_null($this->connection->getDbVersion()))
        {
            $ret = $this->connection->runCmd($this->defaultDb, [
                'buildInfo' => true
            ], []);

            $this->connection->setDbVersion($ret->versionArray);
        }

        return $this->connection->getDbVersion();
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

    public function __destruct()
    {
//        $this->close();
    }

    public function connect()
    {
        if(!is_null($this->connection))return;

        shuffle($this->hosts);

        foreach ($this->hosts as $host)
        {
            if(is_null($this->pool))
            {
                $this->connection = new Connection($host['host'], $host['port']);
                $this->connection->connect();
            }
            else
            {
                $this->pool->setHost($host['host']);
                $this->pool->setPort($host['port']);

                $this->connection = $this->pool->get();
            }

            $this->getVersion();

            if($this->connection->setAuth($this->username, $this->password, $this->defaultDb, $this->authAlgo))
                return;
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
        if(is_null($this->pool))
            $this->connection->close();
        else
            $this->pool->put($this->connection);

        $this->connection = null;
    }
}