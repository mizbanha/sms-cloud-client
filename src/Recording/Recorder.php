<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Recording;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Mizbanha\Sms\Events\AttemptRecorded;
use Mizbanha\Sms\Events\CircuitStateChanged;
use Mizbanha\Sms\Events\DeliveryChecked;
use Mizbanha\Sms\Events\MessageSettled;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;
use Throwable;

/**
 * The only code of this package that runs inside a send.
 *
 * ⚠️ **What it does there is append a small array to memory.** No cache call, no
 * HTTP, no file — and, for 499 of every 500 events, no query. Core dispatches its
 * events synchronously from the middle of the failover loop, so anything slower
 * would be latency added to every message. Writing to the outbox happens when the
 * request or job ends; aggregating and publishing happen in another process.
 *
 * The one exception, deliberately: a process that has buffered 500 events (a
 * bulk-sending command that never ends a request) writes them in ONE multi-row
 * insert, unless a transaction is open. Roughly ten microseconds per message,
 * amortised, in exchange for not losing a bulk send's telemetry to the memory cap.
 *
 * ⚠️ **It cannot throw.** Each handler is wrapped; Core's emitter also guards every
 * listener. Two layers, because the rule they protect — no Cloud code can affect
 * a send — is the reason this product is allowed to exist.
 *
 * ⚠️ **It chooses what is kept.** Only the fields below are read from the events;
 * the recipient, the body, the variables, the template, the sender line, the
 * provider message id and the provider's prose are never touched.
 *
 * ⚠️ **Bounded.** Past `max_memory_events` events are counted and dropped, so a
 * process that sends a million messages before it ever flushes cannot run out of
 * memory because of us.
 */
final class Recorder
{
    /** @var list<array{0: string, 1: float, 2: array<string, mixed>}> */
    private array $events = [];

    private int $dropped = 0;

    private bool $flushing = false;

    public function __construct(
        private readonly Settings $settings,
        private readonly State $state,
        private readonly DatabaseManager $db,
    ) {}

    public function onAttempt(AttemptRecorded $event): void
    {
        try {
            $attempt = $event->attempt;
            $previous = $event->previous;

            $this->push('attempt', [
                'gateway' => $this->settings->gatewayLabel($attempt->gateway_key),
                'driver' => $this->settings->driverLabel($attempt->driver),
                'outcome' => $attempt->outcome?->value,
                'failure_kind' => $attempt->failure_kind?->value,
                'duration_ms' => max(0, $event->durationMs),
                'retry' => $event->isRetry(),
                'first' => $previous === null && ! $event->isRetry(),
                'from' => $previous === null ? null : $this->settings->gatewayLabel($previous->gateway_key),
                'from_driver' => $previous === null ? null : $this->settings->driverLabel($previous->driver),
                'from_reason' => $previous?->failure_kind?->value,
            ]);
        } catch (Throwable) {
            $this->dropped++;
        }
    }

    public function onSettled(MessageSettled $event): void
    {
        try {
            $this->push('settled', ['status' => $event->status->value]);
        } catch (Throwable) {
            $this->dropped++;
        }
    }

    public function onCircuit(CircuitStateChanged $event): void
    {
        try {
            $this->push('circuit', [
                'gateway' => $this->settings->gatewayLabel($event->gateway->key),
                'driver' => $this->settings->driverLabel($event->gateway->driver),
                'from' => $event->from->value,
                'to' => $event->to->value,
                'reason' => $event->reason,
                'failures' => max(0, $event->failures),
                'open_until' => $event->openUntil?->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        } catch (Throwable) {
            $this->dropped++;
        }
    }

    public function onDelivery(DeliveryChecked $event): void
    {
        try {
            $this->push('delivery', [
                'gateway' => $this->settings->gatewayLabel($event->attempt->gateway_key),
                'driver' => $this->settings->driverLabel($event->attempt->driver),
                'ok' => $event->succeeded(),
                'status' => $event->changed() ? $event->current()?->value : null,
            ]);
        } catch (Throwable) {
            $this->dropped++;
        }
    }

    /**
     * Write what is held in memory to the outbox table.
     *
     * Called when the request or job ends (never during one), and early when the
     * buffer is getting full.
     *
     * ⚠️ **Never inside the application's own transaction.** On PostgreSQL a failed
     * statement aborts the whole enclosing transaction; an outbox insert that
     * failed inside the order transaction that sent an SMS would roll back the
     * order. So if the outbox connection has an open transaction, nothing is
     * written now — the events stay in memory for the next flush.
     */
    public function flush(): void
    {
        if ($this->events === [] && $this->dropped === 0) {
            return;
        }

        if ($this->flushing) {
            return;
        }

        $this->flushing = true;

        try {
            $connection = $this->db->connection($this->settings->connection());

            if ($connection->transactionLevel() > 0) {
                return;
            }

            $events = $this->events;
            $this->events = [];

            if ($this->dropped > 0) {
                $this->state->add('dropped_events', $this->dropped);
                $this->dropped = 0;
            }

            if ($events === []) {
                return;
            }

            $rows = array_map(static fn (array $event): array => [
                'type' => $event[0],
                'occurred_at' => gmdate('Y-m-d H:i:s', (int) $event[1]).sprintf('.%03d', (int) (fmod($event[1], 1) * 1000)),
                'payload' => json_encode($event[2], JSON_UNESCAPED_SLASHES),
            ], $events);

            try {
                // Inside the same guard as the insert: a missing table fails HERE
                // first, and must be counted as a drop like any other write failure.
                if (! $this->hasRoomFor(count($rows))) {
                    $this->state->add('dropped_events', count($rows));

                    return;
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    $connection->table($this->settings->eventsTable())->insert($chunk);
                }
            } catch (Throwable) {
                // The outbox is unavailable (not migrated, database down). The
                // events are lost and counted; nothing else is affected.
                $this->state->add('dropped_events', count($rows));
                $this->state->put('last_error', 'outbox_write_failed');
            }
        } catch (Throwable) {
            // Resolving the connection itself failed. Nothing to do but move on.
        } finally {
            $this->flushing = false;
        }
    }

    /** Events currently held in memory. For tests and `sms-cloud:status`. */
    public function pending(): int
    {
        return count($this->events);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $type, array $payload): void
    {
        if (count($this->events) >= $this->settings->int('buffer.max_memory_events', 5000)) {
            $this->dropped++;

            return;
        }

        // Carbon's clock rather than microtime(), so the one clock the application
        // (and its tests) can control is the one telemetry is stamped with.
        $this->events[] = [$type, (float) CarbonImmutable::now('UTC')->format('U.u'), $payload];

        // A long-running process (a bulk command, a worker between jobs) flushes
        // early rather than holding thousands of events; flush() itself refuses
        // to write while a transaction is open.
        if (count($this->events) >= 500) {
            $this->flush();
        }
    }

    /**
     * Whether the outbox can take more rows without passing its ceiling.
     *
     * ⚠️ Estimated from the id range (two index lookups) rather than COUNT(*),
     * which is a table scan on some engines. It can only over-estimate, so it errs
     * towards dropping new events, never towards unbounded growth. The pruner
     * trims the oldest rows separately.
     */
    private function hasRoomFor(int $incoming): bool
    {
        $table = $this->db->connection($this->settings->connection())->table($this->settings->eventsTable());
        $max = (int) (clone $table)->max('id');
        $min = (int) (clone $table)->min('id');
        $estimate = $max === 0 ? 0 : $max - $min + 1;

        return $estimate + $incoming <= $this->settings->int('buffer.max_events', 200000);
    }
}
