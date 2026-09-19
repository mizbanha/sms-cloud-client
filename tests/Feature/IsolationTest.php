<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Events\AttemptRecorded;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\SmsCloud\PublishTelemetry;
use Mizbanha\SmsCloud\Recording\Recorder;
use Mizbanha\SmsCloud\Support\State;

/**
 * THE invariant: nothing about SMS Cloud can change whether, how or how fast an
 * application sends SMS.
 *
 * Every test here sends a real message through the real laravel-sms pipeline with
 * this client installed and enabled, while SMS Cloud misbehaves in a different
 * way, and asserts the send is exactly what it would have been without Cloud.
 */
function sendLogin(string $to = '09121234567')
{
    return Sms::to($to)->template('login-code')->with(['code' => '482193'])->send();
}

function providerAccepts(): array
{
    return ['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])];
}

it('sends normally and keeps telemetry when SMS Cloud is unreachable', function () {
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts() + ['cloud.test/*' => fn () => throw new ConnectionException('Could not resolve host: cloud.test')]);

    $message = sendLogin();
    app()->terminate();

    $this->artisan('sms-cloud:run')->assertSuccessful();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->count())->toBe(1);

    // The batch is still here, waiting, and the error is a code, not a message.
    $this->travel(2)->minutes();
    $this->artisan('sms-cloud:run')->assertSuccessful();

    expect(DB::table('sms_cloud_batches')->count())->toBe(1)
        ->and(app(State::class)->get('last_error'))->toBe('network');
});

it('sends normally when SMS Cloud answers 500, 429, 401 or times out', function (string $failure) {
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts() + ['cloud.test/*' => match ($failure) {
        '500' => Http::response(['error' => ['code' => 'server_error']], 500),
        '429' => Http::response(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '60']),
        '401' => Http::response(['error' => ['code' => 'invalid_credentials']], 401),
        'timeout' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    }]);

    $message = sendLogin();
    app()->terminate();
    $this->travel(2)->minutes();

    $this->artisan('sms-cloud:run')->assertSuccessful();
    $this->artisan('sms-cloud:run')->assertSuccessful();

    expect($message->fresh()->status)->toBe(MessageStatus::Accepted)
        ->and(Http::recorded(fn ($request) => str_contains($request->url(), 'sms.ir'))->count())->toBe(1)
        // Nothing lost: the batch waits for the Cloud to come back.
        ->and(DB::table('sms_cloud_batches')->count())->toBe(1);
})->with(['500', '429', '401', 'timeout']);

it('never makes a network call during a send', function () {
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts() + ['cloud.test/*' => Http::response([], 202)]);

    sendLogin();

    // Exactly one request — the provider. SMS Cloud is contacted only by the
    // background run, never from inside a send.
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cloud.test'));
});

it('does not write inside the application transaction, and writes after it', function () {
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts());

    DB::transaction(function () {
        sendLogin();
        app(Recorder::class)->flush();

        expect(DB::table('sms_cloud_events')->count())->toBe(0)
            ->and(app(Recorder::class)->pending())->toBe(2);
    });

    app(Recorder::class)->flush();

    expect(DB::table('sms_cloud_events')->count())->toBe(2);
});

it('keeps sending when the outbox table does not exist', function () {
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts());
    Schema::drop('sms_cloud_events');

    $message = sendLogin();
    app()->terminate();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and(app(State::class)->count('dropped_events'))->toBe(2);

    $this->artisan('sms-cloud:run')->assertSuccessful();
});

it('keeps sending when the outbox connection is misconfigured', function () {
    config()->set('sms-cloud.buffer.connection', 'no-such-connection');
    // Teardown rolls the outbox migration back on its configured connection.
    $this->beforeApplicationDestroyed(fn () => config()->set('sms-cloud.buffer.connection', null));
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts());

    $message = sendLogin();
    app()->terminate();

    expect($message->status)->toBe(MessageStatus::Accepted);
    $this->artisan('sms-cloud:run')->assertSuccessful();
});

it('does not let a failover decision change because the client is listening', function () {
    $this->chain([['smsir', 'first'], ['kavenegar', 'second']]);
    Http::fake([
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
        'cloud.test/*' => fn () => throw new ConnectionException('down'),
    ]);

    $message = sendLogin();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->orderBy('sequence')->pluck('gateway_key')->all())->toBe(['first', 'second']);
});

it('bounds memory: past the cap, events are counted and dropped', function () {
    config()->set('sms-cloud.buffer.max_memory_events', 3);
    $this->chain([['smsir', 'primary']]);
    Http::fake(providerAccepts());

    sendLogin();
    sendLogin();

    expect(app(Recorder::class)->pending())->toBe(3);

    app(Recorder::class)->flush();

    expect(app(State::class)->count('dropped_events'))->toBe(1)
        ->and(DB::table('sms_cloud_events')->count())->toBe(3);
});

it('registers the background run on the scheduler, in the background', function () {
    $events = collect(app(Schedule::class)->events());
    $run = $events->first(fn ($event) => str_contains((string) $event->command, 'sms-cloud:run'));

    expect($run)->not->toBeNull()
        ->and($run->runInBackground)->toBeTrue()
        ->and($run->withoutOverlapping)->toBeTrue();
});

it('publishes from a queued job with one try', function () {
    $job = new PublishTelemetry;

    expect($job->tries)->toBe(1)
        ->and($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class);
});
