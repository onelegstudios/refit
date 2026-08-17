<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Applier;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Project\ProjectDetector;

/**
 * Tear one library out of a throwaway copy of a fixture.
 *
 * The teardown is a plan contribution like any other, so it can be exercised on
 * its own — no target, no icon answer, no migration in front of it.
 *
 * @return array{Project, Report}
 */
function runTeardown(string $kit): array
{
    $root = copyFixture($kit);
    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;
    $report = new Report;

    (new FluxLibrary)->planTeardown($plan, $project, $report);

    (new Applier)->apply($plan, $project, $report);

    return [$project, $report];
}

it('drops the Flux @source lines from the stylesheet', function (string $kit): void {
    [$project] = runTeardown($kit);

    expect($project->get('resources/css/app.css'))
        ->not->toContain('livewire/flux')
        // Neither edition, and nothing else in the file moved.
        ->not->toContain('flux-pro')
        ->toContain('@import \'tailwindcss\'');
})->with(starterKits());

it('drops the Blade directives that fatal once the package is gone', function (string $kit): void {
    [$project] = runTeardown($kit);

    foreach ($project->blades() as $path) {
        expect($project->get($path))
            ->not->toContain('@fluxAppearance')
            ->not->toContain('@fluxScripts');
    }
})->with(starterKits());

it('deletes the override directory and everything under it', function (string $kit): void {
    [$project] = runTeardown($kit);

    expect($project->exists('resources/views/flux'))->toBeFalse();
})->with(starterKits());

it('takes an override the project added itself, not just the kit\'s', function (): void {
    $root = copyFixture('livewire');

    // Nothing in here is refit's to keep: the directory is a Flux extension
    // point, so a hand-written override is exactly as dead as a vendored icon.
    @mkdir($root.'/resources/views/flux/navlist', 0755, true);
    file_put_contents($root.'/resources/views/flux/navlist/item.blade.php', '<div>mine</div>');

    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;
    $report = new Report;

    (new FluxLibrary)->planTeardown($plan, $project, $report);
    (new Applier)->apply($plan, $project, $report);

    expect($project->exists('resources/views/flux'))->toBeFalse();
});

it('says what it will not do about the package itself', function (): void {
    [, $report] = runTeardown('livewire');

    expect($report->notes())->toContain(
        'Flux is no longer referenced. Run `composer remove livewire/flux` to drop the package itself.',
    );
});

it('leaves a project alone when the library being left is Sheaf', function (): void {
    $root = copyFixture('livewire');
    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;

    (new SheafLibrary)->planTeardown($plan, $project, new Report);

    // Sheaf's components are copied into the application and belong to the user
    // from that moment on, so there is nothing refit may take back out.
    expect($plan->isEmpty())->toBeTrue();
});
