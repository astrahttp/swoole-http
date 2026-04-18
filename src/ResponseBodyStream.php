<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

use Swoole\Coroutine\Channel;

final class ResponseBodyStream
{
    private Channel $channel;
    private bool $closed = false;

    public function __construct() {
        // إنشاء قناة تستوعب 100 حزمة (Chunk) في الذاكرة
        $this->channel = new Channel(100);
    }

    public function push(string $chunk): void {
        if ($this->closed) return;
        $this->channel->push($chunk);
    }

    public function end(): void {
        if ($this->closed) return;
        $this->closed = true;
        $this->channel->push(false); // إشارة انتهاء التحميل
    }

    public function fail(\Throwable $e): void {
        if ($this->closed) return;
        $this->closed = true;
        $this->channel->push($e);
    }

    public function read(): ?string {
        $data = $this->channel->pop(-1); // انتظار لا نهائي للحزمة القادمة
        
        if ($data instanceof \Throwable) throw $data;
        if ($data === false) return null; // انتهى الاستريم
        
        return $data;
    }

    public function close(): void {
        $this->closed = true;
        $this->channel->close();
    }
}
