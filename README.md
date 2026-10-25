# msstc4symfony/tracing-bridge-otlp

![Build Status](https://github.com/msstc4symfony/tracing-bridge-otlp/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Exports msstc4symfony/profiling-bundle spans with msstc4symfony/tracing-bundle ids over OTLP

## Compatibility

| Bundle | PHP  | Symfony  |
|--------|------|----------|
| 1.x    | 8.2+ | 7.4, 8.x |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

## Installation

The package is not on Packagist yet, so register its GitHub repository first:

```sh
composer config repositories.msstc4symfony-tracing-bridge-otlp vcs https://github.com/msstc4symfony/tracing-bridge-otlp
composer require msstc4symfony/tracing-bridge-otlp
```

Symfony Flex registers the bundle automatically; without Flex add it to `config/bundles.php`:

```php
Msstc4Symfony\TracingBridgeOtlp\TracingBridgeOtlpBundle::class => ['all' => true],
```

## Configuration

```yaml
msstc4symfony_tracing_bridge_otlp:
    greeting: Hello   # non-empty string
```

## Usage

```php
use Msstc4Symfony\TracingBridgeOtlp\Application\Greeting\Greet\Action;
use Msstc4Symfony\TracingBridgeOtlp\Application\Greeting\Greet\Input;

final readonly class Welcome
{
    public function __construct(private Action $greet)
    {
    }

    public function __invoke(): string
    {
        return ($this->greet)(new Input('Ada'))->message; // "Hello, Ada!"
    }
}
```

## Layers

`src/` follows the canonical layout of [bundle-standard](https://github.com/msstc4symfony/bundle-standard)
(`templates/deptrac.yaml`). The list is closed — `verify-standard` rejects any other directory:

- `Domain/` — data in `Entity/`, `Document/`, `ValueObject/`, `Enum/`, `Event/`, `Repository/`, `DocumentRepository/`;
  operations in `Service/`, `Helper/`, `Specification/`;
- `Application/`, `Infrastructure/`, `Presentation/`, `Framework/`;
- `DependencyInjection/` (compiler passes in `CompilerPass/`);
- `Resources/` (configuration only, no classes) and the bundle class `<Name>Bundle.php`.

Empty layers need no directory. `deptrac.yaml` starts with the full canonical ruleset; narrow it as the bundle's
layers settle, never widen it.

## Local development

```sh
make install-ci                        # composer-ci.json profile with every CI tool
COMPOSER=composer-ci.json make check   # lint, PHPStan, CS, composer validate/audit, Rector, deptrac
make test                              # unit + integration
```

## License

MIT — see [LICENSE](LICENSE).
