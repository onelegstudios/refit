<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Contracts;

use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\LibraryInstall;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Refit;

/**
 * A UI component library refit can leave a project on.
 *
 * Refit only supports the Livewire starter kit, and the kit ships Flux — so the
 * source library is always Flux and a `Library` is always the *target*. Each
 * target owns its own translation from Flux, which is what keeps a third library
 * to one class rather than a matrix of pairs.
 *
 * Flux itself implements this as the identity case: it detects and it supplies a
 * vocabulary, and its {@see planMigration()} does nothing, because a Flux kit is
 * already on Flux.
 *
 * Registered like tasks, through the {@see Refit} registry, so a package can add
 * its own without refit knowing anything about it.
 */
interface Library
{
    /**
     * Stable identifier, used by `--answers` and in tests.
     */
    public function key(): string;

    public function label(): string;

    public function hint(): string;

    /**
     * How this library spells its tags and icons.
     */
    public function vocabulary(): Vocabulary;

    /**
     * This library's footprint in a project, or null when it is not installed.
     */
    public function detect(string $root): ?LibraryInstall;

    /**
     * Reasons this project cannot be moved onto this library, as sentences the
     * user can act on. An empty list means go.
     *
     * Checked after the target is chosen rather than before, so the user is told
     * what to do about the library they asked for instead of having the option
     * quietly withheld.
     *
     * @return list<string>
     */
    public function preflight(Project $project): array;

    /**
     * The icon answers this target can offer.
     *
     * Libraries resolve icons differently enough that the question is theirs to
     * ask: Flux overrides artwork through a view directory, where Sheaf's icon
     * component is a file in the application that reads from an icon package.
     *
     * @return list<IconStrategy>
     */
    public function iconStrategies(): array;

    public function planIcons(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void;

    /**
     * Everything needed to move a Flux starter kit onto this library.
     *
     * Contributed before the icon work, so the icon sweeps run against the tags
     * the migration has already produced.
     *
     * It takes the icon answer because installing a library and choosing its icon
     * set can be the same decision: Sheaf picks up Phosphor through a flag on the
     * one-time `sheaf:init`, and there is no second chance to pass it.
     */
    public function planMigration(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void;
}
