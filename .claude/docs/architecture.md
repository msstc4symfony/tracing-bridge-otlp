# Архитектура

- `TracingBridgeOtlpBundle` — `AbstractBundle`: `configure()` описывает дерево `msstc4symfony_tracing_bridge_otlp`,
  `loadExtension()` кладёт значения в параметры (`TracingBridgeOtlpBundle::GREETING_PARAMETER`) и импортирует
  `src/Resources/config/services.php`.
- Сервисы бандла приватные, id = FQCN; потребитель получает их автовайрингом.
- Слои (`deptrac.yaml`) — копия `bundle-standard/templates/deptrac.yaml` v3 с полным каноническим ruleset
  (Domain ← DomainService ← Application ← Infrastructure / Presentation / Framework; DependencyInjection — все слои,
  кроме BundleRoot; BundleRoot — Application и DependencyInjection); `BundleRoot` = `^…\\TracingBridgeOtlpBundle$`
  (`bin/init` переименовывает токен, якоря остаются). `verify-standard` v3 сверяет его с шаблоном.
- `#[Override]` на всех переопределениях — PHPStan `checkMissingOverrideMethodAttribute` из шаблона.
  Domain/DomainService различаются подкаталогами `src/Domain/` (данные vs операции).
- Пример `Application/Greeting/Greet` — заглушка, заменить реальным use-case.
