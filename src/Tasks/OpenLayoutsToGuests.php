<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Asks;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Flux\OverrideGenerator;
use Onelegstudios\Refit\Libraries\Flux\Overrides;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\GuardSignedInChrome;
use Onelegstudios\Refit\Plan\Actions\WriteFile;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Feature;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;

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
 *             <flux:button :href="route('login')" icon="arrow-right-end-on-rectangle" variant="subtle" class="flex-1">{{ __('Log in') }}</flux:button>
 *             <flux:button :href="route('register')" icon="user-plus" variant="primary" class="flex-1">{{ __('Register') }}</flux:button>
 *         </div>
 *
 *     @endauth
 *
 * What counts as signed-in chrome is read out of the markup rather than listed
 * anywhere, which is what lets one task cover five kits and two libraries — and a
 * sixth kit refit has never seen. {@see GuardSignedInChrome} is where that
 * reading happens.
 *
 * What the ways in show is asked rather than decided, because the project cannot
 * answer it: an icon and a label, either on its own. The icons themselves are
 * not a choice — they are spelled in whichever set the run is heading for, and
 * on a Lucide run the task brings the artwork for the one name the kit has
 * nowhere else, because nothing else in the run can know it is coming.
 *
 * Only the application family is touched. The auth layouts already render for a
 * guest, that being their whole job, and every other view holding an
 * `auth()->user()` is behind an `auth` middleware. Pass your own family to the
 * constructor if your shell is called something else.
 */
final class OpenLayoutsToGuests implements Asks, Task
{
    private const string FAMILY = 'app';

    /**
     * What a way in shows when nobody has chosen, and the first answer offered.
     *
     * Both, because that is how the kit's own menu items and sidebar items read
     * — an icon, and the words beside it.
     */
    private const string SHOW = 'both';

    /**
     * What a way in can show, and the line each answer is shown as.
     *
     * @var array<string, string>
     */
    private const array SHOWS = [
        'both' => 'An icon and a label, the way the kit\'s own menu items read',
        'text' => 'A label on its own',
        'icon' => 'An icon on its own, with the label as its aria-label',
    ];

    /**
     * The two icons a way in can wear, as Heroicons spells them.
     *
     * Heroicons because that is the set the kit speaks, and the one every
     * translation in {@see IconMap} starts from. Heroicons draws the arrow going
     * into the rectangle for signing in and out of it for signing out, so the
     * log out in the menu being guarded and the log in standing in for it are
     * the same drawing mirrored.
     *
     * @var array<string, string>
     */
    private const array ICONS = [
        'login' => 'arrow-right-end-on-rectangle',
        'register' => 'user-plus',
    ];

    /**
     * @param  string  $family  the delegating layout's filename, and the folder of variants beside it
     * @param  string  $show  what the ways in show — one of {@see SHOWS}
     * @param  OverrideGenerator  $generator  the Lucide artwork refit bundles, which is what a Flux project draws a Lucide icon from
     */
    public function __construct(
        private readonly string $family = self::FAMILY,
        private readonly string $show = self::SHOW,
        private readonly OverrideGenerator $generator = new OverrideGenerator,
    ) {}

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

    /**
     * @return list<Question>
     */
    public function questions(Project $project): array
    {
        if ($this->icons($project) === []) {
            // Nothing to choose between: the only way in refit can write on this
            // run is the one made of words.
            return [];
        }

        return [new Question(
            key: 'show',
            label: 'What should the way in a guest gets show?',
            options: self::SHOWS,
            default: self::SHOW,
            hint: 'The icons come from the set you chose a moment ago.',
        )];
    }

    public function answered(Answers $answers): Task
    {
        return new self($this->family, $answers->get('show'), $this->generator);
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $this->bundleArtwork($plan, $project);

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
            $this->show === 'text' ? [] : $this->icons($project),
            $this->show !== 'icon',
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

    /**
     * The icons a way in wears on this run, in the set the run is heading for.
     *
     * The icon pass has already run by the time the guard does, so what is
     * written here is the final name rather than one for a later sweep to
     * translate — which is why the strategy is read rather than the kit's own
     * spelling being left for somebody else to fix.
     *
     * All or nothing: a set refit cannot spell both icons in gets neither,
     * rather than a pair with one link wearing an icon and the other going bare.
     *
     * No strategy means nobody has asked the icon question yet — a test, or a
     * task driven by hand. The kit ships Heroicons, so Heroicons is the honest
     * assumption, the same way Flux is for the library.
     *
     * @return array<string, string>
     */
    private function icons(Project $project): array
    {
        $named = [];

        foreach (self::ICONS as $route => $heroicon) {
            $name = match ($project->icons ?? IconStrategy::Keep) {
                IconStrategy::Keep, IconStrategy::Heroicons => $heroicon,
                IconStrategy::Lucide => $this->lucide($heroicon),
                IconStrategy::Phosphor => $this->phosphor($heroicon),
            };

            if ($name === null) {
                return [];
            }

            $named[$route] = $name;
        }

        return $named;
    }

    /**
     * The Lucide name, where refit bundles something to draw it with.
     *
     * The artwork is asked about here rather than when it is written, because a
     * name with no drawing behind it is not a name this task can offer: Flux
     * would fall back to its own Heroicons and leave one icon from the set the
     * project is leaving in the middle of the new one.
     */
    private function lucide(string $heroicon): ?string
    {
        $lucide = IconMap::toLucide($heroicon);

        return $lucide !== null && $this->generator->has($lucide) ? $lucide : null;
    }

    /**
     * The Phosphor name, carrying the mark Sheaf's icon component reads a set
     * that is not its default by — the same prefix the Phosphor sweep puts on
     * every other name in the tree.
     */
    private function phosphor(string $heroicon): ?string
    {
        $phosphor = IconMap::toPhosphor($heroicon);

        return $phosphor === null ? null : SheafLibrary::PHOSPHOR_PREFIX.$phosphor;
    }

    /**
     * Bring the artwork for an icon this task is the only one naming.
     *
     * Flux draws Lucide through override files, and the icon pass generates one
     * for every Lucide name the project already uses. The sign-in arrow is in
     * the kit nowhere — refit is what puts it there — so the task that names it
     * is the task that has to bring its drawing. Without the file, Flux resolves
     * the name against its own Heroicons and the shell ends up with one icon
     * from the set the project just left.
     *
     * An override the icon pass is already writing is left to it: `user-plus` is
     * in the teams kits, and the same file planned twice is a preview line
     * promising work nobody needs.
     *
     * Nothing to do on any other run. Heroicons needs no file at all — both
     * libraries resolve a bare name out of the set themselves — and Phosphor
     * arrives as a Composer package with Sheaf.
     */
    private function bundleArtwork(Plan $plan, Project $project): void
    {
        if (($project->icons ?? IconStrategy::Keep) !== IconStrategy::Lucide) {
            return;
        }

        foreach ($this->action($project)->icons() as $name) {
            $path = Overrides::icon($name);

            if ($plan->writes($path)) {
                continue;
            }

            $plan->add(Stage::Write, new WriteFile(
                $path,
                $this->generator->render($name),
                sprintf('write  %s (Lucide "%s", for the way in a guest gets)', $path, $name),
            ));
        }
    }
}
