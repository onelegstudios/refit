<?php

declare(strict_types=1);

use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconScanner;
use Onelegstudios\Refit\Libraries\Flux\OwnedIcons;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\Sheaf\Components;
use Onelegstudios\Refit\Libraries\SheafLibrary;
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
