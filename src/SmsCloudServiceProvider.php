<?php

declare(strict_types=1);

namespace Mizbanha\SmsCloud;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Mizbanha\Sms\Events\AttemptRecorded;
use Mizbanha\Sms\Events\CircuitStateChanged;
use Mizbanha\Sms\Events\DeliveryChecked;
use Mizbanha\Sms\Events\MessageSettled;
use Mizbanha\SmsCloud\Console\RunCommand;
use Mizbanha\SmsCloud\Console\StatusCommand;
use Mizbanha\SmsCloud\Recording\Recorder;
use Mizbanha\SmsCloud\Support\Settings;
use Mizbanha\SmsCloud\Support\State;
use Throwable;

/**
 * Wires the client into an application — only when it is enabled AND has a token.
 *
 * Disabled or unconfigured, this provider registers its config, migrations and
 * commands and nothing else: no listener, no terminating callback, no scheduled
 * task. The application behaves exactly as if the package were not installed.
 */
final class SmsCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sms-cloud.php', 'sms-cloud');

        $this->app->singleton(Settings::class, fn ($app) => new Settings($app['config']));
        $this->app->singleton(State::class, fn ($app) => new State($app['cache']->store()));

        // ⚠️ A singleton: it IS the in-memory buffer for this process.
        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sms-cloud.php' => $this->app->configPath('sms-cloud.php'),
            ], 'sms-cloud-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'sms-cloud-migrations');

            $this->commands([RunCommand::class, StatusCommand::class]);
        }

        try {
            if (! $this->app->make(Settings::class)->active()) {
                return;
            }

            $this->listen();
            $this->schedule();
        } catch (Throwable) {
            // A broken configuration disables the client; it never breaks boot.
        }
    }

    private function listen(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $recorder = fn (): Recorder => $this->app->make(Recorder::class);

        $events->listen(AttemptRecorded::class, fn (AttemptRecorded $e) => $recorder()->onAttempt($e));
        $events->listen(MessageSettled::class, fn (MessageSettled $e) => $recorder()->onSettled($e));
        $events->listen(CircuitStateChanged::class, fn (CircuitStateChanged $e) => $recorder()->onCircuit($e));
        $events->listen(DeliveryChecked::class, fn (DeliveryChecked $e) => $recorder()->onDelivery($e));

        // Written out when the request or command ends (after the response has been
        // sent under FPM) and after every queued job — never during one.
        $this->app->terminating(fn () => $recorder()->flush());

        foreach ([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class] as $event) {
            $events->listen($event, fn () => $recorder()->flush());
        }
    }

    private function schedule(): void
    {
        $settings = $this->app->make(Settings::class);

        if (! $settings->scheduleEnabled()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($settings): void {
            if ($settings->publishMode() === 'queue') {
                [$connection, $queue] = $settings->queue();

                $schedule->job((new PublishTelemetry)->onConnection($connection)->onQueue($queue))
                    ->everyMinute()
                    ->name('sms-cloud:publish');

                return;
            }

            // In the background, so a slow Cloud never delays the application's
            // other scheduled tasks, and never overlapping itself.
            $schedule->command(RunCommand::class)
                ->everyMinute()
                ->withoutOverlapping(10)
                ->runInBackground();
        });
    }
}
