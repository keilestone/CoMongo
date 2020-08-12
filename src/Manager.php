<?php


namespace Wty\Mongodb;


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

    public function executeCmd(array $cmd, array $params = [])
    {
        $this->mongodb->connect();

        $serverVer = join('', $this->mongodb->getVersion());

        if ($serverVer > 3600)
        {
            $cmd['$db'] = $this->mongodb->getDatabase();

            return $this->mongodb->getConnection()->msg($cmd, $params);
        }
        else
            return $this->mongodb->getConnection()->runCmd($this->mongodb->getDatabase(), $cmd, $params);
    }

    public function killCursors(int $id)
    {
        $this->mongodb->connect();

        $this->mongodb->getConnection()->killCursors($id);
    }

    public function query(string $collection, array $query, int $toSkip = 0, int $num = 255, array $selector = [], array $flags = []): ?Reply
    {
        $this->mongodb->connect();

        return $this->mongodb->getConnection()->query($collection, $query, $toSkip, $num, $selector, $flags);
    }

    public function insert(string $collection, array $docs, array $flags = []): bool
    {
        $this->mongodb->connect();

        return $this->mongodb->getConnection()->insert($collection, $docs, $flags);
    }

    public function getMore(string $collection, int $number, int $cid): ?Reply
    {
        $this->mongodb->connect();

        return $this->mongodb->getConnection()->getMore($collection, $number, $cid);
    }
}