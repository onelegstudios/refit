<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Plan\Actions\ApplyLedgerRenames;
use Onelegstudios\Refit\Plan\Actions\FlattenLayoutFamily;
use Onelegstudios\Refit\Plan\Actions\RemoveDirectoryIfEmpty;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\RenameLedger;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;

/**
 * Put every layout in the layouts directory, and lose the folders.
 *
 * `layouts/app.blade.php` does nothing but render `layouts/app/sidebar.blade.php`
 * around its slot, so the shell every view names is in a file no view names. The
 * variant each delegating layout renders is folded into it, the rest move up
 * beside it as `layouts/app-header.blade.php`, and the folders go:
 *
 *     resources/views/layouts/
 *     ├── app.blade.php          ← was app/sidebar.blade.php
 *     ├── app-header.blade.php   ← was app/header.blade.php
 *     ├── auth.blade.php         ← was auth/simple.blade.php
 *     ├── auth-card.blade.php
 *     └── auth-split.blade.php
 *
 * Which one is folded is read from the delegating layout, as {@see KeepOneLayout}
 * reads it, so this cannot pick a different shell than the application already
 * has. The two tasks answer different questions — whether the variants nothing
 * renders are worth keeping, and whether the ones that stay need a folder — so
 * either is worth running without the other.
 *
 * It all happens in the reconcile stage, once the tree has settled: pick this and
 * the deletions together and there is nothing left for this to flatten, which is
 * the whole point of asking both.
 */
final class FlattenLayouts implements Task
{
    /**
     * @var list<string>
     */
    private const array FAMILIES = ['auth', 'app'];

    /**
     * @param  list<string>  $families  each the delegating layout's filename and the folder of variants beside it
     */
    public function __construct(private readonly array $families = self::FAMILIES) {}

    public function key(): string
    {
        return 'flatten-layouts';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Structure;
    }

    public function label(): string
    {
        return 'Flatten the layout folders';
    }

    public function hint(): string
    {
        return 'layouts/app.blade.php holds the shell itself instead of handing off to layouts/app/sidebar.blade.php';
    }

    public function appliesTo(Project $project): bool
    {
        foreach ($this->families as $family) {
            if ($this->variants($project, $family) !== []) {
                return true;
            }
        }

        return false;
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $ledger = new RenameLedger;
        $flattening = false;

        foreach ($this->families as $family) {
            if ($this->variants($project, $family) === []) {
                continue;
            }

            $plan->add(Stage::Reconcile, new FlattenLayoutFamily($family, $ledger));
            $plan->add(Stage::Reconcile, new RemoveDirectoryIfEmpty($this->directory($family)));

            $flattening = true;
        }

        if ($flattening) {
            $plan->add(Stage::Reconcile, new ApplyLedgerRenames(
                $ledger,
                'every reference to a layout that left its folder',
            ));
        }
    }

    private function directory(string $family): string
    {
        return sprintf('resources/views/layouts/%s', $family);
    }

    /**
     * @return list<string>
     */
    private function variants(Project $project, string $family): array
    {
        $matches = glob($project->path($this->directory($family)).'/*.blade.php');

        return $matches === false ? [] : $matches;
    }
}
