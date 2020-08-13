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
//        $this->mongodb->connect();

        $serverVer = join('', $this->mongodb->getVersion());

        if ($serverVer > 3600)
        {
            $cmd['$db'] = $this->mongodb->getDatabase();

            $ret = $this->mongodb->getConnection()->msg($cmd, $params);
        }
        else
            $ret = $this->mongodb->getConnection()->runCmd($this->mongodb->getDatabase(), $cmd, $params);

//        $this->mongodb->close();

        return $ret;
    }

    public function killCursors(int $id)
    {
//        $this->mongodb->connect();

        $this->mongodb->getConnection()->killCursors($id);

//        $this->mongodb->close();
    }

    public function query(string $collection, array $query, int $toSkip = 0, int $num = 255, array $selector = [], array $flags = []): ?Reply
    {
//        $this->mongodb->connect();

        $ret = $this->mongodb->getConnection()->query($collection, $query, $toSkip, $num, $selector, $flags);

//        $this->mongodb->close();

        return $ret;
    }

    public function insert(string $collection, array $docs, array $flags = []): bool
    {
//        $this->mongodb->connect();

        $ret = $this->mongodb->getConnection()->insert($collection, $docs, $flags);

//        $this->mongodb->close();

        return $ret;
    }

    public function getMore(string $collection, int $number, int $cid): ?Reply
    {
//        $this->mongodb->connect();

        $ret = $this->mongodb->getConnection()->getMore($collection, $number, $cid);

//        $this->mongodb->close();

        return $ret;
    }
}