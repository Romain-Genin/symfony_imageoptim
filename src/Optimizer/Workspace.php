<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer;

/**
 * Temporary files that are always cleaned up.
 */
final class Workspace
{
    /** @var list<string> */
    private array $files = [];

    public function __construct(private readonly ?string $directory = null)
    {
    }

    /**
     * Returns a path that does not exist yet (some tools refuse to overwrite files).
     */
    public function path(string $extension = 'tmp'): string
    {
        $path = rtrim($this->directory ?? sys_get_temp_dir(), '/\\') . \DIRECTORY_SEPARATOR . 'imgopt_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $this->files[] = $path;

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->files = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
