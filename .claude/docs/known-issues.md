# Известные ловушки

- **`kernel.build_dir` в тестовом контейнере.** `PhpFileLoader` Symfony 7.4 читает этот параметр,
  8.x — нет; без него интеграционные тесты падают только в ячейке 7.4.
- **Теги для своих интерфейсов** регистрировать в `loadExtension()` через
  `registerForAutoconfiguration()`, а не `#[AutoconfigureTag]` на интерфейсе: атрибут на интерфейсе
  из vendor-пакета приложение не подхватывает.
- **`gh run watch` может вернуть 0 на упавшем прогоне.** Итог проверять через
  `gh run view <id> --json conclusion,jobs`.
- **`actions/checkout` по sha делает shallow-клон**; push из такого клона отклоняется как
  "fetch first" — нужен `fetch-depth: 0`.
- **Пороги Infection** (`infection-min-msi`, `infection-min-covered-msi` в `checks.yml`) = замер минус запас,
  с датой замера (UTC) в комментарии; пересчитывать при заметном изменении тестов.
- **`exec()` портит вывод с путями**: режет по строкам и срезает пробелы в конце строки, поэтому
  `git ls-files -z` через него теряет символы в именах. Читать через `proc_open` целиком. В тестах не
  читать stdout и stderr из двух pipe по очереди (deadlock при заполненном буфере) — stderr писать в файл.
- **Код должен разбираться на PHP 8.2** (bundle-standard v3, профиль `php82`): без типизированных констант
  (`const string X` — 8.3) и без `new X()->m()` / `new X()(...)` (8.4) — только `(new X())->m()`. PHPStan (`phpVersion.min: 80200`) ловит
  типизированную константу (`classConstant.nativeTypeNotSupported`), `new X()->m()` не ловит ни один
  инструмент на 8.4 — только `php -l` в job «minimal» (PHP 8.2) и ячейки PHPUnit на 8.2. Шаблоны
  Rector (`withPhpVersion(PHP_82)`) и CS-Fixer (`new_expression_parentheses`) обратно не поднимают.
  `#[Override]` (8.3) допустим: на 8.2 атрибут не инстанцируется. Функции 8.4 (`array_any/all/find`) —
  только с `symfony/polyfill-php84` в `require`.

## OTLP-мост (1.0.0, 2026-10-25 UTC)

- **`AttributesInterface` SDK без generic-типа** → `missingType.iterableValue`. Stub в `phpstan.dist.neon` запрещён
  стандартом; `ExportedSpan` хранит массив и строит `Attributes` в `getAttributes()` под одним
  `@phpstan-ignore missingType.iterableValue` с Reason.
- **Наносекунды во float теряют цифры** (Unix ns > 2^53): `SpanMapper` считает `round(seconds * 1e6) * 1000`
  (микросекунды, как `microtime()`).
- **SDK логирует ошибки экспорта сам** (`OpenTelemetry\API\Behavior\Internal\Logging`, `OTEL_PHP_LOG_DESTINATION`,
  по умолчанию error_log — в CLI это вывод, PHPUnit помечает risky). Мост глобальное состояние не трогает (README:
  `OTEL_PHP_LOG_DESTINATION=none`); `OtlpTransportTest` делает `Logging::disable()` / `reset()`.
- **Ответ OTLP-приёмника парсится в формате запроса**: на protobuf-запрос JSON `{}` даёт «Unexpected wire type» →
  `export()` = false. Тестовый приёмник отвечает пустым телом для protobuf.
- **OTLP нумерует SpanKind с 1**: API SERVER = 2 → в OTLP/JSON `kind: 2` (SPAN_KIND_SERVER), не 3.
- **tracing-bundle не ставит Messenger-middleware сам**: без `OutgoingTraceMiddleware`/`IncomingTraceMiddleware` на
  шине сообщение потребляется в новой трассе. Тестовое ядро задаёт их в `framework.messenger.buses` и
  `in-memory://?serialize=true`.
- **In-memory транспорт забывает очередь на `kernel.reset`**; воркер в тесте — `messenger:consume --limit 1` через
  `Application`, не ручной `Worker`.
- **Symfony 8.1.9 (установленная)**: `WorkerMessageHandledEvent` идёт из `ack()` внутри `handleMessage()`, до
  `WorkerRunningEvent`; обратный порядок покрыт unit-тестом `UnitRecorderTest` (захват на первом событии).
- **Экспортёр строится лениво** (`service_closure`): неверный endpoint (`PsrTransportFactory` бросает на невалидный URL)
  или grpc без пакета падают как экспорт (warning + пауза), а не в terminate-листенере. Литеральный `protocol: grpc` без
  `open-telemetry/transport-grpc` — `LogicException` при сборке.
- **Локально `composer update` падает на roave/security-advisories** (PHP 8.4.17 < 8.4.27) — `--ignore-platform-req=php`.
- **Эквивалентные мутанты** (MSI 98 %): `(string)` ключа заголовка, `is_string && isSpanId` → `||` (невалидный parent
  всё равно даёт невалидный контекст), `reported[$name] = false` (isset).
