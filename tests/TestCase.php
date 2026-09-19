<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud\Tests;

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\Sms\SmsServiceProvider;
use Mizbanha\SmsCloud\SmsCloudServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A real Laravel application with the real `laravel-sms` pipeline and this
 * client, on an in-memory database. Only the network is faked: providers and SMS
 * Cloud both answer through `Http::fake()`.
 */
abstract class TestCase extends Orchestra
{
    /*
     * ⚠️ No RefreshDatabase. It wraps every test in a transaction, and the
     * recorder deliberately refuses to write inside one — so the suite would test
     * a client that never flushes. Each test gets a fresh in-memory database and
     * real migrations instead.
     */

    protected function getPackageProviders($app): array
    {
        return [SmsServiceProvider::class, SmsCloudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.env', 'production');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('laravel-sms.enabled', true);

        $app['config']->set('sms-cloud.enabled', true);
        $app['config']->set('sms-cloud.endpoint', 'https://cloud.test');
        $app['config']->set('sms-cloud.token', 'smsc_01j8z3q4c9x7m2n5p6r8s0t1v3_'.str_repeat('A', 43));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/mizbanha/laravel-sms/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $gateways  [driver, key], priority ascending
     */
    protected function chain(array $gateways): SmsTemplate
    {
        $template = SmsTemplate::query()->create([
            'key' => 'login-code',
            'name' => 'Login code',
            'body' => 'Your code is {code}',
            'is_sensitive' => true,
        ]);

        foreach ($gateways as $index => [$driver, $key]) {
            $gateway = new SmsGateway;
            $gateway->forceFill([
                'key' => $key,
                'label' => $key,
                'driver' => $driver,
                'sender' => '+989900001111',
                'credentials' => ['api_key' => 'provider-secret-key-123'],
                'is_enabled' => true,
                'priority' => ($index + 1) * 10,
            ])->save();

            SmsTemplateGateway::query()->create([
                'sms_template_id' => $template->getKey(),
                'sms_gateway_id' => $gateway->getKey(),
                'mode' => DeliveryMode::Text,
                'is_enabled' => true,
            ]);
        }

        return $template;
    }
}
