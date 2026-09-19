<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Buffer;

/**
 * Protocol v1's fixed latency buckets.
 *
 * ⚠️ Must match `App\Domain\Telemetry\Protocol\LatencyHistogram` on the server
 * byte for byte: the Cloud adds counts from many installations together, which is
 * only meaningful because everybody uses the same boundaries. Changing one is a
 * new protocol version.
 */
final class Histogram
{
    /** @var list<int> inclusive upper bounds in milliseconds; a 14th bucket holds the rest */
    public const BOUNDS = [50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 10000, 20000];

    public const SIZE = 14;

    public static function bucket(int $durationMs): int
    {
        foreach (self::BOUNDS as $index => $bound) {
            if ($durationMs <= $bound) {
                return $index;
            }
        }

        return self::SIZE - 1;
    }
}
