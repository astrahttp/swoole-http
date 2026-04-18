<?php
declare(strict_types=1);

namespace Astra\SwooleHttp\Runtime;

final class WorkerManager
{
    private static ?self $instance = null;
    
    /** @var array<int, array{runtime:WorkerRuntime, refCount:int}> */
    private array $workers = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function acquire(int $port, bool $debug, ?string $executablePath = null): WorkerRuntime
    {
        if (isset($this->workers[$port])) {
            $this->workers[$port]['refCount']++;
            return $this->workers[$port]['runtime'];
        }

        $runtime = new WorkerRuntime($port, $debug, $executablePath);
        $this->workers[$port] = ['runtime' => $runtime, 'refCount' => 1];
        return $runtime;
    }

    public function release(int $port): void
    {
        if (!isset($this->workers[$port])) {
            return;
        }

        $this->workers[$port]['refCount']--;
        if ($this->workers[$port]['refCount'] > 0) {
            return;
        }

        $this->workers[$port]['runtime']->close();
        unset($this->workers[$port]);
    }

    public function cleanup(): void
    {
        foreach ($this->workers as $port => $entry) {
            $entry['runtime']->close();
            unset($this->workers[$port]);
        }
        $this->workers = [];
    }
}
