<?php

declare(strict_types=1);

namespace Astra\SwooleHttp;

use RuntimeException;

final class WorkerDownloader
{
    private const REPO = 'astrahttp/http';
    private const VERSION = 'v2.0.0';

    public static function install(): void
    {
        $os = self::getOsName();
        $arch = self::getArchName();

        $execDir = self::getExecDir();
        $fileName = self::buildFileName($os, $arch);
        $savePath = $execDir . DIRECTORY_SEPARATOR . $fileName;
        $url = self::buildDownloadUrl($fileName);

        self::ensureDirectory($execDir);

        if (is_file($savePath) && is_executable($savePath)) {
            return;
        }

        $content = self::download($url);

        if ($content === '') {
            throw new RuntimeException('Downloaded worker is empty.');
        }

        if (file_put_contents($savePath, $content, LOCK_EX) === false) {
            throw new RuntimeException("Could not write worker to: {$savePath}");
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($savePath, 0755);
        }

        if (!is_file($savePath)) {
            throw new RuntimeException('Worker installation failed.');
        }
    }

    public static function getBinaryPath(): string
    {
        $os = self::getOsName();
        $arch = self::getArchName();

        return self::getExecDir() . DIRECTORY_SEPARATOR . self::buildFileName($os, $arch);
    }

    public static function isInstalled(): bool
    {
        $path = self::getBinaryPath();

        return is_file($path) && (PHP_OS_FAMILY === 'Windows' || is_executable($path));
    }

    public static function ensureInstalled(): void
    {
        if (!self::isInstalled()) {
            self::install();
        }
    }

    private static function getExecDir(): string
    {
        return realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'exec';
    }

    public static function getOsName(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'win';
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return 'mac';
        }

        if (PHP_OS_FAMILY === 'BSD') {
            return 'freebsd';
        }

        if (file_exists('/system/bin/app_process')) {
            return 'android';
        }

        return 'linux';
    }

    public static function getArchName(): string
    {
        $arch = strtolower((string) php_uname('m'));

        return match ($arch) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            'armv7l', 'armv7' => 'arm',
            'i386', 'i686' => '386',
            default => 'amd64',
        };
    }

    public static function buildFileName(string $os, string $arch): string
    {
        $ext = $os === 'win' ? '.exe' : '';

        return "astra-worker-{$os}-{$arch}{$ext}";
    }

    private static function buildDownloadUrl(string $fileName): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/%s/%s',
            self::REPO,
            self::VERSION,
            $fileName
        );
    }

    private static function download(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: AstraHTTP-Installer\r\n",
                'timeout' => 120,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            throw new RuntimeException("Could not download binary: {$url}");
        }

        return $content;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory: {$dir}");
        }
    }
}
