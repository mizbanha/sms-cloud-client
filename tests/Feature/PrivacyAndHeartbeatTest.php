<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Health\CircuitBreaker;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Results\SendResult;
use Mizbanha\SmsCloud\Heartbeat\HeartbeatBuilder;

/**
 * What leaves the application — asserted on the actual HTTP bodies.
 */
function everythingSentToCloud(): string
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'cloud.test'))
        ->map(fn ($pair) => $pair[0]->body())
        ->implode("\n");
}

it('never sends a recipient, body, code, template key, sender line, credential or provider text', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-19 10:30:05', 'UTC'));
    $this->chain([['smsir', 'first'], ['kavenegar', 'second']]);
    Http::fake([
        // The provider quotes the message and the number back in its refusal.
        'api.sms.ir/*' => Http::response(['message' => 'Rejected text "Your code is 482193" for +989121234567'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 7755331]]]),
        'cloud.test/*' => Http::response(['status' => 'accepted'], 202),
    ]);

    Sms::to('09121234567')->template('login-code')->with(['code' => '482193'])->send();
    app()->terminate();
    $this->travel(2)->minutes();
    $this->artisan('sms-cloud:run')->assertSuccessful();

    $sent = everythingSentToCloud();

    // Both kinds of request really went out, so the assertions below inspect real bodies.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/telemetry/batches'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/heartbeat'));

    expect($sent)->not->toBeEmpty()
        ->and($sent)->not->toContain('482193')
        ->and($sent)->not->toContain('9121234567')
        ->and($sent)->not->toContain('Your code')
        ->and($sent)->not->toContain('login-code')
        ->and($sent)->not->toContain('9900001111')
        ->and($sent)->not->toContain('provider-secret-key')
        ->and($sent)->not->toContain('Rejected text')
        ->and($sent)->not->toContain('7755331')
        ->and($sent)->not->toContain('base64:');
});

it('sends versions, gateway circuits, gauges, buffer state and a configuration fingerprint in the heartbeat', function () {
    $this->chain([['smsir', 'primary'], ['kavenegar', 'backup']]);
    $gateway = SmsGateway::query()->where('key', 'primary')->first();

    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->record($gateway, SendResult::uncertain(FailureKind::Network, 'down'));
    }

    $body = app(HeartbeatBuilder::class)->build();

    expect($body['protocol_version'])->toBe(1)
        ->and($body['app_env'])->toBe('production')
        ->and($body['versions'])->toHaveKeys(['php', 'laravel', 'laravel_sms'])
        ->and($body['circuit_breaker'])->toBe(['monitoring' => true])
        ->and($body['gateways'][0])->toMatchArray(['key' => 'primary', 'driver' => 'smsir', 'enabled' => true, 'priority' => 10])
        ->and($body['gateways'][0]['circuit']['state'])->toBe('open')
        ->and($body['gateways'][1]['circuit']['state'])->toBe('closed')
        ->and($body['gauges'])->toHaveKeys(['messages_pending', 'delivery_pending'])
        ->and($body['buffer'])->toHaveKeys(['pending_events', 'pending_batches', 'dropped_events', 'dropped_batches'])
        ->and($body['config'])->toHaveKeys(['gateways_hash', 'templates_hash', 'routing_hash', 'bindings_hash', 'gateway_count'])
        ->and($body['config']['gateway_count'])->toBe(2)
        ->and($body['config']['routing_strategies'])->toBe(['priority' => 1]);

    $json = json_encode($body);

    expect($json)->not->toContain('provider-secret-key')
        ->and($json)->not->toContain('9900001111')
        ->and($json)->not->toContain('login-code');
});

it('changes the fingerprint when configuration changes, and only then', function () {
    $this->chain([['smsir', 'primary']]);
    $before = app(HeartbeatBuilder::class)->build()['config'];

    expect(app(HeartbeatBuilder::class)->build()['config'])->toBe($before);

    SmsGateway::query()->where('key', 'primary')->update(['priority' => 5]);
    $after = app(HeartbeatBuilder::class)->build()['config'];

    expect($after['gateways_hash'])->not->toBe($before['gateways_hash'])
        ->and($after['templates_hash'])->toBe($before['templates_hash']);

    // A credential change is NOT configuration drift, and never reaches the digest.
    $gateway = SmsGateway::query()->where('key', 'primary')->first();
    $gateway->forceFill(['credentials' => ['api_key' => 'rotated-key']])->save();

    expect(app(HeartbeatBuilder::class)->build()['config']['gateways_hash'])->toBe($after['gateways_hash']);
});

it('reports circuits as not monitored when the breaker cannot run', function () {
    config()->set('laravel-sms.circuit_breaker.enabled', false);
    $this->chain([['smsir', 'primary']]);

    $body = app(HeartbeatBuilder::class)->build();

    expect($body['circuit_breaker'])->toBe(['monitoring' => false])
        ->and($body['gateways'][0])->not->toHaveKey('circuit');
});

it('hashes gateway keys when asked to', function () {
    config()->set('sms-cloud.privacy.hash_gateway_keys', true);
    $this->chain([['smsir', 'kavenegar-main-account']]);

    $key = app(HeartbeatBuilder::class)->build()['gateways'][0]['key'];

    expect($key)->toStartWith('gw-')->toHaveLength(19)
        ->and(json_encode(app(HeartbeatBuilder::class)->build()))->not->toContain('kavenegar-main-account');
});

it('still sends a heartbeat when the SMS tables are missing', function () {
    Schema::drop('sms_template_gateways');
    Schema::drop('sms_attempts');
    Schema::drop('sms_messages');

    Http::fake(['cloud.test/*' => Http::response(['status' => 'ok'], 200)]);

    $this->artisan('sms-cloud:run')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/v1/heartbeat')
        && isset($request['versions']['php']));
});

it('shows status without ever printing the token', function () {
    $this->artisan('sms-cloud:status')
        ->expectsOutputToContain('smsc_01j8')
        ->doesntExpectOutputToContain(str_repeat('A', 43))
        ->assertSuccessful();
});
