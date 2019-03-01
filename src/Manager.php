<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.org
 * @document https://wiki.hyperf.org
 * @contact  group@hyperf.org
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\ModelCache;

use Hyperf\Di\Container;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Model\Model;
use Hyperf\Database\Model\Collection;
use Psr\Container\ContainerInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ModelCache\Handler\RedisHandler;
use Hyperf\ModelCache\Handler\HandlerInterface;

class Manager
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var HandlerInterface[]
     */
    protected $handlers = [];

    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $config = $container->get(ConfigInterface::class);
        if (! $config->has('databases')) {
            throw new \InvalidArgumentException('config databases is not exist!');
        }

        foreach ($config->get('databases') as $key => $item) {
            $handlerClass = $item['handler'] ?? RedisHandler::class;
            $config = new Config($item['cache'] ?? [], $key);

            if ($container instanceof Container) {
                $handler = $container->make($handlerClass, ['config' => $config]);
            } else {
                $handler = new $handlerClass($this->container, $config);
            }

            $this->handlers[$key] = $handler;
        }
    }

    /**
     * Fetch a model from cache.
     * @param mixed $id
     */
    public function findFromCache($id, string $class): ?Model
    {
        /** @var Model $instance */
        $instance = new $class();

        $name = $instance->getConnectionName();
        $primaryKey = $instance->getKeyName();

        if ($handler = $this->handlers[$name] ?? null) {
            $key = $this->getCacheKey($id, $instance, $handler->getConfig());
            $data = $handler->get($key);
            if ($data) {
                return $instance->newInstance($data, true)->syncOriginal();
            }

            // Fetch it from database, because it not exist in cache handler.
            if (is_null($data)) {
                $model = $instance->newQuery()->where($primaryKey, '=', $id)->first();
                $ttl = $handler->getConfig()->getTtl();
                if ($model) {
                    $handler->set($key, $model->toArray(), $ttl);
                } else {
                    $handler->set($key, [], $ttl);
                }
                return $model;
            }

            // It not exist in cache handler and database.
            return null;
        }

        $this->logger->alert('Cache handler not exist, fetch data from database.');
        return $instance->newQuery()->where($primaryKey, '=', $id)->first();
    }

    /**
     * Fetch many models from cache.
     */
    public function findManyFromCache(array $ids, string $class): Collection
    {
        if (count($ids) === 0) {
            return new Collection([]);
        }

        /** @var Model $instance */
        $instance = new $class();

        $name = $instance->getConnectionName();
        $primaryKey = $instance->getKeyName();

        if ($handler = $this->handlers[$name] ?? null) {
            $keys = [];
            foreach ($ids as $id) {
                $keys[] = $this->getCacheKey($id, $instance, $handler->getConfig());
            }
            $data = $handler->getMultiple($keys);
            $items = [];
            $fetchIds = [];
            foreach ($data ?? [] as $item) {
                if (isset($item[$primaryKey])) {
                    $items[] = $item;
                    $fetchIds[] = $item[$primaryKey];
                }
            }

            // Get ids that not exist in cache handler.
            $targetIds = array_diff($ids, $fetchIds);
            if ($targetIds) {
                $models = $instance->newQuery()->whereIn($primaryKey, $targetIds)->get();
                $ttl = $handler->getConfig()->getTtl();
                /** @var Model $model */
                foreach ($models as $model) {
                    $id = $model->getKey();
                    $key = $this->getCacheKey($id, $instance, $handler->getConfig());
                    $handler->set($key, $model->toArray(), $ttl);
                }

                $items = array_merge($items, $models->toArray());
            }
            $map = [];
            foreach ($items as $item) {
                $map[$item[$primaryKey]] = $item;
            }

            $result = [];
            foreach ($ids as $id) {
                if (isset($map[$id])) {
                    $result[] = $map[$id];
                }
            }

            return $instance->hydrate($result);
        }

        $this->logger->alert('Cache handler not exist, fetch data from database.');
        return $instance->newQuery()->whereIn($primaryKey, $ids)->get();
    }

    /**
     * Destroy the models for the given IDs from cache.
     * @param mixed $ids
     */
    public function destroy($ids, string $class): bool
    {
        /** @var Model $instance */
        $instance = new $class();

        $name = $instance->getConnectionName();
        if ($handler = $this->handlers[$name] ?? null) {
            $keys = [];
            foreach ($ids as $id) {
                $keys[] = $this->getCacheKey($id, $instance, $handler->getConfig());
            }

            return $handler->deleteMultiple($keys);
        }

        return false;
    }

    /**
     * Increment a column's value by a given amount.
     * @param mixed $id
     * @param mixed $column
     * @param mixed $amount
     */
    public function increment($id, $column, $amount, string $class): bool
    {
        /** @var Model $instance */
        $instance = new $class();

        $name = $instance->getConnectionName();
        if ($handler = $this->handlers[$name] ?? null) {
            $key = $this->getCacheKey($id, $instance, $handler->getConfig());
            if ($handler->has($key)) {
                return $handler->incr($key, $column, $amount);
            }

            return false;
        }

        var_dump($handler, $name);
        $this->logger->alert('Cache handler not exist, increment failed.');
        return false;
    }

    /**
     * @param $id
     */
    protected function getCacheKey($id, Model $model, Config $config): string
    {
        // mc:$prefix:m:$model:$pk:$id
        return sprintf(
            $config->getCacheKey(),
            $config->getPrefix(),
            $model->getTable(),
            $model->getKeyName(),
            $id
        );
    }
}
