<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Publishing;

use Carbon\CarbonImmutable;
use Mizbanha\SmsCloud\Buffer\Outbox;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;

/**
 * Sends waiting batches to SMS Cloud, oldest first, within a time budget.
 *
 * Per response:
 *
 *   2xx (accepted or duplicate)      → the local copy is deleted.
 *   network / timeout / 5xx          → kept; ALL publishing pauses with exponential
 *                                      backoff (30 s doubling to 30 min, plus
 *                                      jitter) on consecutive failures. The Cloud
 *                                      is down; hammering it helps nobody.
 *   429                              → kept; publishing pauses for Retry-After.
 *   401 / 403 / environment mismatch → kept; publishing pauses 5 minutes. An
 *                                      operator can fix it (rotate the token, fix
 *                                      APP_ENV) and nothing is lost meanwhile.
 *   413 / 422 / 400 / batch conflict → this batch is dropped and counted: the
 *                                      Cloud has said it can never accept it.
 *
 * ⚠️ **A retry is the same batch.** Its uuid and sequence were fixed when it was
 * created; only `sent_at` changes. That is what the Cloud uses to answer a retry
 * with `duplicate` instead of counting it again.
 *
 * ⚠️ **Nothing retries forever.** Backoff is capped at 30 minutes and the pruner
 * drops batches older than `batch_retention_hours`.
 */
final class Publisher
{
    private const BACKOFF = [30, 60, 120, 300, 600, 1800];

    public function __construct(
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Outbox $outbox,
        private readonly CloudApi $api,
    ) {}

    /**
     * @return array{sent: int, failed: int, dropped: int, paused: bool}
     */
    public function run(float $deadline): array
    {
        $report = ['sent' => 0, 'failed' => 0, 'dropped' => 0, 'paused' => false];

        if ($this->state->pausedUntil() !== null) {
            $report['paused'] = true;

            return $report;
        }

        $table = $this->outbox->connection()->table($this->settings->batchesTable());
        $due = (clone $table)
            ->where('next_attempt_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->limit($this->settings->int('publish.max_batches_per_run', 20))
            ->get();

        foreach ($due as $batch) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $response = $this->api->post('/api/v1/telemetry/batches', $this->body($batch));

            if ($response->accepted()) {
                (clone $table)->where('id', $batch->id)->delete();
                $report['sent']++;
                $this->state->put('last_publish_at', CarbonImmutable::now('UTC')->getTimestamp());
                $this->state->forget('last_error');
                $this->state->forget('consecutive_failures');

                continue;
            }

            $this->state->put('last_error', $response->label());

            if ($response->shouldPause()) {
                $seconds = $response->status === 429 ? min(3600, max(1, $response->retryAfter ?? 60)) : 300;
                $this->state->pauseUntil(CarbonImmutable::now('UTC')->addSeconds($seconds), $response->label());
                $report['paused'] = true;

                break;
            }

            if ($response->permanentlyRejected()) {
                (clone $table)->where('id', $batch->id)->delete();
                $this->state->add('dropped_batches', 1);
                $report['dropped']++;

                continue;
            }

            /*
             * Temporary: the Cloud or the network is unwell.
             *
             * ⚠️ The backoff is GLOBAL, driven by consecutive failures, not per
             * batch. A per-batch delay alone would let the next run try the next
             * due batch, and the one after that the next — poking a Cloud that is
             * down once a minute forever. Instead all publishing waits, and the
             * wait doubles with each consecutive failure up to 30 minutes.
             */
            $this->state->add('consecutive_failures', 1);
            $failures = max(1, $this->state->count('consecutive_failures'));
            $delay = self::BACKOFF[min($failures - 1, count(self::BACKOFF) - 1)];
            $delay += random_int(0, (int) ($delay / 5));
            $retryAt = CarbonImmutable::now('UTC')->addSeconds($delay);

            (clone $table)->where('id', $batch->id)->update([
                'attempts' => min((int) $batch->attempts + 1, 65535),
                'next_attempt_at' => $retryAt->format('Y-m-d H:i:s'),
                'last_error' => $response->label(),
            ]);

            $this->state->pauseUntil($retryAt, 'backoff');
            $report['failed']++;

            break;
        }

        return $report;
    }

    /**
     * The wire body for one stored batch.
     *
     * @return array<string, mixed>
     */
    public function body(object $batch): array
    {
        $sections = json_decode((string) $batch->payload, true) ?: [];

        return [
            'protocol_version' => CloudApi::PROTOCOL,
            'batch_id' => (string) $batch->batch_uuid,
            'sequence' => (int) $batch->id,
            'sent_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'app_env' => $this->settings->appEnv(),
            'window' => $sections['window'] ?? null,
            'gateway_minutes' => $this->objects($sections['gateway_minutes'] ?? []),
            'message_minutes' => $sections['message_minutes'] ?? [],
            'failover_minutes' => $sections['failover_minutes'] ?? [],
            'circuit_transitions' => $sections['circuit_transitions'] ?? [],
        ];
    }

    /**
     * JSON decoding turns an empty `failure_kinds` object into `[]`; the protocol
     * wants an object.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function objects(array $rows): array
    {
        return array_map(static function (array $row): array {
            $row['failure_kinds'] = (object) ($row['failure_kinds'] ?? []);

            return $row;
        }, $rows);
    }
}
