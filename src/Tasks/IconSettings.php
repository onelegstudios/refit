<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Flux\OverrideGenerator;
use Onelegstudios\Refit\Libraries\Flux\Overrides;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\IconSettingsSections;
use Onelegstudios\Refit\Plan\Actions\WriteFile;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;

/**
 * Put an icon on each settings section, in the menu and over the page.
 *
 * The settings sub-navigation is the one navlist the kit writes without icons.
 * Every other nav row in the shell has one — Dashboard, Repository,
 * Documentation, the user menu's own Settings and Log out — and then the page
 * those rows open puts four words in a column and a heading above the form.
 *
 * This gives each section a drawing, everywhere the section is named: on its row
 * in the menu, over the page that row opens, and over each section written
 * further down that page — the security page's two-factor and passkey blocks are
 * headings of their own under the layout's. The page heading is the
 * useful half on a narrow screen, where the menu scrolls away above the fold and
 * the heading is the only thing left saying which of the four you are on — so
 * the layout learns an `icon` prop, and each page passes the one its own heading
 * asks for.
 *
 * Nothing is asked, because nothing here is a choice the project cannot make for
 * itself: the sections are the kit's own, and the icons are their labels read
 * back out of {@see ICONS}. A label refit has never seen gets no icon and is
 * named in the run report instead, which is what a project's own settings
 * section gets — the page keeps the heading it always had, the menu row keeps its
 * words, and the note says which.
 *
 * Which set the names are spelled in was settled by the icon question.
 * {@see IconMap} translates each one the same way the icon pass translates the
 * kit's own, and on a Lucide run the task brings the artwork for the names it is
 * the first to use, because nothing else in the run can know they are coming.
 *
 * Pass your own table to the constructor to name your application's own
 * sections. It replaces this one rather than adding to it, so {@see ICONS} is
 * public to spread: labels are matched without regard to case or to how the tag
 * happens to be wrapped, and the names are Heroicons', the set every translation
 * starts from.
 *
 *     Refit::task(new IconSettings([...IconSettings::ICONS, 'billing' => 'credit-card']));
 */
final class IconSettings implements Task
{
    /**
     * The icon each settings section wants, as Heroicons spells it.
     *
     * Heroicons because that is the set the kit speaks and the one every
     * translation in {@see IconMap} starts from. Every name here is one that map
     * can carry into Lucide and into Phosphor, so the table reads the same
     * whichever set the run is heading for.
     *
     * The labels are the kit's own, lower-cased, across all five variations —
     * and they are three kinds of label at once, because a section is named
     * wherever it is written. "Security" is the words on the menu row; "Update
     * password" is the heading on the page that row opens; "Passkeys" and
     * "Two-factor authentication" are the sections further down the same page.
     * Each is answered on its own terms rather than one being made to stand for
     * the others, so the shield marks the section, the key marks the form at the
     * top of it, and the padlock and the fingerprint mark the two below.
     *
     * @var array<string, string>
     */
    public const array ICONS = [
        'appearance' => 'swatch',
        'passkeys' => 'finger-print',
        'profile' => 'user',
        'security' => 'shield-check',
        'teams' => 'users',
        // The lock for the second factor, because the shield is already the
        // section it sits in and a padlock is what the kit's own "Enable 2FA"
        // button wears.
        'two-factor authentication' => 'lock-closed',
        'update password' => 'key',
    ];

    /**
     * @param  array<string, string>  $icons  section label => the Heroicons name it wants
     * @param  OverrideGenerator  $generator  the Lucide artwork refit bundles, which is what a Flux project draws a Lucide icon from
     */
    public function __construct(
        private readonly array $icons = self::ICONS,
        private readonly OverrideGenerator $generator = new OverrideGenerator,
    ) {}

    public function key(): string
    {
        return 'icon-settings';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Structure;
    }

    public function label(): string
    {
        return 'Put an icon on the settings sections';
    }

    public function hint(): string
    {
        return 'Each section gets the icon that goes with it, on its menu row and over its heading';
    }

    public function appliesTo(Project $project): bool
    {
        $action = $this->action($project, (new FluxLibrary)->vocabulary());

        foreach ($project->blades() as $path) {
            if ($action->names($project->get($path)) !== []) {
                return true;
            }
        }

        return false;
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $this->bundleArtwork($plan, $project);

        // In the reconcile stage, because the settings pages are not settled
        // before it: a target renames every tag in the tree from its own map and
        // moves the label on a nav row into an attribute, and refit's own
        // structure tasks move the layout out of the pages folder.
        $plan->add(Stage::Reconcile, $this->action($project, $this->vocabulary($project)));
    }

    /**
     * The sweep, aimed at one library's spelling of the components it rewrites.
     *
     * Two spellings are in play across a run: Flux's, which is what is on disk
     * when the task is asked whether it applies and which artwork it has to
     * bring, and the one the project ends on, which is what the plan rewrites.
     */
    private function action(Project $project, Vocabulary $vocabulary): IconSettingsSections
    {
        return new IconSettingsSections($vocabulary, $this->named($project));
    }

    /**
     * How the library this project is ending on spells its components.
     *
     * Flux when nothing has been chosen yet, because Flux is what the kit ships.
     */
    private function vocabulary(Project $project): Vocabulary
    {
        return $project->target?->vocabulary() ?? (new FluxLibrary)->vocabulary();
    }

    /**
     * The table, with every icon spelled in the set the run is heading for.
     *
     * The icon pass has already run by the time the sweep does, so what is
     * written here is the final name rather than one for a later sweep to
     * translate.
     *
     * A label whose icon the target set cannot spell drops out and is reported as
     * a section without one, rather than becoming a name that resolves to
     * nothing.
     *
     * No strategy means nobody has asked the icon question yet — a test, or a
     * task driven by hand. The kit ships Heroicons, so Heroicons is the honest
     * assumption, the same way Flux is for the library.
     *
     * @return array<string, string>
     */
    private function named(Project $project): array
    {
        $named = [];

        foreach ($this->icons as $label => $heroicon) {
            $name = match ($project->icons ?? IconStrategy::Keep) {
                IconStrategy::Keep, IconStrategy::Heroicons => $heroicon,
                IconStrategy::Lucide => $this->lucide($heroicon),
                IconStrategy::Phosphor => $this->phosphor($heroicon),
            };

            if ($name !== null) {
                $named[$label] = $name;
            }
        }

        return $named;
    }

    /**
     * The Lucide name, where refit bundles something to draw it with.
     *
     * The artwork is asked about here rather than when it is written, because a
     * name with no drawing behind it is not a name this task can offer: Flux
     * would fall back to its own Heroicons and leave one row in the set the
     * project has just left.
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
     * Bring the artwork for the Lucide icons this task is the first to name.
     *
     * Flux draws Lucide through override files, and the icon pass generates one
     * for every Lucide name the project already uses. A settings icon is a name
     * the project does not use yet — refit is what puts it there — so the task
     * that names it is the task that has to bring its drawing.
     *
     * Only the names that are actually going to be written, which is why the
     * tree is read rather than the table: a project with no teams has no Teams
     * row, and a preview promising a file nothing will draw from is a change
     * nobody needs. An override somebody else is already writing is left to
     * them, for the same reason.
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

        $action = $this->action($project, (new FluxLibrary)->vocabulary());
        $names = [];

        foreach ($project->blades() as $path) {
            foreach ($action->names($project->get($path)) as $name) {
                $names[$name] = true;
            }
        }

        ksort($names);

        foreach (array_keys($names) as $name) {
            $path = Overrides::icon($name);

            if ($plan->writes($path)) {
                continue;
            }

            $plan->add(Stage::Write, new WriteFile(
                $path,
                $this->generator->render($name),
                sprintf('write  %s (Lucide "%s", for a settings icon)', $path, $name),
            ));
        }
    }
}
