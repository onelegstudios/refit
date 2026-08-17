<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries;

use Onelegstudios\Refit\Contracts\Library;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Libraries\Sheaf\LayoutStubs;
use Onelegstudios\Refit\Plan\Actions\MapComponentTags;
use Onelegstudios\Refit\Plan\Actions\OrderThemeImport;
use Onelegstudios\Refit\Plan\Actions\PlaceDropdownChildren;
use Onelegstudios\Refit\Plan\Actions\PrefixIconNames;
use Onelegstudios\Refit\Plan\Actions\PreserveTextAlignment;
use Onelegstudios\Refit\Plan\Actions\PromoteContentsToLabel;
use Onelegstudios\Refit\Plan\Actions\RaiseSidebarDropdowns;
use Onelegstudios\Refit\Plan\Actions\RebindAppearanceToTheme;
use Onelegstudios\Refit\Plan\Actions\RestructureBrandLogo;
use Onelegstudios\Refit\Plan\Actions\RestructureOverlays;
use Onelegstudios\Refit\Plan\Actions\RewriteIconNames;
use Onelegstudios\Refit\Plan\Actions\RunProcess;
use Onelegstudios\Refit\Plan\Actions\ShapeSegmentedGroups;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\LibraryInstall;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Support\Composer;

/**
 * Sheaf UI — the first alternative target.
 *
 * Sheaf is distributed the way shadcn/ui is: a CLI copies component source into
 * the application, under `resources/views/components/ui/`, and the code is then
 * the user's. That is the opposite of Flux, which resolves everything out of a
 * vendor package, and it decides most of what this class does differently:
 *
 * - refit installs components by running Sheaf's own CLI rather than writing any
 *   component itself, so nothing here authors a substitute for something Sheaf
 *   ships;
 * - there is no override directory and no internal-icon manifest, because
 *   Sheaf's components are ordinary application Blade that the icon scanner
 *   already reads;
 * - the chrome is a genuine rewrite rather than a rename, because Sheaf's sidebar
 *   sits inside an `<x-ui.layout>` root that Flux has no counterpart for.
 */
final class SheafLibrary implements Library
{
    public const string KEY = 'sheaf';

    public const string PACKAGE = 'sheaf/cli';

    /** Where the CLI installs components, relative to the project root. */
    public const string COMPONENT_DIRECTORY = 'resources/views/components/ui';

    /** Written by `sheaf:init`, and the honest signal that it has been run. */
    public const string THEME_STYLESHEET = 'resources/css/theme.css';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Sheaf UI — replace Flux entirely';
    }

    public function hint(): string
    {
        return 'Installs Sheaf\'s components and rewrites every view onto them.';
    }

    public function vocabulary(): Vocabulary
    {
        return new Vocabulary(
            prefix: 'x-ui.',
            iconTag: 'x-ui.icon',
            iconTagAttribute: 'name',
            // Sheaf takes a trailing icon through `iconAfter`; the leading one is
            // just `icon`. Both spellings are produced by the migration, so only
            // these two ever appear in a Sheaf tree.
            nameAttributes: ['icon', 'iconAfter'],
            // Sheaf has no `<x-ui.icon.home />` form — every icon is named through
            // an attribute, which is why the migration collapses Flux's dotted
            // tags rather than renaming them.
            dottedIconTag: null,
            // Sheaf's icon component supports Heroicons' solid, mini and micro
            // weights natively, so nothing has to drop `variant="solid"` the way
            // the Lucide path does. Deliberately absent, not overlooked.
            variantAttribute: null,
            iconVariantAttribute: null,
        );
    }

    public function detect(string $root): ?LibraryInstall
    {
        $composer = new Composer($root);
        $installed = is_dir($root.'/'.self::COMPONENT_DIRECTORY);

        if (! $composer->has(self::PACKAGE) && ! $installed) {
            return null;
        }

        return new LibraryInstall(key: self::KEY);
    }

    /**
     * Nothing to refuse over.
     *
     * Sheaf not being installed is not a reason to stop — it is the first thing
     * the plan does about it. Both commands land in `Stage::Dependencies`, where
     * the user sees them in the preview and agrees to them along with everything
     * else, so refusing would only be making them run by hand what they had
     * already said yes to.
     */
    public function preflight(Project $project): array
    {
        return [];
    }

    /**
     * Sheaf's icon component reads Heroicons out of the box and can add Phosphor
     * at init time. Lucide would need a third artwork mechanism — blade-icons and
     * the `bk:` name prefix — so it is not offered here.
     */
    public function iconStrategies(): array
    {
        return [IconStrategy::Heroicons, IconStrategy::Phosphor];
    }

    public function planIcons(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void
    {
        // The kit vendors a handful of Lucide icons as Flux overrides. Those
        // overrides go with Flux, so their usages have to name something the icon
        // set actually has. Four entries, and the table already exists.
        $renames = [];

        foreach (array_keys(IconMap::LUCIDE_TO_HEROICONS) as $lucide) {
            $heroicon = IconMap::toHeroicons($lucide);

            if ($heroicon !== null) {
                $renames[$lucide] = $heroicon;
            }
        }

        if ($renames !== []) {
            $plan->add(Stage::Reconcile, new RewriteIconNames(
                $renames,
                'the kit\'s vendored Lucide names to Heroicons',
                $this->vocabulary(),
            ));
        }

        if ($strategy !== IconStrategy::Phosphor) {
            $report->note('Icons stay on Heroicons, which is what Sheaf\'s icon component reads by default.');

            return;
        }

        // When refit is the one running `sheaf:init`, it passes --with-phosphor
        // itself. This note is for the project that was initialised before refit
        // ever saw it, where the flag is no longer on offer.
        if ($project->exists(self::THEME_STYLESHEET)) {
            $report->note(
                'Sheaf was already initialised, so refit could not pass --with-phosphor to `sheaf:init`. '
                .'Install the Phosphor icons yourself with `composer require wireui/phosphoricons`, '
                .'or the ps: names will not resolve.',
            );
        }

        $plan->add(Stage::Reconcile, new PrefixIconNames('ps:', $this->vocabulary()));
    }

    public function planMigration(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void
    {
        $this->planInstall($plan, $project, $strategy);

        $stubs = new LayoutStubs;

        $stubs->contribute($plan, $project, $report);

        // Ahead of the rename, because both of its rewrites read Flux's own
        // arrangement — where a dropdown keeps its trigger, and what a modal
        // close button wraps.
        $plan->add(Stage::Reconcile, new RestructureOverlays);
        $plan->add(Stage::Reconcile, new MapComponentTags);

        // All three read the tags the rename produced, so all three come after it.
        // Each is a place where Sheaf's component renders what Flux's rendered,
        // but styles it differently enough that a rename alone leaves the view
        // looking broken: a logo slot whose classes are dropped, a menu panel that
        // is a grid, and text that no longer inherits its alignment.
        $plan->add(Stage::Reconcile, new RestructureBrandLogo);
        $plan->add(Stage::Reconcile, new PlaceDropdownChildren);
        $plan->add(Stage::Reconcile, new PreserveTextAlignment);
        $plan->add(Stage::Reconcile, new PromoteContentsToLabel);
        $plan->add(Stage::Reconcile, new ShapeSegmentedGroups);

        // Also after the rename — it reads `x-ui.dropdown`, which does not exist
        // until MapComponentTags has run. The chrome refit writes lands in the
        // sidebar already lifted; the kit components it merely puts there have to
        // be lifted where they live.
        if (($sidebar = $stubs->sidebarComponents($project)) !== []) {
            $plan->add(Stage::Reconcile, new RaiseSidebarDropdowns($sidebar));
        }

        // Independent of all of the above: light/dark is the one part of the kit
        // that lives in JavaScript, so it survives the rename untouched and
        // pointed at a magic that is about to stop existing.
        $plan->add(Stage::Reconcile, new RebindAppearanceToTheme);
    }

    /**
     * Nothing to take out.
     *
     * Sheaf's components are copied into `resources/views/components/ui` and are
     * the user's own files from that moment on, so a project leaving Sheaf is not
     * a project refit should be deleting application Blade from. There is no
     * override directory to unwind and no Blade directive to strip; `sheaf/cli`
     * and `theme.css` are a dependency decision, and refit says rather than does
     * those — the same line it draws around `composer remove livewire/flux`.
     *
     * Reachable only from a Sheaf project targeting something else, which refit
     * has no migration for yet. Empty because there is nothing to do, not because
     * it is waiting to be filled in.
     */
    public function planTeardown(Plan $plan, Project $project, Report $report): void
    {
        //
    }

    /**
     * Put Sheaf in the project, to whatever extent it is not there already.
     *
     * Three steps, in the only order they work in, and each skipped when it has
     * already been done — so this is as happy running against a bare kit as
     * against one where somebody has been using Sheaf for a month.
     *
     * All three are `required`, because everything after them rewrites views onto
     * what they install. A failure here stops the run before a single file has
     * changed, which is the whole reason the dependency stage runs first.
     */
    private function planInstall(Plan $plan, Project $project, IconStrategy $strategy): void
    {
        if (! (new Composer($project->root))->has(self::PACKAGE)) {
            $plan->add(Stage::Dependencies, new RunProcess(
                ['composer', 'require', self::PACKAGE, '--no-interaction'],
                'Installing Sheaf\'s CLI',
                required: true,
            ));
        }

        if (! $project->exists(self::THEME_STYLESHEET)) {
            $plan->add(Stage::Dependencies, new RunProcess(
                $this->initCommand($strategy),
                'Initialising Sheaf',
                required: true,
            ));
        }

        // `sheaf:init` writes its import above Tailwind's, which is far enough up
        // the file to push Tailwind's theme variables out of `@layer theme` — and
        // an unlayered `:root` beats the `.dark` overrides that make the accent
        // and primary colours flip. Planned blind when refit is the one running
        // the init, because the line it moves does not exist yet.
        $ordered = $project->exists(OrderThemeImport::STYLESHEET)
            && ! OrderThemeImport::misordered($project->get(OrderThemeImport::STYLESHEET));

        if (! $project->exists(self::THEME_STYLESHEET) || ! $ordered) {
            $plan->add(Stage::Write, new OrderThemeImport);
        }

        foreach ($this->missingComponents($project) as $component) {
            $plan->add(Stage::Dependencies, new RunProcess(
                ['php', 'artisan', 'sheaf:install', $component, '--no-interaction'],
                sprintf('Installing Sheaf\'s "%s"', $component),
                required: true,
            ));
        }
    }

    /**
     * `sheaf:init` and the flags this kit's answers imply.
     *
     * Both are one-time decisions baked in at init, which is why the icon answer
     * has to reach this far. Dark mode is not a guess either — every layout the
     * kit ships puts `class="dark"` on the `<html>` element.
     *
     * @return list<string>
     */
    private function initCommand(IconStrategy $strategy): array
    {
        $command = ['php', 'artisan', 'sheaf:init', '--skip-prompts', '--with-dark-mode'];

        if ($strategy === IconStrategy::Phosphor) {
            $command[] = '--with-phosphor';
        }

        return $command;
    }

    /**
     * Sheaf components this project needs and does not already have.
     *
     * The closure of what the map names, not just the map's own list. Sheaf's
     * CLI resolves only the dependencies a component's config declares, and those
     * configs under-declare: `dropdown` names `icon` alone, while its item writes
     * `<x-ui.kbd>` and `<x-ui.button>`. Installing the dropdown and trusting the
     * CLI therefore leaves a user menu that throws on the first render. Refit
     * asks for every component in the graph by name instead.
     *
     * @return list<string>
     */
    private function missingComponents(Project $project): array
    {
        $needed = [];

        foreach (Components::closure(ComponentMap::components()) as $component) {
            if ($this->hasComponent($project, $component)) {
                continue;
            }

            $needed[] = $component;
        }

        return $needed;
    }

    /**
     * Sheaf installs a component either as a directory of parts or as a single
     * file, depending on how many pieces it has, so both shapes count as present.
     */
    private function hasComponent(Project $project, string $component): bool
    {
        return $project->exists(self::COMPONENT_DIRECTORY.'/'.$component)
            || $project->exists(self::COMPONENT_DIRECTORY.'/'.$component.'.blade.php');
    }
}
