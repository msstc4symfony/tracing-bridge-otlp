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
- **Эквивалентные мутанты** (covered MSI 97 % после ревью): `(string)` ключа заголовка и результата `preg_replace`,
  `reported[$name] = false` (isset), `0.0` → `1.0` в `max()` паузы (сравнение с текущим временем), ранний
  `return null` в `Flusher::sharedPause()` (ошибку без пула ловит `catch`).

## Ревью 1.0.0 (2026-10-25 UTC)

- **Пауза только в процессе бесполезна под PHP-FPM/cron**: каждый запрос ждал бы `timeout_ms`. `Flusher` делит паузу
  через `cache.app` (PSR-6, `nullOnInvalid`), ключ `msstc4symfony_tracing_bridge_otlp.paused_until`, ошибки кэша
  глотаются (пауза остаётся процессной). Счётчик потерянных — по процессу.
- **Имя спана не в UTF-8 роняет весь batch** (`SpanConverter::setName` бросает «Expect utf-8 encoding»): `SpanMapper`
  заменяет байты ≥ 0x80 на `?`, если строка не UTF-8. Значения атрибутов не в UTF-8 проходят как bytes.
- **Огромное значение атрибута** раздувает batch за лимит приёмника (gRPC 4 MiB) → 4xx → весь batch и пауза:
  строки режутся до 4096 символов (`mb_substr`, как SDK; не-UTF-8 — `substr` по байтам), лимит из
  OTEL_*ATTRIBUTE_VALUE_LENGTH_LIMIT читается в конструкторе `SpanMapper` во время выполнения (`AttributeLimit`), а не в
  скомпилированном контейнере. `substr` по байтам резал бы UTF-8 посреди символа → SDK шлёт `bytes_value`. NAN/INF отбрасываются
  (OTLP/JSON их не кодирует).
- **semconv по типу**: `status` → `http.response.status_code` только int, `exception` → только непустая строка (и
  ERROR тоже), `connection` → `db.namespace` и CLIENT только у DBAL-спанов profiling (маркер `profiling_automatic`).
- **`round()` PHP для float ~1e15 ведёт себя по-разному** на 8.2/8.3 и 8.4 — время округляется вручную.
- **`SpanBuffer` не очищается на `kernel.reset`**: спаны, которые profiling закрывает в `reset()`, приходят после
  flush (−512 < −1024 не помогает — порядок сервисов в resetter не гарантирован) и уходят со следующим экспортом.
  Расхождение со спеком §3.5 («очищается на reset») — сознательное: id спана самодостаточны.
- **Сертификаты**: SDK знает только `*_CERTIFICATE`; `*_CLIENT_CERTIFICATE`/`*_CLIENT_KEY` читаются из окружения
  напрямую; для HTTP они идут в опции HttpClient (`cafile`, `local_cert`, `local_pk`) — `PsrTransportFactory` их
  игнорирует.
- **Протокол/сжатие из переменных** вне `http/protobuf|http/json|grpc` / `none|gzip` → `LogicException` при первом
  экспорте (как экспорт: warning + пауза).
- **`CreationRecorder` на приоритете −65536**: должен ключевать итоговую обёртку спана после всех create-процессоров.
- **`ResettableServicePass` без приоритетов**: порядок `kernel.reset` = порядок регистрации бандлов. `CreationRecorder`
  поэтому не сбрасывается (WeakMap чистится GC): иначе при мосте раньше ProfilingBundle в bundles.php спаны, которые
  profiling закрывает в reset, теряли запись создания. Тест `testSpansEndedOnKernelResetGoWithTheNextExport` гоняет оба
  порядка (`ENV_BRIDGE_FIRST`).
- **Unit-спан, закрытый profiling в `kernel.reset`** (единица не закрыла свой спан), берёт `holder->current()`; если
  TracingBundle сбросился раньше, спан попадёт в новую трассу. Редко; не исправлено.
- **Общая пауза читается из `cache.app` не чаще раза в секунду** (`SHARED_PAUSE_READ_SECONDS`): с Redis иначе лишний
  запрос на каждую единицу работы.
- **Сертификаты** читаются через резолверы SDK (`Configuration::has/getString` принимает любые имена): окружение
  важнее `$_SERVER`.
- **Ключ атрибута не в UTF-8** роняет конвертер так же, как имя: такие ключи отбрасываются.
