<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Sheaf;

use Onelegstudios\Refit\Plan\Actions\MapComponentTags;
use Onelegstudios\Refit\Plan\Actions\UnwrapElement;
use Onelegstudios\Refit\Plan\Actions\WriteFile;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Feature;
use Onelegstudios\Refit\Project\Project;
use RuntimeException;

/**
 * Replace the kit's chrome with Sheaf-shaped equivalents.
 *
 * Most of a Flux view is a rename away from a Sheaf one, and
 * {@see MapComponentTags} handles it. The
 * chrome is the exception, and not because the components are missing — Sheaf
 * ships every one of them — but because they compose differently. Flux's sidebar
 * is a root element; Sheaf's is a grid area of an `<x-ui.layout>`, which decides
 * where its siblings go from the variant it was given: the sidebar layout puts
 * the mobile header inside the main, and the header layout puts the header
 * alongside it, in the row the grid keeps for one. No sequence of tag renames
 * produces either nesting.
 *
 * So these few files are written from stubs. The stubs are composed entirely of
 * components Sheaf ships — this authors application layout, never a component —
 * and are chiselled by the features the kit turned out to have, in the same way
 * `install:features` chiselled them in the first place.
 */
final class LayoutStubs
{
    private const string TEAM_SWITCHER = '__TEAM_SWITCHER__';

    private const string CREATE_TEAM_MODAL = '__CREATE_TEAM_MODAL__';

    /** The kit file that renders one of the layout variants beside it. */
    private const string DELEGATING_LAYOUT = 'resources/views/layouts/app.blade.php';

    /** What that file wraps the slot in, and the stubs now render themselves. */
    private const string MAIN = 'flux:main';

    /**
     * Stub file mapped to the project-relative path it replaces.
     *
     * Only files that exist are written: the kit ships one app layout of the two,
     * and `install:features` has already deleted whichever the user did not pick.
     *
     * @var array<string, string>
     */
    private const array LAYOUTS = [
        'layouts/app-sidebar.blade.php.stub' => 'resources/views/layouts/app/sidebar.blade.php',
        'layouts/app-header.blade.php.stub' => 'resources/views/layouts/app/header.blade.php',
        'components/desktop-user-menu.blade.php.stub' => 'resources/views/components/desktop-user-menu.blade.php',
    ];

    public function __construct(
        private readonly string $stubDirectory = __DIR__.'/../../../stubs/sheaf',
    ) {}

    /**
     * Kit components the stubs render directly inside `<x-ui.sidebar>`.
     *
     * The user menu is not among them: refit authors that one from a stub and
     * writes it already lifted. These are the views refit only rewrites, so
     * something else has to make their dropdowns survive the sidebar.
     *
     * @return list<string>
     */
    public function sidebarComponents(Project $project): array
    {
        return $project->has(Feature::Teams) ? ['team-switcher'] : [];
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $written = 0;

        foreach (self::LAYOUTS as $stub => $target) {
            if (! $project->exists($target)) {
                continue;
            }

            $plan->add(Stage::Write, new WriteFile(
                $target,
                $this->render($stub, $project),
                sprintf('write  %s (Sheaf chrome, replacing the Flux original)', $target),
            ));

            $written++;
        }

        if ($written === 0) {
            return;
        }

        $this->unwrapMain($plan, $project);

        $report->note(
            'Rewrote the app chrome from refit\'s Sheaf stubs — Sheaf nests its sidebar and main inside '
            .'<x-ui.layout>, which no rename can produce. Check the diff: anything you had customised in '
            .'those files is in the git history rather than in the new ones.',
        );
    }

    /**
     * Take the kit's `<flux:main>` out of the delegating layout.
     *
     * The kit splits the shell in two: `layouts/app.blade.php` renders the main,
     * and `layouts/app/<variant>.blade.php` renders everything around it. Sheaf
     * cannot be composed that way — its main is a grid area of `<x-ui.layout>`,
     * so the stub has to render it — and a second one wrapping the slot puts a
     * full-height scrolling box inside a full-height scrolling box.
     *
     * Runs before the tag rename, so the tag to look for is still Flux's.
     */
    private function unwrapMain(Plan $plan, Project $project): void
    {
        if (! str_contains($project->get(self::DELEGATING_LAYOUT), '<'.self::MAIN)) {
            return;
        }

        $plan->add(Stage::Write, new UnwrapElement(
            self::DELEGATING_LAYOUT,
            self::MAIN,
            sprintf(
                'edit   %s — drop <%s>, which the Sheaf layout now renders itself',
                self::DELEGATING_LAYOUT,
                self::MAIN,
            ),
        ));
    }

    /**
     * Fill a stub in, dropping the markup the detected kit has no use for.
     */
    private function render(string $stub, Project $project): string
    {
        $path = $this->stubDirectory.'/'.$stub;
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the Sheaf stub at [{$path}].");
        }

        $teams = $project->has(Feature::Teams);

        return str_replace(
            [self::TEAM_SWITCHER."\n", self::CREATE_TEAM_MODAL."\n"],
            $teams
                ? ["\n                <livewire:team-switcher />\n", "\n        <livewire:create-team-modal />\n"]
                : ['', ''],
            $contents,
        );
    }
}
