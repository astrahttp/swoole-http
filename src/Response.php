<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $finalUrl,
        public readonly string $body = '',
        private ?ResponseBodyStream $stream = null
    ) {}

    public function text(): string
    {
        return $this->body;
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function arrayBuffer(): string
    {
        return $this->body;
    }

    public function blob(): string
    {
        return $this->body;
    }

    public function asStream(): ReadableStream
    {
        return $this->stream ? new ResponseStreamAdapter($this->stream) : new ReadableBuffer($this->body);
    }

    public function isStreamed(): bool
    {
        return $this->stream !== null;
    }

    public function withStream(ResponseBodyStream $stream): self
    {
        return new self($this->status, $this->headers, $this->finalUrl, $this->body, $stream);
    }
}
