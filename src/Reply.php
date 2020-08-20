<?php


namespace Wty\Mongodb;


class Reply
{
    //$arr['flags'], $arr['cid'], $arr['start'], $arr['num']
    private int $flags;

    private int $cid;

    private int $start;

    private int $num;

    private array $docs;

    private int $type;

    /**
     * Reply constructor.
     * @param int $flags
     * @param int $cid
     * @param int $start
     * @param int $num
     * @param array $docs
     * @param int $type
     */
    public function __construct(int $flags, int $cid, int $start, int $num, array $docs, int $type)
    {
        $this->flags = $flags;
        $this->cid = $cid;
        $this->start = $start;
        $this->num = $num;
        $this->docs = $docs;
        $this->type = $type;
    }

    /**
     * @return int
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * @return int
     */
    public function getCid(): int
    {
        return $this->cid;
    }

    /**
     * @return int
     */
    public function getStart(): int
    {
        return $this->start;
    }

    /**
     * @return int
     */
    public function getNum(): int
    {
        return $this->num;
    }

    /**
     * @return array
     */
    public function getDocs(): array
    {
        return $this->docs;
    }

    public function hasFirstDoc()
    {
        return count($this->docs) > 0;
    }

    public function getFirstDoc()
    {
        if(empty($this->docs))
            return null;

        return $this->docs[0];
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }
}