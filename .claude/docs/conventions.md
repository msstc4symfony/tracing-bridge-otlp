# Соглашения

- Тесты: `tests/Unit/<путь класса>` (только они идут в Infection); `tests/Integration/KernelExportTest` — реальное ядро
  (Framework, Monolog, Tracing, Profiling, оба моста), экспортёр подменён `InMemoryExporter` по id сервиса;
  `OtlpTransportTest` — настоящий OTLP по HTTP в `php -S tests/Support/OtlpReceiver/receiver.php` (порт 0 → свободный).
- Поддержка тестов: `tests/Support` (StubSpan, RecordingExporter, RecordingLogger), фикстуры ядра — `tests/Fixtures`.
- Логику, которую иначе покрывают только интеграционные тесты, выносить в чистые объекты (`ExporterSettings`), чтобы
  Infection её видел.
- Файлы инструментов байт-в-байт из `bundle-standard/templates` (`phpstan.dist.neon` — только `level:`): stub-файлы
  PHPStan сюда не добавить.
- Коммиты — одна строка на английском, ≤ 20 слов; теги `vX.Y.Z`.
