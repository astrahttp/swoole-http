<?php
declare(strict_types=1);

namespace Astra\SwooleHttp;

use Astra\SwooleHttp\Exception\AstraException;

final class MultipartEncoder
{
    /**
     * @return array{0:string,1:array<string,string>}
     */
    public static function encode(array $fields): array
    {
        // Changed Boundary to AstraHTTP
        $boundary = '----AstraHTTPBoundary' . bin2hex(random_bytes(12));
        $chunks = [];

        foreach ($fields as $name => $value) {
            if ($name === '_multipart') {
                continue;
            }

            if (is_array($value) && (isset($value['filename']) || isset($value['content']) || isset($value['path']))) {
                $filename = (string)($value['filename'] ?? basename((string)($value['path'] ?? 'upload.bin')));
                $mime = (string)($value['mime'] ?? 'application/octet-stream');
                $content = '';

                if (isset($value['path'])) {
                    $content = file_get_contents((string) $value['path']);
                    if ($content === false) {
                        throw new AstraException('Failed to read multipart file: ' . $value['path']);
                    }
                } else {
                    $content = (string)($value['content'] ?? '');
                }

                $chunks[] = "--{$boundary}\r\n";
                $chunks[] = 'Content-Disposition: form-data; name="' . self::escape((string) $name) . '"; filename="' . self::escape($filename) . "\"\r\n";
                $chunks[] = 'Content-Type: ' . $mime . "\r\n\r\n";
                $chunks[] = $content . "\r\n";
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $chunks[] = "--{$boundary}\r\n";
            $chunks[] = 'Content-Disposition: form-data; name="' . self::escape((string) $name) . "\"\r\n\r\n";
            $chunks[] = (string) $value . "\r\n";
        }

        $chunks[] = "--{$boundary}--\r\n";

        return [
            implode('', $chunks),
            ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
        ];
    }

    private static function escape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['\\"', '', ''], $value);
    }
}
