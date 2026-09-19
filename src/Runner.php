<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud;

use Carbon\CarbonImmutable;
use Mizbanha\SmsCloud\Buffer\Outbox;
use Mizbanha\SmsCloud\Heartbeat\HeartbeatBuilder;
use Mizbanha\SmsCloud\Publishing\CloudApi;
use Mizbanha\SmsCloud\Publishing\Publisher;
use Mizbanha\SmsCloud\Recording\Recorder;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;
use Throwable;

/**
 * One background run: flush → aggregate → prune → publish → heartbeat.
 *
 * ⚠️ **Each step is isolated.** A failure in one is recorded as a short code and
 * the next step still runs: an outbox that cannot aggregate must not stop the
 * heartbeat that would tell the Cloud this installation is alive, and a Cloud that
 * is down must not stop the pruner that keeps the local tables bounded.
 *
 * ⚠️ **It never throws**, so the scheduler never logs this package as a failing
 * task and never retries it in a tight loop.
 */
final class Runner
{
    public function __construct(
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Recorder $recorder,
        private readonly Outbox $outbox,
        private readonly Publisher $publisher,
        private readonly HeartbeatBuilder $heartbeat,
        private readonly CloudApi $api,
    ) {}

    /**
     * @return array<string, mixed> what happened, for the command's output
     */
    public function run(): array
    {
        if (! $this->settings->active()) {
            return ['active' => false];
        }

        $deadline = microtime(true) + $this->settings->int('publish.max_seconds_per_run', 30);
        $report = ['active' => true];

        $lock = $this->state->lock('run', $this->settings->int('publish.max_seconds_per_run', 30) + 30);

        if ($lock === false) {
            return ['active' => true, 'skipped' => 'another run is in progress'];
        }

        try {
            $report['flush'] = $this->step('flush', fn () => $this->recorder->flush());
            $report['aggregated'] = $this->step('aggregate', fn () => $this->outbox->aggregate());
            $report['pruned'] = $this->step('prune', fn () => $this->outbox->prune());
            $report['published'] = $this->step('publish', fn () => $this->publisher->run($deadline));
            $report['heartbeat'] = $this->step('heartbeat', fn () => $this->sendHeartbeat());
        } finally {
            if (is_object($lock)) {
                try {
                    $lock->release();
                } catch (Throwable) {
                }
            }
        }

        return $report;
    }

    /**
     * @return array{status: int, code: string|null}
     */
    public function sendHeartbeat(): array
    {
        $response = $this->api->post('/api/v1/heartbeat', $this->heartbeat->build());

        $this->state->put('last_heartbeat_status', $response->status);

        if ($response->accepted()) {
            $this->state->put('last_heartbeat_at', CarbonImmutable::now('UTC')->getTimestamp());
        } else {
            $this->state->put('last_error', $response->label());
        }

        return ['status' => $response->status, 'code' => $response->code];
    }

    private function step(string $name, callable $callback): mixed
    {
        try {
            return $callback() ?? 'ok';
        } catch (Throwable $exception) {
            $this->state->put('last_error', $name.'_failed');

            return ['error' => $exception::class];
        }
    }
}
