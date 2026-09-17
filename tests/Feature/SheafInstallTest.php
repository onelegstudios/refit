<?php

declare(strict_types=1);

use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Libraries\SheafLibrary;

it('installs Sheaf itself rather than refusing to run', function (): void {
    $root = copyFixture('livewire');

    // A kit that has never heard of Sheaf: no CLI, no theme, no components.
    $steps = implode("\n", installSteps($root));

    expect($steps)->toContain('composer require sheaf/cli')
        ->toContain('php artisan sheaf:init')
        ->toContain('php artisan sheaf:install button');

    // In that order, because each one needs the last to have worked.
    expect(strpos($steps, 'composer require'))->toBeLessThan(strpos($steps, 'sheaf:init'))
        ->and(strpos($steps, 'sheaf:init'))->toBeLessThan(strpos($steps, 'sheaf:install'));
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('skips the steps a project has already taken', function (): void {
    $root = sheafKit('livewire');
    $steps = implode("\n", installSteps($root));

    expect($steps)->not->toContain('composer require')
        ->not->toContain('sheaf:init')
        // Every component is already on disk in this fixture.
        ->not->toContain('sheaf:install');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('bakes the icon answer into sheaf:init, which only runs once', function (): void {
    $root = copyFixture('livewire');

    expect(implode("\n", installSteps($root, IconStrategy::Phosphor)))
        ->toContain('--with-phosphor')
        // The kit puts class="dark" on every <html> it ships.
        ->toContain('--with-dark-mode');

    expect(implode("\n", installSteps($root, IconStrategy::Heroicons)))
        ->not->toContain('--with-phosphor');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('stops before touching a view when an install step fails', function (): void {
    $root = sheafKit('livewire', withComponents: false);

    // No vendor directory, so `php artisan` cannot run at all — which is exactly
    // the shape of being offline, or of a pro component needing sheaf:login.
    $before = file_get_contents($root.'/resources/views/layouts/app/sidebar.blade.php');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'sheaf', 'icons' => 'heroicons']),
    ])->assertFailed();

    expect(file_get_contents($root.'/resources/views/layouts/app/sidebar.blade.php'))->toBe($before)
        ->and(fluxTagsUnder($root))->not->toBe([]);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('plans the npm primitive Sheaf\'s own installer leaves out', function (): void {
    $root = copyFixture('livewire');

    // `sheaf:install select` writes a runtime built on `$rover` and declares no
    // external dependency for it, so the component arrives complete and dead.
    expect(implode("\n", installSteps($root)))->toContain('npm install @sheaf/rover');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('leaves the npm step out once the primitive is a dependency', function (): void {
    $root = sheafKit('livewire');

    expect(implode("\n", installSteps($root)))->not->toContain('npm install');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('reinstalls a component whose folder is there and whose files are not', function (): void {
    $root = sheafKit('livewire');
    $ui = $root.'/'.SheafLibrary::COMPONENT_DIRECTORY;

    // A reset that removed the files and left the folders behind.
    foreach ((array) glob($ui.'/button/*') as $file) {
        unlink((string) $file);
    }

    // And one that took a single part, which is the OTP input refit patches.
    unlink($ui.'/otp/input.blade.php');

    $steps = implode("\n", installSteps($root));

    // With --force, because Sheaf's "already exists" question has a default
    // that is not one of its answers, and dies on it without a terminal.
    expect($steps)->toContain('sheaf:install button --no-interaction --force')
        ->toContain('sheaf:install otp --no-interaction --force')
        ->not->toContain('sheaf:install navlist');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('plans a sheaf:install for every component it needs and does not have', function (): void {
    $root = sheafKit('livewire', withComponents: false);
    $steps = implode("\n", installSteps($root));

    expect($steps)->toContain('sheaf:install button')
        ->toContain('sheaf:install navlist')
        // Nothing there to overwrite, so nothing to force.
        ->not->toContain('--force');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('installs what a component needs as well as the component', function (): void {
    $root = sheafKit('livewire', withComponents: false);
    $steps = implode("\n", installSteps($root));

    // The dropdown's own config declares `icon` and nothing else, but its item
    // renders <x-ui.kbd>. Leaving that to Sheaf's resolver is what made the user
    // menu throw "Unable to locate a class or view for component [ui.kbd]" on
    // the first page load after a migration.
    expect($steps)->toContain('sheaf:install dropdown')
        ->toContain('sheaf:install kbd');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('installs something for every component tag the chrome stubs write', function (): void {
    $installed = Components::closure(ComponentMap::components());
    $written = [];

    foreach ((array) glob(__DIR__.'/../../stubs/sheaf/*/*.blade.php.stub') as $stub) {
        preg_match_all('/<'.preg_quote(ComponentMap::PREFIX, '/').'([a-z0-9-]+)/', (string) file_get_contents((string) $stub), $matches);

        foreach ($matches[1] as $component) {
            $written[$component] = true;
        }
    }

    expect($written)->not->toBe([])
        ->and(array_values(array_diff(array_keys($written), $installed)))->toBe([]);
});
