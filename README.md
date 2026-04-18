# AstraHTTP PHP

AstraHTTP PHP is a production-oriented wrapper around a Go-based transport engine. It is designed for high-concurrency HTTP traffic with shared worker management, TLS fingerprint customization, streaming response handling, multipart form encoding, retry support, and WebSocket / SSE protocol hooks.

This document explains the package in detail: installation, architecture, API surface, request options, response methods, streaming, retries, WebSocket / SSE usage, multipart uploads, and practical examples.

---

## 1. Requirements

- PHP 8.2 or newer
- Composer
- One of the supported platforms:
  - Android (arm64 / aarch64)
  - Ubuntu and other Linux distributions (amd64 / x86_64, arm, arm64 / aarch64)
  - FreeBSD (amd64 / x86_64)
  - macOS (amd64 / x86_64, arm64)
  - Windows (x86 / 386, amd64 / x86_64)

> **Note:** `amd64` and `x86_64` refer to the same architecture, and `arm64` is also known as `aarch64`.
---

## 2. Installation

### Using Composer in a project (Recommended)

```bash
composer require astrahttp/http
php vendor/bin/astrahttp install
```

### Installing from source 
- This method installs a **development version (unstable)** and is not recommended for production.

```bash
composer config repositories.astrahttp vcs https://github.com/astrahttp/http.git
composer require astrahttp/http:1.x-dev
php vendor/bin/astrahttp install
```

### Autoloading

The library is PSR-4 namespaced and autoloaded through Composer:

```php
require __DIR__ . '/vendor/autoload.php';
```

The main entry points are:

- `Astra\SwooleHttp\Client`
- `Astra\SwooleHttp\Contract\RequestOptions`
- `Astra\SwooleHttp\initAstraHTTP()`

---

## 3. Package overview

The library is structured around four layers:

### 3.1 Client layer

`Astra\SwooleHttp\Client` is the public API used by application code. It exposes HTTP methods such as:

- `get()`
- `post()`
- `put()`
- `patch()`
- `delete()`
- `head()`
- `options()`
- `trace()`
- `websocket()` / `ws()`
- `sse()` / `eventSource()`

Each method returns an `AsyncRequestHandle`, which can be awaited using `->await()`.

### 3.2 Request options layer

`Astra\SwooleHttp\Contract\RequestOptions` is the configuration object for a request. It contains:

- headers
- cookies
- body
- response type
- TLS fingerprint fields
- connection settings
- protocol settings
- retry policy
- local lifecycle hooks

### 3.3 Runtime layer

The runtime layer manages the external Go worker process and the WebSocket transport between PHP and the worker.

It includes:

- `Astra\SwooleHttp\Runtime\WorkerRuntime`
- `Astra\SwooleHttp\Runtime\WorkerManager`
- `Astra\SwooleHttp\Runtime\WorkerTransport`

This is what gives the library its shared-process behavior and failover behavior.

### 3.4 Response layer

Responses are represented by `Astra\SwooleHttp\Response` and may be consumed as:

- raw text
- decoded JSON
- binary string
- stream

---

## 4. Creating a client

### Basic client creation

```php
use Astra\SwooleHttp\Client;

$client = new Client([
    'port' => 9119,
    'debug' => false,
]);
```

### Configuration options for the client constructor

The client constructor accepts an array with the following keys:

- `port` — the worker port to use
- `debug` — enables worker debugging output
- `workerPath` — explicit path to the Go worker binary

### Example

```php
$client = new Client([
    'port' => 9119,
    'debug' => true,
    'workerPath' => __DIR__ . '/bin/index',
]);
```

### Closing the client

Always close the client when you are finished:

```php
$client->close();
```

This releases the shared worker reference and helps the runtime shut down cleanly.

---

## 5. Main request workflow

The standard flow is:

1. Create a `Client`
2. Build `RequestOptions`
3. Call a method such as `get()` or `post()`
4. Receive an `AsyncRequestHandle`
5. Call `->await()` to get a `Response`

### Example

```php
use Astra\SwooleHttp\Client;
use Astra\SwooleHttp\Contract\RequestOptions;

$client = new Client(['port' => 9119]);

try {
    $handle = $client->get('https://httpbin.org/get', new RequestOptions([
        'responseType' => 'json',
    ]));

    $response = $handle->await();
    echo $response->text();
} finally {
    $client->close();
}
```

---

## 6. Request methods

The client exposes the following request methods.

### 6.1 HTTP methods

- `get(string $url, ?RequestOptions $options = null)`
- `post(string $url, ?RequestOptions $options = null)`
- `put(string $url, ?RequestOptions $options = null)`
- `patch(string $url, ?RequestOptions $options = null)`
- `delete(string $url, ?RequestOptions $options = null)`
- `head(string $url, ?RequestOptions $options = null)`
- `options(string $url, ?RequestOptions $options = null)`
- `trace(string $url, ?RequestOptions $options = null)`

Each returns an `AsyncRequestHandle`.

### 6.2 Protocol-specific methods

- `websocket(string $url, ?RequestOptions $options = null)`
- `ws(string $url, ?RequestOptions $options = null)`
- `sse(string $url, ?RequestOptions $options = null)`
- `eventSource(string $url, ?RequestOptions $options = null)`

These are specialized wrappers that set the `protocol` field internally.

### 6.3 Functional-style entry point

You can also use:

```php
use function Astra\SwooleHttp\initAstraHTTP;

$client = initAstraHTTP(['port' => 9119]);
```

---

## 7. RequestOptions in detail

`Astra\SwooleHttp\Contract\RequestOptions` is the central request configuration object.

### 7.1 Constructor

```php
new RequestOptions([
    'headers' => [],
    'body' => null,
    'responseType' => 'json',
]);
```

All properties are optional. Anything not provided remains `null` or the class default.

---

## 8. Request options reference

### 8.1 `headers`

Type: `array|null`

Defines the request headers.

Example:

```php
new RequestOptions([
    'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'AstraHTTP PHP',
    ],
]);
```

### 8.2 `cookies`

Type: `array|object|null`

Supported forms:

#### Associative array form

```php
new RequestOptions([
    'cookies' => [
        'session' => 'abc123',
        'theme' => 'dark',
    ],
]);
```

#### Array of cookie objects

```php
new RequestOptions([
    'cookies' => [
        ['name' => 'session', 'value' => 'abc123'],
        ['name' => 'theme', 'value' => 'dark'],
    ],
]);
```

#### Object form

```php
new RequestOptions([
    'cookies' => (object) [
        'session' => 'abc123',
    ],
]);
```

### 8.3 `body`

Type: `mixed`

Accepted body forms:

- string
- `Stringable`
- array
- `ReadableStream`
- multipart-like array structure
- binary data string

The body is normalized automatically.

#### String body

```php
new RequestOptions([
    'body' => 'hello world',
]);
```

#### JSON body

Any plain array that does not look like multipart is JSON-encoded automatically.

```php
new RequestOptions([
    'headers' => [
        'Content-Type' => 'application/json',
    ],
    'body' => [
        'name' => 'Ali',
        'age' => 42,
    ],
]);
```

#### Multipart body

An array becomes multipart when it contains `_multipart => true` or field entries that look like file descriptors.

Example with file path:

```php
new RequestOptions([
    'body' => [
        'title' => 'My upload',
        'file' => [
            'path' => __DIR__ . '/document.pdf',
            'filename' => 'document.pdf',
            'mime' => 'application/pdf',
        ],
    ],
]);
```

Example with raw in-memory content:

```php
new RequestOptions([
    'body' => [
        '_multipart' => true,
        'name' => 'Ali',
        'avatar' => [
            'filename' => 'avatar.png',
            'mime' => 'image/png',
            'content' => file_get_contents(__DIR__ . '/avatar.png'),
        ],
    ],
]);
```

### 8.4 `responseType`

Type: `json|text|arraybuffer|blob|stream|null`

Controls how the response body is presented.

- `json` — decode JSON into PHP array
- `text` — return string text
- `arraybuffer` — return binary string suitable for binary handling
- `blob` — return binary string with blob-style intent
- `stream` — retain a stream object for incremental reading

Example:

```php
new RequestOptions([
    'responseType' => 'stream',
]);
```

### 8.5 TLS fingerprint fields

These fields are passed to the worker for transport fingerprint configuration.

- `ja3`
- `ja4r`
- `http2Fingerprint`
- `quicFingerprint`
- `disableGrease`

Example:

```php
new RequestOptions([
    'ja4r' => '771,4865-4866-4867,...',
    'http2Fingerprint' => 'SETTINGS_ENABLE_PUSH=0;WINDOW_SIZE=6291456',
    'disableGrease' => true,
]);
```

### 8.6 Browser / connection fields

- `userAgent`
- `serverName`
- `proxy`
- `timeout`
- `disableRedirect`
- `headerOrder`
- `orderAsProvided`
- `insecureSkipVerify`
- `forceHTTP1`
- `forceHTTP3`
- `protocol`

Example:

```php
new RequestOptions([
    'userAgent' => 'Mozilla/5.0 ...',
    'proxy' => 'http://127.0.0.1:8080',
    'timeout' => 15000,
    'forceHTTP1' => true,
]);
```

### 8.7 Retry policy fields

- `maxRetries`
- `retryDelayMs`
- `retryable`

Example:

```php
new RequestOptions([
    'maxRetries' => 3,
    'retryDelayMs' => 500,
    'retryable' => true,
]);
```

### 8.8 Lifecycle hooks

- `onHeaders`
- `onChunk`
- `onComplete`
- `onError`

These are local PHP callbacks.

#### `onHeaders`
Called when response metadata arrives.

```php
new RequestOptions([
    'onHeaders' => function (array $meta, string $requestId): void {
        echo "Headers received for {$requestId}\n";
    },
]);
```

#### `onChunk`
Called for response body chunks.

```php
new RequestOptions([
    'responseType' => 'stream',
    'onChunk' => function (string $chunk, string $requestId, ?array $meta): void {
        echo $chunk;
    },
]);
```

#### `onComplete`
Called after a request finishes.

```php
new RequestOptions([
    'onComplete' => function (\Astra\SwooleHttp\Response $response, string $requestId): void {
        echo "Completed: {$requestId}\n";
    },
]);
```

#### `onError`
Called when a request fails.

```php
new RequestOptions([
    'onError' => function (\Throwable|string $error, string $requestId): void {
        echo "Error for {$requestId}: " . (string) $error . PHP_EOL;
    },
]);
```

---

## 9. Response object in detail

Requests resolve to `Astra\SwooleHttp\Response`.

### 9.1 Public properties

- `status` — HTTP status code
- `headers` — response headers as an array
- `finalUrl` — final URL after redirection or transport handling
- `body` — raw response body string

### 9.2 Response methods

#### `text(): string`
Returns the raw body as text.

#### `json(): array`
Attempts JSON decoding and returns an array.

#### `arrayBuffer(): string`
Returns the raw binary content as a string.

#### `blob(): string`
Returns the raw binary content as a string.

#### `asStream(): ReadableStream`
Returns a readable stream wrapper around the body.

#### `isStreamed(): bool`
Returns `true` if the response was produced with a stream object.

### 9.3 Examples

#### Text response

```php
$response = $client->get('https://example.com', new RequestOptions([
    'responseType' => 'text',
]))->await();

echo $response->text();
```

#### JSON response

```php
$response = $client->get('https://httpbin.org/json', new RequestOptions([
    'responseType' => 'json',
]))->await();

$data = $response->json();
```

#### Binary response

```php
$response = $client->get('https://example.com/file.bin', new RequestOptions([
    'responseType' => 'arraybuffer',
]))->await();

file_put_contents(__DIR__ . '/file.bin', $response->arrayBuffer());
```

#### Stream response

```php
$response = $client->get('https://example.com/large-file', new RequestOptions([
    'responseType' => 'stream',
]))->await();

$stream = $response->asStream();
while (($chunk = $stream->read()) !== null) {
    echo $chunk;
}
```

---

## 10. Request handle

All request methods return an `AsyncRequestHandle`.

### Methods

- `await(): Response`
- `future(): Future`
- `getId(): string`

### Example

```php
$handle = $client->get('https://httpbin.org/get', new RequestOptions());

// Do other work here...

$response = $handle->await();
```

This style lets your application structure work around asynchronous request submission.

---

## 11. Streaming behavior

Streaming is useful for:

- large downloads
- incremental parsing
- log processing
- media delivery
- server-sent chunk processing

### Stream response example

```php
$response = $client->get('https://speed.hetzner.de/100MB.bin', new RequestOptions([
    'responseType' => 'stream',
]))->await();

$stream = $response->asStream();
while (null !== $chunk = $stream->read()) {
    // Process chunk immediately
    echo strlen($chunk) . " bytes\n";
}
```

### Important note

The stream interface is designed for consumption after the response resolves. For very large payloads, use streaming and process chunks as they arrive.

---

## 12. Multipart and file upload scenarios

### 12.1 Single file upload

```php
$response = $client->post('https://example.com/upload', new RequestOptions([
    'body' => [
        'file' => [
            'path' => __DIR__ . '/image.jpg',
            'filename' => 'image.jpg',
            'mime' => 'image/jpeg',
        ],
    ],
]))->await();
```

### 12.2 Mixed form fields and files

```php
$response = $client->post('https://example.com/upload', new RequestOptions([
    'body' => [
        'title' => 'Project file',
        'description' => 'Uploaded from PHP',
        'document' => [
            'path' => __DIR__ . '/report.pdf',
            'filename' => 'report.pdf',
            'mime' => 'application/pdf',
        ],
    ],
]))->await();
```

### 12.3 Multipart using explicit marker

```php
$response = $client->post('https://example.com/upload', new RequestOptions([
    'body' => [
        '_multipart' => true,
        'name' => 'Ali',
        'avatar' => [
            'filename' => 'avatar.png',
            'mime' => 'image/png',
            'content' => file_get_contents(__DIR__ . '/avatar.png'),
        ],
    ],
]))->await();
```

---

## 13. TLS / fingerprint customization

AstraHTTP PHP exposes fields for advanced transport fingerprinting.

### Common fields

- `ja3`
- `ja4r`
- `http2Fingerprint`
- `quicFingerprint`
- `disableGrease`

### Example

```php
$options = new RequestOptions([
    'ja4r' => '771,4865-4866-4867,0-10-11-13-16,29-23-24,0',
    'http2Fingerprint' => 'SETTINGS_ENABLE_PUSH=0',
    'disableGrease' => true,
]);
```

### When to use these

Use fingerprint settings when you need:

- stable transport identity
- controlled TLS behavior
- special upstream compatibility
- advanced traffic shaping

---

## 14. Retry and failover behavior

The wrapper includes local retry logic for safe methods and configurable retry policies.

### Default behavior

If `retryable` is not set:

- idempotent methods such as GET, HEAD, OPTIONS, TRACE may be retried
- unsafe methods are not retried automatically

### Force retry

```php
new RequestOptions([
    'retryable' => true,
    'maxRetries' => 5,
    'retryDelayMs' => 250,
]);
```

### Disable retry

```php
new RequestOptions([
    'retryable' => false,
]);
```

### Practical note

Retry is useful when the worker reconnects or the shared transport is interrupted. The library keeps request-level state separate through request identifiers.

---

## 15. Shared worker model

The library uses a shared-worker concept tied to a port.

### What this means

- Multiple PHP clients can point to the same worker port
- The worker manager keeps a reference count
- The transport can reconnect after a disconnect
- The runtime can attempt to attach to an existing worker first

### Why this matters

This reduces repeated startup costs and makes the wrapper suitable for long-running services and concurrent workloads.

### Example

```php
$clientA = new Client(['port' => 9119]);
$clientB = new Client(['port' => 9119]);
```

Both clients will share the same worker runtime in the same process space.

---

## 16. WebSocket usage

### Basic WebSocket request

```php
$socket = $client->websocket('wss://echo.example.com/socket', new RequestOptions([
    'protocol' => 'websocket',
]))->await();
```

### Notes

The library exposes the protocol field so your worker can treat the request as a WebSocket upgrade or a WS transport flow.

### Alias

`ws()` is an alias for `websocket()`.

---

## 17. SSE usage

### Basic SSE request

```php
$sse = $client->sse('https://example.com/events', new RequestOptions([
    'protocol' => 'sse',
]))->await();
```

### Alias

`eventSource()` is an alias for `sse()`.

### Typical use cases

- server push updates
- live event feeds
- progress streams
- dashboard refresh channels

---

## 18. Cookies

Cookies are normalized into the transport-friendly structure expected by the worker.

### Associative array example

```php
new RequestOptions([
    'cookies' => [
        'session' => 'abc123',
        'lang' => 'en',
    ],
]);
```

### Array of objects example

```php
new RequestOptions([
    'cookies' => [
        ['name' => 'session', 'value' => 'abc123'],
        ['name' => 'lang', 'value' => 'en'],
    ],
]);
```

---

## 19. Common usage patterns

### 19.1 Simple GET

```php
$response = $client->get('https://httpbin.org/get')->await();
echo $response->text();
```

### 19.2 POST JSON

```php
$response = $client->post('https://httpbin.org/post', new RequestOptions([
    'headers' => ['Content-Type' => 'application/json'],
    'body' => ['name' => 'Ali'],
    'responseType' => 'json',
]))->await();
```

### 19.3 Custom headers

```php
$response = $client->get('https://example.com', new RequestOptions([
    'headers' => [
        'Accept' => 'text/html',
        'Cache-Control' => 'no-cache',
    ],
]))->await();
```

### 19.4 Custom user agent

```php
$response = $client->get('https://example.com', new RequestOptions([
    'userAgent' => 'Mozilla/5.0 ...',
]))->await();
```

### 19.5 Proxy

```php
$response = $client->get('https://example.com', new RequestOptions([
    'proxy' => 'http://127.0.0.1:8080',
]))->await();
```

### 19.6 Ignore TLS verification

```php
$response = $client->get('https://self-signed.example.local', new RequestOptions([
    'insecureSkipVerify' => true,
]))->await();
```

### 19.7 Force HTTP/1

```php
$response = $client->get('https://example.com', new RequestOptions([
    'forceHTTP1' => true,
]))->await();
```

### 19.8 Long timeout

```php
$response = $client->get('https://example.com/slow', new RequestOptions([
    'timeout' => 30000,
]))->await();
```

---

## 20. Error handling

Wrap requests in `try / catch` blocks whenever failures are possible.

### Example

```php
try {
    $response = $client->get('https://example.com', new RequestOptions([
        'timeout' => 5000,
    ]))->await();

    echo $response->text();
} catch (\Throwable $e) {
    echo 'Request failed: ' . $e->getMessage();
}
```

### Typical error sources

- worker binary not found
- worker not reachable
- request timeout
- invalid response parsing
- transport disconnect during request
- malformed multipart file path

---

## 21. Practical examples

### Example A: read JSON API

```php
$response = $client->get('https://api.github.com', new RequestOptions([
    'headers' => [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'AstraHTTP PHP',
    ],
    'responseType' => 'json',
]))->await();

$data = $response->json();
```

### Example B: upload a file

```php
$response = $client->post('https://example.com/upload', new RequestOptions([
    'body' => [
        'file' => [
            'path' => __DIR__ . '/report.pdf',
            'filename' => 'report.pdf',
            'mime' => 'application/pdf',
        ],
    ],
]))->await();
```

### Example C: stream a large download

```php
$response = $client->get('https://example.com/big.bin', new RequestOptions([
    'responseType' => 'stream',
]))->await();

$stream = $response->asStream();
while (($chunk = $stream->read()) !== null) {
    file_put_contents(__DIR__ . '/big.bin', $chunk, FILE_APPEND);
}
```

### Example D: set TLS fingerprint

```php
$response = $client->get('https://example.com', new RequestOptions([
    'ja3' => '771,4865-4867-4866-49195-49199,...',
    'disableGrease' => true,
]))->await();
```

### Example E: event stream

```php
$sse = $client->sse('https://example.com/events')->await();
```

---

## 22. Recommended production practices

- always create the client once and reuse it where possible
- close the client explicitly at shutdown
- set a meaningful timeout on every external request
- use streaming for large bodies
- keep multipart file paths validated before use
- prefer explicit `RequestOptions` objects in shared codebases
- keep the worker binary under version control for deployments
- handle `\Throwable` around awaited requests

---

## 23. Minimal full example

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astra\SwooleHttp\Client;
use Astra\SwooleHttp\Contract\RequestOptions;

$client = new Client([
    'port' => 9119,
    'debug' => false,
]);

try {
    $response = $client->get('https://httpbin.org/get', new RequestOptions([
        'responseType' => 'json',
        'headers' => [
            'Accept' => 'application/json',
        ],
    ]))->await();

    echo $response->text() . PHP_EOL;
} finally {
    $client->close();
}
```

---

## 24. Summary

AstraHTTP PHP is best used when you need:

- a stable worker shared across requests
- customizable TLS and transport behavior
- high-performance PHP orchestration
- streaming-aware response handling
- flexible request body encoding
- simple object-based request configuration

The package is designed to feel like a modern async client while still fitting naturally into PHP 8.2+ applications.

