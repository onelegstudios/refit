<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Feature;
use Onelegstudios\Refit\Project\ProjectDetector;

it('detects the component style each variant ships', function (string $kit, ComponentStyle $style): void {
    expect(detectFixture($kit)->componentStyle)->toBe($style);
})->with([
    ['livewire', ComponentStyle::SingleFile],
    ['livewire-class-components', ComponentStyle::ClassBased],
    ['livewire-teams', ComponentStyle::SingleFile],
    ['livewire-workos', ComponentStyle::SingleFile],
    ['livewire-workos-teams', ComponentStyle::SingleFile],
]);

it('detects teams support', function (string $kit, bool $teams): void {
    expect(detectFixture($kit)->has(Feature::Teams))->toBe($teams);
})->with([
    ['livewire', false],
    ['livewire-teams', true],
    ['livewire-workos', false],
    ['livewire-workos-teams', true],
]);

it('detects the authentication provider', function (string $kit, bool $workos): void {
    expect(detectFixture($kit)->has(Feature::WorkOs))->toBe($workos);
})->with([
    ['livewire', false],
    ['livewire-teams', false],
    ['livewire-workos', true],
    ['livewire-workos-teams', true],
]);

it('detects which auth features survived chiselling', function (): void {
    $fortify = detectFixture('livewire');

    expect($fortify->has(Feature::Passkeys))->toBeTrue()
        ->and($fortify->has(Feature::TwoFactor))->toBeTrue()
        ->and($fortify->has(Feature::Registration))->toBeTrue();

    // WorkOS hands authentication off entirely, so none of them exist.
    $workos = detectFixture('livewire-workos');

    expect($workos->has(Feature::Passkeys))->toBeFalse()
        ->and($workos->has(Feature::TwoFactor))->toBeFalse()
        ->and($workos->has(Feature::Registration))->toBeFalse();
});

it('finds auth views under either component style', function (): void {
    // The class variant keeps them in resources/views/livewire, and the
    // single-file variant prefixes the filename, so neither path is literal.
    expect(detectFixture('livewire-class-components')->has(Feature::Registration))->toBeTrue()
        ->and(detectFixture('livewire')->has(Feature::Registration))->toBeTrue();
});

it('still finds the auth views once they have moved out of pages', function (): void {
    $root = copyFixture('livewire');

    rename($root.'/resources/views/pages/auth', $root.'/resources/views/auth');

    $project = (new ProjectDetector)->detect($root);

    expect($project->has(Feature::TwoFactor))->toBeTrue()
        ->and($project->has(Feature::Registration))->toBeTrue();
});

it('reports Flux Pro as absent for a stock kit', function (string $kit): void {
    expect(detectFixture($kit)->library(FluxLibrary::KEY)?->pro)->toBeFalse();
})->with(starterKits());

it('notices chisel has not run yet', function (): void {
    expect(detectFixture('livewire')->chiselPending)->toBeTrue();
});

it('lists every Blade file as a project-relative path', function (): void {
    $blades = detectFixture('livewire')->blades();

    expect($blades)->toContain('resources/views/layouts/app/sidebar.blade.php')
        ->and($blades)->each->toStartWith('resources/views/');
});
