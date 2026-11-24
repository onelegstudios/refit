<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use RuntimeException;

final class WriteFile implements Action
{
    public function __construct(
        private readonly string $path,
        private readonly string $contents,
        private readonly ?string $description = null,
    ) {}

    public function describe(): string
    {
        return $this->description ?? 'write  '.$this->path;
    }

    /**
     * Where this write lands.
     *
     * Public so a contributor can see whether the plan already holds the file it
     * was about to add — the same file planned twice is a preview line promising
     * work nobody needs.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function apply(Project $project, Report $report): void
    {
        $target = $project->path($this->path);
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create [{$directory}].");
        }

        if (file_put_contents($target, $this->contents) === false) {
            throw new RuntimeException("Unable to write [{$this->path}].");
        }

        $report->changed($this->path);
    }
}
