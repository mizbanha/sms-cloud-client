<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Heartbeat;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Health\CircuitBreaker;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsMessage;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\SmsCloud\Buffer\Outbox;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;
use Throwable;

/**
 * What a heartbeat says about this installation.
 *
 * ⚠️ **What is NOT here, on purpose:** gateway credentials (not even hashed), the
 * sender line, hostnames, IPs, paths, environment variables, template wording,
 * template variables, recipients, message ids. Template keys and gateway settings
 * appear only inside SHA-256 digests used to detect configuration drift between
 * installations, and those digests are computed over configuration, never over
 * credentials.
 *
 * ⚠️ **Every section is optional.** A section that cannot be built — the SMS
 * tables are not migrated yet, the cache store is down — is left out, and the
 * heartbeat still goes. A heartbeat that fails to BUILD is a heartbeat the Cloud
 * never gets, which would mark a perfectly healthy installation offline.
 */
final class HeartbeatBuilder
{
    public function __construct(
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Outbox $outbox,
        private readonly Application $app,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $body = [
            'protocol_version' => 1,
            'sent_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'app_env' => $this->settings->appEnv(),
            'versions' => $this->versions(),
            'capabilities' => ['events.v1', 'config_fingerprint.v1'],
        ];

        $monitoring = $this->safely(fn () => $this->circuitMonitoring());

        if ($monitoring !== null) {
            $body['circuit_breaker'] = ['monitoring' => $monitoring];
        }

        foreach ([
            'gateways' => fn () => $this->gateways($monitoring === true),
            'gauges' => fn () => $this->gauges(),
            'buffer' => fn () => $this->buffer(),
            'config' => fn () => $this->fingerprint(),
        ] as $key => $section) {
            $value = $this->safely($section);

            if ($value !== null) {
                $body[$key] = $value;
            }
        }

        return $body;
    }

    /** @return array<string, string> */
    private function versions(): array
    {
        $versions = [
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
            'laravel' => $this->clean($this->app->version()),
        ];

        foreach (['laravel_sms' => 'mizbanha/laravel-sms', 'filament_sms' => 'mizbanha/filament-sms', 'client' => 'mizbanha/sms-cloud-client'] as $key => $package) {
            $version = $this->safely(fn () => InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null);

            if (is_string($version) && ($clean = $this->clean($version)) !== null) {
                $versions[$key] = $clean;
            }
        }

        if (($app = $this->settings->appVersion()) !== null) {
            $versions['app'] = $app;
        }

        return array_filter($versions);
    }

    /**
     * Whether Core's breaker can actually run. Mirrors Core's own rule — enabled in
     * config, and a cache store with atomic locks — without calling its internals.
     * "Not monitored" is reported as such, never as a row of closed circuits.
     */
    private function circuitMonitoring(): bool
    {
        if (! (bool) config('laravel-sms.circuit_breaker.enabled', true)) {
            return false;
        }

        $store = config('laravel-sms.circuit_breaker.store') ?? config('laravel-sms.lock.store');

        return Cache::store($store)->getStore() instanceof LockProvider;
    }

    /** @return list<array<string, mixed>> */
    private function gateways(bool $monitoring): array
    {
        $breaker = $this->app->make(CircuitBreaker::class);

        return SmsGateway::query()->orderBy('priority')->orderBy('id')->limit(100)->get()
            ->map(function (SmsGateway $gateway) use ($breaker, $monitoring): array {
                $row = [
                    'key' => $this->settings->gatewayLabel($gateway->key),
                    'driver' => $this->settings->driverLabel($gateway->driver),
                    'enabled' => (bool) $gateway->is_enabled,
                    'priority' => max(0, (int) $gateway->priority),
                ];

                if ($monitoring) {
                    // Read-only: status() never claims a probe or changes anything.
                    $snapshot = $breaker->status($gateway);
                    $row['circuit'] = [
                        'state' => $snapshot->state->value,
                        'failures' => max(0, $snapshot->failures),
                        'open_until' => $snapshot->openUntil?->utc()->format('Y-m-d\TH:i:s\Z'),
                    ];
                }

                return $row;
            })
            ->unique('key')
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function gauges(): array
    {
        $since = CarbonImmutable::now()->subDay();

        return [
            'messages_pending' => SmsMessage::query()
                ->whereIn('status', [MessageStatus::Queued->value, MessageStatus::Sending->value])
                ->where('created_at', '>=', $since)
                ->count(),
            'delivery_pending' => SmsMessage::query()
                ->where('delivery_status', 'pending')
                ->where('created_at', '>=', CarbonImmutable::now()->subDays(7))
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function buffer(): array
    {
        $stats = $this->outbox->stats();
        $error = $this->state->get('last_error');

        return array_filter([
            'pending_events' => $stats['events'],
            'pending_batches' => $stats['batches'],
            'dropped_events' => $this->state->count('dropped_events'),
            'dropped_batches' => $this->state->count('dropped_batches'),
            'oldest_pending_batch_at' => $stats['oldest_batch_at'],
            'last_publish_error' => is_string($error) && preg_match('/^[a-z0-9_]{1,32}$/', $error) === 1 ? $error : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * Digests of configuration, for drift detection between installations of the
     * same environment. Two installations with the same gateways, bindings and
     * routing produce the same digests; any difference shows up as a mismatch
     * without anybody learning what the configuration is.
     *
     * @return array<string, mixed>
     */
    private function fingerprint(): array
    {
        $gateways = SmsGateway::query()->orderBy('key')->get()->map(fn (SmsGateway $g): array => [
            $g->key, $g->driver, (bool) $g->is_enabled, (int) $g->priority,
            $g->country_policy?->value ?? (string) $g->country_policy,
            collect($g->countries ?? [])->sort()->values()->all(),
        ])->all();

        $templates = SmsTemplate::query()->orderBy('key')->get();

        $bindings = SmsTemplateGateway::query()
            ->with(['template:id,key', 'gateway:id,key'])
            ->get()
            ->map(fn ($b): array => [
                $b->template?->key, $b->gateway?->key, $b->mode?->value ?? (string) $b->mode, (bool) $b->is_enabled, (int) ($b->weight ?? 1),
            ])
            ->sort()
            ->values()
            ->all();

        $strategies = $templates->countBy(fn (SmsTemplate $t): string => $t->routing_strategy?->value ?? 'priority')->all();
        ksort($strategies);

        return [
            'gateways_hash' => $this->digest($gateways),
            'templates_hash' => $this->digest($templates->map(fn (SmsTemplate $t): array => [$t->key, (bool) $t->is_sensitive])->all()),
            'routing_hash' => $this->digest($templates->map(fn (SmsTemplate $t): array => [$t->key, $t->routing_strategy?->value])->all()),
            'bindings_hash' => $this->digest($bindings),
            'gateway_count' => count($gateways),
            'enabled_gateway_count' => count(array_filter($gateways, static fn (array $g): bool => $g[2])),
            'template_count' => $templates->count(),
            'routing_strategies' => $strategies,
        ];
    }

    private function digest(array $value): string
    {
        return hash('sha256', (string) json_encode($value));
    }

    private function clean(string $version): ?string
    {
        $version = ltrim($version, 'v');

        return preg_match('/^[A-Za-z0-9._+\-]{1,40}$/', $version) === 1 ? $version : null;
    }

    private function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
