<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Small, best-effort operational state: drop counters, the last error code, a
 * pause after 401/429. Kept in the application's cache.
 *
 * ⚠️ Every method swallows failure. This state exists to REPORT on the client's
 * health; losing it (a cache flush, a Redis restart) costs a counter, never a
 * send and never a batch.
 */
final class State
{
    private const PREFIX = 'sms-cloud:';

    public function __construct(private readonly Repository $cache) {}

    public function add(string $counter, int $by): void
    {
        if ($by <= 0) {
            return;
        }

        try {
            $this->cache->add(self::PREFIX.$counter, 0);
            $this->cache->increment(self::PREFIX.$counter, $by);
        } catch (Throwable) {
            // See class docblock.
        }
    }

    public function count(string $counter): int
    {
        try {
            return (int) $this->cache->get(self::PREFIX.$counter, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public function put(string $key, mixed $value): void
    {
        try {
            $this->cache->forever(self::PREFIX.$key, $value);
        } catch (Throwable) {
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            return $this->cache->get(self::PREFIX.$key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->cache->forget(self::PREFIX.$key);
        } catch (Throwable) {
        }
    }

    public function pauseUntil(CarbonImmutable $until, string $reason): void
    {
        $this->put('paused_until', $until->getTimestamp());
        $this->put('paused_reason', $reason);
    }

    public function pausedUntil(): ?CarbonImmutable
    {
        $until = $this->get('paused_until');

        if (! is_int($until) || $until <= CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($until);
    }

    /**
     * Acquire a short-lived lock if the store supports it. Without lock support
     * the caller proceeds anyway: the database-level checks it makes are the
     * real guard, the lock only saves wasted work.
     *
     * @return \Illuminate\Contracts\Cache\Lock|false|null  the held lock; false
     *         when another process holds it; null when locks are unavailable
     */
    public function lock(string $name, int $seconds): mixed
    {
        try {
            $lock = $this->cache->lock(self::PREFIX.'lock:'.$name, $seconds);

            return $lock->get() ? $lock : false;
        } catch (Throwable) {
            return null;
        }
    }
}
