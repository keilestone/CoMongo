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

    private string $defaultDb;
    private ?string $username = null;
    private ?string $password = null;
    private ?string $authAlgo = null;
    private bool $ssl = false;
    private ?array $version = null;

    private ?string $database = null;

    public function __construct(string $url)
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
        return new Collection($this, $name);
    }
    /**
     * @return Connection|null
     */
    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    public function connect()
    {
        shuffle($this->hosts);

        foreach ($this->hosts as $host)
        {
            $this->connection = new Connection($host['host'], $host['port']);

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

                    $this->auth();
                }

                return;
            }
            elseif($ret->primary)
            {

            }
        }

        throw new ConnectException('can not connect to server');
    }

    public function close()
    {
        $this->connection->close();
    }
}