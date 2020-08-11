<?php


namespace Wty\Mongodb;


use function MongoDB\BSON\fromPHP;

class Cursor implements \Iterator
{
    private ?Collection $collection = null;

    private array $query;

    private array $fields;

    private int $id = 0;

    private int $skip = 0;

    private int $limit = 0;

    private array $docs = [];

    private bool $start = false;

    private ?string $comment = null;

    /**
     * @var null | string | array
     */
    private $hit = null;

    private ?int $max_scan = null;

    private ?int $max_time_ms = null;

    private $read_preference = null;

    private bool $snapshot = false;

    private ?array $sort = null;

    private bool $await = false;

    private bool $tailable = false;

    private bool $explain = false;

    private int $travelPos = 0;

    private int $keyPos = 0;

    private const DEFAULT_QUERY_MAX = 2000;

    /**
     * Cursor constructor.
     * @param Collection|null $collection
     * @param array $query
     * @param array $fields
     * @param bool $explain
     * @param int $id
     */
    public function __construct(?Collection $collection, array $query, array $fields, bool $explain = false, int $id = 0)
    {
        $this->collection = $collection;
        $this->query = $query;
        $this->fields = $fields;

        $this->explain = $explain;
        $this->id = $id;
    }

    /**
     * @return array
     */
    public function getDocs(): array
    {
        return $this->docs;
    }

    /**
     * @param string|null $comment
     */
    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * @param array|string|null $hit
     */
    public function setHit($hit): self
    {
        $this->hit = $hit;
        return $this;
    }

    /**
     * @param int $max_scan
     */
    public function setMaxScan(int $max_scan): self
    {
        $this->max_scan = $max_scan;
        return $this;
    }

    /**
     * @param int|null $max_time_ms
     */
    public function setMaxTimeMs(?int $max_time_ms): self
    {
        $this->max_time_ms = $max_time_ms;
        return $this;
    }

    /**
     * @param null $read_preference
     */
    public function setReadPreference($read_preference): self
    {
        $this->read_preference = $read_preference;
        return $this;
    }

    /**
     * @param bool $snapshot
     */
    public function setSnapshot(bool $snapshot): self
    {
        $this->snapshot = $snapshot;
        return $this;
    }

    /**
     * @param array $sort
     */
    public function setSort(array $sort): self
    {
        $this->sort = $sort;
        return $this;
    }

    /**
     * @param bool $await
     */
    public function setAwait(bool $await): self
    {
        $this->await = $await;
        return $this;
    }

    /**
     * @param bool $tailable
     */
    public function setTailable(bool $tailable): self
    {
        $this->tailable = $tailable;
        return $this;
    }

    /**
     * @param bool $explain
     */
    public function setExplain(bool $explain): self
    {
        $this->explain = $explain;
        return $this;
    }


    public function skip(int $skip)
    {
        if($this->start)
        {
            throw new \MongoException('Can not set skip after starting cursor');
        }

        $this->skip = $skip;
    }

    public function limit(int $limit)
    {
        if($this->start)
        {
            throw new \MongoException('Can not set limit after starting cursor');
        }

        $this->limit = $limit;
    }

    private function buildQuery(): array
    {
        $ext = [];

        if(!is_null($this->comment))
        {
            $ext['$comment'] = $this->comment;
        }

        if($this->explain)
        {
            $ext['$explain'] = true;
        }

        if(!is_null($this->hit))
        {
            $ext['$hit'] = $this->hit;
        }

        if(!is_null($this->max_scan))
        {
            $ext['$maxScan'] = $this->max_scan;
        }

        if(!is_null($this->max_time_ms))
        {
            $ext['$maxTimeMS'] = $this->max_time_ms;
        }

        if(!is_null($this->read_preference))
        {
            $ext['$readPreference'] = $this->read_preference;
        }

        if($this->snapshot)
        {
            $ext['$snapshot'] = true;
        }

        if(!is_null($this->sort))
        {
            $ext['$orderby'] = $this->sort;
        }

        $ext['$query'] = $this->query;

        return $ext;
    }

    public function cursorNext(): ?array
    {
        $this->collection->getDb()->connect();

        if($this->finished())
        {
            if($this->id != 0)
            {
                $this->collection->getDb()->getConnection()->killCursors($this->id);
                $this->id = 0;
            }

            return null;
        }

        if(!$this->start && $this->id == 0)
        {
            $reply = $this->collection->getDb()
                ->getConnection()
                ->query($this->collection->fullName(), $this->buildQuery(),
                $this->skip, $this->limit == 0 ? self::DEFAULT_QUERY_MAX : $this->limit, $this->fields, ['tailable' => $this->tailable, 'await' => $this->await]);

            if(Utils::checkFlags($reply->getFlags()))
            {
                return null;
            }

            $this->id = $reply->getCid();

            $this->addBatch($reply->getDocs());
        }
        elseif (empty($this->docs) && $this->id != 0)
        {
            $reply = $this->collection->getDb()->getConnection()
                ->getMore($this->collection->fullName(), $this->limit == 0 ? self::DEFAULT_QUERY_MAX : $this->limit, $this->id);

            if(Utils::checkFlags($reply->getFlags()))
            {
                return null;
            }

            $this->addBatch($reply->getDocs());

            $this->id = $reply->getCid();
        }
        elseif (empty($this->docs))
        {
            return null;
        }

        return $this->docs;
    }

    public function cursorRewind()
    {
        $this->start = false;
        $this->docs = [];
        $this->collection->getDb()->getConnection()->killCursors($this->id);
        $this->id = 0;
    }

    public function count()
    {
        $ret = $this->collection->getDb()->getConnection()->runCmd($this->collection->getDb()->getDatabase(), [
            'count' => $this->collection->getName()
        ], [
            'query' => $this->query,
            'skip' => $this->skip,
            'limit' => $this->limit
        ]);

        if(empty($ret))
            return null;

        return $ret->n ?? 0;
    }

    public function distinct($key)
    {
        $ret = $this->collection->getDb()->getConnection()->runCmd($this->collection->getDb()->getDatabase(), [
            'distinct' => $this->collection->getName()
        ], [
            'query' => $this->query,
            'key' => $key,
        ]);

        if(empty($ret))
            return null;

        return $ret['values'] ?? [];
    }

    public function all(): array
    {
        $docs = [];

        do
        {
            $doc = $this->cursorNext();
            array_push($docs, $doc);
        }while(empty($docs));

        return $docs;
    }

    public function explain()
    {
        $self = clone $this;

        return $self->setSort(null)->cursorNext();
    }

    public function finished(): bool
    {
        if($this->limit == 0)
            return false;
        elseif($this->travelPos >= abs($this->limit))
            return true;

        return false;
    }

    public function addBatch(array $docs): self
    {
        $this->start = true;

        foreach ($docs as $doc)
        {
            $this->docs[] = $doc;
        }

        return $this;
    }

    public function current()
    {
        // TODO: Implement current() method.

        return $this->docs[$this->travelPos];
    }

    public function next()
    {
        // TODO: Implement next() method.
        $this->travelPos++;
        $this->keyPos++;
    }

    public function key()
    {
        // TODO: Implement key() method.
        return $this->keyPos;
    }

    public function valid()
    {
        // TODO: Implement valid() method.

        if($this->limit != 0)
        {
            return $this->travelPos <= $this->limit;
        }

        if(!isset($this->docs[$this->travelPos]))
        {
            if($this->id != 0)
            {
                $this->docs = [];
                $this->travelPos = 0;

                $next = $this->cursorNext();
                if(!is_null($next))
                {
                    return true;
                }
            }

            return false;
        }
        return true;
    }

    public function rewind()
    {
        $this->travelPos = 0;
        $this->keyPos = 0;
    }
}