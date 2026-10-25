# CLAUDE.md

Guidance for Claude Code in this repository. Deep references live under `.claude/docs/`.

## What this is

Symfony bundle (`msstc4symfony/tracing-bridge-otlp`, namespace `Msstc4Symfony\TracingBridgeOtlp`, config alias
`msstc4symfony_tracing_bridge_otlp`): Exports msstc4symfony/profiling-bundle spans with msstc4symfony/tracing-bundle ids over OTLP. PHP >= 8.2, Symfony 7.4 / 8.x.
Library code only.

## Common commands

Develop against the CI profile: `COMPOSER=composer-ci.json composer install`.

- `make check` — `php -l`, PHPStan level 10, PHP-CS-Fixer, `composer validate --strict`,
  `composer audit`, Rector dry-run, deptrac. Run as `COMPOSER=composer-ci.json make check`.
- `make test` — unit + integration suites; `make infection`, `make fix`, `make regenerate-baseline`.
- `php ../bundle-standard/bin/verify-standard.php .` — standard compliance (the same check CI runs).

## Architecture in 60 seconds

- `TracingBridgeOtlpBundle` (`AbstractBundle`) defines the config tree and imports `src/Resources/config/services.php`.
- Use-cases are vertical slices `src/Application/<Aggregate>/<UseCase>/{Action,Input,Output}.php`.
- Layers and allowed dependencies are enforced by `deptrac.yaml`.

Details: `.claude/docs/architecture.md`. **Read `.claude/docs/known-issues.md` before chasing a
"weird" failure.**

## Pointers

- `.claude/docs/architecture.md` — layers, wiring, service ids.
- `.claude/docs/conventions.md` — layout, tests, commits.
- `.claude/docs/known-issues.md` — family-wide Symfony/CI traps.
