<?php

declare(strict_types=1);

namespace Doctrine\ODM\MongoDB\Query;

use BadMethodCallException;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Iterator\CachingIterator;
use Doctrine\ODM\MongoDB\Iterator\HydratingIterator;
use Doctrine\ODM\MongoDB\Iterator\Iterator;
use Doctrine\ODM\MongoDB\Iterator\PrimingIterator;
use Doctrine\ODM\MongoDB\Iterator\UnrewindableIterator;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\MongoDBException;
use InvalidArgumentException;
use IteratorAggregate;
use MongoDB\Collection;
use MongoDB\DeleteResult;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndUpdate;
use MongoDB\UpdateResult;
use Traversable;
use UnexpectedValueException;

use function array_combine;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function is_array;
use function is_callable;
use function is_string;
use function key;
use function reset;

/**
 * ODM Query wraps the raw Doctrine MongoDB queries to add additional functionality
 * and to hydrate the raw arrays of data to Doctrine document objects.
 */
final class Query implements IteratorAggregate
{
    public const TYPE_FIND            = 1;
    public const TYPE_FIND_AND_UPDATE = 2;
    public const TYPE_FIND_AND_REMOVE = 3;
    public const TYPE_INSERT          = 4;
    public const TYPE_UPDATE          = 5;
    public const TYPE_REMOVE          = 6;
    public const TYPE_DISTINCT        = 9;
    public const TYPE_COUNT           = 11;

    public const HINT_REFRESH = 1;
    // 2 was used for HINT_SLAVE_OKAY, which was removed in 2.0
    public const HINT_READ_PREFERENCE = 3;
    public const HINT_READ_ONLY       = 5;

    /**
     * The DocumentManager instance.
     *
     * @var DocumentManager
     */
    private $dm;

    /**
     * The ClassMetadata instance.
     *
     * @var ClassMetadata
     */
    private $class;

    /**
     * Whether to hydrate results as document class instances.
     *
     * @var bool
     */
    private $hydrate = true;

    /**
     * Array of primer Closure instances.
     *
     * @var array
     */
    private $primers = [];

    /** @var bool */
    private $rewindable = true;

    /**
     * Hints for UnitOfWork behavior.
     *
     * @var array
     */
    private $unitOfWorkHints = [];

    /**
     * The Collection instance.
     *
     * @var Collection
     */
    protected $collection;

    /**
     * Query structure generated by the Builder class.
     *
     * @var array
     */
    private $query;

    /** @var Iterator|null */
    private $iterator;

    /**
     * Query options
     *
     * @var array
     */
    private $options;

    public function __construct(DocumentManager $dm, ClassMetadata $class, Collection $collection, array $query = [], array $options = [], bool $hydrate = true, bool $refresh = false, array $primers = [], bool $readOnly = false, bool $rewindable = true)
    {
        $primers = array_filter($primers);

        switch ($query['type']) {
            case self::TYPE_FIND:
            case self::TYPE_FIND_AND_UPDATE:
            case self::TYPE_FIND_AND_REMOVE:
            case self::TYPE_INSERT:
            case self::TYPE_UPDATE:
            case self::TYPE_REMOVE:
            case self::TYPE_DISTINCT:
            case self::TYPE_COUNT:
                break;

            default:
                throw new InvalidArgumentException('Invalid query type: ' . $query['type']);
        }

        $this->collection = $collection;
        $this->query      = $query;
        $this->options    = $options;
        $this->dm         = $dm;
        $this->class      = $class;
        $this->hydrate    = $hydrate;
        $this->primers    = $primers;

        $this->setReadOnly($readOnly);
        $this->setRefresh($refresh);
        $this->setRewindable($rewindable);

        if (! isset($query['readPreference'])) {
            return;
        }

        $this->unitOfWorkHints[self::HINT_READ_PREFERENCE] = $query['readPreference'];
    }

    public function __clone()
    {
        $this->iterator = null;
    }

    /**
     * Return an array of information about the query structure for debugging.
     *
     * The $name parameter may be used to return a specific key from the
     * internal $query array property. If omitted, the entire array will be
     * returned.
     */
    public function debug(?string $name = null)
    {
        return $name !== null ? $this->query[$name] : $this->query;
    }

    /**
     * Execute the query and returns the results.
     *
     * @return Iterator|UpdateResult|InsertOneResult|DeleteResult|array|object|int|null
     *
     * @throws MongoDBException
     */
    public function execute()
    {
        $results = $this->runQuery();

        if (! $this->hydrate) {
            return $results;
        }

        $uow = $this->dm->getUnitOfWork();

        /* If a single document is returned from a findAndModify command and it
         * includes the identifier field, attempt hydration.
         */
        if (
            ($this->query['type'] === self::TYPE_FIND_AND_UPDATE ||
                $this->query['type'] === self::TYPE_FIND_AND_REMOVE) &&
            is_array($results) && isset($results['_id'])
        ) {
            $results = $uow->getOrCreateDocument($this->class->name, $results, $this->unitOfWorkHints);

            if (! empty($this->primers)) {
                $referencePrimer = new ReferencePrimer($this->dm, $uow);

                foreach ($this->primers as $fieldName => $primer) {
                    $primer = is_callable($primer) ? $primer : null;
                    $referencePrimer->primeReferences($this->class, [$results], $fieldName, $this->unitOfWorkHints, $primer);
                }
            }
        }

        return $results;
    }

    /**
     * Gets the ClassMetadata instance.
     */
    public function getClass(): ClassMetadata
    {
        return $this->class;
    }

    public function getDocumentManager(): DocumentManager
    {
        return $this->dm;
    }

    /**
     * Execute the query and return its result, which must be an Iterator.
     *
     * If the query type is not expected to return an Iterator,
     * BadMethodCallException will be thrown before executing the query.
     * Otherwise, the query will be executed and UnexpectedValueException will
     * be thrown if {@link Query::execute()} does not return an Iterator.
     *
     * @see http://php.net/manual/en/iteratoraggregate.getiterator.php
     *
     * @throws BadMethodCallException If the query type would not return an Iterator.
     * @throws UnexpectedValueException If the query did not return an Iterator.
     * @throws MongoDBException
     */
    public function getIterator(): Iterator
    {
        switch ($this->query['type']) {
            case self::TYPE_FIND:
            case self::TYPE_DISTINCT:
                break;

            default:
                throw new BadMethodCallException('Iterator would not be returned for query type: ' . $this->query['type']);
        }

        if ($this->iterator === null) {
            $result = $this->execute();
            if (! $result instanceof Iterator) {
                throw new UnexpectedValueException('Iterator was not returned for query type: ' . $this->query['type']);
            }

            $this->iterator = $result;
        }

        return $this->iterator;
    }

    /**
     * Return the query structure.
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * Execute the query and return the first result.
     *
     * @return array|object|null
     */
    public function getSingleResult()
    {
        $clonedQuery                 = clone $this;
        $clonedQuery->query['limit'] = 1;

        return $clonedQuery->getIterator()->current() ?: null;
    }

    /**
     * Return the query type.
     */
    public function getType(): int
    {
        return $this->query['type'];
    }

    /**
     * Sets whether or not to hydrate the documents to objects.
     */
    public function setHydrate(bool $hydrate): void
    {
        $this->hydrate = $hydrate;
    }

    /**
     * Set whether documents should be registered in UnitOfWork. If document would
     * already be managed it will be left intact and new instance returned.
     *
     * This option has no effect if hydration is disabled.
     */
    public function setReadOnly(bool $readOnly): void
    {
        $this->unitOfWorkHints[self::HINT_READ_ONLY] = $readOnly;
    }

    /**
     * Set whether to refresh hydrated documents that are already in the
     * identity map.
     *
     * This option has no effect if hydration is disabled.
     */
    public function setRefresh(bool $refresh): void
    {
        $this->unitOfWorkHints[self::HINT_REFRESH] = $refresh;
    }

    /**
     * Set to enable wrapping of resulting Iterator with CachingIterator
     */
    public function setRewindable(bool $rewindable = true): void
    {
        $this->rewindable = $rewindable;
    }

    /**
     * Execute the query and return its results as an array.
     *
     * @see IteratorAggregate::toArray()
     */
    public function toArray(): array
    {
        return $this->getIterator()->toArray();
    }

    /**
     * Returns an array containing the specified keys and their values from the
     * query array, provided they exist and are not null.
     */
    private function getQueryOptions(string ...$keys): array
    {
        return array_filter(
            array_intersect_key($this->query, array_flip($keys)),
            static function ($value) {
                return $value !== null;
            }
        );
    }

    /**
     * Decorate the cursor with caching, hydration, and priming behavior.
     *
     * Note: while this method could strictly take a MongoDB\Driver\Cursor, we
     * accept Traversable for testing purposes since Cursor cannot be mocked.
     * HydratingIterator, CachingIterator, and BaseIterator expect a Traversable
     * so this should not have any adverse effects.
     */
    private function makeIterator(Traversable $cursor): Iterator
    {
        if ($this->hydrate) {
            $cursor = new HydratingIterator($cursor, $this->dm->getUnitOfWork(), $this->class, $this->unitOfWorkHints);
        }

        $cursor = $this->rewindable ? new CachingIterator($cursor) : new UnrewindableIterator($cursor);

        if (! empty($this->primers)) {
            $referencePrimer = new ReferencePrimer($this->dm, $this->dm->getUnitOfWork());
            $cursor          = new PrimingIterator($cursor, $this->class, $referencePrimer, $this->primers, $this->unitOfWorkHints);
        }

        return $cursor;
    }

    /**
     * Returns an array with its keys renamed based on the translation map.
     *
     * @return array $rename Translation map (from => to) for renaming keys
     */
    private function renameQueryOptions(array $options, array $rename): array
    {
        if (empty($options)) {
            return $options;
        }

        $options = array_combine(
            array_map(
                static function ($key) use ($rename) {
                    return $rename[$key] ?? $key;
                },
                array_keys($options)
            ),
            array_values($options)
        );

        // Necessary because of https://github.com/phpstan/phpstan/issues/1580
        assert($options !== false);

        return $options;
    }

    /**
     * Execute the query and return its result.
     *
     * The return value will vary based on the query type. Commands with results
     * (e.g. aggregate, inline mapReduce) may return an ArrayIterator. Other
     * commands and operations may return a status array or a boolean, depending
     * on the driver's write concern. Queries and some mapReduce commands will
     * return an Iterator.
     *
     * @return Iterator|UpdateResult|InsertOneResult|DeleteResult|array|object|int|null
     */
    private function runQuery()
    {
        $options = $this->options;

        switch ($this->query['type']) {
            case self::TYPE_FIND:
                $queryOptions = $this->getQueryOptions('select', 'sort', 'skip', 'limit', 'readPreference', 'hint');
                $queryOptions = $this->renameQueryOptions($queryOptions, ['select' => 'projection']);

                $cursor = $this->collection->find(
                    $this->query['query'],
                    array_merge($options, $queryOptions)
                );

                return $this->makeIterator($cursor);

            case self::TYPE_FIND_AND_UPDATE:
                $queryOptions                   = $this->getQueryOptions('select', 'sort', 'upsert');
                $queryOptions                   = $this->renameQueryOptions($queryOptions, ['select' => 'projection']);
                $queryOptions['returnDocument'] = $this->query['new'] ?? false ? FindOneAndUpdate::RETURN_DOCUMENT_AFTER : FindOneAndUpdate::RETURN_DOCUMENT_BEFORE;

                $operation = $this->isFirstKeyUpdateOperator() ? 'findOneAndUpdate' : 'findOneAndReplace';

                return $this->collection->{$operation}(
                    $this->query['query'],
                    $this->query['newObj'],
                    array_merge($options, $queryOptions)
                );

            case self::TYPE_FIND_AND_REMOVE:
                $queryOptions = $this->getQueryOptions('select', 'sort');
                $queryOptions = $this->renameQueryOptions($queryOptions, ['select' => 'projection']);

                return $this->collection->findOneAndDelete(
                    $this->query['query'],
                    array_merge($options, $queryOptions)
                );

            case self::TYPE_INSERT:
                return $this->collection->insertOne($this->query['newObj'], $options);

            case self::TYPE_UPDATE:
                $multiple = $this->query['multiple'] ?? false;

                if ($this->isFirstKeyUpdateOperator()) {
                    $operation = 'updateOne';
                } else {
                    if ($multiple) {
                        throw new InvalidArgumentException('Combining the "multiple" option without using an update operator as first operation in a query is not supported.');
                    }

                    $operation = 'replaceOne';
                }

                if ($multiple) {
                    return $this->collection->updateMany(
                        $this->query['query'],
                        $this->query['newObj'],
                        array_merge($options, $this->getQueryOptions('upsert'))
                    );
                }

                return $this->collection->{$operation}(
                    $this->query['query'],
                    $this->query['newObj'],
                    array_merge($options, $this->getQueryOptions('upsert'))
                );

            case self::TYPE_REMOVE:
                return $this->collection->deleteMany($this->query['query'], $options);

            case self::TYPE_DISTINCT:
                $collection = $this->collection;
                $query      = $this->query;

                return $collection->distinct(
                    $query['distinct'],
                    $query['query'],
                    array_merge($options, $this->getQueryOptions('readPreference'))
                );

            case self::TYPE_COUNT:
                $collection = $this->collection;
                $query      = $this->query;

                return $collection->count(
                    $query['query'],
                    array_merge($options, $this->getQueryOptions('hint', 'limit', 'skip', 'readPreference'))
                );

            default:
                throw new InvalidArgumentException('Invalid query type: ' . $this->query['type']);
        }
    }

    private function isFirstKeyUpdateOperator(): bool
    {
        reset($this->query['newObj']);
        $firstKey = key($this->query['newObj']);

        return is_string($firstKey) && $firstKey[0] === '$';
    }
}
