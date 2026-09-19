<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Buffer;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;
use RuntimeException;

/**
 * The two local tables: raw events, and aggregated batches waiting to be sent.
 *
 * ⚠️ **Events become a batch exactly once.** Selecting events, inserting the
 * batch and deleting those same events happen in one transaction, and the delete
 * must remove exactly as many rows as were selected — if a second aggregator got
 * to some of them first, the count differs and the whole transaction rolls back.
 * Two schedulers on two servers can run this at the same moment; at most one wins
 * each event.
 *
 * ⚠️ **Only closed minutes are aggregated.** An event for the current minute
 * waits, so a minute is normally sent once, complete. A late event for an old
 * minute simply goes into a later batch: the Cloud adds counts, it does not
 * replace them.
 */
final class Outbox
{
    public function __construct(
        private readonly Settings $settings,
        private readonly State $state,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return int batches created
     */
    public function aggregate(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $cutoff = $now->utc()->startOfMinute()->format('Y-m-d H:i:s');
        $limit = $this->settings->int('publish.max_events_per_aggregation', 50000);
        $connection = $this->connection();

        $events = $connection->table($this->settings->eventsTable())
            ->where('occurred_at', '<', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'type', 'occurred_at', 'payload']);

        if ($events->isEmpty()) {
            return 0;
        }

        $aggregation = new Aggregation;
        $aggregation->add($events);
        $batches = $aggregation->batches($this->settings->int('publish.max_rows_per_batch', 1000));
        $ids = $events->pluck('id')->all();

        $connection->transaction(function (ConnectionInterface $connection) use ($batches, $ids, $now): void {
            $created = $now->format('Y-m-d H:i:s');

            foreach ($batches as $batch) {
                $rows = $batch['rows'];
                unset($batch['rows']);

                $connection->table($this->settings->batchesTable())->insert([
                    'batch_uuid' => (string) Str::uuid(),
                    'payload' => json_encode($batch, JSON_UNESCAPED_SLASHES),
                    'rows' => $rows,
                    'attempts' => 0,
                    'next_attempt_at' => $created,
                    'created_at' => $created,
                ]);
            }

            $deleted = 0;

            foreach (array_chunk($ids, 1000) as $chunk) {
                $deleted += $connection->table($this->settings->eventsTable())->whereIn('id', $chunk)->delete();
            }

            if ($deleted !== count($ids)) {
                throw new RuntimeException('Another aggregator claimed some of these events; this run is rolled back.');
            }
        });

        return count($batches);
    }

    /**
     * Enforce the ceilings: batch age, batch count, event count. Oldest first.
     *
     * @return array{dropped_batches: int, dropped_events: int}
     */
    public function prune(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $connection = $this->connection();
        $batches = $connection->table($this->settings->batchesTable());
        $events = $connection->table($this->settings->eventsTable());

        $expired = (clone $batches)
            ->where('created_at', '<', $now->subHours($this->settings->int('buffer.batch_retention_hours', 72))->format('Y-m-d H:i:s'))
            ->delete();

        $overflowBatches = $this->trimOldest($batches, $this->settings->int('buffer.max_batches', 5000));

        // Events older than the batch retention can never be sent usefully either;
        // a stopped scheduler would otherwise let them sit forever.
        $staleEvents = (clone $events)
            ->where('occurred_at', '<', $now->subHours($this->settings->int('buffer.batch_retention_hours', 72))->format('Y-m-d H:i:s'))
            ->delete();

        $overflowEvents = $this->trimOldest($events, $this->settings->int('buffer.max_events', 200000));

        $droppedBatches = $expired + $overflowBatches;
        $droppedEvents = $staleEvents + $overflowEvents;

        $this->state->add('dropped_batches', $droppedBatches);
        $this->state->add('dropped_events', $droppedEvents);

        return ['dropped_batches' => $droppedBatches, 'dropped_events' => $droppedEvents];
    }

    /**
     * @return array{events: int, batches: int, oldest_batch_at: string|null}
     */
    public function stats(): array
    {
        $connection = $this->connection();
        $events = $connection->table($this->settings->eventsTable());
        $max = (int) (clone $events)->max('id');
        $min = (int) (clone $events)->min('id');
        $oldest = $connection->table($this->settings->batchesTable())->min('created_at');

        return [
            // An upper-bound estimate; see Recorder::hasRoomFor().
            'events' => $max === 0 ? 0 : $max - $min + 1,
            'batches' => $connection->table($this->settings->batchesTable())->count(),
            'oldest_batch_at' => $oldest === null ? null : CarbonImmutable::parse((string) $oldest, 'UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function connection(): ConnectionInterface
    {
        return $this->db->connection($this->settings->connection());
    }

    /**
     * Delete the oldest rows beyond `$keep`. Two index reads and one delete.
     */
    private function trimOldest(\Illuminate\Database\Query\Builder $table, int $keep): int
    {
        $boundary = (clone $table)->orderByDesc('id')->skip($keep)->limit(1)->value('id');

        return $boundary === null ? 0 : (clone $table)->where('id', '<=', $boundary)->delete();
    }
}
