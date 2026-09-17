<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconScanner;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Flux\OwnedIcons;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\OrderThemeImport;
use Onelegstudios\Refit\Plan\Actions\WireSheafRuntimes;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Project\ProjectDetector;
use Onelegstudios\Refit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A scanner reading Flux's vocabulary, which is what most icon tests want.
 */
function fluxScanner(): IconScanner
{
    return new IconScanner((new FluxLibrary)->vocabulary());
}

function sheafScanner(): IconScanner
{
    return new IconScanner((new SheafLibrary)->vocabulary());
}

/**
 * The Lucide artwork a name ends up drawn by, from either source.
 *
 * IconMap knows what a Heroicon is called in Lucide; OwnedIcons knows what to
 * draw for the names Flux resolves from neither set.
 */
function artworkFor(string $name): ?string
{
    return IconMap::toLucide($name) ?? OwnedIcons::artwork($name);
}

/**
 * Every tag a Sheaf install answers to, without the `x-ui.` prefix.
 *
 * Recorded from Sheaf's public registry by `composer sheaf:components`, so a
 * test asserting against it is asserting against what Sheaf really ships.
 *
 * @return list<string>
 */
function sheafComponents(): array
{
    return Components::tags();
}

/**
 * The starter kit variations `composer fixtures` downloads.
 *
 * @return list<string>
 */
function starterKits(): array
{
    return [
        'livewire',
        'livewire-class-components',
        'livewire-teams',
        'livewire-workos',
        'livewire-workos-teams',
    ];
}

function fixturePath(string $kit): string
{
    return dirname(__DIR__).'/tests/fixtures/starter-kits/'.$kit;
}

/**
 * Fixtures are gitignored, so any test needing one skips when it is absent.
 * Run `composer fixtures` to download them.
 */
function requireFixture(string $kit): string
{
    $path = fixturePath($kit);

    if (! is_dir($path)) {
        test()->markTestSkipped("Starter kit fixture [{$kit}] is missing — run `composer fixtures`.");
    }

    return $path;
}

/**
 * The stubs of a licensed Flux edition inside a fixture, or a skip.
 *
 * `livewire/flux-pro` needs a licence, so it is absent from most checkouts and
 * from every fork's CI run — GitHub does not pass secrets to workflows triggered
 * from a fork. Tests that need it skip rather than fail, which keeps the suite
 * green for contributors who cannot install it.
 *
 * The recorded names in resources/flux/internal-icons.json are what refit falls
 * back to, and those are asserted without a licence in tests/Unit/IconsTest.php.
 */
function requireFluxPro(string $kit): string
{
    $stubs = requireFixture($kit).'/vendor/livewire/flux-pro/stubs';

    if (! is_dir($stubs)) {
        test()->markTestSkipped('Flux Pro is not installed in the fixture — this needs a licence, so it is optional.');
    }

    return $stubs;
}

/**
 * A throwaway copy of a fixture, cleaned up when the test process ends.
 */
function copyFixture(string $kit): string
{
    $source = requireFixture($kit);
    $destination = sys_get_temp_dir().'/refit-'.$kit.'-'.bin2hex(random_bytes(6));

    copyDirectory($source, $destination);

    register_shutdown_function(static fn () => deleteDirectory($destination));

    return $destination;
}

function detectFixture(string $kit): Project
{
    return (new ProjectDetector)->detect(requireFixture($kit));
}

function copyDirectory(string $source, string $destination): void
{
    mkdir($destination, 0755, true);

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        $target = $destination.DIRECTORY_SEPARATOR.$items->getSubPathname();

        $item->isDir() ? mkdir($target, 0755, true) : copy($item->getPathname(), $target);
    }
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

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
            $base = $root.'/'.SheafLibrary::COMPONENT_DIRECTORY.'/'.$component;

            @mkdir($base, 0755, true);

            foreach (Components::components()[$component] ?? [''] as $part) {
                touch($base.'/'.($part === '' ? 'index' : $part).'.blade.php');
            }
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
 * A Sheaf kit refit has already migrated, shared by the tests that only read it.
 *
 * Copying a kit and running the whole migration is most of this file's runtime,
 * and most tests here only inspect the result — so each kit and icon answer is
 * migrated once per process. A test that writes to the tree, or runs refit with
 * other answers, takes its own copy from sheafKit() instead.
 */
function migratedSheafKit(string $kit, string $icons = 'heroicons'): string
{
    /** @var array<string, string> $migrated */
    static $migrated = [];

    $key = $kit.'|'.$icons;

    if (! isset($migrated[$key])) {
        $root = sheafKit($kit);

        test()->artisan('refit', [
            '--force' => true,
            '--answers' => json_encode(['library' => 'sheaf', 'icons' => $icons]),
        ])->assertSuccessful();

        $migrated[$key] = $root;
    }

    app()->setBasePath($migrated[$key]);

    return $migrated[$key];
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
