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
    protected ?Channel $pool = null;

    /** @var int */
    protected $size;

    /** @var int */
    protected $num;

    private ?string $host;

    private ?int $port;

    private int $timeout;

    public function __construct(int $size = self::DEFAULT_SIZE, int $timeout = 3000)
    {
//        print_r('====new pool=====');
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

        $flag = false;
        do
        {
//            echo 'is empty?';
//            var_dump($this->pool->isEmpty());
//            echo '===============';
//            var_dump($this->num);
            if ($this->pool->isEmpty() && $this->num < $this->size)
            {
                $this->make();
            }

//            print_r('get pool   ');
//            var_dump($this->pool->length());

            $connection = $this->pool->pop();

            if(!$connection->isConnected())
            {
                $flag = true;
                $this->num -= 1;
            }
        }while ($flag);

        return $connection;
    }

    public function put($connection): void
    {
        if ($this->pool === null) {
            return;
        }

        if ($connection !== null && $connection->isConnected()) {
            $this->pool->push($connection);

//            print_r("put:  ");
//            var_dump($this->pool->length());
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
//        echo 'make' . '-------------';
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