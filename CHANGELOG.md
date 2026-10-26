# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [1.1.0] - 2026-10-26

### Added

- `pause_pool`: a PSR-6 pool service id holding the export pause, so the hosts sharing the pool share the pause.
  An id that is no service, written with `@` or given as an env placeholder fails the container build. The hosts must share
  `framework.cache.prefix_seed`. Without it (or with `""`) the pause stays in the filesystem cache of 1.0.1.

### Fixed

- An explicit `~` for `service_name` or `endpoint` (as the README shows, or to reset a value of another configuration
  file) failed configuration processing: the string nodes turned it into `""` and rejected it.

### Changed

- An empty `service_name` or `endpoint`, written as `""` or from an empty env variable, means unset, as an empty
  `OTEL_*` variable does: the service name and the endpoint then come from the variables or the defaults. An empty
  literal used to fail configuration processing; an empty env variable sent `service.name=""` or failed the export.

## [1.0.1] - 2026-10-25

### Fixed

- The export pause is shared through a filesystem cache in `%kernel.share_dir%/msstc4symfony_tracing_bridge_otlp`
  (`%kernel.cache_dir%/…` without a share dir) instead of `cache.app`: every unit of work read `cache.app`, which
  counted as a cache miss in the application's metrics (metrics-bundle `symfony_cache_request`).

### Changed

- The pause is shared by the processes using that directory, no longer by every host of a `cache.app` on Redis or
  Memcached (unless the directory is a shared volume).

## [1.0.0] - 2026-10-25

### Added

- Export of profiling-bundle spans over OTLP (http/protobuf, http/json, grpc with open-telemetry/transport-grpc) with the
  trace and span ids of tracing-bundle and tracing-bridge-profiling: unit-of-work spans (SERVER / CONSUMER / INTERNAL,
  remote parent from the caller), traced spans (DBAL as CLIENT) and detached HTTP client spans (CLIENT).
- Only spans of sampled traces (tracing-bundle 2.2 `sampling.ratio`, parent-based).
- One export per unit of work (`kernel.terminate`, `console.terminate`, worker events) plus an early one at
  `max_buffered_spans`; a failed export logs one warning and pauses exporting for 30 seconds; no retries.
- The pause is shared through `cache.app` (PHP-FPM children, cron commands); warnings on the `otlp` log channel.
- String attribute values cut to 4096 characters (`OTEL_[SPAN_]ATTRIBUTE_VALUE_LENGTH_LIMIT`, read at runtime); span names
  made valid UTF-8, attribute keys that are not UTF-8 dropped.
- Configuration `enabled`, `service_name`, `endpoint`, `protocol`, `timeout_ms`, `max_buffered_spans`; the standard
  `OTEL_EXPORTER_OTLP_*` (endpoint, protocol, headers, compression, certificates), `OTEL_SERVICE_NAME` and
  `OTEL_RESOURCE_ATTRIBUTES` variables as fallbacks.

[1.0.1]: https://github.com/msstc4symfony/tracing-bridge-otlp/releases/tag/v1.0.1
[1.0.0]: https://github.com/msstc4symfony/tracing-bridge-otlp/releases/tag/v1.0.0
