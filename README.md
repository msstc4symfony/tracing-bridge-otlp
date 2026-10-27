# msstc4symfony/tracing-bridge-otlp

![Build Status](https://github.com/msstc4symfony/tracing-bridge-otlp/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Exports the spans of [profiling-bundle](https://github.com/msstc4symfony/profiling-bundle) to any OTLP receiver
(OpenTelemetry Collector, Grafana Alloy or Tempo, Jaeger, a vendor) with the trace and span ids of
[tracing-bundle](https://github.com/msstc4symfony/tracing-bundle): the ids in the logs, in `traceparent` headers and in
Messenger stamps are the ids of the exported spans. It uses the official OpenTelemetry OTLP exporter, but the
OpenTelemetry SDK does not run the spans: it would make up ids of its own.

## Compatibility

| Bundle | PHP  | Symfony  | profiling-bundle | tracing-bundle | tracing-bridge-profiling |
|--------|------|----------|------------------|----------------|--------------------------|
| 1.0    | 8.2+ | 7.4, 8.x | ^2.2             | ^2.2           | ^1.2                     |
| 1.1    | 8.2+ | 7.4, 8.x | ^2.2             | ^2.2           | ^1.2                     |
| 1.2    | 8.2+ | 7.4, 8.x | ^2.3.1           | ^2.4           | ^1.3                     |

64-bit PHP. Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

## Installation

The packages are not on Packagist yet, so register their GitHub repositories first:

```sh
composer config repositories.msstc4symfony-profiling vcs https://github.com/msstc4symfony/profiling-bundle
composer config repositories.msstc4symfony-tracing vcs https://github.com/msstc4symfony/tracing-bundle
composer config repositories.msstc4symfony-tracing-bridge-profiling vcs https://github.com/msstc4symfony/tracing-bridge-profiling
composer config repositories.msstc4symfony-tracing-bridge-otlp vcs https://github.com/msstc4symfony/tracing-bridge-otlp
composer require msstc4symfony/tracing-bridge-otlp
```

Register the four bundles in `config/bundles.php` (order does not matter):

```php
Msstc4Symfony\TracingBundle\TracingBundle::class => ['all' => true],
Msstc4Symfony\ProfilingBundle\ProfilingBundle::class => ['all' => true],
Msstc4Symfony\TracingBridgeProfiling\TracingBridgeProfilingBundle::class => ['all' => true],
Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle::class => ['all' => true],
```

For Messenger, tracing-bundle's `OutgoingTraceMiddleware` and `IncomingTraceMiddleware` must be on the bus (see its
README); without them a consumed message starts a new trace.

## What is exported

Every span profiling records, once it ends, when its trace is sampled:

| Span | Span id, parent | Kind |
|---|---|---|
| Unit of work: `request <route>`, `cli command <name>`, `message <class>`, `action <class>` (with tracing-bridge-logic) | the unit's span of tracing-bundle; parent: the caller's span from `traceparent` / `TraceStamp` (remote), the enclosing unit, or none | SERVER, INTERNAL, CONSUMER, INTERNAL |
| A span tracing-bridge-profiling traced (yours, DBAL, Action) | its `trace_span_id` / `trace_parent_span_id` | CLIENT for profiling's automatic DBAL spans, else INTERNAL |
| Detached HTTP client span of profiling | the span id tracing-bridge-profiling reserved for the request (the one its `traceparent` names), under the span current when the request started | CLIENT |

- Name: the span message; bytes that are not UTF-8 become `?` (the OTLP encoder rejects them). Times: start and
  duration of profiling, microsecond precision.
- Attributes: every scalar context value as `profiling.<key>`, plus the semantic convention names
  `exception.type` (a non-empty string `exception`), `http.response.status_code` (an integer `status`, and the
  response code of a request span), `db.namespace` (`connection` of a DBAL span) and `messaging.destination.name`
  (`transport`). Arrays, objects, enums, `null`, NAN and INF are dropped, and so are keys that are not UTF-8; strings
  are cut to 4096 characters (bytes for a string that is not UTF-8)
  (`OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT` / `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT`), so one huge value cannot push the
  batch over the receiver's size limit. The bridge adds nothing profiling did not record (no SQL text, no query string).
- Status: ERROR with a non-empty `exception`, `message_failed: true` or an HTTP status of 500 and above; otherwise
  unset.
- Events: a span with a non-empty `exception` records an `exception` event (attribute `exception.type`) at its end.
- Links: a `message …` CONSUMER span links to the sender's span (its remote parent), as the messaging conventions ask.
- Resource: `service.name` from `service_name`, else `OTEL_SERVICE_NAME` / `OTEL_RESOURCE_ATTRIBUTES`, else the name of
  the project directory; the SDK's resource detectors add the rest. Scope: `msstc4symfony/tracing-bridge-otlp`.

Not exported:

- spans of an unsampled trace. A new trace is sampled by tracing-bundle's `sampling.ratio`; a continued one keeps the
  caller's flag;
- profiling's `NullSpan` (spans its decision makers filtered out);
- spans opened outside a unit of work tracing-bridge-profiling knows, and spans of a route, command or message that is
  not in profiling's `routes` / `commands` / `messages` lists: there is no unit span, so the children point at a
  parent that is never exported. Such a span name is logged once at `debug`.

## Delivery

Spans wait in memory for the end of their unit of work: `kernel.terminate`, `console.terminate`, the worker moving on
after a message (`WorkerRunningEvent`) or stopping. Then one OTLP request sends them; a unit with more than
`max_buffered_spans` spans sends early. Spans profiling ends on `kernel.reset` (left open by the unit) go with the next
export.

The export never throws into the application. A failed export (receiver down, timeout, rejected or partially rejected
batch, a wrong endpoint or protocol) logs one `warning` on the `otlp` channel with the number of dropped spans, and
exporting pauses for 30 seconds: the spans of that time are dropped and counted in the next log record (`info` when
exporting resumes). There are no retries. Without `pause_pool`, the pause is shared through a filesystem cache in
`%kernel.share_dir%/msstc4symfony_tracing_bridge_otlp` (`%kernel.cache_dir%/…` when the kernel has no share dir), so
the PHP-FPM children, cron commands and workers using that directory wait too: a PHP-FPM request reads it once, a
long-running worker at most once a second. The application's cache pools are not used: their lookups are metrics. When
the directory is not writable the pause holds per process. The cost of a down receiver is at most `timeout_ms` once per
pause and directory: typically a host or a container, several hosts when `share_dir` is a shared volume.

To share the pause between hosts, point `pause_pool` at a PSR-6 pool they share, for example a dedicated pool on Redis:

```yaml
framework:
    cache:
        pools:
            otlp_pause:
                adapter: cache.adapter.redis
                provider: '%env(REDIS_DSN)%'

msstc4symfony_tracing_bridge_otlp:
    pause_pool: otlp_pause
```

Then a down receiver costs `timeout_ms` once per pause for the whole cluster. The value is a service id without `@`.
The hosts must share `framework.cache.prefix_seed`: a pool's key namespace derives from it, and it defaults to the
project directory, which differs between release directories. Prefer a pool of its own, left out of cache metrics
(metrics-bundle `cache.pools`): a counted pool records one lookup per PHP-FPM request and at most one a second per
worker. The hosts compare the pause with their clocks, so they need synced clocks (NTP). Each of those lookups is a
round trip, at the end of the unit of work: give the Redis provider a short
`timeout`. A pool that fails holds the pause per process.

The exporter has its own HTTP client: the application's `http_client` is traced and profiled, so the export would trace
itself. Install `ext-curl`: the native HTTP client resolves host names without a timeout.

## Configuration

```yaml
# config/packages/msstc4symfony_tracing_bridge_otlp.yaml
msstc4symfony_tracing_bridge_otlp:
    enabled: true            # false: no service is registered
    service_name: ~          # ~: OTEL_SERVICE_NAME / OTEL_RESOURCE_ATTRIBUTES, else the project directory name
    endpoint: ~              # base URL, /v1/traces is appended for HTTP; ~: the variables below, else http://localhost:4318
    protocol: ~              # http/protobuf | http/json | grpc; ~: the variables below, else http/protobuf
    timeout_ms: 1000         # whole export request
    max_buffered_spans: 2048 # spans per unit of work before an early export
    pause_pool: ~            # PSR-6 pool service id for the export pause; ~: files in kernel.share_dir, else kernel.cache_dir
    logs:
        enabled: false       # true: Monolog records over OTLP (see Logs)
        level: info          # lowest Monolog level exported
        max_buffered_logs: 2048
```

`service_name` and `endpoint` take env placeholders (`'%env(OTLP_ENDPOINT)%'`); an empty value means unset, as an
empty `OTEL_*` variable does. `pause_pool` is a service id, fixed when the container is built: no placeholder; an
empty one keeps the filesystem cache.

Environment variables honoured: `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT` (used as is) and `OTEL_EXPORTER_OTLP_ENDPOINT`,
`OTEL_EXPORTER_OTLP[_TRACES]_PROTOCOL` (`http/protobuf`, `http/json`, `grpc`), `OTEL_EXPORTER_OTLP[_TRACES]_HEADERS`,
`OTEL_EXPORTER_OTLP[_TRACES]_COMPRESSION` (`none`, `gzip`), `OTEL_EXPORTER_OTLP[_TRACES]_CERTIFICATE`,
`OTEL_EXPORTER_OTLP[_TRACES]_CLIENT_CERTIFICATE`, `OTEL_EXPORTER_OTLP[_TRACES]_CLIENT_KEY`,
`OTEL_[SPAN_]ATTRIBUTE_VALUE_LENGTH_LIMIT`, `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES`. The configuration wins over
them. `OTEL_EXPORTER_OTLP_TIMEOUT` is ignored: the SDK's 10 seconds would hold every request and message that long
when the receiver hangs.

`protocol: grpc` needs `open-telemetry/transport-grpc` and `ext-grpc`; configured without them the container does not
compile. gRPC chosen through the variables fails at the first export instead (one warning per pause).

The OpenTelemetry SDK also reports a failed export through its own logger (`OTEL_PHP_LOG_DESTINATION`, by default
PHP's error log). Set `OTEL_PHP_LOG_DESTINATION=none` to keep only the bridge's `warning`.

## Logs

With `logs.enabled` the bundle registers the Monolog handler `msstc4symfony_tracing_bridge_otlp.logs_handler`; add it
to your handlers (it needs `monolog/monolog`):

```yaml
monolog:
    handlers:
        otlp:
            type: service
            id: msstc4symfony_tracing_bridge_otlp.logs_handler
```

Each record becomes an OTLP log record of the span it was logged in: the `trace_id` / `span_id` that tracing-bundle's
`TraceLogProcessor` put in its `extra`, so a buffering handler (`fingers_crossed`, `buffer`) that passes records on
later keeps them; without them, the span current when the handler writes it. The sampled flag is that of the trace when
it is still current, also for an unsampled trace. Records written outside a unit of work (an idle worker) carry a trace
of their own without spans.

- Body: the message, cleaned of bytes that are not UTF-8 but not cut. Severity: from the level.
- Attributes: `monolog.channel`, `exception.type` / `exception.message` for a `Throwable` in `context['exception']`,
  then the scalar `extra` and `context` values (context wins; `trace_id` and `span_id` become the trace context
  instead). The bridge's own names come first and win over keys spelled the same. Values are cut and cleaned like span
  attributes (`OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT` / `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT`).
- Every scalar value goes out, including what processors add (`ip`, `url` of WebProcessor, user ids): export only what
  you would log.
- The records go with the spans at the end of the unit of work, or earlier when `max_buffered_logs` is reached. A failed
  log export logs one `warning` and pauses both exports; records of the bridge's own `otlp` channel are not exported.
- The endpoint and protocol are the span ones; `OTEL_EXPORTER_OTLP_LOGS_*` take the place of the `_TRACES_` variables.

## Limitations

- A Guzzle redirect is a new pass through the handler stack: its `traceparent` names the span around the call, not the
  CLIENT span (Symfony HttpClient keeps the CLIENT span on every redirect).
- A fatal error ends PHP before the export: the spans of that unit of work are lost.
- Log records written after the unit's export and before `kernel.reset` (a `kernel.terminate` / `console.terminate`
  listener below -8192, a `WorkerRunningEvent` listener between -512 and -1024) are dropped with the reset.
- One buffer serves the container: spans of concurrent Fibers share it.
- With an asynchronous Messenger execution strategy the worker may move on before the handler ran: the `message …`
  span then gets the worker's idle trace instead of the message's.
- A create span processor of the application that wraps spans must run before the bridge's (priority above -65536),
  or its spans are not exported.

## Public API

The bundle class `Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle` and its configuration. Everything else is
`@internal`.

## Local development

`composer-ci.json` installs profiling-bundle, tracing-bundle and tracing-bridge-profiling from their GitHub
repositories.

```sh
make install-ci                        # composer-ci.json profile with every CI tool
COMPOSER=composer-ci.json make check   # lint, PHPStan, CS, composer validate/audit, Rector, deptrac
make test                              # unit + integration (a local php -S OTLP receiver)
```

## License

MIT — see [LICENSE](LICENSE).
