#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Flux internal icon recorder
|--------------------------------------------------------------------------
|
| Refreshes resources/flux/internal-icons.json: the names Flux renders from
| inside its own components, which refit has to write overrides for because it
| cannot rewrite Flux's vendor code.
|
| At runtime refit scans the Flux package installed alongside the project it is
| refitting, which is always more accurate than a recorded list. This exists for
| the case where there is nothing to scan — a fixture, or a project where Flux
| is not installed yet — and, more importantly, so the `livewire/flux-pro` names
| are in the repository at all. Pro is a licensed package: most contributors
| cannot install it, and a hard dependency on it would break `composer install`
| for them rather than degrade.
|
| Only icon names are recorded. None of Flux's source is copied here.
|
| Reads a project that has Flux installed. With no --project, that is the
| sidecar install in .flux-pro, set up once with:
|
|   composer install --working-dir=.flux-pro
|
| The sidecar exists because refit's root composer.json must never name the
| licensed repository. Composer loads every composer-type repository's
| packages.json before it resolves anything, so a single unauthenticated 401
| from composer.fluxui.dev fails the whole install — a contributor without a
| licence would lose Pest and PHPStan too, not just Flux. Keeping it in a
| separate manifest that the root install never reads avoids that entirely.
|
| Editions that are not installed keep whatever the manifest already records, so
| running this without a Pro licence refreshes the free names and leaves the Pro
| ones untouched.
|
| Usage:
|   php bin/scan-flux-internals.php [options]
|
| Options:
|   --project=path    Project root holding vendor/livewire/flux[-pro].
|                     Defaults to .flux-pro, then the working directory.
|   --check           Report drift against the manifest, write nothing.
|                     Exits non-zero when a scanned edition disagrees.
|   --dest=path       Write to a different manifest file.
|   --help            Show this help.
|
*/

use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconScanner;
use Onelegstudios\Refit\Libraries\Flux\Internals;
use Onelegstudios\Refit\Libraries\Flux\OwnedIcons;
use Onelegstudios\Refit\Libraries\FluxLibrary;

require __DIR__.'/../vendor/autoload.php';

exit(main($argv));

function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));

    if (isset($options['help'])) {
        usage();

        return 0;
    }

    $project = rtrim($options['project'] ?? defaultProject(), '/');
    $destination = $options['dest'] ?? Internals::manifestPath();
    $check = isset($options['check']);

    if (! is_dir($project)) {
        error("Project root [{$project}] does not exist.");

        return 1;
    }

    $manifest = readManifest($destination);
    $recorded = recordedEditions($manifest);

    info(sprintf('%s Flux stubs under %s', $check ? 'Checking' : 'Scanning', $project));

    $scanner = new IconScanner((new FluxLibrary)->vocabulary());
    $editions = [];
    $drift = [];
    $scannedAny = false;

    foreach (Internals::STUB_DIRECTORIES as $relative) {
        $package = packageFor($relative);
        $directory = $project.'/'.$relative;
        $was = $recorded[$package] ?? null;

        if (! is_dir($directory)) {
            // Not installed here. Keep what the manifest already says rather than
            // recording an absence — this is the unlicensed contributor's run,
            // and dropping the Pro names would be a silent regression.
            if ($was !== null) {
                $editions[$package] = $was;

                info(sprintf('  %-20s not installed — keeping %d recorded name(s)', $package, count($was['icons'])));

                continue;
            }

            info(sprintf('  %-20s not installed — nothing recorded', $package));

            continue;
        }

        $scannedAny = true;
        $icons = $scanner->scanStubDirectory($directory);
        $version = installedVersion($project, $package);

        $editions[$package] = [
            'version' => $version,
            'scanned' => true,
            'icons' => $icons,
        ];

        info(sprintf(
            '  %-20s %-10s %d name(s)',
            $package,
            $version ?? 'unknown',
            count($icons),
        ));

        $previous = $was['icons'] ?? [];
        $before = $was['version'] ?? null;

        // The version is part of the record, not just a comment on it: a bump
        // with an unchanged icon set still has to be written, or the manifest
        // claims to describe a release it was never scanned against.
        if ($previous !== $icons || $before !== $version) {
            $drift[$package] = [
                'added' => array_values(array_diff($icons, $previous)),
                'removed' => array_values(array_diff($previous, $icons)),
                'from' => $before,
                'to' => $version,
            ];
        }
    }

    if (! $scannedAny) {
        error("No Flux stubs found under [{$project}].");
        error('Either install the sidecar once:');
        error('  composer install --working-dir=.flux-pro');
        error('or point this at a project that already has Flux:');
        error('  php bin/scan-flux-internals.php --project=../my-app');

        return 1;
    }

    // Preserve editions the loop never considered, so an unrelated key added by
    // a later version of this script is not dropped by an older one.
    foreach ($recorded as $package => $edition) {
        $editions[$package] ??= $edition;
    }

    ksort($editions);

    reportDrift($drift);
    reportUntranslatable($editions);

    if ($check) {
        // Only the icon set decides the exit code. A version bump with the same
        // icons changes nothing refit does, and Flux ships often enough that
        // failing on it would turn this red for something harmless.
        $names = array_filter(
            $drift,
            static fn (array $change): bool => $change['added'] !== [] || $change['removed'] !== [],
        );

        if ($names === []) {
            info($drift === []
                ? 'Manifest matches the installed stubs.'
                : 'Manifest matches the installed stubs. Only the recorded version is behind.');

            return 0;
        }

        error('Flux renders a different set of icons than the manifest records.');
        error('Run `composer flux:internals -- --project=path` to refresh it, then map any new names in IconMap.');

        return 1;
    }

    if ($drift === []) {
        info('Done — the manifest already matched the installed stubs.');

        return 0;
    }

    if (! writeManifest($destination, [
        'generated_at' => gmdate('c'),
        'editions' => $editions,
    ])) {
        return 1;
    }

    info('Done — review the diff before committing.');

    return 0;
}

/**
 * Where to look when no --project is given.
 *
 * Refit's own vendor directory never holds Flux, so the useful default is the
 * sidecar install: .flux-pro/composer.json is committed, its vendor directory is
 * not, and the root `composer install` never reads either. That keeps the
 * licensed repository out of refit's own manifest, where merely *naming* it
 * would break `composer install` for every contributor without a licence —
 * Composer fetches each composer-type repository's packages.json upfront, so an
 * unauthenticated 401 there stops the whole install, not just Flux.
 */
function defaultProject(): string
{
    $sidecar = dirname(__DIR__).'/.flux-pro';

    if (is_dir($sidecar.'/vendor/livewire')) {
        return $sidecar;
    }

    return (string) getcwd();
}

/**
 * `vendor/livewire/flux-pro/stubs` -> `livewire/flux-pro`.
 */
function packageFor(string $relative): string
{
    $parts = explode('/', $relative);

    return $parts[1].'/'.$parts[2];
}

/**
 * The editions a manifest already records, normalised to this script's shape.
 *
 * @return array<string, array{version: ?string, scanned: bool, icons: list<string>}>
 */
function recordedEditions(array $manifest): array
{
    $editions = $manifest['editions'] ?? [];

    if (! is_array($editions)) {
        return [];
    }

    $recorded = [];

    foreach ($editions as $package => $edition) {
        if (! is_string($package) || ! is_array($edition)) {
            continue;
        }

        $icons = $edition['icons'] ?? [];
        $version = $edition['version'] ?? null;

        $recorded[$package] = [
            'version' => is_string($version) ? $version : null,
            'scanned' => (bool) ($edition['scanned'] ?? false),
            'icons' => array_values(array_filter(is_array($icons) ? $icons : [], 'is_string')),
        ];
    }

    return $recorded;
}

/**
 * The installed version of a package, read from Composer's own metadata.
 */
function installedVersion(string $project, string $package): ?string
{
    $path = $project.'/vendor/composer/installed.json';

    if (! is_file($path)) {
        return null;
    }

    $installed = json_decode((string) file_get_contents($path), true);

    if (! is_array($installed)) {
        return null;
    }

    foreach ($installed['packages'] ?? $installed as $entry) {
        if (is_array($entry) && ($entry['name'] ?? null) === $package) {
            $version = $entry['version'] ?? null;

            return is_string($version) ? $version : null;
        }
    }

    return null;
}

/**
 * @param  array<string, array{added: list<string>, removed: list<string>, from: ?string, to: ?string}>  $drift
 */
function reportDrift(array $drift): void
{
    foreach ($drift as $package => $change) {
        if ($change['from'] !== $change['to']) {
            info(sprintf(
                '  %s moved %s -> %s',
                $package,
                $change['from'] ?? 'unrecorded',
                $change['to'] ?? 'unknown',
            ));
        }

        if ($change['added'] !== []) {
            info(sprintf('  %s renders %d new name(s): %s', $package, count($change['added']), implode(', ', $change['added'])));
        }

        if ($change['removed'] !== []) {
            info(sprintf('  %s no longer renders: %s', $package, implode(', ', $change['removed'])));
        }
    }
}

/**
 * Names refit records but cannot translate, which would stay Heroicons in an
 * otherwise all-Lucide kit.
 *
 * Reported rather than fatal: a new Flux release adding an icon should produce a
 * committed manifest plus a clear next step, not a script that refuses to run.
 *
 * @param  array<string, array{version: ?string, scanned: bool, icons: list<string>}>  $editions
 */
function reportUntranslatable(array $editions): void
{
    $names = [];

    foreach ($editions as $edition) {
        foreach ($edition['icons'] as $name) {
            // OwnedIcons covers the names Flux resolves from neither icon set —
            // `loading` is its own spinner, and it has artwork without being a
            // Heroicon, so it is translatable even though IconMap has never
            // heard of it.
            if (IconMap::toLucide($name) === null && OwnedIcons::artwork($name) === null) {
                $names[$name] = true;
            }
        }
    }

    if ($names === []) {
        return;
    }

    $names = array_keys($names);
    sort($names);

    error(sprintf('%d recorded name(s) have no Lucide translation: %s', count($names), implode(', ', $names)));
    error('Add them to IconMap::HEROICONS_TO_LUCIDE and run `composer icons`, or they stay Heroicons after a refit.');
}

/**
 * Parse `--flag` and `--option=value` arguments.
 *
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        if (! str_starts_with($argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');

        $options[$key] = $value;
    }

    return $options;
}

/**
 * @return array<string, mixed>
 */
function readManifest(string $path): array
{
    if (! is_file($path)) {
        return [];
    }

    $manifest = json_decode((string) file_get_contents($path), true);

    return is_array($manifest) ? $manifest : [];
}

function writeManifest(string $path, array $manifest): bool
{
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
        error("Unable to create [{$directory}].");

        return false;
    }

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false || file_put_contents($path, $json.PHP_EOL) === false) {
        error("Unable to write [{$path}].");

        return false;
    }

    return true;
}

function usage(): void
{
    info('Usage: php bin/scan-flux-internals.php [--project=path] [--check] [--dest=path]');
}

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function error(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
