<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\GuardSignedInChrome;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Feature;
use Onelegstudios\Refit\Project\Project;

/**
 * Let the application layouts render whether or not anybody is signed in.
 *
 * The kit draws its shell for one kind of visitor. `layouts/app/sidebar.blade.php`
 * reads `auth()->user()->name` where the profile goes, `auth()->user()->initials()`
 * in the mobile header, and posts to `route('logout')` from the menu — none of it
 * asking first. So the layout every signed-in page names cannot be used by a page
 * that is open to everybody: point a marketing page, a pricing page or a public
 * share link at `<x-layouts::app>` and the view fails on a null user rather than
 * rendering without one.
 *
 * That is a structural decision rather than a missing feature, and it is the one
 * this task reverses. The signed-in chrome goes behind `@auth`, the way in the
 * kit still has to offer takes the user menu's place for a guest, and both
 * states render the same shell:
 *
 *     @auth
 *         <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
 *
 *     @else
 *         <div class="hidden lg:flex items-center gap-2">
 *             <flux:button :href="route('login')" variant="subtle" class="flex-1">{{ __('Log in') }}</flux:button>
 *             <flux:button :href="route('register')" variant="primary" class="flex-1">{{ __('Register') }}</flux:button>
 *         </div>
 *
 *     @endauth
 *
 * What counts as signed-in chrome is read out of the markup rather than listed
 * anywhere, which is what lets one task cover five kits and two libraries — and a
 * sixth kit refit has never seen. {@see GuardSignedInChrome} is where that
 * reading happens.
 *
 * Only the application family is touched. The auth layouts already render for a
 * guest, that being their whole job, and every other view holding an
 * `auth()->user()` is behind an `auth` middleware. Pass your own family to the
 * constructor if your shell is called something else.
 */
final class OpenLayoutsToGuests implements Task
{
    private const string FAMILY = 'app';

    /**
     * @param  string  $family  the delegating layout's filename, and the folder of variants beside it
     */
    public function __construct(private readonly string $family = self::FAMILY) {}

    public function key(): string
    {
        return 'guests-in-app-layouts';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Structure;
    }

    public function label(): string
    {
        return 'Let the app layouts render for a guest';
    }

    public function hint(): string
    {
        return 'Puts the signed-in chrome behind @auth, with a way in where the user menu was';
    }

    public function appliesTo(Project $project): bool
    {
        $action = $this->action($project);

        foreach ($project->blades() as $path) {
            if ($action->regions($project->get($path), $path, $project) !== []) {
                return true;
            }
        }

        return false;
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        // In the reconcile stage, because the shell is not settled before it: a
        // target may have rewritten the chrome from its own stubs, and refit's
        // own layout tasks may have deleted or flattened half the family.
        $plan->add(Stage::Reconcile, $this->action($project));
    }

    private function action(Project $project): GuardSignedInChrome
    {
        return new GuardSignedInChrome(
            $this->family,
            $this->vocabulary($project),
            $project->has(Feature::Registration),
        );
    }

    /**
     * How the library this project is ending on spells its tags, which is what
     * the guest's way in is written in.
     *
     * Flux when nothing has been chosen yet, because Flux is what the kit ships.
     */
    private function vocabulary(Project $project): Vocabulary
    {
        return $project->target?->vocabulary() ?? (new FluxLibrary)->vocabulary();
    }
}
