<?php


namespace Wty\Mongodb;


use Swoole\Coroutine;
use Wty\Mongodb\Authenticate\Scram;
use Wty\Mongodb\Exceptions\AuthException;
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

    private ?PoolInterface $pool = null;

//    private array $connectionArr = [];

    private Manager $manager;

    public function __construct(string $url, $pool = null)
    {
        $config = Utils::parseUrl($url);

//        print_r('====new mongodb====');

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
        /**
         * @var Connection $connection
         */
        $connection = $this->getConnection();

        if(is_null($connection->getDbVersion()))
        {
            $ret = $connection->runCmd($this->defaultDb, [
                'buildInfo' => true
            ], []);

            $connection->setDbVersion($ret->getFirstDoc()->versionArray);
        }

        $version = $connection->getDbVersion();

        $this->release($connection);

        return $version;
    }

    public function getCollection(string $name): Collection
    {
        return new Collection($this, $name);
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        shuffle($this->hosts);

        foreach ($this->hosts as $host)
        {
            if(is_null($this->pool))
            {
                if(!is_null($this->connection))
                    throw new \Exception('还有连接未关闭');

                $connection = new Connection($host['host'], $host['port'], 3000);
                $connection->connect();

                $connection->setAuth($this->username, $this->password, $this->defaultDb, $this->authAlgo);
            }
            else
            {
                $this->pool->setHost($host['host']);
                $this->pool->setPort($host['port']);

                /**
                 * @var Connection $connection
                 */
                $connection = $this->pool->get();
            }

            if(!$connection->isAuth() && !$connection->setAuth($this->username, $this->password, $this->defaultDb, $this->authAlgo))
            {
                throw new AuthException();
            }

            if(is_null($this->pool))
                $this->connection = $connection;
            else
                $this->pool->put($connection);

            return;
        }
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

    public function getConnection(): Connection
    {
        if(is_null($this->connection))
        {
            if(is_null($this->pool))
                throw new ConnectException('have no connection before connect');

            $connection = $this->pool->get();
            if(!$connection->isAuth())
            {
                $connection->setAuth($this->username, $this->password, $this->defaultDb, $this->authAlgo);
            }
            
            return $connection;
        }

        return $this->connection;
    }

    public function release(Connection $connection): void
    {
        if(!is_null($this->pool))
        {
            $this->pool->put($connection);
        }
    }


    public function close(): void
    {
        if(!is_null($this->pool))
        {
            $this->pool->close();
            $this->pool = null;
        }
        else
        {
            $this->connection->close();

            $this->connection = null;
        }
    }
}