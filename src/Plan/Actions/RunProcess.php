<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\DependencyFailed;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use Symfony\Component\Process\Process;

/**
 * Run a command in the project root.
 *
 * A failure is reported rather than thrown by default: Pint and asset builds are
 * finishing touches, and a missing binary should not strand a project that has
 * already been rewritten successfully.
 *
 * `required: true` inverts that, for a command the rest of the plan is built on.
 * Installing the components every later rewrite points at is the case this exists
 * for — carrying on after that failed would rewrite an application onto
 * components that are not there.
 */
final class RunProcess implements Action
{
    /**
     * @param  list<string>  $command
     * @param  bool  $required  Abort the run when this fails, rather than noting it.
     */
    public function __construct(
        private readonly array $command,
        private readonly string $description,
        private readonly int $timeout = 300,
        private readonly bool $required = false,
    ) {}

    public function describe(): string
    {
        return sprintf('run    %s (%s)', implode(' ', $this->command), $this->description);
    }

    public function apply(Project $project, Report $report): void
    {
        $process = new Process($this->command, $project->root, timeout: $this->timeout);

        $process->run();

        if ($process->isSuccessful()) {
            $report->note($this->description.' succeeded.');

            return;
        }

        $reason = trim($process->getErrorOutput());
        $reason = $reason !== '' ? $reason : trim($process->getOutput());
        $reason = $reason !== '' ? $reason : 'no output';

        if ($this->required) {
            throw new DependencyFailed(sprintf(
                "%s failed, so refit stopped before changing anything.\n\n%s\n\nRun `%s` yourself, then run refit again.",
                $this->description,
                $reason,
                implode(' ', $this->command),
            ));
        }

        $report->warn(sprintf(
            '%s failed (%s). Run `%s` yourself to see why.',
            $this->description,
            $reason,
            implode(' ', $this->command),
        ));
    }
}
