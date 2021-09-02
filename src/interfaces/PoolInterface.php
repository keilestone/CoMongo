<?php


namespace Wty\Mongodb\interfaces;


interface PoolInterface
{
    public function close();

    public function get();

    public function put($connect);

    public function setHost(string $host):void;

    public function setPort(int $port):void;
}