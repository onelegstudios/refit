<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Plan\Actions\RemoveLinesContaining;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;

/**
 * Drop the Flux Pro `@source` line from the stylesheet.
 *
 * Every variant ships `@source '../../vendor/livewire/flux-pro/stubs/**\/*.blade.php'`
 * on line 6 of `resources/css/app.css`, pointing at a directory that only exists
 * with a Flux Pro licence. Offered only when Flux Pro is absent, so an upgrade
 * later is never quietly broken.
 */
final class RemoveFluxProSource implements Task
{
    private const string STYLESHEET = 'resources/css/app.css';

    private const string NEEDLE = 'flux-pro';

    public function key(): string
    {
        return 'remove-flux-pro-source';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Cleanup;
    }

    public function label(): string
    {
        return 'Remove the Flux Pro @source line from app.css';
    }

    public function hint(): string
    {
        return 'Tailwind is scanning a vendor path that only exists with Flux Pro';
    }

    public function appliesTo(Project $project): bool
    {
        // Only worth offering to a project staying on Flux. One that is leaving
        // takes both @source lines out in Flux's teardown, and offering to remove
        // half of something you are about to remove entirely is just confusing.
        return $project->targets(FluxLibrary::KEY)
            && $project->library(FluxLibrary::KEY)?->pro !== true
            && $project->exists(self::STYLESHEET)
            && str_contains($project->get(self::STYLESHEET), self::NEEDLE);
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $plan->add(Stage::Write, new RemoveLinesContaining(
            self::STYLESHEET,
            self::NEEDLE,
            'edit   '.self::STYLESHEET.' — drop the Flux Pro @source line',
        ));
    }
}
