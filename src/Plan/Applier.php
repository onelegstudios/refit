<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan;

use Closure;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Project\Project;

/**
 * Executes a confirmed plan.
 */
final class Applier
{
    /**
     * @param  (callable(Action, Closure(): void): void)|null  $around  Runs each action by calling
     *                                                                  the closure it is handed, so the
     *                                                                  caller can echo it or wrap it.
     */
    public function apply(
        Plan $plan,
        Project $project,
        Report $report,
        ?callable $around = null,
    ): void {
        foreach ($plan->actions() as $action) {
            $run = static fn () => $action->apply($project, $report);

            if ($around === null) {
                $run();

                continue;
            }

            $around($action, $run);
        }
    }
}
