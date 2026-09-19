<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Console;

use Illuminate\Console\Command;
use Mizbanha\SmsCloud\Runner;

final class RunCommand extends Command
{
    protected $signature = 'sms-cloud:run';

    protected $description = 'Aggregate buffered SMS telemetry, publish it to SMS Cloud and send a heartbeat';

    public function handle(Runner $runner): int
    {
        $report = $runner->run();

        if (! ($report['active'] ?? false)) {
            $this->components->info('SMS Cloud is not enabled (SMS_CLOUD_ENABLED / SMS_CLOUD_TOKEN). Nothing to do.');

            return self::SUCCESS;
        }

        $this->line(json_encode($report, JSON_UNESCAPED_SLASHES) ?: '{}');

        // Always success: an unreachable Cloud is an expected state, not a failed
        // scheduled task, and must not page anybody through the scheduler.
        return self::SUCCESS;
    }
}
