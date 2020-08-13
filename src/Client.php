<?php


namespace Wty\Mongodb;
use Swoole\Coroutine\Client as BaseClient;

class Client extends BaseClient
{
    private bool $auth = false;

    public function setAuth(): void
    {
        $this->auth = true;
    }

    /**
     * @return bool
     */
    public function isAuth(): bool
    {
        return $this->auth;
    }
}