<?php


namespace Wty\Mongodb\interfaces;


interface PoolInterface
{
    public function close();

    public function get();

    public function put($connect);
}