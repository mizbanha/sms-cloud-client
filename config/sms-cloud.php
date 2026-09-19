<?php

/*
|--------------------------------------------------------------------------
| Mizbanha SMS Cloud client
|--------------------------------------------------------------------------
|
| ⚠️ This package is OBSERVATIONAL. It never sends an SMS, never sits between
| your application and your SMS provider, and cannot delay, block or change a
| send. If SMS Cloud is down, slow, misconfigured or unreachable, your
| application sends exactly as it would without this package installed; the
| telemetry waits in a bounded local buffer and is discarded by age if the
| outage outlasts it.
|
| Nothing leaves your server that could identify a person or a message: no
| recipient, no body, no template variable, no OTP, no provider credential, no
| provider response text. See README "What is sent".
|
*/

return [

    // Off unless switched on, and inert without a token.
    'enabled' => (bool) env('SMS_CLOUD_ENABLED', false),

    'endpoint' => rtrim((string) env('SMS_CLOUD_ENDPOINT', 'https://cloud.mizbanha.com'), '/'),

    // The installation token from the SMS Cloud dashboard: smsc_…
    'token' => env('SMS_CLOUD_TOKEN'),

    // The environment name reported to SMS Cloud; it must match what the Cloud
    // environment expects (production, staging, local, …). Defaults to APP_ENV.
    'app_env' => env('SMS_CLOUD_APP_ENV'),

    // Your own application's version, if you want it on the dashboard.
    'app_version' => env('SMS_CLOUD_APP_VERSION'),

    'http' => [
        // Short on purpose: publishing runs in the background, and a slow Cloud
        // should cost a scheduler slot a few seconds, never minutes.
        'connect_timeout' => 3,
        'timeout' => 10,
    ],

    'buffer' => [
        // The database connection for the two outbox tables. Null = default.
        'connection' => env('SMS_CLOUD_DB_CONNECTION'),

        'tables' => [
            'events' => 'sms_cloud_events',
            'batches' => 'sms_cloud_batches',
        ],

        // Events held in memory per process before they are written out. Beyond
        // this, events are counted as dropped rather than using more memory.
        'max_memory_events' => 5000,

        // Hard ceilings on local storage. Beyond them the OLDEST data is dropped
        // and counted, so an outage can never grow your database without bound.
        'max_events' => 200000,
        'max_batches' => 5000,

        // Batches the Cloud has not accepted within this many hours are dropped.
        'batch_retention_hours' => 72,
    ],

    'publish' => [
        // `schedule`: `sms-cloud:run` runs from your scheduler, in the background.
        // `queue`: the scheduler dispatches a job to the queue below instead.
        // ⚠️ Never your SMS queue — see README "Queues".
        'mode' => env('SMS_CLOUD_PUBLISH_MODE', 'schedule'),
        'queue_connection' => env('SMS_CLOUD_QUEUE_CONNECTION'),
        'queue' => env('SMS_CLOUD_QUEUE', 'sms-cloud'),

        // Register the scheduler entry automatically. Turn off to schedule
        // `sms-cloud:run` yourself.
        'schedule' => true,

        // Bounds on one run.
        'max_batches_per_run' => 20,
        'max_seconds_per_run' => 30,
        'max_events_per_aggregation' => 50000,
        'max_rows_per_batch' => 1000,
    ],

    'privacy' => [
        // Send a short one-way digest instead of your gateway keys (e.g.
        // `kavenegar-main`). Drivers are always sent: they name software, not you.
        'hash_gateway_keys' => (bool) env('SMS_CLOUD_HASH_GATEWAY_KEYS', false),
    ],
];
