<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

use Astra\SwooleHttp\Exception\AstraException;

final class PacketBuffer
{
    public int $offset = 0;

    public function __construct(public string $data) {}

    private function ensure(int $n): void
    {
        if ($this->offset + $n > strlen($this->data)) {
            throw new AstraException('Packet underflow while parsing worker message.');
        }
    }

    public function readU8(): int
    {
        $this->ensure(1);
        return ord($this->data[$this->offset++]);
    }

    public function readU16(): int
    {
        return ($this->readU8() << 8) | $this->readU8();
    }

    public function readU32(): int
    {
        return ($this->readU8() << 24) | ($this->readU8() << 16) | ($this->readU8() << 8) | $this->readU8();
    }

    public function readString(): string
    {
        $len = $this->readU16();
        if ($len <= 0) { return ''; }

        $this->ensure($len);
        $s = substr($this->data, $this->offset, $len);
        $this->offset += $len;

        return $s;
    }

    public function readBytes(): string
    {
        $len = $this->readU32();
        if ($len <= 0) { return ''; }

        $this->ensure($len);
        $b = substr($this->data, $this->offset, $len);
        $this->offset += $len;

        return $b;
    }
}
