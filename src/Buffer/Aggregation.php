<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Buffer;

/**
 * Turns raw outbox events into protocol-v1 sections, per UTC minute.
 *
 * Pure: arrays in, arrays out. Everything it emits is a count, a duration, an
 * outcome name, a failure kind, a gateway label or a driver name.
 */
final class Aggregation
{
    /** @var array<string, array<string, array<string, mixed>>> minute => gateway => row */
    private array $gateways = [];

    /** @var array<string, array<string, int>> minute => counters */
    private array $messages = [];

    /** @var array<string, array<string, array<string, mixed>>> minute => from|to|reason => row */
    private array $failovers = [];

    /** @var array<string, list<array<string, mixed>>> minute => transitions */
    private array $transitions = [];

    /**
     * @param  iterable<object{type: string, occurred_at: string, payload: string}>  $events
     */
    public function add(iterable $events): void
    {
        foreach ($events as $event) {
            $payload = json_decode((string) $event->payload, true);

            if (! is_array($payload)) {
                continue;
            }

            $occurred = self::rfc3339((string) $event->occurred_at);
            $minute = substr($occurred, 0, 16);

            match ($event->type) {
                'attempt' => $this->attempt($minute, $payload),
                'settled' => $this->settled($minute, $payload),
                'circuit' => $this->circuit($minute, $occurred, $payload),
                'delivery' => $this->delivery($minute, $payload),
                default => null,
            };
        }
    }

    /**
     * Split into batches of at most `$maxRows` rows and at most 23 hours each,
     * never splitting one minute across two batches.
     *
     * @return list<array<string, mixed>> batch payload sections, each with a window
     */
    public function batches(int $maxRows): array
    {
        $minutes = array_unique([...array_keys($this->gateways), ...array_keys($this->messages),
            ...array_keys($this->failovers), ...array_keys($this->transitions)]);
        sort($minutes);

        $batches = [];
        $current = null;

        foreach ($minutes as $minute) {
            $sections = $this->minuteSections($minute);
            $rows = array_sum(array_map('count', $sections));

            $tooMany = $current !== null && $current['rows'] + $rows > $maxRows;
            $tooLong = $current !== null && (strtotime($minute.':00Z') - strtotime($current['start'].':00Z')) >= 23 * 3600;

            if ($current === null || $tooMany || $tooLong) {
                if ($current !== null) {
                    $batches[] = $current;
                }

                $current = ['start' => $minute, 'end' => $minute, 'rows' => 0,
                    'gateway_minutes' => [], 'message_minutes' => [], 'failover_minutes' => [], 'circuit_transitions' => []];
            }

            foreach ($sections as $name => $list) {
                array_push($current[$name], ...$list);
            }

            $current['end'] = $minute;
            $current['rows'] += $rows;
        }

        if ($current !== null) {
            $batches[] = $current;
        }

        return array_map(static fn (array $batch): array => [
            'window' => ['start' => $batch['start'].':00Z', 'end' => $batch['end'].':00Z'],
            'gateway_minutes' => $batch['gateway_minutes'],
            'message_minutes' => $batch['message_minutes'],
            'failover_minutes' => $batch['failover_minutes'],
            'circuit_transitions' => $batch['circuit_transitions'],
            'rows' => $batch['rows'],
        ], $batches);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function minuteSections(string $minute): array
    {
        $stamp = $minute.':00Z';

        $gateways = [];
        foreach ($this->gateways[$minute] ?? [] as $row) {
            $row['minute'] = $stamp;
            $row['failure_kinds'] = (object) $row['failure_kinds'];
            $gateways[] = $row;
        }

        $messages = isset($this->messages[$minute]) ? [['minute' => $stamp] + $this->messages[$minute]] : [];

        $failovers = [];
        foreach ($this->failovers[$minute] ?? [] as $row) {
            $failovers[] = ['minute' => $stamp] + $row;
        }

        return [
            'gateway_minutes' => $gateways,
            'message_minutes' => $messages,
            'failover_minutes' => $failovers,
            'circuit_transitions' => $this->transitions[$minute] ?? [],
        ];
    }

    private function attempt(string $minute, array $payload): void
    {
        $row = &$this->gatewayRow($minute, (string) ($payload['gateway'] ?? 'unknown'), (string) ($payload['driver'] ?? 'unknown'));

        $row['attempts']++;

        match ($payload['outcome'] ?? null) {
            'accepted' => $row['accepted']++,
            'uncertain' => $row['uncertain']++,
            default => $row['rejected']++,
        };

        $kind = $payload['failure_kind'] ?? null;

        if (is_string($kind) && $kind !== '') {
            $row['failure_kinds'][$kind] = ($row['failure_kinds'][$kind] ?? 0) + 1;
        }

        $duration = max(0, (int) ($payload['duration_ms'] ?? 0));
        $row['latency']['count']++;
        $row['latency']['sum_ms'] += $duration;
        $row['latency']['max_ms'] = max($row['latency']['max_ms'], $duration);
        $row['latency']['histogram'][Histogram::bucket($duration)]++;

        if (! empty($payload['retry'])) {
            $row['retries']++;
        }

        $accepted = ($payload['outcome'] ?? null) === 'accepted';

        if (! empty($payload['from'])) {
            $row['failover_in']++;

            if ($accepted) {
                $row['failover_in_accepted']++;
            }

            unset($row);

            $from = &$this->gatewayRow($minute, (string) $payload['from'], (string) ($payload['from_driver'] ?? 'unknown'));
            $from['failover_out']++;
            unset($from);

            $reason = is_string($payload['from_reason'] ?? null) && $payload['from_reason'] !== '' ? $payload['from_reason'] : 'unknown';
            $key = $payload['from'].'|'.$payload['gateway'].'|'.$reason;

            $this->failovers[$minute][$key] ??= [
                'from' => (string) $payload['from'], 'to' => (string) $payload['gateway'], 'reason' => $reason, 'count' => 0, 'accepted' => 0,
            ];
            $this->failovers[$minute][$key]['count']++;

            if ($accepted) {
                $this->failovers[$minute][$key]['accepted']++;
            }
        }

        if (! empty($payload['first'])) {
            $message = &$this->messageRow($minute);
            $message['attempted']++;
        }
    }

    private function settled(string $minute, array $payload): void
    {
        $status = $payload['status'] ?? null;

        if (in_array($status, ['accepted', 'failed', 'unknown', 'suppressed'], true)) {
            $message = &$this->messageRow($minute);
            $message[$status]++;
        }
    }

    private function circuit(string $minute, string $occurred, array $payload): void
    {
        $this->transitions[$minute][] = [
            'at' => $occurred.'Z',
            'gateway' => (string) ($payload['gateway'] ?? 'unknown'),
            'driver' => (string) ($payload['driver'] ?? 'unknown'),
            'from' => (string) ($payload['from'] ?? 'closed'),
            'to' => (string) ($payload['to'] ?? 'closed'),
            'reason' => (string) ($payload['reason'] ?? 'unknown'),
            'failures' => max(0, (int) ($payload['failures'] ?? 0)),
            'open_until' => $payload['open_until'] ?? null,
        ];
    }

    private function delivery(string $minute, array $payload): void
    {
        $row = &$this->gatewayRow($minute, (string) ($payload['gateway'] ?? 'unknown'), (string) ($payload['driver'] ?? 'unknown'));

        $row['delivery']['lookups']++;

        if (empty($payload['ok'])) {
            $row['delivery']['lookup_failures']++;

            return;
        }

        $status = $payload['status'] ?? null;

        if (in_array($status, ['delivered', 'failed', 'sent', 'unknown'], true)) {
            $row['delivery'][$status]++;
        }
    }

    /**
     * `2026-09-19 10:36:12.345` → `2026-09-19T10:36:12.345`.
     *
     * ⚠️ The fraction is normalised to exactly three digits because engines
     * disagree: PostgreSQL trims trailing zeros (`.25`), MySQL and SQLite keep
     * them (`.250`), and a timestamp on the wire should not depend on which
     * database the customer happens to run.
     */
    private static function rfc3339(string $stored): string
    {
        $value = str_replace(' ', 'T', trim($stored));
        $dot = strpos($value, '.');

        if ($dot === false) {
            return substr($value, 0, 19).'.000';
        }

        return substr($value, 0, 19).'.'.str_pad(substr(substr($value, $dot + 1), 0, 3), 3, '0');
    }

    /** @return array<string, mixed> */
    private function &gatewayRow(string $minute, string $gateway, string $driver): array
    {
        if (! isset($this->gateways[$minute][$gateway])) {
            $this->gateways[$minute][$gateway] = [
                'gateway' => $gateway,
                'driver' => $driver,
                'attempts' => 0, 'accepted' => 0, 'rejected' => 0, 'uncertain' => 0,
                'failure_kinds' => [],
                'failover_out' => 0, 'failover_in' => 0, 'failover_in_accepted' => 0, 'retries' => 0,
                'latency' => ['count' => 0, 'sum_ms' => 0, 'max_ms' => 0, 'histogram' => array_fill(0, Histogram::SIZE, 0)],
                'delivery' => ['lookups' => 0, 'lookup_failures' => 0, 'delivered' => 0, 'failed' => 0, 'sent' => 0, 'unknown' => 0],
            ];
        } elseif ($this->gateways[$minute][$gateway]['driver'] === 'unknown' && $driver !== 'unknown') {
            $this->gateways[$minute][$gateway]['driver'] = $driver;
        }

        return $this->gateways[$minute][$gateway];
    }

    /** @return array<string, int> */
    private function &messageRow(string $minute): array
    {
        $this->messages[$minute] ??= ['attempted' => 0, 'accepted' => 0, 'failed' => 0, 'unknown' => 0, 'suppressed' => 0];

        return $this->messages[$minute];
    }
}
