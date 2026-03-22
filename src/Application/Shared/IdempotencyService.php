<?php

declare(strict_types=1);

namespace App\Application\Shared;

use Psr\Cache\CacheItemPoolInterface;

final readonly class IdempotencyService
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $ttl,
    ) {
    }

    public function exists(string $key): bool
    {
        return $this->cache->getItem("idempotency_{$key}")->isHit();
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $item = $this->cache->getItem("idempotency_{$key}");
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();
        assert(is_array($value));
        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $value */
    public function store(string $key, array $value): void
    {
        $item = $this->cache->getItem("idempotency_{$key}");
        $item->set($value)->expiresAfter($this->ttl);
        $this->cache->save($item);
    }
}
