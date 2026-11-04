<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Binary;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Locates and runs the optional command line optimizers (jpegtran, optipng, cwebp...).
 *
 * Every binary is optional: when it is missing the bundle falls back to pure PHP / GD
 * strategies, or simply skips the step.
 */
final class BinaryRunner
{
    /** @var array<string, string|false> */
    private array $resolved = [];

    /**
     * @param array<string, string|false|null> $binaries name => explicit path, false (disabled) or null (auto-detect)
     */
    public function __construct(
        private readonly array $binaries = [],
        private readonly float $timeout = 60.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function has(string $name): bool
    {
        return false !== $this->resolve($name);
    }

    /**
     * @param list<string> $arguments
     */
    public function run(string $name, array $arguments): bool
    {
        $binary = $this->resolve($name);
        if (false === $binary) {
            return false;
        }

        $process = new Process([$binary, ...$arguments]);
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->warning('Image optimizer binary "{binary}" failed: {message}', ['binary' => $name, 'message' => $e->getMessage()]);

            return false;
        }

        if (!$process->isSuccessful()) {
            $this->logger->debug('Image optimizer binary "{binary}" exited with code {code}: {error}', [
                'binary' => $name,
                'code' => $process->getExitCode(),
                'error' => trim($process->getErrorOutput()),
            ]);

            return false;
        }

        return true;
    }

    private function resolve(string $name): string|false
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $configured = $this->binaries[$name] ?? null;

        if (false === $configured) {
            return $this->resolved[$name] = false;
        }

        if (\is_string($configured) && '' !== $configured) {
            return $this->resolved[$name] = is_executable($configured) ? $configured : false;
        }

        if (!\function_exists('proc_open')) {
            return $this->resolved[$name] = false;
        }

        $found = (new ExecutableFinder())->find($name, null, ['/usr/local/bin', '/usr/bin', '/opt/homebrew/bin', '/opt/local/bin']);

        return $this->resolved[$name] = $found ?? false;
    }
}
