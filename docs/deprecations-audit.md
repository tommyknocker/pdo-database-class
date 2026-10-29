# Аудит deprecated-конструкций PHP

Проверка рабочего дерева для PHP 8.4 и 8.5; статус исправлений указан ниже. Цель — найти места, которые уже помечены как устаревшие в поддерживаемых версиях PHP, сохранив совместимость публичного API PDOdb.

## Найдено в production-коде

1. **Исправлено: `null` как ключ массива при eager loading** — `src/orm/relations/EagerLoader.php:172`. В PHP 8.5 обращение `$indexed[$foreignValue]`, когда внешний ключ равен `null`, вызывает deprecation. Подтверждено тестами `ActiveRecordRelationsTests` и `EagerLoaderTests`. Рекомендуемое исправление: обработать `null` отдельно и назначить отсутствие связи, сохранив поведение для `0` и пустой строки. Добавить тесты на все три значения.
2. **Исправлено: `$http_response_header`** — `src/ai/BaseAiProvider.php:77`. Переменная устарела в PHP 8.5; обнаружена при `php -l`. Рекомендуемая замена: `http_get_last_response_headers()` (доступна с PHP 8.4). Проверить успешный ответ, ошибочный HTTP-статус и последовательные запросы, чтобы не использовать старые заголовки. Код HTTP-запроса сейчас использует оператор подавления ошибок, поэтому обычный прогон тестов не гарантирует видимость предупреждения.
3. **`PDO::sqliteCreateFunction()`** — `src/dialects/sqlite/SQLiteSqlFormatter.php:700`. Это намеренный fallback: на PHP 8.5 обычный внешний экземпляр `PDO`, переданный приложением, может попасть в эту ветку и вызвать deprecation; соединения, созданные библиотекой через `PDO::connect()`, используют `Pdo\Sqlite::createFunction()`. На PHP 8.4 fallback необходим для старого типа PDO. Нельзя заменять или запрещать внешний PDO без учёта совместимости. Нужно отдельно выбрать и протестировать стратегию для переданного обычного PDO на PHP 8.5.

## Тесты, примеры и scripts

- **Исправлено:** `ReflectionMethod::setAccessible()` и `ReflectionProperty::setAccessible()` устарели в PHP 8.5 и не влияют на доступность с PHP 8.1. Найдено **579 вызовов в 60 файлах `tests/`**, а также по одному в `scripts/test-cache-stats.php` и `examples/12-reliability/02-connection-retry.php`. Можно удалить вызовы, сохранив проверки обращений через Reflection. Рекомендуется проверка на PHP 8.4 и 8.5 после пакетного изменения.
- В предыдущем запуске PHP 8.5: shared — 540 deprecations (539 мест вызова Reflection и одно место с `null` в production), SQLite — одно место Reflection. Это число диагностированных мест, а не количество исходных вызовов или случаев в production.
- **Исправлено:** ссылки в `tests/README.md` обновлены на `Pdo\Mysql::ATTR_LOCAL_INFILE`.

## Собственные deprecated API библиотеки

- `PdoDb::LOCK_WRITE` и `PdoDb::LOCK_READ` помечены `@deprecated` в `src/PdoDb.php:36`. Замена — `QueryConstants`. Оставить публичные алиасы для существующих пользователей; удалять только в согласованном major-релизе.
- Защищённый `Dashboard::getAutocompleteContext()` помечен `@deprecated` в `src/cli/ui/Dashboard.php:4205`. Внутренних вызовов по текстовому поиску не найдено, но метод может использоваться подклассами; удаление требует отдельной проверки совместимости.

## Объём и ограничения

- Проверены `src/`, `tests/`, `examples/`, `scripts/`, а также оба PHP-файла в `bin/`. Синтаксическая проверка PHP 8.5 охватила 464 файла `.php` вне `tests/` и 355 файлов тестов; PHP 8.4 — те же 464 файла вне тестов. Проверены диагностические сообщения уже выполненных SQLite и shared тестов.
- Поиск включал список deprecated-возможностей PHP 8.5 из официального migration guide, PHPDoc `@deprecated`, известные старые функции, вызовы Reflection и старые методы/константы PDO. Синтаксический поиск не определяет все типы переменных и не доказывает отсутствие проблем в динамических путях.
- MySQL, MariaDB, PostgreSQL, MSSQL и Oracle не исполнялись в этом окружении. Не проверены зависимости из `vendor/`; они могут иметь собственные deprecations. Для полной проверки нужны запуски CI на PHP 8.5 с каждой доступной СУБД и с тестами, которые фактически выполняют AI HTTP и внешние PDO-соединения.

Источники: [PHP 8.5: Deprecated Features](https://www.php.net/manual/en/migration85.deprecated.php), [PHP 8.4: Deprecated Features](https://www.php.net/manual/en/migration84.deprecated.php), [ReflectionProperty::setAccessible](https://www.php.net/manual/en/reflectionproperty.setaccessible.php).

## Статус после исправлений

Удалены вызовы Reflection из тестов, примера и скрипта; HTTP-код использует функции PHP 8.4+, при eager loading отдельно обрабатывается null. Сохранён fallback для внешнего экземпляра обычного PDO: без замены переданного клиентом подключения доступный способ регистрации функций SQLite на нём остаётся deprecated в PHP 8.5. Не применять подавление ошибок или замену PDO без явного изменения контракта.
