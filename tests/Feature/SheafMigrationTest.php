<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Icons\IconMap;
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

it('keeps the auth pages centred once Sheaf owns their alignment', function (): void {
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
    $header = $project->get('resources/views/components/auth-header.blade.php');

    // The wrapper still says text-center, but Sheaf's heading declares text-start
    // of its own and its text defaults to it, so neither inherits any more.
    expect($header)->toContain('text-center')
        ->toContain('<x-ui.heading class="text-center!"')
        ->toContain('<x-ui.text class="text-center!"');
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

it('gives the user menu the row a Sheaf nav item would have had', function (): void {
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

    // Sheaf's navs indent their items by a navlist's gutter of 2. The menu is the
    // last row of the same column, and outside a navlist it runs edge to edge.
    expect($project->get('resources/views/layouts/app/sidebar.blade.php'))
        ->toContain("<x-ui.navlist class=\"max-lg:hidden\">\n                    <x-desktop-user-menu");

    // And a ghost button is taller, squarer, darker and heavier than a nav item,
    // and hovers neutral where every Sheaf nav hovers on the primary.
    expect($project->get('resources/views/components/desktop-user-menu.blade.php'))
        ->toContain('rounded-box')
        ->toContain('py-1 ps-3! pe-1! font-normal!')
        ->toContain('hover:bg-[--alpha(var(--color-primary)_/5%)]!')
        ->toContain('hover:text-[var(--color-primary)]!')
        // And the chevron ends the row rather than trailing the name, the way the
        // kit's profile had it. Sheaf files a trailing icon under `left-icon`.
        ->toContain('[&>[data-slot=left-icon]]:ms-auto')
        // The panel opens no narrower than the row it belongs to. A minimum
        // rather than a width, so it still grows for a long address.
        ->toContain('<x-slot:menu class="min-w-60">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('composes the header layout the way Sheaf\'s grid reads it', function (): void {
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
    $header = $project->get('resources/views/layouts/app/header.blade.php');

    // Sheaf's header-sidebar variant keeps a row for the header and a row for the
    // sidebar and main. All three have to be children of the layout to land in
    // them — a header nested inside the main leaves the header row empty, which
    // is a screen-height gap above the page.
    expect(strpos($header, '<x-ui.layout.header'))->toBeLessThan((int) strpos($header, '<x-ui.sidebar '))
        ->and(strpos($header, '<x-ui.sidebar '))->toBeLessThan((int) strpos($header, '<x-ui.layout.main'));

    // The kit centred the bar on a container instead of running it the width of
    // the screen, and measured its gutter from the edge — so the header gives up
    // the padding of 2 it would otherwise keep for itself.
    expect($header)->toContain('<div class="mx-auto flex h-full w-full max-w-7xl items-center px-6 lg:px-8">')
        ->toContain('<x-ui.layout.header class="border-b border-zinc-200 bg-zinc-50 p-0!');

    // And the kit's own main goes, because the stub renders one now.
    expect($project->get('resources/views/layouts/app.blade.php'))
        ->not->toContain('layout.main')
        ->toContain('{{ $slot }}');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('gives the page inside Sheaf\'s main the height and padding the kit\'s had', function (string $fixture, string $layout): void {
    $root = sheafKit($fixture);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $main = (new ProjectDetector)->detect($root)->get($layout);

    // Sheaf's main is a plain block, so a page that sizes itself against it — the
    // kit's dashboard fills the screen — has nothing to measure and collapses to
    // the height of its own borders. The column, and the child that grows into
    // it, are what the kit's `<flux:main>` gave those pages for free.
    // The important is not decoration: Sheaf pads main's children from the main
    // itself, with a selector a plain utility loses to.
    expect($main)->toContain('<x-ui.layout.main class="flex flex-col')
        ->toContain('<div class="flex flex-1 flex-col p-6! lg:p-8!">');
})->with([
    'sidebar' => ['livewire', 'resources/views/layouts/app/sidebar.blade.php'],
    'header' => ['livewire', 'resources/views/layouts/app/header.blade.php'],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('names only icons refit\'s own table knows in the chrome it writes', function (): void {
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

    // Heroicons the table knows: the ones it can translate, plus the four it
    // translates the kit's vendored Lucide names into.
    $known = array_merge(
        array_keys(IconMap::HEROICONS_TO_LUCIDE),
        array_values(IconMap::LUCIDE_TO_HEROICONS),
    );

    $files = [
        'resources/views/layouts/app/sidebar.blade.php',
        'resources/views/layouts/app/header.blade.php',
        'resources/views/components/desktop-user-menu.blade.php',
    ];

    // The stubs are written rather than renamed, so nothing upstream checks the
    // names in them. A name outside the table is one the Phosphor run prefixes
    // into nothing and the report never mentions — the kit's own `cog` spelled
    // `cog-6-tooth` is a different glyph that fails exactly that quietly.
    $unknown = [];

    foreach ($files as $file) {
        preg_match_all('/\bicon="([^"]+)"/', $project->get($file), $matches);

        foreach ($matches[1] as $name) {
            if (! in_array($name, $known, true)) {
                $unknown[] = $file.': '.$name;
            }
        }
    }

    expect($unknown)->toBe([]);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('keeps the header\'s utility links down to the icons the kit showed', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    $header = (new ProjectDetector)->detect($root)->get('resources/views/layouts/app/header.blade.php');

    // The kit's navbar item drew its label from slot content and these three were
    // given none, so they were icons with a tooltip for a name. Sheaf's item draws
    // its label unconditionally, and left alone the bar reads Search Repository
    // Documentation in full.
    foreach (['Search', 'Repository', 'Documentation'] as $name) {
        expect($header)->toContain(':aria-label="__(\''.$name.'\')"')
            ->toContain('<x-ui.tooltip.content>{{ __(\''.$name.'\') }}</x-ui.tooltip.content>');
    }

    // The label is taken out of the row rather than left to render, and only for
    // those three: the Dashboard item beside them showed one in the kit and still
    // renders it rather than answering to a tooltip.
    expect(substr_count($header, '[&>span]:hidden'))->toBe(3)
        ->and($header)->toContain(':label="__(\'Dashboard\')"')
        ->not->toContain(':aria-label="__(\'Dashboard\')"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('sizes the header layout\'s main to the row the grid left it', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['remove-flux'],
        ]),
    ])->assertSuccessful();

    // Sheaf's main asks for a screen of height whichever variant it lands in. In
    // the header one the grid already spent a header on the row above it, so a
    // screen is a header too many and the layout clips the overflow — the bottom
    // of every page, unreachable.
    expect((new ProjectDetector)->detect($root)->get('resources/views/layouts/app/header.blade.php'))
        ->toContain('min-h-0! max-h-full!');

    // The sidebar variant gives its main the whole grid, and starts its children
    // rather than stretching them, so there the screen is the right answer.
    expect((new ProjectDetector)->detect($root)->get('resources/views/layouts/app/sidebar.blade.php'))
        ->not->toContain('min-h-0!');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
