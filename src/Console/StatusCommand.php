<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Mizbanha\SmsCloud\Buffer\Outbox;
use Mizbanha\SmsCloud\Runner;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'sms-cloud:status {--ping : Send a heartbeat now and report the answer}';

    protected $description = 'Show the SMS Cloud client configuration, local buffer and last errors';

    public function handle(Settings $settings, State $state, Outbox $outbox, Runner $runner): int
    {
        $this->components->twoColumnDetail('Enabled', $settings->active() ? '<fg=green>yes</>' : '<fg=yellow>no</>');
        $this->components->twoColumnDetail('Endpoint', $settings->endpoint() ?: '—');
        // Never the token: the prefix and the last four characters are enough to
        // tell which one is configured.
        $token = $settings->token();
        $this->components->twoColumnDetail('Token', $token === '' ? '—' : substr($token, 0, 9).'…'.substr($token, -4));
        $this->components->twoColumnDetail('Reported APP_ENV', $settings->appEnv());
        $this->components->twoColumnDetail('Publish mode', $settings->publishMode());

        try {
            $stats = $outbox->stats();
            $this->components->twoColumnDetail('Buffered events (approx.)', (string) $stats['events']);
            $this->components->twoColumnDetail('Batches waiting', (string) $stats['batches']);
            $this->components->twoColumnDetail('Oldest waiting batch', $stats['oldest_batch_at'] ?? '—');
        } catch (Throwable) {
            $this->components->twoColumnDetail('Local buffer', '<fg=red>unavailable — run the sms-cloud migrations</>');
        }

        $this->components->twoColumnDetail('Dropped events (total)', (string) $state->count('dropped_events'));
        $this->components->twoColumnDetail('Dropped batches (total)', (string) $state->count('dropped_batches'));
        $this->components->twoColumnDetail('Last error', (string) ($state->get('last_error') ?? '—'));

        $paused = $state->pausedUntil();
        $this->components->twoColumnDetail('Publishing paused until', $paused === null ? '—' : $paused->toIso8601ZuluString().' ('.$state->get('paused_reason').')');

        $last = $state->get('last_heartbeat_at');
        $this->components->twoColumnDetail('Last successful heartbeat', is_int($last) ? CarbonImmutable::createFromTimestamp($last)->toIso8601ZuluString() : '—');

        if ($this->option('ping')) {
            if (! $settings->active()) {
                $this->components->warn('Not enabled; nothing sent.');

                return self::FAILURE;
            }

            $result = $runner->sendHeartbeat();
            $ok = $result['status'] >= 200 && $result['status'] < 300;
            $this->components->twoColumnDetail('Heartbeat', $ok ? '<fg=green>accepted</>' : '<fg=red>'.($result['code'] ?? 'http_'.$result['status']).'</>');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
