<?php
declare(strict_types=1);

namespace Astra\SwooleHttp\Contract;

use Astra\SwooleHttp\Client;
use Astra\SwooleHttp\Response;
use Swoole\Coroutine\Channel;

final class AsyncRequestHandle
{
    public function __construct(
        private Channel $channel,
        private string $id,
        private Client $client
    ) {}

    public function await(): Response
    {
        return $this->client->awaitHandle($this);
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
