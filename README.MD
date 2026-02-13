# salesrender/plugin-component-request-dispatcher

Queue-based component for sending special outgoing HTTP requests from SalesRender plugins to the backend. Requests are JWT-signed, persisted to a database, and dispatched asynchronously via Symfony Console commands with automatic retry logic.

## Installation

```bash
composer require salesrender/plugin-component-request-dispatcher
```

## Requirements

| Requirement | Version |
|---|---|
| PHP | >= 7.4 |
| ext-json | * |
| symfony/console | ^5.3 |
| salesrender/plugin-component-db | ^0.3.8 |
| salesrender/plugin-component-guzzle | ^0.3.1 |
| salesrender/plugin-component-queue | ^0.3.0 |

## Overview

The component implements a persistent queue for outgoing HTTP requests. Each request is saved as a `SpecialRequestTask` in the database and processed later by a cron-driven queue. Failed requests are retried automatically until the attempt limit is reached or the request expires.

### How It Works

1. Create a `SpecialRequest` with HTTP method, URI, JWT body, expiration, and expected success code.
2. Wrap it in a `SpecialRequestTask` and call `save()`.
3. The `SpecialRequestQueueCommand` (running on cron every minute) picks up pending tasks.
4. Each task is handled by `SpecialRequestHandleCommand`, which sends the HTTP request via Guzzle.
5. On success (matching `successCode`), the task is deleted. On failure, the attempt counter increments. On stop-code or expiration, the task is deleted without further retries.

## Key Classes

### `SpecialRequest`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Components`

Model representing a single outgoing HTTP request.

| Method | Return Type | Description |
|---|---|---|
| `__construct(string $method, string $uri, string $body, ?int $expireAt, int $successCode, array $stopCodes = [])` | | Creates a request. Stop-code `418` (archived plugin) is always added automatically. |
| `getMethod()` | `string` | HTTP method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`). |
| `getUri()` | `string` | Target URI. |
| `getBody()` | `string` | Request body (typically a JWT token string). |
| `getExpireAt()` | `?int` | Unix timestamp when the request expires, or `null` for no expiration. |
| `isExpired()` | `bool` | Returns `true` if the current time has passed `expireAt`. |
| `getSuccessCode()` | `int` | HTTP status code that indicates success (e.g. `200`, `202`). |
| `getStopCodes()` | `array` | HTTP status codes that cause the task to be deleted without retrying. Always includes `418`. |

### `SpecialRequestTask`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Models`

Extends `Task` from [`plugin-component-queue`](https://github.com/SalesRender/plugin-component-queue). Persists a `SpecialRequest` to the database for asynchronous processing.

| Method | Return Type | Description |
|---|---|---|
| `__construct(SpecialRequest $request, ?int $attemptLimit = null, int $attemptTimeout = 60, int $httpTimeout = 30)` | | Creates a task. If `attemptLimit` is `null`, it is calculated from the request's expiration time or defaults to `1440` (24 hours at 1-minute intervals). |
| `getRequest()` | `SpecialRequest` | Returns the wrapped request. |
| `getAttempt()` | `TaskAttempt` | Returns the attempt tracker (number, limit, interval, last time). |
| `getHttpTimeout()` | `int` | HTTP timeout in seconds for Guzzle. Default: `30`. |
| `save()` | `void` | Persists the task to the database. |
| `delete()` | `void` | Removes the task from the database. |

**Database schema (additional columns):**

| Column | Type |
|---|---|
| `request` | `MEDIUMTEXT NOT NULL` |
| `httpTimeout` | `INT NOT NULL` |

### `SpecialRequestQueueCommand`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Commands`

Symfony Console command that polls the database for pending tasks and spawns handler processes.

- **Command name:** `specialRequest:queue`
- **Default queue limit:** value from `$_ENV['LV_PLUGIN_SR_QUEUE_LIMIT']` or `100`
- **Default max memory:** `25` MB

Queries tasks ordered by `createdAt ASC`, excluding tasks whose last attempt was too recent (respects `attemptInterval`).

### `SpecialRequestHandleCommand`

**Namespace:** `SalesRender\Plugin\Components\SpecialRequestDispatcher\Commands`

Symfony Console command that processes a single task by its ID.

- **Command name:** `specialRequest:handle`
- Sends the HTTP request via `Guzzle::getInstance()->request()`
- On matching `successCode`: deletes the task, returns `Command::SUCCESS`
- On matching any `stopCode`: deletes the task, returns `Command::INVALID`
- On expired request: deletes the task, returns `Command::INVALID`
- On failure with retries remaining: increments attempt counter, saves the task, returns `Command::FAILURE`
- On failure with no retries remaining: deletes the task, returns `Command::FAILURE`

**Request JSON payload structure:**

```json
{
    "request": "<JWT body string>",
    "__task": {
        "createdAt": "<task object>",
        "attempt": {
            "number": 1,
            "limit": 1440,
            "interval": 60
        }
    }
}
```

## Usage Examples

### Sending a CDR record from a PBX plugin

Taken from `plugin-core-pbx` (`CdrSender`):

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

$ttl = 60 * 60 * 24; // 24 hours
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

### Sending a chat message status with stop-codes and custom retry interval

Taken from `plugin-core-chat` (`MessageStatusSender`):

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

$ttl = 300; // 5 minutes
$request = new SpecialRequest(
    'PATCH',
    (string) $uri,
    (string) Registration::find()->getSpecialRequestToken($data, $ttl),
    time() + $ttl,
    200,
    [404] // stop retrying if resource not found
);

$task = new SpecialRequestTask($request, null, 10); // retry every 10 seconds
$task->save();
```

### Sending logistic status notifications

Taken from `plugin-core-logistic` (`Track::createNotification`):

```php
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Components\SpecialRequest;
use SalesRender\Plugin\Components\SpecialRequestDispatcher\Models\SpecialRequestTask;

$request = new SpecialRequest(
    'PATCH',
    $uri,
    (string) $jwt,
    time() + 24 * 60 * 60,
    202,
    [410] // 410 - logistic removed from order
);

$task = new SpecialRequestTask($request);
$task->save();
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `LV_PLUGIN_SR_QUEUE_LIMIT` | `100` | Maximum number of tasks the queue command processes per cycle. |

### Console Registration

Both commands are registered automatically by `ConsoleAppFactory` in [`plugin-core`](https://github.com/SalesRender/plugin-core):

```php
$app->add(new SpecialRequestQueueCommand());
$app->add(new SpecialRequestHandleCommand());
```

A cron task is added to run the queue every minute:

```php
$this->addCronTask('* * * * *', 'specialRequest:queue');
```

## Dependencies

| Package | Purpose |
|---|---|
| `salesrender/plugin-component-db` | Database persistence (Medoo), model base classes |
| `salesrender/plugin-component-guzzle` | Guzzle HTTP client singleton |
| `salesrender/plugin-component-queue` | Base `Task`, `TaskAttempt`, `QueueCommand`, `QueueHandleCommand` classes |
| `symfony/console` | Console command infrastructure |

## See Also

- [salesrender/plugin-component-queue](https://github.com/SalesRender/plugin-component-queue) -- base queue/task infrastructure
- [salesrender/plugin-component-db](https://github.com/SalesRender/plugin-component-db) -- database models and Connector
- [salesrender/plugin-component-guzzle](https://github.com/SalesRender/plugin-component-guzzle) -- HTTP client
- [salesrender/plugin-component-access](https://github.com/SalesRender/plugin-component-access) -- `Registration` class and `getSpecialRequestToken()` for JWT signing
- [salesrender/plugin-core](https://github.com/SalesRender/plugin-core) -- `ConsoleAppFactory` where commands are registered
