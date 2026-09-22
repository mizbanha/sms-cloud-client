# Mizbanha SMS Cloud client

Reports operational metrics from [`mizbanha/laravel-sms`](https://github.com/mizbanha/laravel-sms)
to [Mizbanha SMS Cloud](https://cloud.mizbanha.com), so you can watch many
applications, environments and providers from one dashboard.

```bash
composer require mizbanha/sms-cloud-client
```

> ⚠️ **This package cannot affect your SMS.** It never sends a message, never sits
> between your application and your provider, and never delays or fails a send. If
> SMS Cloud is unreachable, slow, rate limiting you, or refusing your credential,
> your application sends exactly as it does without this package installed.

## What it does

1. Listens to Core's events (`AttemptRecorded`, `MessageSettled`,
   `CircuitStateChanged`, `DeliveryChecked`) and **appends a small array to
   memory** — no query, no cache call, no HTTP inside a send.
2. Writes those to a local outbox when the request or job ends, never inside an
   open transaction.
3. Once a minute, in the background, aggregates closed minutes into a batch,
   publishes it, and sends a heartbeat.

## What is sent

Counts per minute (attempts, accepted, rejected, uncertain, messages by status,
retries), failure kinds, failovers (from → to, reason, whether the fallback was
accepted), a latency histogram, delivery lookup results, circuit transitions,
package versions, buffer health and SHA-256 digests of your gateway/template
configuration.

## What is never sent

Recipients · message bodies · template variables · OTP codes · template keys ·
sender lines · gateway credentials · provider response text · message or attempt
ids · hostnames · IPs. The payload is built from a fixed whitelist, and the test
suite asserts it on real request bodies.

Gateway keys and driver names *are* sent, so the dashboard can name what is
failing. Set `SMS_CLOUD_HASH_GATEWAY_KEYS=true` to send stable digests instead.

## Setup

```dotenv
SMS_CLOUD_ENABLED=true
SMS_CLOUD_ENDPOINT=https://cloud.mizbanha.com
SMS_CLOUD_TOKEN=smsc_…          # from the dashboard, shown once
SMS_CLOUD_APP_ENV=production    # optional; defaults to APP_ENV
```

```bash
php artisan vendor:publish --tag=sms-cloud-migrations
php artisan migrate
```

Make sure the scheduler runs (`php artisan schedule:run` every minute). That is
the whole runtime requirement; the package registers `sms-cloud:run` itself, in
the background, without overlapping.

Prefer a queue? `SMS_CLOUD_PUBLISH_MODE=queue` and `SMS_CLOUD_QUEUE=sms-cloud`
(**never your SMS queue**). The job has one try and a short timeout.

Without a token, or with `SMS_CLOUD_ENABLED=false`, the package registers no
listener, no terminating callback and no scheduled task.

## Commands

```bash
php artisan sms-cloud:status          # configuration, buffer, last error (never the token)
php artisan sms-cloud:status --ping   # send a heartbeat now and report the answer
php artisan sms-cloud:run             # flush → aggregate → prune → publish → heartbeat
```

## Behaviour when SMS Cloud misbehaves

| Response | What happens |
|---|---|
| `202` | the local copy is deleted |
| network error / timeout / `5xx` | kept; **all** publishing backs off 30 s → 30 min with jitter |
| `429` | kept; paused for `Retry-After` |
| `401` / `403` / environment mismatch | kept; paused 5 minutes so an operator can fix the token or `APP_ENV` |
| `400` / `413` / `422` / duplicate-id conflict | that batch is dropped and counted — it can never be accepted |

Retries reuse the same `batch_id` and sequence, so the Cloud answers `duplicate`
and never counts a batch twice.

## Bounded by design

| Limit | Default |
|---|---|
| Events buffered in memory per process | 5 000 |
| Rows in the local outbox | 200 000 |
| Batches waiting | 5 000 |
| Age of a waiting batch | 72 hours |
| Batches sent per run · seconds per run | 20 · 30 |

Past a limit the **oldest** telemetry is dropped and counted, and the counts are
reported in the next heartbeat, so a long outage can never grow your database or
your memory without bound.

## Requirements

PHP 8.3+ · Laravel 13 · `mizbanha/laravel-sms` ^0.1.1

## Tests

```bash
./scripts/use-local-core.sh ../laravel-sms   # only while Core is unreleased
vendor/bin/pest
```

MIT. © Mizbanha — deliberately open, so anyone can audit what leaves their server.
