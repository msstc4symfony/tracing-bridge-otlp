# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/tracing-bridge-otlp`, namespace `Msstc4Symfony\TracingBridgeOtlp`, config alias
`msstc4symfony_tracing_bridge_otlp`) that exports msstc4symfony/profiling-bundle spans over OTLP with the trace and span
ids of msstc4symfony/tracing-bundle and tracing-bridge-profiling. PHP >= 8.2 (64-bit), Symfony 7.4 / 8.x. Library code
only. Spec: `../docs/superpowers/specs/2026-10-25-otlp-span-export-design.md`.

## Common commands

Develop against the CI profile: `COMPOSER=composer-ci.json composer install` (locally add
`--ignore-platform-req=php` when roave/security-advisories conflicts with the installed PHP patch release).

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check`.
- `make test` — unit + integration suites; `make infection` (unit suite only), `make fix`.
- `php ../bundle-standard/bin/verify-standard.php .` — standard compliance (the same check CI runs).

## Architecture in 60 seconds

- `Infrastructure\Profiling\CreationRecorder` — profiling create processor (priority -65536, after every wrapper):
  `WeakMap` span → trace current at creation.
- `Infrastructure\Profiling\UnitRecorder` — subscriber: trace of a consumed message (captured at 4096 on the first
  worker event after handling) and the main response status.
- `Infrastructure\Profiling\SpanPlacer` — unit span / traced span / detached span → `SpanPlacement` (`SpanIds`, kind,
  response status). `OtlpEndSpanProcessor` — sampled only → `SpanMapper` (4096-byte values, UTF-8 names) →
  `SpanBuffer`; full buffer → `Application\Port\SpanFlushInterface`.
- `Infrastructure\Otlp\Flusher` (implements the port) — one export per unit, 30 s pause after a failure shared through
  a filesystem cache in `kernel.share_dir` / `kernel.cache_dir` (not `cache.app`: its lookups are metrics), exporter built lazily through a `service_closure`. `Presentation\EventListener\FlushListener` calls it at unit ends.
- `Infrastructure\Otlp\ExporterSettings` / `ExporterFactory` — config then `OTEL_*`, own Symfony HttpClient (PSR-18).

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing a
"weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — layers, wiring, event priorities.
- `.claude/docs/conventions.md` — layout, tests, commits.
- `.claude/docs/known-issues.md` — OTel SDK, Messenger and CI traps.
