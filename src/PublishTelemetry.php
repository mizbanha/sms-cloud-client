<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The queue-mode wrapper around one `Runner` run.
 *
 * ⚠️ One try, a short timeout, unique while queued: a Cloud outage produces one
 * quick failure per minute on a dedicated queue — never a growing pile of retries,
 * and never a job on the queue your SMS jobs use.
 */
final class PublishTelemetry implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function handle(Runner $runner): void
    {
        $runner->run();
    }
}
