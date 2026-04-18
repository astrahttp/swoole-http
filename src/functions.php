<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

function initAstraHTTP(array $config = []): Client
{
    return new Client($config);
}
