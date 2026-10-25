# Архитектура (1.0.0)

Спек: `../docs/superpowers/specs/2026-10-25-otlp-span-export-design.md`, план:
`../docs/superpowers/plans/2026-10-25-otlp-span-export.md` (Part B).

## Поток

```
profiling createSpan ─► TraceSpanProcessor (tracing-bridge-profiling, prio 0) ─► CreationRecorder (prio -65536):
                        WeakMap[обёртка TracedSpan] = holder->current()
profiling end ─► OtlpEndSpanProcessor ─► SpanPlacer::place() (SpanPlacement) ─► SpanMapper::map() ─► SpanBuffer::add()
                                                              └ full ─► SpanFlushInterface::flush()
конец единицы ─► FlushListener ─► Flusher::flush() ─► SpanExporter (OTLP) ─► приёмник
```

## Разрешение id (SpanPlacer)

| Спан | trace | span id / parent | kind |
|---|---|---|---|
| префикс единицы (`unit_span_prefixes` моста profiling) | `message ` → `UnitRecorder::takeMessageTrace() ?? current()`, иначе `current()` | `traceParent.spanId` / `parentSpanId`, remote если = `remoteTraceParent.spanId` | request SERVER (+статус ответа), message CONSUMER, иначе INTERNAL |
| `trace_span_id` в контексте | `CreationRecorder::traceAt()` | ключи моста (невалидный parent → корень) | `connection` + `profiling_automatic` (DBAL) → CLIENT, иначе INTERNAL |
| `DETACHED` | `traceAt()` | `traceParent->child()->spanId` / `traceParent.spanId` | CLIENT |
| иначе / нет записи создания | — пропуск, `debug` один раз на имя до `reset()` | | |

Пропуск, если в trace нет `FLAG_SAMPLED` (проверяет `OtlpEndSpanProcessor` по `SpanIds::flags`). `SpanIds` валидирует hex-id в конструкторе.

## Приоритеты событий

- `UnitRecorder`: `WorkerMessageReceivedEvent` 8192 (забыть, in-flight), Handled/Failed/Running 4096 (захват до
  `closeUnit()` tracing-bundle на 0 и закрытия спана profiling на 0 — при любом порядке Symfony), `kernel.request` 8192,
  `kernel.response` -4096 (main request).
- `FlushListener`: `kernel.terminate` / `console.terminate` -8192 (после `onTerminateEnd` profiling на -4096),
  `WorkerRunningEvent` / `WorkerStoppedEvent` -512 (после закрытия спана сообщения на 0, до `ResetServicesListener` -1024).

## Слои (deptrac)

Infrastructure ↛ Framework/Presentation и наоборот, поэтому: `UnitRecorder` — в `Infrastructure/Profiling` (состояние
для end-процессора), `FlushListener` — `Presentation/EventListener` через порт `Application/Port/SpanFlushInterface`
(реализует `Flusher`). Бандл-класс не ссылается на Infrastructure (строка grpc-фабрики продублирована).

## DI

`services.php`: `msstc4symfony_tracing_bridge_otlp.exporter` (factory `ExporterFactory::create`), `SpanMapper` со scope
и resource (inline-фабрики `ResourceFactory`), `Flusher` с `service_closure(exporter)`, inline `NativeClock`, inline `FilesystemAdapter` в `%kernel.cache_dir%/msstc4symfony_tracing_bridge_otlp` для общей паузы (не `cache.app`),
лог-канал `otlp` (тег `monolog.logger`, и у процессора); `SpanPlacer` с `unit_span_prefixes` (тег `monolog.logger` otlp, debug о невалидном parent — раз на имя за единицу работы через `ReportedNames`, ≤ 256, как у процессора);
`CreationRecorder` с `autoconfigure(false)` + тег `CreateSpanProcessorInterface` priority -65536 (без `kernel.reset`);
остальное autoconfigure (end processor, subscribers, `ResetInterface` → `kernel.reset`). `enabled: false` — ни одного
сервиса. Параметры `msstc4symfony_tracing_bridge_otlp.<key>`.
