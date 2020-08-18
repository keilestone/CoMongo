<?php
namespace Wty\Mongodb;


use MongoDB\BSON\Javascript;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Session;
use Wty\Mongodb\Exceptions\VersionException;

class Collection
{
    private Mongodb $db;
    private string $name;
    private Cursor $cursor;

    /**
     * Collection constructor.
     * @param Mongodb $db
     * @param string $name
     */
    public function __construct(Mongodb $db, string $name)
    {
        $this->db = $db;
        $this->name = $name;
    }

    public function fullName(): string
    {
        return $this->db->getDatabase() . '.' . $this->name;
    }

    /**
     * @return Mongodb
     */
    public function getDb(): Mongodb
    {
        return $this->db;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function buildWriteConcern()
    {
        return [
            'j' => $this->db->journal,
            'w' => intval($this->db->w),
            'wtimeout' => $this->db->wtimeout,
        ];
    }

    public function checkWriteConcern($doc, $default)
    {
        if(isset($doc->writeConcernError))
        {
            if(!($doc->n))
            {
                return $doc->writeConcernError->errmsg;
            }
        }

        return $default;
    }

    private function getLastError()
    {
        $write_concern = $this->buildWriteConcern();

        $cmd = [
            'getLastError' => 1,
            'j' => $write_concern['j'],
            'w' => $write_concern['w'],
            'wtimeout' => $write_concern['wtimeout']
        ];

        return $this->cmd($cmd);
    }

    private function checkLastError(...$other)
    {
        $err = $this->getLastError();

        if(is_null($err))
            return null;

        return $err->err;
    }

    private function newCursor(array $query, array $opts)
    {
        $this->cursor = new Cursor($this, $query, []);

        if(isset($opts['sort']))
            $this->cursor->setSort($opts['sort']);

        if(isset($opts['skip']))
            $this->cursor->skip($opts['skip']);

        if(isset($opts['limit']))
            $this->cursor->limit($opts['limit']);

        if(isset($opts['comment']))
            $this->cursor->setComment($opts['comment']);

        if (isset($opts['hint']))
            $this->cursor->setHit($opts['hit']);

        if(isset($opts['maxTimeMS']))
            $this->cursor->setMaxTimeMs($opts['maxTimeMS']);

        if(isset($opts['readPreference']))
            $this->cursor->setReadPreference($opts['readPreference']);

        if(isset($opts['maxScan']))
            $this->cursor->setMaxScan($opts['maxScan']);
    }

    private function genOids(array $docs)
    {
        $ids = [];
        foreach ($docs as &$doc)
        {
            if(!isset($doc['_id']))
                $doc['_id'] = new ObjectId();

            array_push($ids, $doc['_id']);
        }

        return [$docs, $ids];
    }

    public function insertOne(array $doc)
    {
        $ret = $this->insert($doc);

        return $ret['ids'][0];
    }

    public function insertMany(array $docs)
    {
        return $this->insert($docs);
    }

    public function insert(array $docs)
    {
        if(empty($docs))
        {
            $docs = [[]];
        }

        if(!Utils::indexedArray($docs))
        {
            $docs = [$docs];
        }

        [$docs, $ids] = $this->genOids($docs);

        $serverVer = join('', $this->getDb()->getVersion());

        if($serverVer < 2540)
        {
            $this->getDb()->getManager()->insert($this->fullName(),$docs);

            return $this->checkLastError($ids);
        }
        elseif ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'insert' => $this->name,
                'ordered' => true,
            ], $docs);

            $reply = $replies->getDocs()[0];
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd( ['insert' => $this->name], [
                'documents' => $docs,
                'ordered' => true,
                'writeConcern' => $this->buildWriteConcern()
            ]);
        }

        if(empty($reply))
            return null;

        return $this->checkWriteConcern($reply, [
            'ids' => $ids,
            'count' => $reply->n
        ]);
    }

    public function drop(): bool
    {
        $serverVer = join('', $this->getDb()->getVersion());

        if ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'drop' => $this->name,
            ]);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return false;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd( ['drop' => $this->name]);

            if(empty($reply))
                return false;
        }

        return true;
    }

    public function dropIndex(string $name): bool
    {
        $serverVer = join('', $this->getDb()->getVersion());

        if ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'dropIndexes' => $this->name,
                'index' => $name,
            ]);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return false;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd( ['dropIndexes' => $this->name], ['index' => $name]);

            if(empty($reply))
                return false;
        }

        return true;
    }

    private function buildIndex(array $key, array $options)
    {
        $index = [
            'key' => $key,
        ];

        foreach ($options as $k => $option)
        {
            $index[$k] = $option;
        }

        if(!isset($index['name']))
        {
            $name = [];

            foreach ($key as $k => $v)
            {
                $name[] = $k . '_' . $v;
            }

            $index['name'] = join('_', $name);
        }

        return [$index];
    }

    public function createIndex(array $key, array $options = [])
    {
        $index = $this->buildIndex($key, $options);

        $serverVer = join('', $this->getDb()->getVersion());

        if ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'createIndexes' => $this->name,
                'indexes' => $index,
            ]);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return false;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd(['createIndexes' => $this->name], ['indexes' => $index]);

            if(empty($reply))
                return false;
        }

        return true;
    }

    public function find(array $query, array $opts = [])
    {
        $this->newCursor($query, $opts);

        $this->cursor->cursorNext();

        return $this->cursor;
    }

    public function findOne(array $query, array $opts = [])
    {
        $opts['limit'] = 1;

        return $this->find($query, $opts)->getDocs()[0];
    }

    /**
     * @param array $pipeline
     * @param array $options
     * @return Cursor |null
     */
    public function aggregate(array $pipeline, array $options = []): ?\Traversable
    {
        if(!isset($options['explain']))
        {
            $options['cursor'] = new class{};
        }

        $serverVer = join('', $this->getDb()->getVersion());

        if ($serverVer > 3600)
        {
            $body = [
                'aggregate' => $this->name,
                'pipeline' => $pipeline,
            ];

            foreach ($options as $k => $option)
            {
                $body[$k] = $option;
            }

            $ret = $this->db->getManager()->executeCmd($body);

            $doc = $ret->getDocs()[0];
        }
        else
        {
            $options['pipeline'] = $pipeline;

            $doc = $this->getDb()->getManager()->executeCmd([
                'aggregate' => $this->name
            ], $options);

            if(empty($doc))
                return null;
        }

        if(isset($options['explain']))
            return $doc;

        $cursor = new Cursor($this, $options, [], false, $doc->cursor->id);
        $cursor->addBatch($doc->cursor->firstBatch);

        return $cursor;
    }

    public function count(array $filter, array $options = [])
    {
        $pipeline = [
            [
                '$match' => $filter
            ],
            [
                '$group' => [
                    '_id' => null,
                    'count' => [
                        '$sum' => 1
                    ]
                ],
            ],
            [
                '$project' => [
                    '_id' => 0
                ]
            ]
        ];
        $ret = $this->aggregate($pipeline, $options)->getDocs();

        return empty($ret) ? 0 : $ret[0]->count;
    }

    public function deleteOne(array $filter, array $options = [])
    {
        return $this->delete($filter, $options);
    }

    public function deleteMany(array $filter, array $options = [])
    {
        return $this->delete($filter, $options, false);
    }

    public function delete(array $filter, array $options = [], bool $single = true)
    {
        $serverVer = join('', $this->getDb()->getVersion());

        if ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'delete' => $this->name,
                'deletes' => [
                    [
                        'q' => $filter,
                        'limit' => $single
                    ]
                ],
                'ordered' => true,
                'writeConcern' => $this->buildWriteConcern(),
            ]);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return false;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd(
                [
                    'delete' => $this->name
                ],
                [
                    'deletes' => [
                        [
                            'q' => $filter,
                            'limit' => $single
                        ]
                    ],
                    'ordered' => true,
                    'writeConcern' => $this->buildWriteConcern()
                ]
            );

            if(empty($reply))
                return false;
        }

        return $this->checkWriteConcern($reply, $reply->n);
    }

    public function findAndModify(array $query, array $options = []): ?\stdClass
    {
        $serverVer = join('', $this->getDb()->getVersion());

        $options['query'] = $query;

        if ($serverVer > 3600)
        {
            $body = [
                'findAndModify' => $this->name,
            ];

            foreach ($options as $k => $v)
            {
                $body[$k] = $v;
            }

            $replies = $this->getDb()->getManager()->executeCmd($body);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return null;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd(
                [
                    'findAndModify' => $this->name
                ],
                $options
            );

            if(empty($reply))
                return null;
        }

        return $reply->value;
    }

    public function findOneAndDelete(array $filter, array $options = []): ?\stdClass
    {
        $options['remove'] = true;

        return $this->findAndModify($filter, $options);
    }

    public function findOneAndUpdate(array $filter, array $update, array $opts = []): ?\stdClass
    {
        $opts['update'] = $update;

        return $this->findAndModify($filter, $opts);
    }

    public function update(array $query, array $update, array $options = []): ?int
    {
        $serverVer = join('', $this->getDb()->getVersion());

        if(isset($options['multi']) && !isset($options['multiple']))
            $options['multiple'] = $options['multi'];

        if ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'update' => $this->name,
                'updates' => [
                    [
                        'q' => $query,
                        'u' => $update,
                        'upsert' => isset($options['upsert']) ? $options['upsert'] : false,
                        'multi' => isset($options['multiple']) ? $options['multiple'] : false
                    ]
                ],
                'ordered' => true,
                'writeConcern' => $this->buildWriteConcern(),
            ]);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return null;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd(
                [
                    'update' => $this->name
                ],
                [
                    'updates' => [
                        [
                            'q' => $query,
                            'u' => $update,
                            'upsert' => isset($options['upsert']) ? $options['upsert'] : false,
                            'multi' => isset($options['multiple']) ? $options['multiple'] : false
                        ]
                    ],
                    'ordered' => true,
                    'writeConcern' => $this->buildWriteConcern()
                ]
            );

            if(empty($reply))
                return null;
        }

        return $reply->nModified;
    }

    public function updateMany(array $query, array $update, array $options = []): ?int
    {
        $options['multiple'] = true;

        return $this->update($query, $update, $options);
    }

    public function updateOne(array $query, array $update, array $options = []): ?int
    {
        $options['multiple'] = false;

        return $this->update($query, $update, $options);
    }

    public function mapReduce(Javascript $map, Javascript $reduce, $out, array $options = [])
    {
        $options['map'] = $map;
        $options['reduce'] = $reduce;
        $options['out'] = $out;

        $reply = $this->getDb()->getManager()->executeCmd(
            [
                'mapReduce' => $this->name
            ],
            $options
        );

        if(empty($reply))
            return null;

        return $reply->results ?? null;
    }

    public function distinct(string $column, array $wheres = [])
    {
        $serverVer = join('', $this->getDb()->getVersion());

        if ($serverVer > 3600)
        {
            $replies = $this->getDb()->getManager()->executeCmd([
                'distinct' => $this->name,
                'key' => $column,
                'query' => (object) $wheres,
            ]);

            $reply = $replies->getDocs()[0];

            if(!$reply->ok)
                return null;
        }
        else
        {
            $reply = $this->getDb()->getManager()->executeCmd(
                [
                    'distinct' => $this->name
                ],
                [
                    'key' => $column,
                    'query' => (object) $wheres,
                ]
            );

            if(empty($reply))
                return 0;
        }

        return $reply->values;
    }
}