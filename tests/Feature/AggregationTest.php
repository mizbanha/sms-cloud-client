<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Health\CircuitBreaker;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Results\SendResult;
use Mizbanha\SmsCloud\Buffer\Outbox;
use Mizbanha\SmsCloud\Recording\Recorder;

/**
 * From recorded events to protocol-v1 batches.
 */
function sendAt(string $time, string $to = '09121234567'): void
{
    test()->travelTo(CarbonImmutable::parse($time, 'UTC'));
    Sms::to($to)->template('login-code')->with(['code' => '482193'])->send();
    app(Recorder::class)->flush();
}

function storedBatches(): array
{
    return DB::table('sms_cloud_batches')->orderBy('id')->get()
        ->map(fn ($row) => json_decode($row->payload, true) + ['_uuid' => $row->batch_uuid, '_rows' => $row->rows])
        ->all();
}

it('aggregates a failover into gateway, failover and message rows', function () {
    $this->chain([['smsir', 'first'], ['kavenegar', 'second']]);
    Http::fake([
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    sendAt('2026-09-19 10:36:12');
    sendAt('2026-09-19 10:36:40');

    expect(app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:38:00', 'UTC')))->toBe(1);

    [$batch] = storedBatches();
    $gateways = collect($batch['gateway_minutes'])->keyBy('gateway');

    expect($batch['window'])->toBe(['start' => '2026-09-19T10:36:00Z', 'end' => '2026-09-19T10:36:00Z'])
        ->and($gateways['first'])->toMatchArray([
            'minute' => '2026-09-19T10:36:00Z', 'driver' => 'smsir', 'attempts' => 2, 'accepted' => 0, 'rejected' => 2,
            'uncertain' => 0, 'failover_out' => 2, 'failover_in' => 0,
        ])
        ->and($gateways['first']['failure_kinds'])->toBe(['gateway_configuration' => 2])
        ->and($gateways['second'])->toMatchArray(['attempts' => 2, 'accepted' => 2, 'failover_in' => 2, 'failover_in_accepted' => 2])
        ->and(array_sum($gateways['second']['latency']['histogram']))->toBe(2)
        ->and($gateways['second']['latency']['count'])->toBe(2)
        ->and($batch['failover_minutes'])->toBe([[
            'minute' => '2026-09-19T10:36:00Z', 'from' => 'first', 'to' => 'second', 'reason' => 'gateway_configuration', 'count' => 2, 'accepted' => 2,
        ]])
        ->and($batch['message_minutes'])->toBe([[
            'minute' => '2026-09-19T10:36:00Z', 'attempted' => 2, 'accepted' => 2, 'failed' => 0, 'unknown' => 0, 'suppressed' => 0,
        ]]);

    expect(DB::table('sms_cloud_events')->count())->toBe(0);
});

it('leaves the current minute alone until it has closed', function () {
    $this->chain([['smsir', 'primary']]);
    Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    sendAt('2026-09-19 10:36:12');
    sendAt('2026-09-19 10:37:05');

    app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:37:30', 'UTC'));

    [$batch] = storedBatches();

    expect($batch['window']['end'])->toBe('2026-09-19T10:36:00Z')
        ->and(DB::table('sms_cloud_events')->count())->toBe(2);
});

it('records circuit transitions with their exact time', function () {
    [$gateway] = [SmsGateway::query()->forceCreate([
        'key' => 'kavenegar-main', 'label' => 'k', 'driver' => 'kavenegar', 'is_enabled' => true, 'priority' => 10,
    ])];
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:36:10.250', 'UTC'));

    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->record($gateway, SendResult::uncertain(FailureKind::Network, 'down'));
    }

    app(Recorder::class)->flush();
    app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:38:00', 'UTC'));

    [$batch] = storedBatches();

    expect($batch['circuit_transitions'])->toHaveCount(1)
        ->and($batch['circuit_transitions'][0])->toMatchArray([
            'at' => '2026-09-19T10:36:10.250Z', 'gateway' => 'kavenegar-main', 'driver' => 'kavenegar',
            'from' => 'closed', 'to' => 'open', 'reason' => 'failure_threshold', 'failures' => 3,
        ]);
});

it('splits a large backlog into bounded batches without splitting a minute', function () {
    config()->set('sms-cloud.publish.max_rows_per_batch', 2);
    $this->chain([['smsir', 'primary']]);
    Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    sendAt('2026-09-19 10:30:05');
    sendAt('2026-09-19 10:31:05');
    sendAt('2026-09-19 10:32:05');

    expect(app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:40:00', 'UTC')))->toBe(3);

    $batches = storedBatches();

    expect(collect($batches)->pluck('_rows')->all())->toBe([2, 2, 2])
        ->and(collect($batches)->pluck('_uuid')->unique())->toHaveCount(3)
        ->and($batches[0]['window']['start'])->toBe('2026-09-19T10:30:00Z')
        ->and($batches[2]['window']['start'])->toBe('2026-09-19T10:32:00Z');
});

it('gives each event to exactly one batch when two aggregators race', function () {
    $this->chain([['smsir', 'primary']]);
    Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);
    sendAt('2026-09-19 10:30:05');

    // A second aggregator deletes one of "our" events between our select and our
    // delete. The count check must notice and roll the whole run back.
    DB::statement("CREATE TRIGGER steal AFTER INSERT ON sms_cloud_batches BEGIN DELETE FROM sms_cloud_events WHERE id = (SELECT MIN(id) FROM sms_cloud_events); END");

    expect(fn () => app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:40:00', 'UTC')))
        ->toThrow(RuntimeException::class);

    expect(DB::table('sms_cloud_batches')->count())->toBe(0)
        ->and(DB::table('sms_cloud_events')->count())->toBe(2);

    DB::statement('DROP TRIGGER steal');

    expect(app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:40:00', 'UTC')))->toBe(1)
        ->and(app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:40:00', 'UTC')))->toBe(0);
});

it('counts a queue retry as a retry and a first attempt as an attempted message', function () {
    $this->chain([['smsir', 'primary']]);
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:30:05', 'UTC'));
    Illuminate\Support\Facades\Queue::fake();
    Http::fakeSequence('api.sms.ir/*')
        ->push(['message' => 'slow down'], 429)
        ->push(['status' => 1, 'data' => ['messageIds' => [42]]]);

    $message = Sms::to('09121234567')->template('login-code')->with(['code' => '482193'])->queue();

    foreach ([1, 2] as $run) {
        $job = new Mizbanha\Sms\Jobs\SendSmsMessage($message->getKey(), ['code' => '482193']);
        $underlying = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
        $underlying->shouldReceive('attempts')->andReturn($run);
        $underlying->shouldReceive('release')->andReturnNull();
        $underlying->shouldReceive('hasFailed')->andReturn(false);
        $underlying->shouldReceive('isReleased')->andReturn(false);
        $underlying->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $underlying->shouldReceive('getJobId')->andReturn('1');
        $job->setJob($underlying);
        $job->handle(app(Mizbanha\Sms\Sending\MessageDispatcher::class));
    }

    app(Recorder::class)->flush();
    app(Outbox::class)->aggregate(CarbonImmutable::parse('2026-09-19 10:40:00', 'UTC'));

    [$batch] = storedBatches();

    expect($batch['gateway_minutes'][0])->toMatchArray(['attempts' => 2, 'accepted' => 1, 'rejected' => 1, 'retries' => 1, 'failover_in' => 0])
        ->and($batch['message_minutes'][0])->toMatchArray(['attempted' => 1, 'accepted' => 1]);
});
