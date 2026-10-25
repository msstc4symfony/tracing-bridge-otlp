# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

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

[1.0.0]: https://github.com/msstc4symfony/tracing-bridge-otlp/releases/tag/v1.0.0
