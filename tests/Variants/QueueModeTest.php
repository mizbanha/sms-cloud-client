<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Mizbanha\SmsCloud\Tests\Variants\QueueModeTestCase;

uses(QueueModeTestCase::class);

it('schedules a job on its own queue, never the SMS queue', function () {
    $events = collect(app(Schedule::class)->events());
    $job = $events->first(fn ($event) => $event->description === 'sms-cloud:publish');

    expect($job)->toBeInstanceOf(CallbackEvent::class)
        ->and($events->filter(fn ($e) => str_contains((string) $e->command, 'sms-cloud:run')))->toHaveCount(0)
        ->and(config('sms-cloud.publish.queue'))->toBe('sms-cloud')
        ->and(config('laravel-sms.queue.queue'))->not->toBe(config('sms-cloud.publish.queue'));
});
