<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mizbanha\SmsCloud\Buffer\Outbox;
use Mizbanha\SmsCloud\Publishing\Publisher;
use Mizbanha\SmsCloud\Support\State;

/**
 * Publishing: retry semantics, pauses, dead letters and local bounds.
 */
function queueBatches(int $count, ?string $createdAt = null): void
{
    $createdAt ??= CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

    foreach (range(1, $count) as $i) {
        DB::table('sms_cloud_batches')->insert([
            'batch_uuid' => (string) Str::uuid(),
            'payload' => json_encode([
                'window' => ['start' => '2026-09-19T10:30:00Z', 'end' => '2026-09-19T10:30:00Z'],
                'gateway_minutes' => [], 'message_minutes' => [['minute' => '2026-09-19T10:30:00Z', 'attempted' => $i, 'accepted' => $i, 'failed' => 0, 'unknown' => 0, 'suppressed' => 0]],
                'failover_minutes' => [], 'circuit_transitions' => [],
            ]),
            'rows' => 1,
            'attempts' => 0,
            'next_attempt_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
    }
}

function publish(): array
{
    return app(Publisher::class)->run(microtime(true) + 30);
}

it('sends each batch with the token, the protocol header and its fixed identity, then forgets it', function () {
    queueBatches(2);
    $uuids = DB::table('sms_cloud_batches')->orderBy('id')->pluck('batch_uuid', 'id')->all();
    Http::fake(['cloud.test/*' => Http::response(['status' => 'accepted'], 202)]);

    expect(publish())->toMatchArray(['sent' => 2, 'failed' => 0]);

    $sequences = [];
    Http::assertSent(function (Request $request) use ($uuids, &$sequences) {
        $sequences[] = $request['sequence'];

        return $request->url() === 'https://cloud.test/api/v1/telemetry/batches'
            && $request->hasHeader('Authorization', 'Bearer '.config('sms-cloud.token'))
            && $request->hasHeader('X-Sms-Cloud-Protocol', '1')
            && $request['protocol_version'] === 1
            && $request['app_env'] === 'production'
            && $uuids[$request['sequence']] === $request['batch_id'];
    });

    expect(DB::table('sms_cloud_batches')->count())->toBe(0);
});

it('retries the SAME batch after a 500, with backoff, and stops the run', function () {
    queueBatches(3);
    Http::fakeSequence('cloud.test/*')
        ->push(['error' => ['code' => 'server_error']], 500)
        ->push(['status' => 'accepted'], 202)
        ->push(['status' => 'accepted'], 202)
        ->push(['status' => 'accepted'], 202);

    expect(publish())->toMatchArray(['sent' => 0, 'failed' => 1]);

    // Only one request: a Cloud answering 500 is not hammered with the rest.
    Http::assertSentCount(1);
    $first = DB::table('sms_cloud_batches')->orderBy('id')->first();

    expect($first->attempts)->toBe(1)
        ->and($first->last_error)->toBe('server_error')
        ->and(CarbonImmutable::parse($first->next_attempt_at, 'UTC')->isFuture())->toBeTrue();

    // Publishing as a whole is backing off — the OTHER due batches wait too, so a
    // Cloud that is down is not poked by a different batch every run.
    expect(publish())->toMatchArray(['paused' => true]);
    Http::assertSentCount(1);

    $this->travel(40)->seconds();
    expect(publish())->toMatchArray(['sent' => 3]);

    $ids = collect(Http::recorded())->map(fn ($pair) => $pair[0]['batch_id']);
    expect($ids[0])->toBe($ids[1])
        ->and(DB::table('sms_cloud_batches')->count())->toBe(0);
});

it('treats a timeout or a network failure as temporary', function () {
    queueBatches(1);
    Http::fake(['cloud.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds')]);

    expect(publish())->toMatchArray(['failed' => 1]);

    $batch = DB::table('sms_cloud_batches')->first();

    expect($batch->last_error)->toBe('network')
        ->and(app(State::class)->get('last_error'))->toBe('network');
});

it('backs off exponentially and caps the delay', function () {
    queueBatches(1);
    Http::fake(['cloud.test/*' => Http::response([], 503)]);

    $delays = [];
    foreach (range(1, 8) as $ignored) {
        $before = CarbonImmutable::now('UTC');
        publish();
        $next = CarbonImmutable::parse(DB::table('sms_cloud_batches')->value('next_attempt_at'), 'UTC');
        $delays[] = $before->diffInSeconds($next);
        $this->travelTo($next->addSecond());
    }

    expect($delays[0])->toBeGreaterThanOrEqual(30)->toBeLessThan(40)
        ->and($delays[1])->toBeGreaterThanOrEqual(60)
        ->and(max($delays))->toBeLessThanOrEqual(1800 * 1.2 + 1);
});

it('pauses for Retry-After on 429 and keeps the batch', function () {
    queueBatches(2);
    Http::fakeSequence('cloud.test/*')
        ->push(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '120'])
        ->push(['status' => 'accepted'], 202)
        ->push(['status' => 'accepted'], 202);

    expect(publish())->toMatchArray(['paused' => true]);

    $until = app(State::class)->pausedUntil();

    expect($until)->not->toBeNull()
        ->and((int) round(CarbonImmutable::now()->diffInSeconds($until)))->toBeGreaterThanOrEqual(119)->toBeLessThanOrEqual(120)
        ->and(DB::table('sms_cloud_batches')->count())->toBe(2);

    publish();
    Http::assertSentCount(1);

    $this->travel(121)->seconds();
    expect(publish())->toMatchArray(['sent' => 2]);
});

it('pauses and keeps everything on 401, until the credential is fixed', function () {
    queueBatches(1);
    Http::fake(['cloud.test/*' => Http::response(['error' => ['code' => 'invalid_credentials']], 401)]);

    publish();

    expect(DB::table('sms_cloud_batches')->count())->toBe(1)
        ->and(app(State::class)->get('paused_reason'))->toBe('invalid_credentials');
});

it('pauses and keeps everything on an environment mismatch', function () {
    queueBatches(1);
    Http::fake(['cloud.test/*' => Http::response(['error' => ['code' => 'environment_mismatch']], 409)]);

    publish();

    expect(DB::table('sms_cloud_batches')->count())->toBe(1)
        ->and(app(State::class)->pausedUntil())->not->toBeNull();
});

it('drops a batch the Cloud says it can never accept, and moves on', function (int $status, string $code) {
    queueBatches(2);
    Http::fakeSequence('cloud.test/*')
        ->push(['error' => ['code' => $code]], $status)
        ->push(['status' => 'accepted'], 202);

    expect(publish())->toMatchArray(['dropped' => 1, 'sent' => 1]);

    expect(DB::table('sms_cloud_batches')->count())->toBe(0)
        ->and(app(State::class)->count('dropped_batches'))->toBe(1);
})->with([
    'invalid payload' => [422, 'invalid_payload'],
    'too large' => [413, 'payload_too_large'],
    'id conflict' => [409, 'batch_conflict'],
]);

it('respects the per-run time budget', function () {
    queueBatches(5);
    Http::fake(['cloud.test/*' => Http::response(['status' => 'accepted'], 202)]);

    $report = app(Publisher::class)->run(microtime(true) - 1);

    expect($report['sent'])->toBe(0);
    Http::assertNothingSent();
});

it('drops batches older than the retention and counts them', function () {
    queueBatches(2, CarbonImmutable::now('UTC')->subHours(73)->format('Y-m-d H:i:s'));
    queueBatches(1);

    expect(app(Outbox::class)->prune())->toMatchArray(['dropped_batches' => 2]);

    expect(DB::table('sms_cloud_batches')->count())->toBe(1)
        ->and(app(State::class)->count('dropped_batches'))->toBe(2);
});

it('keeps at most max_batches, dropping the oldest', function () {
    config()->set('sms-cloud.buffer.max_batches', 3);
    queueBatches(5);
    $newest = DB::table('sms_cloud_batches')->orderByDesc('id')->limit(3)->pluck('id')->sort()->values()->all();

    app(Outbox::class)->prune();

    expect(DB::table('sms_cloud_batches')->orderBy('id')->pluck('id')->all())->toBe($newest);
});

it('keeps at most max_events, dropping the oldest', function () {
    config()->set('sms-cloud.buffer.max_events', 3);

    foreach (range(1, 5) as $i) {
        DB::table('sms_cloud_events')->insert(['type' => 'settled', 'occurred_at' => now()->format('Y-m-d H:i:s.v'), 'payload' => '{"status":"accepted"}']);
    }

    expect(app(Outbox::class)->prune())->toMatchArray(['dropped_events' => 2])
        ->and(DB::table('sms_cloud_events')->count())->toBe(3);
});
