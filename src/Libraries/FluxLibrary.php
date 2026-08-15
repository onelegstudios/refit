<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries;

use Onelegstudios\Refit\Contracts\Library;
use Onelegstudios\Refit\Icons\IconScanner;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Flux\IconPlanner;
use Onelegstudios\Refit\Libraries\Flux\OverrideGenerator;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\LibraryInstall;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Support\Composer;

/**
 * Flux — what the Livewire starter kit ships, and the identity target.
 *
 * Choosing Flux means keeping the library the kit already installed, so
 * {@see planMigration()} does nothing. That is not a placeholder: a project that
 * is already on Flux has nothing to migrate, and saying so in one empty method is
 * more honest than pretending the answer is symmetric with the others.
 *
 * The icon work is where Flux earns a class of its own. It resolves an icon by
 * bare name and lets a Blade file at `resources/views/flux/icon/{name}.blade.php`
 * take precedence, which is a mechanism no other library refit knows about has.
 */
final class FluxLibrary implements Library
{
    public const string KEY = 'flux';

    public const string PACKAGE = 'livewire/flux';

    public const string PRO_PACKAGE = 'livewire/flux-pro';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Flux — keep the library the kit ships';
    }

    public function hint(): string
    {
        return 'Changes no components. The icon question still applies.';
    }

    public function vocabulary(): Vocabulary
    {
        return new Vocabulary(
            prefix: 'flux:',
            iconTag: 'flux:icon',
            iconTagAttribute: 'name',
            // `icon:variant` is deliberately absent — it takes an appearance
            // keyword such as `outline`, not a name, and translating it would
            // corrupt the tag.
            nameAttributes: ['icon', 'icon-leading', 'icon-trailing', 'icon:leading', 'icon:trailing'],
            // The kit writes all three forms: `<flux:icon.key />`, `icon="key"`,
            // and `<flux:icon name="key" />`.
            dottedIconTag: 'flux:icon.',
            variantAttribute: 'variant',
            iconVariantAttribute: 'icon:variant',
        );
    }

    /**
     * Flux Pro is never in composer.json — it is a private repository the user
     * adds themselves — so the lock file is the honest signal.
     */
    public function detect(string $root): ?LibraryInstall
    {
        $composer = new Composer($root);

        if (! $composer->has(self::PACKAGE)) {
            return null;
        }

        return new LibraryInstall(
            key: self::KEY,
            pro: $composer->has(self::PRO_PACKAGE),
        );
    }

    /**
     * Nothing to check. Staying on Flux asks nothing of the project that running
     * refit at all has not already asked.
     */
    public function preflight(Project $project): array
    {
        return [];
    }

    public function iconStrategies(): array
    {
        return [IconStrategy::Keep, IconStrategy::Heroicons, IconStrategy::Lucide];
    }

    public function planIcons(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void
    {
        $vocabulary = $this->vocabulary();

        (new IconPlanner($vocabulary, new IconScanner($vocabulary), new OverrideGenerator))
            ->contribute($plan, $project, $strategy, $report);
    }

    /**
     * A Flux kit is already on Flux.
     */
    public function planMigration(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void
    {
        //
    }
}
