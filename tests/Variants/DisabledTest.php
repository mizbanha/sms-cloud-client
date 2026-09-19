<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Events\AttemptRecorded;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\SmsCloud\Tests\Variants\DisabledTestCase;

uses(DisabledTestCase::class);

it('does nothing at all when disabled', function () {
    expect(Event::hasListeners(AttemptRecorded::class))->toBeFalse();

    $this->chain([['smsir', 'primary']]);
    Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    $message = Sms::to('09121234567')->template('login-code')->with(['code' => '482193'])->send();
    app()->terminate();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and(DB::table('sms_cloud_events')->count())->toBe(0)
        ->and(collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'sms-cloud')))->toHaveCount(0);

    Http::assertSentCount(1);

    $this->artisan('sms-cloud:run')->expectsOutputToContain('not enabled')->assertSuccessful();
});
