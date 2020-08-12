<?php
namespace Wty\Mongodb;

use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;
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

    /** @var null|string */
    protected $proxy;

    private $connection;

    public function __construct(string $host, int $port, int $timeout, int $size = self::DEFAULT_SIZE, ?string $proxy = null)
    {
        $this->size = $size;
        $this->constructor = function () use($host, $port, $timeout){
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

            while($client->connect($host, intval($port)) == false)
            {
                $client->close();
                if(++$retry > 3)
                {
                    throw new \http\Exception\RuntimeException('can not connect to server');
                }
            }

            return $client;
        };


        $this->proxy = $proxy;

        if($size > 0 && !$this->pool)
        {
            $this->pool = new Channel($size);
            $this->num = 0;
        }
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
        if ($this->pool->isEmpty() && $this->num < $this->size) {
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
        if ($connection !== null || $connection->isConnected()) {
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
        if($this->size == 0)
        {
            $constructor = $this->constructor;
            $this->connection = $constructor();
            return;
        }
        $this->num++;
        try {
            if ($this->proxy) {
                $connection = new $this->proxy($this->constructor);
            } else {
                $constructor = $this->constructor;
                $connection = $constructor();
            }
        } catch (Throwable $throwable) {
            $this->num--;
            throw $throwable;
        }

        $this->put($connection);
    }
}