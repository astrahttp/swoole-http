<?php
declare(strict_types=1);

namespace Astra\SwooleHttp\Runtime;

use Swoole\Coroutine\Http\Client;
use Astra\SwooleHttp\Exception\WorkerException;
use Swoole\Timer;

final class WorkerTransport
{
    private ?Client $ws = null;
    private bool $closed = false;
    private bool $connecting = false;
    private bool $isConnected = false;
    private mixed $onMessage = null;
    private mixed $onConnect = null;
    private mixed $onDisconnect = null;

    public function __construct(private WorkerRuntime $runtime)
    {
        $this->connect();
    }

    public function onMessage(callable $handler): void { $this->onMessage = $handler; }
    public function onConnect(callable $handler): void { $this->onConnect = $handler; }
    public function onDisconnect(callable $handler): void { $this->onDisconnect = $handler; }

    public function isConnected(): bool
    {
        return $this->isConnected && $this->ws !== null;
    }

    public function send(string $payload): void
    {
        if (!$this->isConnected() || !$this->ws) {
            throw new WorkerException('Transport is not connected yet.');
        }
        
        // دالة push في Swoole آمنة تماماً ضد الكتابة المتزامنة (Coroutine Safe)
        $this->ws->push($payload);
    }

    private function emitConnect(): void
    {
        if (is_callable($this->onConnect)) {
            ($this->onConnect)();
        }
    }

    private function emitDisconnect(string $reason): void
    {
        if (is_callable($this->onDisconnect)) {
            ($this->onDisconnect)($reason);
        }
    }

    private function connect(): void
    {
        if ($this->closed || $this->connecting) {
            return;
        }

        $this->connecting = true;

        // استخدام Swoole Coroutine بدلاً من Amp Async
        go(function () {
            try {
                $client = new Client('127.0.0.1', $this->runtime->port);
                $ret = $client->upgrade('/'); // ترقية الاتصال إلى WebSocket

                if (!$ret) {
                    $this->handleDown('WebSocket upgrade failed');
                    return;
                }

                $this->ws = $client;
                $this->isConnected = true;
                $this->connecting = false;
                $this->emitConnect();

                // حلقة الاستماع للرسائل (لا تستهلك CPU لأنها معلقة في الـ Epoll)
                while (true) {
                    $frame = $client->recv(-1); // انتظار لا نهائي
                    
                    if ($frame === false || $frame === "") {
                        $this->handleDown('WebSocket closed by worker');
                        break;
                    }
                    
                    if ($frame->opcode === WEBSOCKET_OPCODE_TEXT || $frame->opcode === WEBSOCKET_OPCODE_BINARY) {
                        if (is_callable($this->onMessage)) {
                            ($this->onMessage)($frame->data);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->handleDown('Exception: ' . $e->getMessage());
            }
        });
    }

    private function handleDown(string $reason): void
    {
        if ($this->closed) return;

        $wasConnected = $this->isConnected;
        $this->isConnected = false;
        
        if ($this->ws) {
            @$this->ws->close();
        }
        
        $this->ws = null;
        $this->connecting = false;

        if ($wasConnected) {
            $this->emitDisconnect($reason);
        }

        try {
            $this->runtime->spawnOrAttach();
        } catch (\Throwable) {}

        // إعادة المحاولة بعد ثانية باستخدام Swoole Timer
        Timer::after(1000, function () {
            $this->connect();
        });
    }

    public function close(): void
    {
        $this->closed = true;
        $this->isConnected = false;
        if ($this->ws) {
            @$this->ws->close();
        }
        $this->ws = null;
    }
}
