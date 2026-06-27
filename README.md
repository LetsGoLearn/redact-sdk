# redact-sdk

A performant, dependency-free PHP client for the **redactor** PII-redaction API
(the Go service over `privacy-filter.cpp`), with first-class Laravel support.

- **Fast**: a native cURL transport with a persistent, keep-alive connection
  and `curl_multi` for concurrent batch redaction. No Guzzle, no PSR stack — just
  `ext-curl` + `ext-json`.
- **Typed**: readonly DTOs (`RedactResult`, `Entity`, `Label`), a `Mode` enum,
  and a fluent immutable `Policy` builder.
- **Robust**: typed exceptions, automatic retries with backoff for transient
  failures, and a partial-failure-tolerant batch mode.
- **Laravel-ready**: auto-discovered service provider, `Redact` facade, and a
  publishable config. Registered as a singleton, so connections stay warm —
  especially under Octane.

## Requirements

- PHP 8.1+
- `ext-curl`, `ext-json`
- A running redactor API (see the main project README)

## Installation

```sh
composer require letsgolearn/redact-sdk
```

## Plain PHP

```php
use RedactSdk\Client;
use RedactSdk\DTO\Policy;
use RedactSdk\Enums\Mode;

$client = Client::make('http://192.168.100.118:8080', 'your-api-key');

$result = $client->redact(
    'Hi, I am Jane Doe. Email jane@acme.com or call 415-555-0142.',
    Policy::make()
        ->withDefault(Mode::Tag)                          // [EMAIL], [PHONE], ...
        ->label('private_person', Mode::KeepFirst),       // "Jane Doe" -> "Jane [LAST]"
);

echo $result->redacted;          // Hi, I am Jane [LAST]. Email [EMAIL] or call [PHONE].
echo $result->count();           // 3
echo $result->count('private_email'); // 1
foreach ($result->entities as $e) {
    echo "{$e->label} [{$e->start}:{$e->end}] {$e->score}\n";
}
```

## Laravel

The provider and `Redact` facade are auto-discovered. Publish the config if you
want to tweak defaults:

```sh
php artisan vendor:publish --tag=redact-config
```

Set credentials in `.env`:

```dotenv
REDACT_BASE_URI=http://192.168.100.118:8080
REDACT_API_KEY=your-api-key
# optional tuning
REDACT_TIMEOUT=10
REDACT_RETRIES=2
REDACT_DEFAULT_THRESHOLD=0.5
```

Use the facade or inject the client:

```php
use RedactSdk\Laravel\Facades\Redact;
use RedactSdk\DTO\Policy;
use RedactSdk\Enums\Mode;

$result = Redact::redact($comment, Policy::make()->label('private_person', Mode::KeepFirst));

// or via the container
public function __construct(private \RedactSdk\Client $redact) {}
```

## Redaction modes

| Mode | Result for a span |
|---|---|
| `Mode::Tag` (default) | `[EMAIL]`, `[PHONE]`, … |
| `Mode::Mask` | a fixed server-configured string, e.g. `[REDACTED]` |
| `Mode::Hash` | stable keyed token, e.g. `EMAIL_a1b2c3d4` |
| `Mode::KeepFirst` | keep first name, strip surname: `Jane Doe` → `Jane [LAST]` |
| `Mode::Drop` | remove the span |

Build policies fluently (each call returns a new, immutable `Policy`):

```php
Policy::make()
    ->withDefault(Mode::Tag)
    ->label('private_person', Mode::KeepFirst)
    ->label('secret', Mode::Drop)
    ->only('private_person', 'private_email', 'secret'); // allow-list (optional)
```

## Concurrent batch

`redactMany()` runs requests in parallel (bounded by `concurrency`) over the
persistent connection pool. Server-side parallelism scales with the redactor's
`REDACTOR_POOL_SIZE`.

```php
$results = $client->redactMany(
    ['ticket-1' => $body1, 'ticket-2' => $body2, 'ticket-3' => $body3],
    Policy::make()->label('private_person', Mode::KeepFirst),
    concurrency: 8,
);
// results are keyed by your input keys, in input order
echo $results['ticket-1']->redacted;
```

Tolerate partial failures with `redactManySettled()`:

```php
foreach ($client->redactManySettled($texts) as $key => $outcome) {
    if ($outcome['error']) {
        report($outcome['error']);
        continue;
    }
    save($key, $outcome['result']->redacted);
}
```

## Other endpoints

```php
$client->labels();   // list<Label> { label, tag }
$client->ready();    // bool — engine loaded (GET /readyz), never throws
$client->healthy();  // bool — service up (GET /healthz), never throws
```

## Error handling

All exceptions extend `RedactSdk\Exceptions\RedactException`:

| Exception | When |
|---|---|
| `ValidationException` | HTTP 400 (bad request / invalid policy) |
| `AuthenticationException` | HTTP 401/403 (bad or missing API key) |
| `ApiException` | any other non-2xx (`->status`, `->body`) |
| `TransportException` | connection failure / timeout (no HTTP response) |

```php
use RedactSdk\Exceptions\{AuthenticationException, ApiException, TransportException};

try {
    $result = $client->redact($text);
} catch (AuthenticationException $e) {
    // 401 — check REDACT_API_KEY
} catch (ApiException $e) {
    logger()->warning("redactor {$e->status}: {$e->getMessage()}");
} catch (TransportException $e) {
    // service unreachable
}
```

## Performance notes

- **Reuse one `Client`.** The transport holds a warm connection; the Laravel
  binding already registers it as a singleton. Under Octane the connection
  survives across requests for the worker's lifetime.
- **Batch with `redactMany()`** instead of looping `redact()` when you have many
  texts; it pipelines them concurrently.
- **Match client concurrency to the server**: raising `concurrency` only helps if
  the redactor was started with a larger `REDACTOR_POOL_SIZE` (each pool context
  is a full model copy in RAM).
- Retries (default 2, exponential backoff) cover transient connect/timeout/5xx;
  4xx are never retried.

## Custom transport / testing

`Client` accepts any `RedactSdk\Http\Transport`. Tests use the in-memory
`FakeTransport`; you can supply your own to route through a different HTTP stack.

```php
$client = new Client(new Config('http://redactor.test', 'key'), $myTransport);
```

## Tests

```sh
composer install
composer test
```
