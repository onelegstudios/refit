<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\OrderThemeImport;
use Onelegstudios\Refit\Plan\Actions\WireSheafRuntimes;
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

    // And the npm primitive the select's runtime is built on, so the plan has no
    // `npm install` to run either.
    $package = json_decode((string) file_get_contents($root.'/package.json'), true);
    $package['dependencies'][WireSheafRuntimes::PRIMITIVE] = '^1.0.4';
    file_put_contents($root.'/package.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/'.SheafLibrary::THEME_STYLESHEET, ":root { --color-accent: #000; }\n");

    // And `sheaf:init` does not only write that file — it prepends the import for
    // it to the top of the stylesheet, above Tailwind's own.
    file_put_contents(
        $root.'/'.OrderThemeImport::STYLESHEET,
        "@import './theme.css'; /* By Sheaf.dev */\n".file_get_contents($root.'/'.OrderThemeImport::STYLESHEET),
    );

    if ($withComponents) {
        // Stand in for what `sheaf:install` would have written, so the plan has
        // no work to do at the dependency stage and the test is about rewriting.
        foreach (Components::closure(ComponentMap::components()) as $component) {
            @mkdir($root.'/'.SheafLibrary::COMPONENT_DIRECTORY.'/'.$component, 0755, true);
        }

        // Including the halves it writes into resources/js and imports nowhere:
        // a magic in `globals`, an `Alpine.data()` in `components`.
        @mkdir($root.'/'.WireSheafRuntimes::GLOBALS, 0755, true);
        file_put_contents($root.'/'.WireSheafRuntimes::GLOBALS.'/modals.js', "document.addEventListener('alpine:init', () => {});\n");

        @mkdir($root.'/'.WireSheafRuntimes::RUNTIMES, 0755, true);
        file_put_contents($root.'/'.WireSheafRuntimes::RUNTIMES.'/select.js', "this.\$rover.options\nAlpine.data('selectComponent', () => ({}));\n");
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
 * The Reconcile stage of a Sheaf plan, as described lines.
 *
 * @return list<string>
 */
function reconcileSteps(string $root, IconStrategy $strategy = IconStrategy::Heroicons): array
{
    $plan = new Plan;

    (new SheafLibrary)->planMigration($plan, (new ProjectDetector)->detect($root), $strategy, new Report);

    return array_map(
        fn ($action): string => $action->describe(),
        $plan->grouped()[Stage::Reconcile->name] ?? [],
    );
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
        ]),
    ])->assertSuccessful();

    expect(fluxTagsUnder($root))->toBe([]);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);

it('gives the kit\'s toasts something that is listening for them', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $raised = [];

    foreach ([...$project->blades(), ...$project->livewireClasses()] as $path) {
        $source = $project->get($path);

        // The container is only half of it. A Flux::toast() left behind still
        // runs and still succeeds — it just dispatches an event that nothing on
        // the page answers any more, which is a form that saves in silence.
        expect($source)->not->toContain('Flux::toast')
            ->not->toContain('use Flux\\Flux;');

        if (str_contains($source, "dispatch('notify'")) {
            $raised[] = $path;
        }
    }

    expect($raised)->not->toBeEmpty();

    // And the thing they are dispatched at is in the layout.
    $layouts = array_values(array_filter(
        $project->blades(),
        fn (string $path): bool => str_starts_with($path, 'resources/views/layouts/'),
    ));

    expect(array_filter($layouts, fn (string $path): bool => str_contains($project->get($path), '<x-ui.toast')))
        ->not->toBeEmpty();
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

it('leaves the auth pages a button worth pressing', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // Sheaf's `solid` is a 5% neutral wash — the quiet one of its set — so
    // translating Flux's `primary` into it demoted every submit in the kit to the
    // look of the link beside it. The word means the same thing in both, and in
    // Sheaf it is what the component falls back to with no variant at all.
    foreach (['login', 'register', 'forgot-password', 'reset-password', 'confirm-password', 'verify-email'] as $page) {
        expect($project->get("resources/views/pages/auth/{$page}.blade.php"))
            ->toContain('variant="primary"')
            ->not->toContain('variant="solid"');
    }

    // The settings pages write the same button for the same reason, so they move
    // with the auth ones rather than ending up a second visual language.
    expect($project->get('resources/views/pages/settings/⚡profile.blade.php'))
        ->toContain('<x-ui.button variant="primary" type="submit" class="w-full" data-test="update-profile-button">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('settles light or dark before the first paint', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $head = $project->get('resources/views/partials/head.blade.php');

    // Flux's @fluxAppearance did this synchronously in the head, and its teardown
    // takes it away. Sheaf's replacement registers on `alpine:init` and arrives
    // through a deferred module, so without a pre-paint script the hardcoded
    // `dark` class is what the reader sees first and the correction is what they
    // see next — the whole page snapping to light on every load.
    expect($head)->not->toContain('@fluxAppearance')
        ->toContain("localStorage.getItem('theme') ?? 'system'")
        ->toContain("document.documentElement.classList.toggle('dark', dark)")
        // And once more per navigation: wire:navigate writes the incoming
        // document's <html> attributes onto the live one, so the hardcoded
        // `class="dark"` comes back on every link the reader clicks, and Livewire
        // re-runs no head script the page already has.
        ->toContain("document.addEventListener('livewire:navigated', window.applyStoredTheme)");

    // Ahead of the tags: @vite is a module either way, so the only thing that can
    // beat the paint is an inline script above it.
    expect(strpos($head, '<script>'))->toBeLessThan((int) strpos($head, '@vite'));

    // One script, in the one file every layout includes.
    $scripts = array_filter(
        $project->blades(),
        fn (string $path): bool => str_contains($project->get($path), 'classList.toggle(\'dark\''),
    );

    expect(array_values($scripts))->toBe(['resources/views/partials/head.blade.php']);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('follows the head when a task turns it into a component', function (): void {
    $root = sheafKit('livewire');

    // The partials task moves partials/head.blade.php to components/head.blade.php
    // in the move stage. This runs in the reconcile stage, over the settled tree,
    // so it finds the head there rather than writing to a path that has gone.
    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['partials-to-components'],
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->exists('resources/views/partials/head.blade.php'))->toBeFalse()
        ->and($project->get('resources/views/components/head.blade.php'))
        ->toContain("localStorage.getItem('theme') ?? 'system'");
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('keeps the logo tile Sheaf\'s brand would have dropped', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
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
        ->toContain('<x-slot:menu class="z-[100]! min-w-60">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('empties the user menu to an avatar when the sidebar collapses', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $menu = $project->get('resources/views/components/desktop-user-menu.blade.php');

    // A collapsed sidebar is 64px of icons, and Sheaf empties a navlist item down
    // to one. The trigger goes the same way: the name and the chevron leave, and
    // an avatar of 8 in a padding of 0.5 keeps the 36px square the icons stand in
    // — width included, or the hover tint would run the full width of the row.
    expect($menu)
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:w-auto')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:justify-center')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:p-0.5!')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:[&>[data-slot=left-icon]]:hidden')
        ->toContain('<span class="truncate [[data-collapsed]_[data-slot=sidebar]_&]:hidden">');

    // Sheaf stamps the collapse on the layout, which the header sits under as
    // well, and this is the header's menu too. So every rule names the sidebar,
    // and the header's copy keeps its name at a width where the sidebar is
    // already collapsed underneath it.
    expect($menu)->not->toContain('[[data-collapsed]_&]');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('composes the header layout the way Sheaf\'s grid reads it', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
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

it('labels the items Sheaf would otherwise render empty', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // Flux read a nav item's text out of its slot. Sheaf's renders `{{ $label }}`
    // and never touches the slot, so the settings sub-navigation came out as three
    // links with a href, a hover state and no text at all.
    expect($project->get('resources/views/pages/settings/layout.blade.php'))
        ->toContain('<x-ui.navlist.item :href="route(\'profile.edit\')" wire:navigate :label="__(\'Profile\')" />')
        ->toContain('<x-ui.navlist.item :href="route(\'security.edit\')" wire:navigate :label="__(\'Security\')" />')
        ->toContain('<x-ui.navlist.item :href="route(\'appearance.edit\')" wire:navigate :label="__(\'Appearance\')" />')
        ->not->toContain('</x-ui.navlist.item>');

    // And the appearance page's segmented control loses its three words the same
    // way, through `x-ui.radio.item`.
    expect($project->get('resources/views/pages/settings/⚡appearance.blade.php'))
        ->toContain(':label="__(\'Light\')" />')
        ->toContain(':label="__(\'Dark\')" />')
        ->toContain(':label="__(\'System\')" />');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('labels the form controls Sheaf renders bare, and says why they were rejected', function (): void {
    $root = sheafKit('livewire-teams');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // Flux's input was the label, the control and the space between them. Sheaf's
    // is the control alone, and the label it was handed goes nowhere — so every
    // field of every auth page arrived as an unlabelled box.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-ui.label :text="__(\'Email address\')" />')
        ->toContain('<x-ui.label :text="__(\'Password\')" />')
        ->toContain('<x-ui.field>')
        // The label came off the control rather than being copied onto a second
        // tag — from there it renders nowhere.
        ->not->toContain('<x-ui.input name="email" :label=');

    // The select declares a `label` prop and then never renders it, which looks
    // like the one case a rename would have got right and is not.
    expect($project->get('resources/views/pages/teams/⚡invite-member-modal.blade.php'))
        ->toContain('<x-ui.label :text="__(\'Role\')" />')
        ->toContain('<x-ui.select wire:model="inviteRole" data-test="invite-role">');

    // And the code field keeps its label hidden, the way `label:sr-only` had it.
    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->toContain('<x-ui.label class="sr-only" text="OTP Code" />')
        ->not->toContain('label:sr-only');

    // The checkbox is not a target: Sheaf's own renders the label it is given.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-ui.checkbox name="remember" :label="__(\'Remember me\')"');

    // Flux's input drew the validation message too, and the kit's auth pages have
    // no @error block of their own — so a failed login said nothing at all.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-ui.error name="email" />');

    // Keyed off the Livewire property where the control has no name.
    expect($project->get('resources/views/pages/settings/⚡security.blade.php'))
        ->toContain('<x-ui.error name="current_password" />')
        ->toContain('<x-ui.error name="password" />');

    // The recovery code field is the one input the kit labels nowhere and errors
    // itself, and it is left exactly as it was.
    expect($project->get('resources/views/pages/auth/two-factor-challenge.blade.php'))
        ->toContain('@error(\'recovery_code\')')
        ->not->toContain('<x-ui.error name="recovery_code" />');

    // And the password fields keep the eye Flux drew from `viewable`.
    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('revealable')
        ->not->toContain('viewable');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');

it('gives the select the runtime and the primitive that make it open', function (): void {
    $root = sheafKit('livewire-teams');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // Sheaf writes both halves of a select and imports neither: the Alpine.data()
    // its `x-data` names, and the `$rover` plugin that runtime drives the option
    // list with. Without them the browser throws `selectComponent is not defined`
    // as it walks the page, and the invite modal's Role never opens — a team
    // member cannot be given a role at all.
    expect($project->get(WireSheafRuntimes::ENTRYPOINT))
        ->toContain("import rover from '@sheaf/rover';")
        ->toContain("import './components/select.js';")
        ->toContain('Alpine.plugin(rover);')
        ->toContain("import './globals/modals.js';");

    // The plugin is registered before Alpine walks anything, and the primitive is
    // imported ahead of the runtime that reads it.
    $entrypoint = $project->get(WireSheafRuntimes::ENTRYPOINT);

    expect(strpos($entrypoint, 'import rover'))->toBeLessThan(strpos($entrypoint, './components/select.js'));
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');

it('posts the two-factor code Sheaf would have left out of the form', function (string $kit, string $challenge, string $setup): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // Flux's <ui-otp> kept a hidden input holding the joined digits, so the
    // challenge page's plain POST carried the whole code. Sheaf has no such
    // input, and spends `name` on every digit box instead — six inputs called
    // `code`, of which PHP keeps the last, so Fortify rejected every login.
    expect($project->get($challenge))
        ->toContain('<input type="hidden" name="code" x-bind:value="code" />')
        ->not->toContain('<x-ui.otp name="code"')
        // The error is still keyed, because the field wrapping reads the name
        // before this sweep takes it off.
        ->toContain('<x-ui.error name="code" />');

    // Sheaf's digit boxes are unconditionally `required`, and the recovery form
    // posts from the same <form> with the OTP merely x-show'd away — a hidden
    // required control the browser refuses to submit past at all.
    expect($project->get($challenge))
        ->toContain('<fieldset class="contents" x-bind:disabled="showRecoveryInput">');

    // The kit's other OTP binds through Livewire, which carries its own value and
    // names the boxes after the binding on purpose. Nothing to fix there.
    expect($project->get($setup))
        ->toContain('wire:model="code"')
        ->not->toContain('x-bind:value');
})->with([
    [
        'livewire',
        'resources/views/pages/auth/two-factor-challenge.blade.php',
        'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
    ],
    [
        'livewire-teams',
        'resources/views/pages/auth/two-factor-challenge.blade.php',
        'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
    ],
    [
        'livewire-class-components',
        'resources/views/livewire/auth/two-factor-challenge.blade.php',
        'resources/views/livewire/settings/security.blade.php',
    ],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('plans the OTP autofill patch only for a kit that has an OTP', function (): void {
    // Sheaf's OTP is hostile to password managers in three ways Flux's was not,
    // and all three live in the component `sheaf:install` copies into the project
    // — so this is the one action that edits Sheaf's own source.
    expect(implode("\n", reconcileSteps(sheafKit('livewire'))))
        ->toContain('patch  Sheaf\'s OTP');

    // A kit built without two-factor never installs the component, and there is
    // nothing to patch.
    $root = sheafKit('livewire');

    foreach ((array) glob($root.'/resources/views/pages/**/*two-factor*.blade.php') as $path) {
        @unlink((string) $path);
    }

    file_put_contents(
        $root.'/resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
        "<div>no otp here</div>\n",
    );

    expect(implode("\n", reconcileSteps($root)))->not->toContain('patch  Sheaf\'s OTP');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('keeps the browser\'s own defaults dark once Flux stops declaring it', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'sheaf', 'icons' => 'heroicons']),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // `@fluxAppearance` emitted a script and a stylesheet, and the teardown takes
    // both. Sheaf reads `prefers-color-scheme` to choose a theme but never
    // declares `color-scheme`, so without this the UA keeps its light defaults in
    // dark mode: black text wherever nothing has coloured it.
    expect($project->get('resources/views/partials/head.blade.php'))
        ->toContain(':root.dark {')
        ->toContain('color-scheme: dark;')
        // The directive itself is gone — the only mention left is the comment
        // explaining what took its place.
        ->not->toMatch('/^\s*@fluxAppearance/m');

    // The kit's own components colour themselves, so what this rescues is the
    // plain markup between them — here, the toggle under the two-factor form,
    // which carries opacity and an underline and no colour at all.
    expect($project->get('resources/views/pages/auth/two-factor-challenge.blade.php'))
        ->toContain('login using a recovery code');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('keeps the OTP centred once it is wrapped in a field', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'sheaf', 'icons' => 'heroicons']),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // The kit centres both its OTPs with `mx-auto`, which worked because Flux's
    // <ui-otp> was `w-fit`. Sheaf's is an ordinary block inside a `w-full` field,
    // so the auto margins collapse and the boxes go hard left — 48px off centre on
    // the challenge page, measured against the row that is still centring them.
    expect($project->get('resources/views/pages/auth/two-factor-challenge.blade.php'))
        ->toContain('class="mx-auto w-fit"');

    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->toContain('class="mx-auto w-fit"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('keeps Tailwind\'s import ahead of Sheaf\'s so the theme stays layered', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $stylesheet = (new ProjectDetector)->detect($root)->get(OrderThemeImport::STYLESHEET);
    $lines = preg_split('/\R/', $stylesheet) ?: [];

    $tailwind = array_search("@import 'tailwindcss';", $lines, true);
    $theme = array_search("@import './theme.css'; /* By Sheaf.dev */", $lines, true);

    // Above Tailwind's import, Sheaf's pushes the `:root` block holding every
    // theme variable out of `@layer theme`, and an unlayered declaration beats the
    // `.dark` overrides that flip the accent and primary colours. The kit's logo
    // is the visible half of that: a `dark:text-black` mark on a tile that never
    // turns white.
    expect($tailwind)->toBeInt()
        ->and($theme)->toBeInt()
        ->and($theme)->toBeGreaterThan($tailwind);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('leaves Sheaf\'s sidebar the one surface Sheaf paints', function (string $fixture, string $layout): void {
    $root = sheafKit($fixture);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $sidebar = (new ProjectDetector)->detect($root)->get($layout);

    // Sheaf's sidebar paints itself twice — the panel, and the sticky brand row
    // above it — and only the panel takes attributes. Restating the kit's tint
    // therefore reaches one of the two, and the row stays white behind the logo.
    expect($sidebar)->not->toContain('bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900')
        // Flux's spellings, which Sheaf takes as `sticky-header` and `collapsable`
        // — left alone they render as literal attributes on the div.
        ->not->toContain('<x-ui.sidebar sticky')
        ->not->toContain('collapsible="mobile"');
})->with([
    'sidebar' => ['livewire', 'resources/views/layouts/app/sidebar.blade.php'],
    'header' => ['livewire', 'resources/views/layouts/app/header.blade.php'],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('gives the page inside Sheaf\'s main the height and padding the kit\'s had', function (string $fixture, string $layout): void {
    $root = sheafKit($fixture);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
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

it('drives the appearance control with Sheaf\'s theme runtime, not Flux\'s magic', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $appearance = $project->get('resources/views/pages/settings/⚡appearance.blade.php');

    // The segmented control was bound to `$flux.appearance`, which is gone with
    // the package: the buttons rendered, moved, and changed nothing.
    expect($appearance)->not->toContain('$flux')
        ->toContain('x-model="$theme.storedTheme"')
        // Reading `storedTheme` selects the right button; only `setTheme()`
        // persists the choice and puts `.dark` on the document.
        ->toContain('x-on:change="$theme.setTheme($event.target.value)"');

    // The QR code inverts itself in dark mode by asking what the appearance
    // currently resolves to, which is one property on Sheaf's side.
    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->not->toContain('$flux')
        ->toContain('$theme.storedTheme')
        ->toContain('$theme.isResolvedToDark');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('leaves no Flux Alpine magic anywhere in the tree', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $found = [];

    foreach ($project->blades() as $path) {
        if (str_contains($project->get($path), '$flux')) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
})->with(['livewire', 'livewire-class-components', 'livewire-teams', 'livewire-workos', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('refuses to guess at a hand-written write to Flux\'s appearance', function (): void {
    $root = sheafKit('livewire');

    file_put_contents(
        $root.'/resources/views/dashboard.blade.php',
        '<div x-data><button x-on:click="$flux.appearance = \'dark\'">Dark</button></div>'."\n",
    );

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // A write is not a rename: assigning to `$theme.storedTheme` moves the
    // reactive value and persists nothing, so refit says so rather than
    // producing a button that looks right and does half the job.
    expect($project->get('resources/views/dashboard.blade.php'))
        ->toContain('$flux.appearance = \'dark\'')
        ->and($project->get('REFIT-NOTES.md'))
        ->toContain('resources/views/dashboard.blade.php')
        ->toContain('$theme.setTheme(value)');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('takes Flux out on the way to Sheaf without being asked to', function (): void {
    $root = sheafKit('livewire');

    // No `tasks` in the answers at all: the teardown belongs to the library being
    // left, so choosing to leave it is the whole instruction.
    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'sheaf', 'icons' => 'heroicons']),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->get('resources/views/partials/head.blade.php'))
        ->not->toContain('@fluxAppearance')
        ->not->toContain('@fluxScripts')
        ->and($project->get('resources/css/app.css'))->not->toContain('livewire/flux')
        // The override directory only ever existed to intercept Flux's own
        // resolution, so it goes with the icons that were in it.
        ->and($project->exists('resources/views/flux'))->toBeFalse();

    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain('@fluxScripts');
    }

    // And nothing to report about any of it. The chrome files the layout stubs
    // overwrite never carried a directive by the time the sweep ran, so the run
    // must not claim it went looking in them and came back empty-handed.
    expect($project->get('REFIT-NOTES.md'))
        ->not->toContain('@fluxAppearance')
        ->not->toContain('Nothing in');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('leaves Flux alone when Flux is where the project is staying', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'flux', 'icons' => 'keep']),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->get('resources/views/partials/head.blade.php'))->toContain('@fluxAppearance')
        ->and($project->exists('resources/views/flux'))->toBeTrue();
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('gives a segmented group the row and the bare segments Flux implied', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    // Flux reads "segmented" as the whole shape. Sheaf reads it as the pill
    // background, and defaults the rest the other way: `direction` vertical puts
    // `space-y-2` on the group, `indicator` true draws a radio dot in every
    // segment. The appearance control came out as a grey column of dotted rows.
    expect((new ProjectDetector)->detect($root)->get('resources/views/pages/settings/⚡appearance.blade.php'))
        ->toContain('<x-ui.radio.group direction="horizontal" :indicator="false" x-data variant="segmented"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('leaves a segmented group that has already decided its own shape', function (): void {
    $root = sheafKit('livewire');

    file_put_contents($root.'/resources/views/dashboard.blade.php', <<<'BLADE'
        <x-ui.radio.group variant="segmented" direction="vertical" :indicator="true" />
        <x-ui.radio.group :variant="$variant" />
        BLADE);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    // Said for itself, and a bound variant is not a value to read at all.
    expect((new ProjectDetector)->detect($root)->get('resources/views/dashboard.blade.php'))
        ->toContain('<x-ui.radio.group variant="segmented" direction="vertical" :indicator="true" />')
        ->toContain('<x-ui.radio.group :variant="$variant" />');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('teleports the user menu out of the sidebar\'s stacking context', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $menu = (new ProjectDetector)->detect($root)->get('resources/views/components/desktop-user-menu.blade.php');

    // Sheaf's sidebar is scrollable by default, and an `overflow-y` of auto makes
    // the `overflow-x: visible` beside it compute to auto too, so the sidebar
    // clips on both axes. This panel grows past the sidebar's 256px for a long
    // address, and in place it came out with its right-hand side sliced off.
    expect($menu)->toContain('<x-ui.dropdown position="bottom-start" portal');

    // But teleporting alone trades one bug for a worse one: at the body the panel
    // is no longer a descendant of the sidebar, and Sheaf's `z-50` panel loses to
    // the inline `z-index:99` the sidebar carries. The whole menu paints behind
    // the sidebar, which reads as a trigger that does nothing at all.
    expect($menu)->toContain('<x-slot:menu class="z-[100]! min-w-60">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('raises the team switcher clear of the sidebar that clips it', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    // The switcher is the other dropdown refit puts inside Sheaf's sidebar, and
    // it is a kit file rather than a stub. Measured at 265px against the 256px
    // sidebar, whose `overflow-y: auto` makes the `overflow-x: visible` beside it
    // compute to auto too: the right-hand edge of the menu was cut off.
    expect((new ProjectDetector)->detect($root)->get('resources/views/components/⚡team-switcher.blade.php'))
        ->toContain('<x-ui.dropdown portal position="bottom" align="start">')
        // And teleporting alone drops it below the sidebar's inline z-index 99.
        ->toContain('<x-slot:menu class="z-[100]! min-w-56">');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');

it('lays the team switcher out the way Flux laid it out', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $switcher = (new ProjectDetector)->detect($root)->get('resources/views/components/⚡team-switcher.blade.php');

    // The trigger holds an icon, the team name and a chevron. Sheaf wraps a
    // button's whole slot in one plain `<span data-text>`, and Tailwind's
    // preflight makes an svg a block — so the three came out as three lines, and
    // the chevron's `ms-auto` had no free space to push against.
    expect($switcher)->toContain('[&>[data-text]]:contents')
        ->toContain('[&>[data-loading=true]:first-child~[data-text]>*]:opacity-0');

    // And the panel's heading. Sheaf's group is `display: contents` and styles
    // `label` alone, so the word arrived in the grid as a bare text node in the
    // first of three columns, with the first team beside it rather than under it.
    expect($switcher)->toContain('<x-ui.dropdown.group :label="__(\'Teams\')" />');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');

it('gives the tooltips on the team pages the trigger Sheaf renders', function (string $kit): void {
    $root = sheafKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $index = (new ProjectDetector)->detect($root)->get('resources/views/pages/teams/⚡index.blade.php');

    // Flux hangs a tooltip on its child and takes the text as an attribute; Sheaf
    // renders `{{ $trigger }}` and reads a content child. A rename alone left the
    // teams list throwing "Undefined variable $trigger" before it drew a row.
    expect($index)->toContain('<x-slot:trigger>')
        ->toContain('<x-ui.tooltip.content>{{ __(\'Leave team\') }}</x-ui.tooltip.content>')
        ->not->toContain(':content=');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');

it('leaves the kits without teams without a switcher to raise', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    // Nothing to do, and nothing done: the page dropdowns a project writes for
    // itself are not refit's to move around.
    expect((new ProjectDetector)->detect($root)->exists('resources/views/components/⚡team-switcher.blade.php'))->toBeFalse();
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
