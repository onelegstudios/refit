<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Libraries\Flux\Overrides;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Plan\Actions\DeleteFile;
use Onelegstudios\Refit\Plan\Actions\RemoveDirectoryIfEmpty;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;

/**
 * Go back to Flux's own components where the kit has replaced them.
 *
 * Every kit ships `resources/views/flux/navlist/group.blade.php`, a restyled copy
 * of Flux's navlist group that silently takes precedence over the real one — and
 * stops following it the moment Flux changes its version. This deletes that and
 * anything else under `resources/views/flux`, except the icons.
 *
 * The icons are left to the icon question. Deleting them here would break every
 * usage of a name Heroicons does not have, undo the overrides the Lucide answer
 * writes, and quietly contradict "keep the current mix". Going all Heroicons
 * already removes them properly, usages and all.
 *
 * The root goes too once it is empty, which only happens when the icon answer
 * emptied `icon/` as well — so an occupied root is expected, not reported.
 */
final class RemoveFluxOverrides implements Task
{
    public function key(): string
    {
        return 'remove-flux-overrides';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Cleanup;
    }

    public function label(): string
    {
        return 'Use Flux\'s own components instead of the kit\'s overrides';
    }

    public function hint(): string
    {
        return 'Deletes resources/views/flux except the icons, which the icon choice decides';
    }

    public function appliesTo(Project $project): bool
    {
        // A project leaving Flux loses the whole directory in Flux's teardown.
        return $project->targets(FluxLibrary::KEY) && $this->overrides($project) !== [];
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $overrides = $this->overrides($project);

        foreach ($overrides as $path) {
            $plan->add(Stage::Move, new DeleteFile(
                $path,
                sprintf('delete %s (back to Flux\'s own)', $path),
            ));
        }

        foreach (Overrides::directories($overrides) as $directory) {
            $plan->add(Stage::Move, new RemoveDirectoryIfEmpty($directory));
        }

        $plan->add(Stage::Move, new RemoveDirectoryIfEmpty(Overrides::ROOT, warnIfOccupied: false));
    }

    /**
     * @return list<string>
     */
    private function overrides(Project $project): array
    {
        return Overrides::files($project, except: [Overrides::ICONS]);
    }
}
