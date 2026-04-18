<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

use Amp\DeferredFuture;
use Amp\ByteStream\ReadableStream;
use Astra\SwooleHttp\Contract\RequestOptions;
use Astra\SwooleHttp\Contract\AsyncRequestHandle;
use Astra\SwooleHttp\Runtime\WorkerManager;
use Astra\SwooleHttp\Runtime\WorkerRuntime;
use Astra\SwooleHttp\Runtime\WorkerTransport;
use Astra\SwooleHttp\Exception\AstraException;
use Revolt\EventLoop;
use Throwable;

final class Client
{
    private WorkerRuntime $runtime;
    private WorkerTransport $transport;
    private bool $closed = false;
    private string $clientId;

    /** @var array<string, array> */
    private array $pending = [];

    /** @var string[] */
    private array $sendQueue = [];

    public function __construct(array $config = [])
    {
        $port = (int)($config['port'] ?? 9119);
        $debug = (bool)($config['debug'] ?? false);
        $workerPath = $config['workerPath'] ?? null;

        $this->runtime = WorkerManager::getInstance()->acquire($port, $debug, is_string($workerPath) ? $workerPath : null);
        $this->clientId = bin2hex(random_bytes(4));
if (class_exists(\Revolt\EventLoop::class)) {
            \Revolt\EventLoop::repeat(1, function () {
                // أنا حي أرزق، لا تغلق الـ Event Loop!
            });
        }
        $this->transport = new WorkerTransport($this->runtime);

        $this->transport->onMessage(function (string $message): void {
            $this->handleMessage($message);
        });

        $this->transport->onConnect(function (): void {
            $this->flushSendQueue();
        });

        $this->transport->onDisconnect(function (string $reason): void {
            $this->handleTransportDisconnect($reason);
        });
    }

    public function requestAsync(string $method, string $url, ?RequestOptions $options = null): AsyncRequestHandle
    {
        if ($this->closed) {
            throw new AstraException('Client is closed.');
        }

        $options ??= new RequestOptions();
        $requestId = $this->clientId . ':' . bin2hex(random_bytes(12));
        //$deferred = new DeferredFuture();
        $channel = new \Swoole\Coroutine\Channel(1);
        $this->pending[$requestId] = [
            //'deferred' => $deferred,
            'channel' => $channel, 
            'body' => '',
            'meta' => null,
            'sent' => false,
            'attempt' => 0,
            'timeoutTimer' => null,
            'method' => strtoupper($method),
            'url' => $url,
            'options' => $options,
            'stream' => null,
        ];

        if (($options->timeout ?? 0) > 0) {
            $timeoutMs = (int) $options->timeout;
            $this->pending[$requestId]['timeoutTimer'] = EventLoop::delay(
                $timeoutMs / 1000,
                function () use ($requestId, $timeoutMs): void {
                    if (isset($this->pending[$requestId])) {
                        $this->rejectRequest($requestId, "Request timeout after {$timeoutMs}ms.");
                    }
                }
            );
        }

        $this->queueForSend($requestId);
        $this->flushSendQueue();

//        return new AsyncRequestHandle($deferred->getFuture(), $requestId, $this);
return new AsyncRequestHandle($channel, $requestId, $this);
    }

    public function awaitHandle2(AsyncRequestHandle $handle): Response
    {
        $result = $handle->future()->await();
        if (!$result instanceof Response) {
            throw new AstraException('No response was produced by the worker.');
        }
        return $result;
    }

    public function awaitHandle(AsyncRequestHandle $handle): Response
    {
        $requestId = $handle->getId();
        
        // جلب الـ Timeout من الإعدادات (أو -1 للانتظار للأبد)
        $timeoutMs = $this->pending[$requestId]['options']->timeout ?? 0;
        $popTimeout = $timeoutMs > 0 ? ($timeoutMs / 1000) : -1;

        // سحر Swoole: ننتظر البيانات من القناة، مع مهلة زمنية!
        $result = $handle->channel()->pop($popTimeout);

        if ($result === false && $handle->channel()->errCode === SWOOLE_CHANNEL_TIMEOUT) {
            $this->settleRequest($requestId);
            throw new AstraException("Request timeout after {$timeoutMs}ms");
        }

        if ($result instanceof \Throwable) {
            throw $result;
        }

        if (!$result instanceof Response) {
            throw new AstraException('No response was produced by the worker.');
        }

        return $result;
    }



    private function clearRequestTimer(string $requestId): void
    {
        if (!isset($this->pending[$requestId])) return;

        $timer = $this->pending[$requestId]['timeoutTimer'] ?? null;
        if ($timer !== null) {
            try {
                EventLoop::cancel($timer);
            } catch (Throwable) {}
            $this->pending[$requestId]['timeoutTimer'] = null;
        }
    }

    private function settleRequest(string $requestId): void
    {
        $this->clearRequestTimer($requestId);
        unset($this->pending[$requestId]);
        $this->sendQueue = array_values(array_filter($this->sendQueue, static fn (string $id) => $id !== $requestId));
    }

    private function rejectRequest(string $requestId, string $reason): void
    {
        if (!isset($this->pending[$requestId])) return;

        // دفع الخطأ داخل القناة ليستلمه الـ await()
        $this->pending[$requestId]['channel']->push(new AstraException($reason));

        $options = $this->pending[$requestId]['options'];
        if (is_callable($options->onError)) {
            ($options->onError)(new AstraException($reason), $requestId);
        }

        $this->settleRequest($requestId);
    }


    private function rejectRequest2(string $requestId, string $reason): void
    {
        if (!isset($this->pending[$requestId])) return;

        /** @var DeferredFuture $deferred */
        $deferred = $this->pending[$requestId]['deferred'];
        $deferred->error(new AstraException($reason));

        $options = $this->pending[$requestId]['options'];
        if (is_callable($options->onError)) {
            ($options->onError)(new AstraException($reason), $requestId);
        }

        $this->settleRequest($requestId);
    }

    private function shouldRetry(string $method, RequestOptions $options): bool
    {
        if ($options->retryable === true) return true;
        if ($options->retryable === false) return false;
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }

    private function backoffMs(int $attempt, RequestOptions $options): int
    {
        $base = max(50, (int)($options->retryDelayMs ?? 250));
        $delay = (int)($base * (2 ** max(0, $attempt - 1)));
        return min(5000, $delay);
    }

    private function handleTransportDisconnect(string $reason): void
    {
        if ($this->closed) return;

        foreach ($this->pending as $requestId => $state) {
            if (!$state['sent']) continue;

            $method = $state['method'];
            $options = $state['options'];

            if (!$this->shouldRetry($method, $options) || $state['attempt'] >= (int)($options->maxRetries ?? 0)) {
                $this->rejectRequest($requestId, $reason);
                continue;
            }

            $this->pending[$requestId]['attempt']++;
            $this->pending[$requestId]['sent'] = false;
            $this->pending[$requestId]['body'] = '';
            $this->pending[$requestId]['meta'] = null;

            $delayMs = $this->backoffMs($this->pending[$requestId]['attempt'], $options);
            EventLoop::delay($delayMs / 1000, function () use ($requestId): void {
                if (isset($this->pending[$requestId]) && !$this->closed) {
                    $this->queueForSend($requestId);
                    $this->flushSendQueue();
                }
            });
        }
    }

    private function handleJsonMessage(array $decoded): void
    {
        if (!isset($decoded['RequestID'])) return;

        $rid = (string) $decoded['RequestID'];
        if (!str_starts_with($rid, $this->clientId . ':') || !isset($this->pending[$rid])) {
            return;
        }

        /** @var RequestOptions $options */
        $options = $this->pending[$rid]['options'];

        if (($decoded['Success'] ?? true) === false) {
            $errorMessage = (string)($decoded['Error'] ?? 'Unknown error');
            $this->rejectRequest($rid, $errorMessage);
            return;
        }

        $resData = $decoded['Response'] ?? [];
        $meta = [
            'status' => (int)($resData['Status'] ?? 200),
            'headers' => (array)($resData['Headers'] ?? []),
            'url' => (string)($resData['Url'] ?? ''),
        ];

        $this->pending[$rid]['meta'] = $meta;
        if (is_callable($options->onHeaders)) {
            ($options->onHeaders)($meta, $rid);
        }

        $body = (string)($resData['Body'] ?? '');
        $stream = new ResponseBodyStream();
        $stream->push($body);
        $stream->end();

        $response = new Response($meta['status'], $meta['headers'], $meta['url'], $body, $stream);
        //$this->pending[$rid]['deferred']->complete($response);
$this->pending[$rid]['channel']->push($response);
        if (is_callable($options->onComplete)) {
            ($options->onComplete)($response, $rid);
        }

        $this->settleRequest($rid);
    }

    private function handleBinaryMessage(string $message): void
    {
        $buffer = new PacketBuffer($message);
        $requestId = $buffer->readString();

        if (!str_starts_with($requestId, $this->clientId . ':') || !isset($this->pending[$requestId])) {
            return;
        }

        $method = $buffer->readString();
        $options = $this->pending[$requestId]['options'];
        $state = &$this->pending[$requestId];

        switch ($method) {
            case 'response':
                $statusCode = $buffer->readU16();
                $finalUrl = $buffer->readString();
                $headersLength = $buffer->readU16();
                $headers = [];
                for ($i = 0; $i < $headersLength; $i++) {
                    $headerName = $buffer->readString();
                    $valuesLength = $buffer->readU16();
                    $values = [];
                    for ($j = 0; $j < $valuesLength; $j++) {
                        $values[] = $buffer->readString();
                    }
                    $headers[$headerName] = $values;
                }
                $state['meta'] = ['status' => $statusCode, 'headers' => $headers, 'url' => $finalUrl];
                if (is_callable($options->onHeaders)) ($options->onHeaders)($state['meta'], $requestId);
                break;

            case 'data':
                $chunk = $buffer->readBytes();
                $state['body'] .= $chunk;
                if (is_callable($options->onChunk)) ($options->onChunk)($chunk, $requestId, $state['meta']);
                break;

            case 'error':
                $statusCode = $buffer->readU16();
                $errorMessage = $buffer->readString();
                $stream = $state['stream'] ?? null;
                if ($stream) $stream->fail(new AstraException($errorMessage));
                $this->rejectRequest($requestId, "[{$statusCode}] {$errorMessage}");
                break;

            case 'end':
                $meta = $state['meta'] ?? ['status' => 200, 'headers' => [], 'url' => $state['url']];
                $stream = new ResponseBodyStream();
                if ($state['body'] !== '') $stream->push($state['body']);
                $stream->end();
                $state['stream'] = $stream;
                
                $responseType = strtolower((string)($options->responseType ?? 'json'));
                $response = new Response((int)$meta['status'], (array)$meta['headers'], (string)$meta['url'], $state['body'], $stream);
                if ($responseType === 'stream') $response = $response->withStream($stream);

                //$this->pending[$requestId]['deferred']->complete($response);
$this->pending[$requestId]['channel']->push($response);
                if (is_callable($options->onComplete)) ($options->onComplete)($response, $requestId);
                $this->settleRequest($requestId);
                break;
        }
    }

    private function handleMessage(string $message): void
    {
        if ($message === '') return;

        $trim = ltrim($message);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($message, true);
            if (is_array($decoded)) {
                $this->handleJsonMessage($decoded);
                return;
            }
        }
        $this->handleBinaryMessage($message);
    }

    private function queueForSend(string $requestId): void
    {
        if (!in_array($requestId, $this->sendQueue, true)) {
            $this->sendQueue[] = $requestId;
        }
    }

    private function flushSendQueue(): void
    {
        if (!$this->transport->isConnected() || $this->closed) return;

        $queue = $this->sendQueue;
        $this->sendQueue = [];

        foreach ($queue as $requestId) {
            if (isset($this->pending[$requestId]) && !$this->pending[$requestId]['sent']) {
                $this->dispatchRequest($requestId);
            }
        }
    }

    private function dispatchRequest(string $requestId): void
    {
        if (!isset($this->pending[$requestId])) return;

        if (!$this->transport->isConnected()) {
            $this->queueForSend($requestId);
            return;
        }

        $state = &$this->pending[$requestId];
        $payload = [
            'requestId' => $requestId,
            'options' => $this->prepareOptions($state['url'], $state['method'], $state['options']),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $this->rejectRequest($requestId, 'Failed to encode request payload.');
            return;
        }

        try {
            $this->transport->send($encoded);
            $state['sent'] = true;
            $this->sendQueue = array_values(array_filter($this->sendQueue, static fn (string $id) => $id !== $requestId));
        } catch (Throwable) {
            $this->queueForSend($requestId);
        }
    }

    private function prepareOptions(string $url, string $method, RequestOptions $options): array
    {
        $optsArray = array_replace_recursive([], $options->toTransportArray());
        $optsArray['url'] = $url;
        $optsArray['method'] = strtoupper($method);
        $optsArray['protocol'] = $optsArray['protocol'] ?? '';
        $optsArray['responseType'] = $optsArray['responseType'] ?? 'json';

        if (empty($optsArray['ja3']) && empty($optsArray['ja4r']) && empty($optsArray['http2Fingerprint']) && empty($optsArray['quicFingerprint'])) {
            $optsArray['ja3'] = '771,4865-4867-4866-49195-49199-52393-52392-49196-49200-49162-49161-49171-49172-51-57-47-53-10,0-23-65281-10-11-35-16-5-51-43-13-45-28-21,29-23-24-25-256-257,0';
        }

        if (!isset($optsArray['userAgent'])) {
            $optsArray['userAgent'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.54 Safari/537.36';
        }

        if (!isset($optsArray['body'])) $optsArray['body'] = '';

        if (isset($optsArray['body']) && $optsArray['body'] instanceof ReadableStream) {
            $buffer = '';
            while (($chunk = $optsArray['body']->read()) !== null) {
                $buffer .= $chunk;
            }
            $optsArray['body'] = $buffer;
        }

        return $optsArray;
    }

    public function get(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('GET', $url, $options); }
    public function post(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('POST', $url, $options); }
    public function put(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('PUT', $url, $options); }
    public function patch(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('PATCH', $url, $options); }
    public function delete(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('DELETE', $url, $options); }
    public function head(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('HEAD', $url, $options); }
    public function options(string $url, ?RequestOptions $options = null): AsyncRequestHandle { return $this->requestAsync('OPTIONS', $url, $options); }
    
    public function websocket(string $url, ?RequestOptions $options = null): AsyncRequestHandle
    {
        $options ??= new RequestOptions();
        $options->protocol = 'websocket';
        return $this->requestAsync('GET', $url, $options);
    }

    public function sse(string $url, ?RequestOptions $options = null): AsyncRequestHandle
    {
        $options ??= new RequestOptions();
        $options->protocol = 'sse';
        return $this->requestAsync('GET', $url, $options);
    }

    public function close(): void
    {
        if ($this->closed) return;
        $this->closed = true;

        foreach ($this->pending as $requestId => $_state) {
            $this->clearRequestTimer($requestId);
        }

        $this->pending = [];
        $this->sendQueue = [];

        $this->transport->close();
        WorkerManager::getInstance()->release($this->runtime->port);
    }

    public function __destruct() { $this->close(); }
}
