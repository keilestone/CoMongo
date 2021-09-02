<?php


namespace Wty\Mongodb;

/**
 * Class Manager
 * @package Wty\Mongodb
 *
 */
class Manager
{
    private MongoDB $mongodb;

    /**
     * Manager constructor.
     * @param MongoDB $mongodb
     */
    public function __construct(MongoDB $mongodb)
    {
        $this->mongodb = $mongodb;
    }

    public function executeCmd(array $cmd, array $params = []): ?Reply
    {
//        $this->mongodb->connect();

        $serverVer = join('', $this->mongodb->getVersion());

        $connection = $this->mongodb->getConnection();

        if ($serverVer > 3600)
        {
            $cmd['$db'] = $this->mongodb->getDatabase();

            $ret = $connection->msg($cmd, $params);
        }
        else
            $ret = $connection->runCmd($this->mongodb->getDatabase(), $cmd, $params);

        $this->mongodb->release($connection);

        return $ret;
    }

    public function killCursors(int $id)
    {
//        $this->mongodb->connect();

        $connection = $this->mongodb->getConnection();
        $connection->killCursors($id);

        $this->mongodb->release($connection);
    }

    public function query(string $collection, array $query, int $toSkip = 0, int $num = 255, array $selector = [], array $flags = []): ?Reply
    {
//        $this->mongodb->connect();

        $connection = $this->mongodb->getConnection();

        $ret = $connection->query($collection, $query, $toSkip, $num, $selector, $flags);

        $this->mongodb->release($connection);

        return $ret;
    }

    public function insert(string $collection, array $docs, array $flags = []): bool
    {
//        $this->mongodb->connect();

        $connection = $this->mongodb->getConnection();

        $ret = $connection->insert($collection, $docs, $flags);

        $this->mongodb->release($connection);

        return $ret;
    }

    public function getMore(string $collection, int $number, int $cid): ?Reply
    {
//        $this->mongodb->connect();

        $connection = $this->mongodb->getConnection();

        $ret = $connection->getMore($collection, $number, $cid);

        $this->mongodb->release($connection);

        return $ret;
    }
}