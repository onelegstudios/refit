<?php

declare(strict_types=1);

use Onelegstudios\Refit\Icons\IconScanner;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Flux\IconPlanner;
use Onelegstudios\Refit\Libraries\Flux\OverrideGenerator;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Plan\Applier;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ProjectDetector;

/**
 * @return array{Plan, Report}
 */
function planIcons(string $root, IconStrategy $strategy): array
{
    $plan = new Plan;
    $report = new Report;

    $vocabulary = (new FluxLibrary)->vocabulary();

    (new IconPlanner($vocabulary, new IconScanner($vocabulary)))
        ->contribute($plan, (new ProjectDetector)->detect($root), $strategy, $report);

    return [$plan, $report];
}

/**
 * A throwaway project holding a single view, for cases a starter kit fixture
 * would only make harder to read.
 */
function projectWithView(string $path, string $source): string
{
    $root = sys_get_temp_dir().'/refit-view-'.bin2hex(random_bytes(6));

    mkdir(dirname($root.'/'.$path), 0755, true);
    file_put_contents($root.'/'.$path, $source);

    register_shutdown_function(static fn () => deleteDirectory($root));

    return $root;
}

/**
 * The bundled Lucide artwork with one name removed, standing in for a mapping
 * that points at art refit does not ship.
 */
function iconsWithout(string $name): string
{
    $directory = sys_get_temp_dir().'/refit-icons-'.bin2hex(random_bytes(6));

    copyDirectory(dirname(__DIR__, 2).'/resources/icons/lucide', $directory);
    unlink($directory.'/'.$name.'.svg');

    register_shutdown_function(static fn () => deleteDirectory($directory));

    return $directory;
}

it('plans nothing when the mix is being kept', function (string $kit): void {
    [$plan, $report] = planIcons(requireFixture($kit), IconStrategy::Keep);

    expect($plan->isEmpty())->toBeTrue()
        ->and($report->hasWarnings())->toBeFalse();
})->with(starterKits());

it('deletes the vendored Lucide overrides when going all Heroicons', function (): void {
    [$plan, $report] = planIcons(requireFixture('livewire'), IconStrategy::Heroicons);

    expect($plan->describe())->toContain('  delete resources/views/flux/icon/layout-grid.blade.php (now Heroicons "squares-2x2")')
        ->and($plan->describe())->toContain('  icons  Lucide to Heroicons (4 names)')
        ->and($report->hasWarnings())->toBeFalse();
});

it('writes an override for every icon when going all Lucide', function (string $kit): void {
    $root = copyFixture($kit);
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    (new Applier)->apply($plan, $project, $report);

    $overrides = array_map(
        static fn (string $path): string => basename($path, '.blade.php'),
        glob($root.'/resources/views/flux/icon/*.blade.php') ?: [],
    );

    $remaining = array_keys(fluxScanner()->scan($project));

    // Every name still referenced must resolve to an override we wrote —
    // including `loading`, which keeps Flux's name but gets Lucide artwork.
    expect(array_values(array_diff($remaining, $overrides)))->toBe([]);
})->with(starterKits());

it('overrides the Flux spinner in place, still spinning', function (): void {
    $root = copyFixture('livewire');
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    expect($plan->describe())
        ->toContain('  write  resources/views/flux/icon/loading.blade.php (Lucide "loader-circle", in place of Flux\'s own)');

    (new Applier)->apply($plan, $project, $report);

    // Flux renders `flux:icon.loading` from inside its own components, so the
    // usages have to keep the name the override is written at.
    expect($project->get('resources/views/flux/icon/loading.blade.php'))
        ->toContain("Flux::classes('shrink-0 animate-spin')")
        ->and(array_keys(fluxScanner()->scan($project)))->toContain('loading')
        ->and($plan->describe())->not->toContain('loader-circle.blade.php');
});

it('aliases the Heroicons name so Flux internals follow the switch', function (): void {
    [$plan] = planIcons(requireFixture('livewire'), IconStrategy::Lucide);

    // Flux asks for `x-mark` from inside its own components, and refit cannot
    // rewrite vendor code, so the override has to live at that name.
    expect($plan->describe())
        ->toContain('  write  resources/views/flux/icon/x-mark.blade.php (Lucide "x", for Flux internals)');
});

it('never rewrites a name attribute that is not an icon', function (): void {
    $root = copyFixture('livewire');
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    (new Applier)->apply($plan, $project, $report);

    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('name="email"')
        ->toContain('name="password"');
});

it('translates icons in all three written forms', function (): void {
    $root = copyFixture('livewire-teams');
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    (new Applier)->apply($plan, $project, $report);

    expect($project->get('resources/views/layouts/app/sidebar.blade.php'))->toContain('icon="house"')
        ->and($project->get('resources/views/components/desktop-user-menu.blade.php'))->toContain('name="chevrons-up-down"');
});

it('drops the solid variant the kit asks for, which Lucide cannot draw', function (string $kit, string $view): void {
    $root = copyFixture($kit);
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    expect($plan->describe())->toContain('  icons  drop variant="solid" (Lucide draws one weight)');

    (new Applier)->apply($plan, $project, $report);

    // The override throws on `solid` rather than quietly drawing an outline, so
    // a leftover here fatals the first time someone copies their setup key.
    expect($project->get($view))
        ->not->toContain('variant="solid"')
        ->toContain('x-show="copied"');
})->with([
    ['livewire', 'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'],
    ['livewire-teams', 'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'],
    ['livewire-class-components', 'resources/views/livewire/settings/security.blade.php'],
]);

it('leaves a solid variant alone unless the icon it weighs became Lucide', function (): void {
    $root = projectWithView('resources/views/panel.blade.php', implode(PHP_EOL, [
        '<flux:badge variant="solid">Live</flux:badge>',
        '<flux:icon.sparkles variant="solid" />',
        '<flux:icon.check variant="solid" />',
    ]));
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    (new Applier)->apply($plan, $project, $report);

    // A badge's variant is its own, and `sparkles` has no Lucide translation, so
    // it keeps both its Heroicon and the weight that goes with it.
    expect($project->get('resources/views/panel.blade.php'))
        ->toContain('<flux:badge variant="solid">')
        ->toContain('<flux:icon.sparkles variant="solid" />')
        ->toContain('<flux:icon.check />');
});

it('drops the variant a component hands down to the icon it renders', function (): void {
    $root = projectWithView(
        'resources/views/toolbar.blade.php',
        '<flux:button icon="trash" icon:variant="solid">Delete</flux:button>',
    );
    $project = (new ProjectDetector)->detect($root);

    [$plan, $report] = planIcons($root, IconStrategy::Lucide);

    (new Applier)->apply($plan, $project, $report);

    expect($project->get('resources/views/toolbar.blade.php'))
        ->toBe('<flux:button icon="trash-2">Delete</flux:button>');
});

it('reports an icon whose Lucide artwork is missing rather than failing', function (): void {
    $root = projectWithView('resources/views/mailbox.blade.php', '<flux:icon.envelope />');
    $project = (new ProjectDetector)->detect($root);

    $plan = new Plan;
    $report = new Report;

    (new IconPlanner(
        (new FluxLibrary)->vocabulary(),
        fluxScanner(),
        new OverrideGenerator(iconDirectory: iconsWithout('mail')),
    ))
        ->contribute($plan, $project, IconStrategy::Lucide, $report);

    (new Applier)->apply($plan, $project, $report);

    expect($report->warnings())->toContain('No Lucide artwork bundled for "mail" — "envelope" stays Heroicons in resources/views/mailbox.blade.php.')
        ->and($plan->describe())->not->toContain('flux/icon/mail.blade.php')
        // Renaming without an override to land on would have broken the icon.
        ->and($project->get('resources/views/mailbox.blade.php'))->toBe('<flux:icon.envelope />');
});

it('reports nothing it could not translate for a stock kit', function (string $kit): void {
    [, $report] = planIcons(requireFixture($kit), IconStrategy::Lucide);

    expect($report->warnings())->toBe([]);
})->with(starterKits());
