<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\ProjectDetector;

/**
 * A copied fixture that looks like `composer require sheaf/cli` and
 * `php artisan sheaf:init` have both been run.
 *
 * Fixtures are raw checkouts with no vendor directory, so this is the only way
 * to exercise a Sheaf target at all — and it is honest, because those two
 * commands are exactly what refit's preflight insists on.
 */
function sheafKit(string $kit, bool $withComponents = true): string
{
    $root = copyFixture($kit);

    @unlink($root.'/chisel.php');

    $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true);
    $manifest['require-dev'][SheafLibrary::PACKAGE] = '^1.0';
    file_put_contents($root.'/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/'.SheafLibrary::THEME_STYLESHEET, ":root { --color-accent: #000; }\n");

    if ($withComponents) {
        // Stand in for what `sheaf:install` would have written, so the plan has
        // no work to do at the dependency stage and the test is about rewriting.
        foreach (Components::closure(ComponentMap::components()) as $component) {
            @mkdir($root.'/'.SheafLibrary::COMPONENT_DIRECTORY.'/'.$component, 0755, true);
        }
    }

    app()->setBasePath($root);

    return $root;
}

/**
 * @return list<string>
 */
function fluxTagsUnder(string $root): array
{
    $project = (new ProjectDetector)->detect($root);
    $parser = new TagParser;
    $found = [];

    foreach ($project->blades() as $path) {
        foreach ($parser->parse($project->get($path), 'flux:') as $tag) {
            $found[$path.': '.$tag->name] = true;
        }
    }

    return array_keys($found);
}

/**
 * The Dependencies stage of a Sheaf plan, as described lines.
 *
 * @return list<string>
 */
function installSteps(string $root, IconStrategy $strategy = IconStrategy::Heroicons): array
{
    $plan = new Plan;

    (new SheafLibrary)->planMigration($plan, (new ProjectDetector)->detect($root), $strategy, new Report);

    return array_map(
        fn ($action): string => $action->describe(),
        $plan->grouped()[Stage::Dependencies->name] ?? [],
    );
}

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

it('plans a sheaf:install for every component it needs and does not have', function (): void {
    $root = sheafKit('livewire', withComponents: false);
    $steps = implode("\n", installSteps($root));

    expect($steps)->toContain('sheaf:install button')
        ->toContain('sheaf:install navlist');
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

it('leaves no Flux tag behind anywhere in the tree', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    expect(fluxTagsUnder($root))->toBe([]);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);

it('only ever produces components Sheaf actually ships', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $known = sheafComponents();
    $project = (new ProjectDetector)->detect($root);
    $parser = new TagParser;
    $unknown = [];

    foreach ($project->blades() as $path) {
        foreach ($parser->parse($project->get($path), 'x-ui.') as $tag) {
            $name = substr($tag->name, strlen('x-ui.'));

            if (! in_array($name, $known, true)) {
                $unknown[$name] = true;
            }
        }
    }

    expect(array_keys($unknown))->toBe([]);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);

it('takes the Flux directives and overrides out with it', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->exists('resources/views/flux'))->toBeFalse()
        ->and($project->get('resources/css/app.css'))->not->toContain('livewire/flux')
        ->and($project->get('resources/views/partials/head.blade.php'))->not->toContain('@fluxAppearance');

    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain('@fluxScripts');
    }
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('points the kit\'s vendored Lucide names back at Heroicons', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $names = array_keys(sheafScanner()->scan((new ProjectDetector)->detect($root)));

    // The four the kit vendors as Flux overrides have no artwork once Flux is
    // gone, so they have to name something Heroicons has.
    expect($names)->not->toContain('folder-git-2')
        ->and($names)->not->toContain('book-open-text')
        ->and($names)->not->toContain('chevrons-up-down')
        ->and($names)->not->toContain('layout-grid');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('prefixes every icon name when Phosphor is asked for', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'phosphor',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $names = array_keys(sheafScanner()->scan((new ProjectDetector)->detect($root)));

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect($name)->toStartWith('ps:');
    }
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('keeps the logo tile Sheaf\'s brand would have dropped', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $logo = $project->get('resources/views/components/app-logo.blade.php');

    // Sheaf's brand renders {{ $logo }} and nothing else, so the accent tile has
    // to be an element rather than attributes on the slot. Without it the mark is
    // white on a white sidebar and black on a black one.
    expect($logo)->toContain('<x-slot name="logo">')
        ->toContain('<div class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">')
        ->not->toContain('<x-slot name="logo" class=');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('gives everything in a dropdown menu a place in Sheaf\'s grid', function (): void {
    $root = sheafKit('livewire-teams');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $menu = $project->get('resources/views/components/desktop-user-menu.blade.php');

    // The panel is a three-column grid: the profile block spans it, and the form
    // steps out of the way so its item is the grid child.
    expect($menu)->toContain('<div class="col-span-full flex items-center')
        ->toContain('<form method="POST" action="{{ route(\'logout\') }}" class="contents">');

    // Sheaf renders the modal trigger's outer element, so it is wrapped instead.
    expect($project->get('resources/views/components/⚡team-switcher.blade.php'))
        ->toContain('<div class="col-span-full">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
