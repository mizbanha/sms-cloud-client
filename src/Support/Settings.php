<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed, defaulted access to `config('sms-cloud')`.
 *
 * Every reader tolerates a missing or malformed value by falling back to a safe
 * default: a typo in a config file must not become an exception inside a send.
 */
final class Settings
{
    public function __construct(private readonly Repository $config) {}

    /** Enabled AND configured. Nothing is listened to, recorded or scheduled otherwise. */
    public function active(): bool
    {
        return (bool) $this->config->get('sms-cloud.enabled', false)
            && $this->token() !== ''
            && $this->endpoint() !== '';
    }

    public function endpoint(): string
    {
        return rtrim((string) $this->config->get('sms-cloud.endpoint', ''), '/');
    }

    public function token(): string
    {
        return trim((string) $this->config->get('sms-cloud.token', ''));
    }

    public function appEnv(): string
    {
        $env = $this->config->get('sms-cloud.app_env');

        return is_string($env) && $env !== '' ? $env : (string) $this->config->get('app.env', 'production');
    }

    public function appVersion(): ?string
    {
        $version = $this->config->get('sms-cloud.app_version');

        return is_string($version) && preg_match('/^[A-Za-z0-9._+\-]{1,40}$/', $version) === 1 ? $version : null;
    }

    public function connection(): ?string
    {
        $connection = $this->config->get('sms-cloud.buffer.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function eventsTable(): string
    {
        return (string) $this->config->get('sms-cloud.buffer.tables.events', 'sms_cloud_events');
    }

    public function batchesTable(): string
    {
        return (string) $this->config->get('sms-cloud.buffer.tables.batches', 'sms_cloud_batches');
    }

    public function int(string $key, int $default, int $min = 1): int
    {
        $value = $this->config->get('sms-cloud.'.$key, $default);

        return is_numeric($value) ? max($min, (int) $value) : $default;
    }

    public function publishMode(): string
    {
        return $this->config->get('sms-cloud.publish.mode') === 'queue' ? 'queue' : 'schedule';
    }

    public function scheduleEnabled(): bool
    {
        return (bool) $this->config->get('sms-cloud.publish.schedule', true);
    }

    public function hashGatewayKeys(): bool
    {
        return (bool) $this->config->get('sms-cloud.privacy.hash_gateway_keys', false);
    }

    /** @return array{0: string|null, 1: string} */
    public function queue(): array
    {
        $connection = $this->config->get('sms-cloud.publish.queue_connection');

        return [is_string($connection) && $connection !== '' ? $connection : null, (string) $this->config->get('sms-cloud.publish.queue', 'sms-cloud')];
    }

    /**
     * How a gateway is named on the wire.
     *
     * With hashing on, a short one-way digest replaces the key. Stable per key, so
     * the dashboard can still tell gateways apart and follow one over time.
     */
    public function gatewayLabel(?string $key): string
    {
        $key = (string) $key;

        if ($key === '') {
            return 'unknown';
        }

        if ($this->hashGatewayKeys()) {
            return 'gw-'.substr(hash('sha256', 'sms-cloud-gateway:'.$key), 0, 16);
        }

        // The protocol's identifier grammar; anything else is reduced to it rather
        // than refused, so an odd key never costs a batch.
        $clean = preg_replace('/[^A-Za-z0-9._:\-]/', '-', $key) ?? 'unknown';
        $clean = ltrim($clean, '._:-');

        return $clean === '' ? 'unknown' : substr($clean, 0, 64);
    }

    public function driverLabel(?string $driver): string
    {
        $clean = ltrim(preg_replace('/[^A-Za-z0-9._:\-]/', '-', (string) $driver) ?? '', '._:-');

        return $clean === '' ? 'unknown' : substr($clean, 0, 64);
    }
}
