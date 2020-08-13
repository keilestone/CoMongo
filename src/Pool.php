<?php
namespace Wty\Mongodb;

use RuntimeException;
use Swoole\Coroutine\Channel;
use Throwable;
use Wty\Mongodb\interfaces\PoolInterface;

class Pool implements PoolInterface
{
    public const DEFAULT_SIZE = 0;

    /** @var Channel */
    protected $pool = null;

    /** @var callable */
    protected $constructor;

    /** @var int */
    protected $size;

    /** @var int */
    protected $num;

    private $connection;

    private ?string $host;

    private ?int $port;

    public function __construct(int $size = self::DEFAULT_SIZE, int $timeout = 3000)
    {
        $this->size = $size;

        $this->constructor = function () use($timeout){
            $client = new Client(SWOOLE_SOCK_TCP);

            $client->set([
                'timeout' => $timeout / 1000,
                'open_length_check' => true,
                'package_length_func' => function($data){
                    $arr = unpack('Vlen', $data);

                    return $arr['len'];
                },

            ]);

            $retry = 0;

            while($client->connect($this->host, intval($this->port)) == false)
            {
                $client->close();
                if(++$retry > 3)
                {
                    throw new RuntimeException('can not connect to server');
                }
            }

            return $client;
        };

        if($size > 0 && !$this->pool)
        {
            $this->pool = new Channel($size);
            $this->num = 0;
        }
    }

    /**
     * @param string $host
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * @param int $port
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function fill(): void
    {
        if($this->size == 0)return;

        while ($this->size > $this->num) {
            $this->make();
        }
    }

    public function get()
    {
        if($this->size == 0)
        {
            $this->make();

            return $this->connection;
        }
        if ($this->pool === null) {
            throw new RuntimeException('Pool has been closed');
        }
        if ($this->pool->isEmpty() && $this->num < $this->size)
        {
            $this->make();
        }
        return $this->pool->pop();
    }

    public function put($connection): void
    {
        if($this->size == 0)
        {
            return;
        }

        if ($this->pool === null) {
            return;
        }

        if ($connection !== null && $connection->isConnected()) {
            $this->pool->push($connection);
        } else {
            /* connection broken */
            $this->num -= 1;
            $this->make();
        }
    }

    public function close(): void
    {
        if($this->size == 0)
        {
            $this->connection->close();
            return;
        }

        $this->pool->close();
        $this->pool = null;
        $this->num = 0;
    }

    protected function make(): void
    {
        $this->num++;
        try {
            $constructor = $this->constructor;
            $connection = $constructor();

            if($this->size == 0)
            {
                $this->num--;
                return;
            }
        } catch (Throwable $throwable) {
            $this->num--;
            throw $throwable;
        }

        $this->put($connection);
    }
}