<?php
declare(strict_types=1);

namespace Astra\SwooleHttp\Runtime;

use Astra\SwooleHttp\Exception\WorkerException;

final class WorkerRuntime
{
    private mixed $process = null;
    public readonly int $port;
    private bool $ownsWorker = false;

    public function __construct(int $port = 0, private bool $debug = false, private ?string $executablePath = null)
    {
        $this->port = $port !== 0 ? $port : $this->findFreePort();
        $this->spawnOrAttach();
    }

    public function ownsWorker(): bool
    {
        return $this->ownsWorker;
    }

    private function findFreePort(): int
    {
        $sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$sock) {
            throw new WorkerException('Unable to create socket for port discovery.');
        }

        if (!@socket_bind($sock, '127.0.0.1', 0)) {
            @socket_close($sock);
            throw new WorkerException('Unable to bind to an ephemeral port.');
        }

        if (!@socket_getsockname($sock, $addr, $port)) {
            @socket_close($sock);
            throw new WorkerException('Unable to read ephemeral port.');
        }

        @socket_close($sock);
        return (int) $port;
    }

/**
    private function getExecutablePathOld(): string
    {
        if ($this->executablePath !== null) {
            return $this->executablePath;
        }

        // Resolves to the 'bin' folder at the root of the project
        $baseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exec';
        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');

        if ($os === 'Windows') {
            return $baseDir . DIRECTORY_SEPARATOR . 'index.exe';
        }

        if ($os === 'Darwin') {
            return $baseDir . DIRECTORY_SEPARATOR . ((str_contains($arch, 'arm') || str_contains($arch, 'arm64')) ? 'index-mac-arm64' : 'index-mac');
        }

        return $baseDir . DIRECTORY_SEPARATOR . ((str_contains($arch, 'aarch64') || str_contains($arch, 'arm64')) ? 'index-arm64' : 'index');
    }

    private function getExecutablePath(): string
    {
        if ($this->executablePath !== null) {
            return $this->executablePath;
        }
    
        $baseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exec';
        $os = PHP_OS_FAMILY;
        $arch = strtolower((string) php_uname('m'));
    
        $archName = match (true) {
            str_contains($arch, 'aarch64'),
            str_contains($arch, 'arm64') => 'arm64',
    
            str_contains($arch, 'armv7'),
            str_contains($arch, 'arm') => 'arm',
    
            str_contains($arch, 'i386'),
            str_contains($arch, 'i686') => '386',
    
            default => 'amd64',
        };
    
        $fileName = match ($os) {
            'Windows' => "astra-worker-win-{$archName}.exe",
            'Darwin'  => "astra-worker-mac-{$archName}",
            'BSD'     => "astra-worker-freebsd-{$archName}",
            default   => "astra-worker-linux-{$archName}",
        };
    
        return $this->executablePath = $baseDir . DIRECTORY_SEPARATOR . $fileName;
    }
*/
    private function getExecutablePath(): string
    {
        if ($this->executablePath !== null) {
            return $this->executablePath;
        }

        $baseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exec';
        $osName  = \Astra\SwooleHttp\WorkerDownloader::getOsName();
        $archName = \Astra\SwooleHttp\WorkerDownloader::getArchName();

        return $baseDir. DIRECTORY_SEPARATOR . \Astra\SwooleHttp\WorkerDownloader::buildFileName($osName, $archName);
    }

    private function isPortInUse(int $port): bool
    {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($conn)) {
            fclose($conn);
            return true;
        }
        return false;
    }

    private function waitForPort(int $timeoutMs = 5000): void
    {
        $start = microtime(true);
        while ((microtime(true) - $start) * 1000 < $timeoutMs) {
            if ($this->isPortInUse($this->port)) {
                return;
            }
            usleep(100_000);
        }

        throw new WorkerException("Astra worker did not open port {$this->port} within {$timeoutMs}ms.");
    }

    public function spawnOrAttach(): void
    {
        $this->closeProcessHandleOnly();
        $this->process = null;
        $this->ownsWorker = false;

        if ($this->isPortInUse($this->port)) {
            $this->waitForPort();
            return;
        }

        $execPath = $this->getExecutablePath();
        if (!file_exists($execPath)) {
            throw new WorkerException("AstraHTTP executable not found at: {$execPath}");
        }

        $env = $_ENV;
        $env['WS_PORT'] = (string) $this->port;

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $this->debug ? ['pipe', 'w'] : ['file', $nullDevice, 'a'],
            2 => $this->debug ? ['pipe', 'w'] : ['file', $nullDevice, 'a'],
        ];

        $command = [$execPath];

        $this->process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($this->process)) {
            throw new WorkerException('Failed to start AstraHTTP worker process.');
        }

        if (isset($pipes) && is_array($pipes)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        $this->ownsWorker = true;
        $this->waitForPort();
    }

    private function closeProcessHandleOnly(): void
    {
        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;
    }

    public function close(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        if (!empty($status['running']) && !empty($status['pid']) && $this->ownsWorker) {
            if (PHP_OS_FAMILY === 'Windows') {
                @exec("taskkill /F /T /PID {$status['pid']} >nul 2>&1");
            } else {
                @exec("kill -9 {$status['pid']} >/dev/null 2>&1");
            }
        }

        @proc_close($this->process);
        $this->process = null;
    }
}
