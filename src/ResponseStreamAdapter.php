<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Traversable;
use IteratorAggregate;

final class ResponseStreamAdapter implements ReadableStream, IteratorAggregate
{
    /** @var list<\Closure> */
    private array $onCloseListeners = [];
    private bool $isClosed = false;

    public function __construct(private ResponseBodyStream $stream) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->stream->read($cancellation);
    }

    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }
        $this->isClosed = true;
        $this->stream->close();

        foreach ($this->onCloseListeners as $listener) {
            $listener();
        }
        $this->onCloseListeners = [];
    }

    public function isReadable(): bool
    {
        return !$this->isClosed;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->isClosed) {
            $onClose();
            return;
        }
        $this->onCloseListeners[] = $onClose;
    }

    public function getIterator(): Traversable
    {
        while (null !== $chunk = $this->read()) {
            yield $chunk;
        }
    }
}
