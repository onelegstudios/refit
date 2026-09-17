<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Drop a directory the plan has just emptied.
 *
 * Only ever removes an empty directory: anything still in there is something the
 * plan did not account for, so it is reported and left for the developer.
 *
 * Unless the contributor expects it may not be empty. A directory that two
 * independent choices share — `resources/views/flux`, say, emptied only when both
 * the icons and the other overrides go — is tidied by whichever runs last, and the
 * earlier attempt finding it occupied is not news.
 */
final class RemoveDirectoryIfEmpty implements Action
{
    public function __construct(
        private readonly string $path,
        private readonly bool $warnIfOccupied = true,
    ) {}

    public function describe(): string
    {
        return 'remove '.$this->path.' once it is empty';
    }

    public function apply(Project $project, Report $report): void
    {
        $target = $project->path($this->path);

        if (! is_dir($target)) {
            return;
        }

        $remaining = $this->entries($target);

        if ($remaining !== []) {
            if (! $this->warnIfOccupied) {
                return;
            }

            $report->warn(sprintf(
                'Left [%s] in place — it still holds %s.',
                $this->path,
                implode(', ', $remaining),
            ));

            return;
        }

        if (! rmdir($target)) {
            $report->warn("Could not remove the empty [{$this->path}] directory.");

            return;
        }

        $report->note("Removed the now empty {$this->path} directory");
    }

    /**
     * @return list<string>
     */
    private function entries(string $path): array
    {
        $entries = scandir($path);

        if ($entries === false) {
            return ['unreadable contents'];
        }

        return array_values(array_diff($entries, ['.', '..']));
    }
}
