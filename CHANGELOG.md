# Changelog

All notable changes to `mizbanha/sms-cloud-client` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First release. Nothing has been tagged yet.

### Added

- Telemetry protocol v1 client for Mizbanha SMS Cloud: listeners on Core's four
  events, a bounded in-memory recorder, a local outbox, minute aggregation into
  idempotent batches, a publisher with global exponential backoff, `Retry-After`
  and credential/environment pauses, dead-lettering of permanently rejected
  batches, age- and count-based pruning, and a heartbeat carrying versions,
  gateway circuit snapshots, gauges, buffer health and configuration digests.
- `sms-cloud:run` (scheduled every minute in the background) and
  `sms-cloud:status [--ping]`.
- Optional queue mode on a dedicated queue, never the SMS queue.

### Guarantees

- ⚠️ **Nothing in this package can affect SMS delivery.** Listeners append to
  memory; every handler and every background step is wrapped; the outbox is never
  written inside an open transaction; a run never throws and never fails the
  scheduler.
- ⚠️ **No message content leaves the application**: no recipient, body, variable,
  OTP, template key, sender line, provider credential, provider prose, or message
  id. Only counts, outcomes, failure kinds, durations, gateway/driver names and
  one-way configuration digests.
- ⚠️ **Everything is bounded**: memory, outbox rows, batch count, batch age,
  backoff and per-run time. Past a limit the oldest telemetry is dropped and
  counted, and the counts are reported in the next heartbeat.
