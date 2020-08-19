<?php
namespace Wty\Mongodb;

use RuntimeException;
use Swoole\Coroutine\Channel;
use Throwable;
use Wty\Mongodb\interfaces\PoolInterface;

class Pool implements PoolInterface
{
    public const DEFAULT_SIZE = 10;

    /** @var Channel */
    protected $pool = null;

    /** @var int */
    protected $size;

    /** @var int */
    protected $num;

    private ?string $host;

    private ?int $port;

    private int $timeout;

    public function __construct(int $size = self::DEFAULT_SIZE, int $timeout = 3000)
    {
        $this->size = $size;

        $this->timeout = $timeout;

        if(is_null($this->pool))
            $this->pool = new Channel($size);

        $this->num = 0;
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
        while ($this->size > $this->num) {
            $this->make();
        }
    }

    public function get()
    {
        if ($this->pool === null)
        {
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
        $this->pool->close();
        $this->pool = null;
        $this->num = 0;
    }

    protected function make(): void
    {
        $this->num++;
        try {
            $connection = new Connection($this->host, $this->port, $this->timeout);
            $connection->connect();
        } catch (Throwable $throwable) {
            $this->num--;
            throw $throwable;
        }

        $this->put($connection);
    }
}