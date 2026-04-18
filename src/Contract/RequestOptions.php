<?php
declare(strict_types=1);

namespace Astra\SwooleHttp\Contract;

use Amp\ByteStream\ReadableStream;
use Astra\SwooleHttp\MultipartEncoder;
use Astra\SwooleHttp\Exception\AstraException;

final class RequestOptions
{
    public ?array $headers = null;
    public array|object|null $cookies = null;
    public mixed $body = null;
    public ?string $responseType = null; // json|text|arraybuffer|blob|stream
    public ?string $ja3 = null;
    public ?string $ja4r = null;
    public ?string $http2Fingerprint = null;
    public ?string $quicFingerprint = null;
    public ?bool $disableGrease = null;
    public ?string $userAgent = null;
    public ?string $serverName = null;
    public ?string $proxy = null;
    public ?int $timeout = null;
    public ?bool $disableRedirect = null;
    public ?array $headerOrder = null;
    public ?bool $orderAsProvided = null;
    public ?bool $insecureSkipVerify = null;
    public ?bool $forceHTTP1 = null;
    public ?bool $forceHTTP3 = null;
    public ?string $protocol = null; // http1|http2|http3|websocket|sse
    public ?int $maxRetries = 2;
    public ?int $retryDelayMs = 250;
    public ?bool $retryable = null;

    public mixed $onHeaders = null;
    public mixed $onChunk = null;
    public mixed $onComplete = null;
    public mixed $onError = null;
    public ?bool $enableConnectionReuse = true;

    public function __construct(array $options = [])
    {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    public function toTransportArray(): array
    {
        $vars = get_object_vars($this);
        unset($vars['onHeaders'], $vars['onChunk'], $vars['onComplete'], $vars['onError'], $vars['maxRetries'], $vars['retryDelayMs'], $vars['retryable']);

        if (isset($vars['cookies']) && is_array($vars['cookies'])) {
            $formattedCookies = [];
            foreach ($vars['cookies'] as $key => $value) {
                if (is_string($key)) {
                    $formattedCookies[] = ['name' => (string) $key, 'value' => (string) $value];
                } else {
                    $formattedCookies[] = $value;
                }
            }
            $vars['cookies'] = $formattedCookies;
        } elseif (isset($vars['cookies']) && is_object($vars['cookies'])) {
            $formattedCookies = [];
            foreach (get_object_vars($vars['cookies']) as $name => $value) {
                $formattedCookies[] = ['name' => (string) $name, 'value' => (string) $value];
            }
            $vars['cookies'] = $formattedCookies;
        }

        if (array_key_exists('body', $vars)) {
            $headers = $vars['headers'] ?? null;
            $vars['body'] = self::normalizeBody($vars['body'], $headers);
            $vars['headers'] = $headers;
        }

        return array_filter($vars, static fn ($v) => $v !== null);
    }

    private static function normalizeBody(mixed $body, ?array &$headers = null): mixed
    {
        if ($body instanceof \Stringable) {
            return (string) $body;
        }

        if ($body instanceof ReadableStream) {
            return $body;
        }

        if (is_array($body)) {
            if (self::looksLikeMultipart($body)) {
                [$multipartBody, $multipartHeaders] = MultipartEncoder::encode($body);
                $headers = array_replace($headers ?? [], $multipartHeaders);
                return $multipartBody;
            }

            if (!isset($headers['Content-Type']) && !isset($headers['content-type'])) {
                $headers['Content-Type'] = 'application/json; charset=utf-8';
            }

            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new AstraException('Failed to JSON-encode request body.');
            }
            return $json;
        }

        return $body;
    }

    private static function looksLikeMultipart(array $body): bool
    {
        if (($body['_multipart'] ?? false) === true) {
            return true;
        }

        foreach ($body as $value) {
            if (is_array($value) && (isset($value['filename']) || isset($value['content']) || isset($value['path']) || isset($value['mime']))) {
                return true;
            }
        }
        return false;
    }
}
