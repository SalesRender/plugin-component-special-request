# salesrender/plugin-component-request-dispatcher

Компонент для отправки специальных исходящих HTTP-запросов из плагинов SalesRender на backend. Запросы подписываются JWT, сохраняются в базу данных и обрабатываются асинхронно через консольные команды Symfony Console с автоматической логикой повторных попыток.

## Установка

```bash
composer require salesrender/plugin-component-request-dispatcher
```

## Требования

| Требование | Версия |
|---|---|
| PHP | >= 7.4 |
| ext-json | * |
| symfony/console | ^5.3 |
| salesrender/plugin-component-db | ^0.3.8 |
| salesrender/plugin-component-guzzle | ^0.3.1 |
| salesrender/plugin-component-queue | ^0.3.0 |

## Обзор

Компонент реализует персистентную очередь для исходящих HTTP-запросов. Каждый запрос сохраняется как `SpecialRequestTask` в базе данных и обрабатывается позже cron-процессом. Неудавшиеся запросы автоматически повторяются, пока не исчерпается лимит попыток или не истечет срок действия запроса.

### Принцип работы

1. Создайте `SpecialRequest` с HTTP-методом, URI, JWT-телом, временем истечения и ожидаемым кодом успеха.
2. Оберните его в `SpecialRequestTask` и вызовите `save()`.
3. Команда `SpecialRequestQueueCommand` (запускается по cron каждую минуту) выбирает ожидающие задачи.
4. Каждая задача обрабатывается командой `SpecialRequestHandleCommand`, которая отправляет HTTP-запрос через Guzzle.
5. При успехе (совпадение с `successCode`) задача удаляется. При неудаче счетчик попыток увеличивается. При получении stop-кода или истечении срока задача удаляется без дальнейших попыток.

## Основные классы

### `SpecialRequest`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Components`

Модель, представляющая один исходящий HTTP-запрос.

| Метод | Возврат | Описание |
|---|---|---|
| `__construct(string $method, string $uri, string $body, ?int $expireAt, int $successCode, array $stopCodes = [])` | | Создает запрос. Stop-код `418` (архивированный плагин) добавляется автоматически. |
| `getMethod()` | `string` | HTTP-метод (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`). |
| `getUri()` | `string` | Целевой URI. |
| `getBody()` | `string` | Тело запроса (как правило, строка JWT-токена). |
| `getExpireAt()` | `?int` | Unix-метка времени истечения или `null`, если срок не ограничен. |
| `isExpired()` | `bool` | Возвращает `true`, если текущее время превысило `expireAt`. |
| `getSuccessCode()` | `int` | HTTP status-код, означающий успех (например, `200`, `202`). |
| `getStopCodes()` | `array` | HTTP status-коды, при получении которых задача удаляется без повтора. Всегда включает `418`. |

### `SpecialRequestTask`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Models`

Наследует `Task` из [`plugin-component-queue`](https://github.com/SalesRender/plugin-component-queue). Сохраняет `SpecialRequest` в базу данных для асинхронной обработки.

| Метод | Возврат | Описание |
|---|---|---|
| `__construct(SpecialRequest $request, ?int $attemptLimit = null, int $attemptTimeout = 60, int $httpTimeout = 30)` | | Создает задачу. Если `attemptLimit` равен `null`, он вычисляется из времени истечения запроса или устанавливается по умолчанию `1440` (24 часа с интервалом в 1 минуту). |
| `getRequest()` | `SpecialRequest` | Возвращает обернутый запрос. |
| `getAttempt()` | `TaskAttempt` | Возвращает трекер попыток (номер, лимит, интервал, время последней попытки). |
| `getHttpTimeout()` | `int` | Таймаут HTTP-запроса в секундах для Guzzle. По умолчанию: `30`. |
| `save()` | `void` | Сохраняет задачу в базу данных. |
| `delete()` | `void` | Удаляет задачу из базы данных. |

**Схема базы данных (дополнительные столбцы):**

| Столбец | Тип |
|---|---|
| `request` | `MEDIUMTEXT NOT NULL` |
| `httpTimeout` | `INT NOT NULL` |

### `SpecialRequestQueueCommand`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Commands`

Консольная команда Symfony, которая опрашивает базу данных на наличие ожидающих задач и запускает процессы-обработчики.

- **Имя команды:** `specialRequest:queue`
- **Лимит очереди по умолчанию:** значение из `$_ENV['LV_PLUGIN_SR_QUEUE_LIMIT']` или `100`
- **Максимальная память по умолчанию:** `25` МБ

Выбирает задачи, отсортированные по `createdAt ASC`, исключая те, у которых последняя попытка была слишком недавно (учитывается `attemptInterval`).

### `SpecialRequestHandleCommand`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Commands`

Консольная команда Symfony, которая обрабатывает одну задачу по её ID.

- **Имя команды:** `specialRequest:handle`
- Отправляет HTTP-запрос через `Guzzle::getInstance()->request()`
- При совпадении с `successCode`: удаляет задачу, возвращает `Command::SUCCESS`
- При совпадении с любым `stopCode`: удаляет задачу, возвращает `Command::INVALID`
- При истечении срока запроса: удаляет задачу, возвращает `Command::INVALID`
- При неудаче с оставшимися попытками: увеличивает счетчик, сохраняет задачу, возвращает `Command::FAILURE`
- При неудаче без оставшихся попыток: удаляет задачу, возвращает `Command::FAILURE`

**Структура JSON-тела запроса:**

```json
{
    "request": "<строка JWT-тела>",
    "__task": {
        "createdAt": "<объект задачи>",
        "attempt": {
            "number": 1,
            "limit": 1440,
            "interval": 60
        }
    }
}
```

## Примеры использования

### Отправка CDR-записи из PBX-плагина

Из `plugin-core-pbx` (`CdrSender`):

```php
use SalesRender\Plugin\Components\Access\Registration\Registration;
use SalesRender\Plugin\Components\Db\Components\Connector;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Components\SpecialRequest;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;
use XAKEPEHOK\Path\Path;

$registration = Registration::find();
$uri = (new Path($registration->getClusterUri()))
    ->down('companies')
    ->down(Connector::getReference()->getCompanyId())
    ->down('CRM/plugin/pbx/cdr');

$ttl = 60 * 60 * 24; // 24 часа
$request = new SpecialRequest(
    'PATCH',
    (string) $uri,
    (string) Registration::find()->getSpecialRequestToken($cdrData, $ttl),
    time() + $ttl,
    202
);

$task = new SpecialRequestTask($request);
$task->save();
```

### Отправка статуса сообщения в чат-плагине с stop-кодами и настраиваемым интервалом повтора

Из `plugin-core-chat` (`MessageStatusSender`):

```php
use SalesRender\Plugin\Components\Access\Registration\Registration;
use SalesRender\Plugin\Components\Db\Components\Connector;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Components\SpecialRequest;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;
use XAKEPEHOK\Path\Path;

$data = [
    'id' => $messageId,
    'status' => 'delivered',
];

$registration = Registration::find();
$uri = (new Path($registration->getClusterUri()))
    ->down('companies')
    ->down(Connector::getReference()->getCompanyId())
    ->down('CRM/plugin/chat/status');

$ttl = 300; // 5 минут
$request = new SpecialRequest(
    'PATCH',
    (string) $uri,
    (string) Registration::find()->getSpecialRequestToken($data, $ttl),
    time() + $ttl,
    200,
    [404] // прекратить повторы, если ресурс не найден
);

$task = new SpecialRequestTask($request, null, 10); // повтор каждые 10 секунд
$task->save();
```

### Отправка уведомлений о статусе логистики

Из `plugin-core-logistic` (`Track::createNotification`):

```php
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Components\SpecialRequest;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;

$request = new SpecialRequest(
    'PATCH',
    $uri,
    (string) $jwt,
    time() + 24 * 60 * 60,
    202,
    [410] // 410 - логистика удалена из заказа
);

$task = new SpecialRequestTask($request);
$task->save();
```

## Конфигурация

### Переменные окружения

| Переменная | По умолчанию | Описание |
|---|---|---|
| `LV_PLUGIN_SR_QUEUE_LIMIT` | `100` | Максимальное количество задач, обрабатываемых командой очереди за один цикл. |

### Регистрация консольных команд

Обе команды регистрируются автоматически в `ConsoleAppFactory` из [`plugin-core`](https://github.com/SalesRender/plugin-core):

```php
$app->add(new SpecialRequestQueueCommand());
$app->add(new SpecialRequestHandleCommand());
```

Cron-задача добавляется для запуска очереди каждую минуту:

```php
$this->addCronTask('* * * * *', 'specialRequest:queue');
```

## Зависимости

| Пакет | Назначение |
|---|---|
| `salesrender/plugin-component-db` | Персистентность данных (Medoo), базовые классы моделей |
| `salesrender/plugin-component-guzzle` | HTTP-клиент Guzzle (singleton) |
| `salesrender/plugin-component-queue` | Базовые классы `Task`, `TaskAttempt`, `QueueCommand`, `QueueHandleCommand` |
| `symfony/console` | Инфраструктура консольных команд |

## Смотрите также

- [salesrender/plugin-component-queue](https://github.com/SalesRender/plugin-component-queue) -- базовая инфраструктура очередей и задач
- [salesrender/plugin-component-db](https://github.com/SalesRender/plugin-component-db) -- модели базы данных и Connector
- [salesrender/plugin-component-guzzle](https://github.com/SalesRender/plugin-component-guzzle) -- HTTP-клиент
- [salesrender/plugin-component-access](https://github.com/SalesRender/plugin-component-access) -- класс `Registration` и метод `getSpecialRequestToken()` для подписи JWT
- [salesrender/plugin-core](https://github.com/SalesRender/plugin-core) -- `ConsoleAppFactory`, где регистрируются команды
