#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Starter kit playground installer
|--------------------------------------------------------------------------
|
| Installs every Livewire + Flux starter kit variation the way a user gets
| them, by driving the real `laravel new` with the flags the installer would
| have asked for, so there is a running application per variation to try
| `php artisan refit` against.
|
| The fixtures downloaded by bin/download-starter-kits.php are raw repository
| sources: no vendor directory, no .env, no built assets. These are installed
| applications instead, created next to this checkout as refit-<variation> so
| nothing lands inside the package and nothing claims a bare name there.
|
| Each application gets this checkout wired in as a path repository, which
| Composer symlinks, so refit is the working copy and edits are picked up
| without reinstalling. Pass --no-link to leave the application alone.
|
| Usage:
|   php bin/install-starter-kits.php [options]
|
| Options:
|   --list            List the known variations and exit.
|   --only=a,b        Install only the given variations.
|   --dest=path       Install somewhere other than the directory above this checkout.
|   --database=name   Database driver to install with (default: sqlite).
|   --force           Hand `--force` to the installer, which installs over an existing directory.
|   --no-link         Skip wiring this checkout into the application.
|   --no-node         Skip installing and building NPM dependencies.
|   --phpunit         Install PHPUnit instead of Pest.
|   --git             Initialise a Git repository in each application.
|   --help            Show this help.
|
| The WorkOS variations install without credentials. Their pages render, but
| signing in needs WORKOS_* values in the application's .env.
|
*/

/*
 * The variation names are the fixture names, so `--only` takes the same list as
 * the downloader. The directories are prefixed, since they land next to whatever
 * else lives above this checkout and a bare `livewire/` says nothing about why.
 */
const PREFIX = 'refit-';

const VARIANTS = [
    'livewire' => [
        'flags' => ['--livewire'],
        'description' => "Laravel's built-in authentication, single-file Livewire components",
    ],
    'livewire-class-components' => [
        'flags' => ['--livewire', '--livewire-class-components'],
        'description' => "Laravel's built-in authentication, stand-alone Livewire class components",
    ],
    'livewire-teams' => [
        'flags' => ['--livewire', '--teams'],
        'description' => "Laravel's built-in authentication with teams support",
    ],
    'livewire-workos' => [
        'flags' => ['--livewire', '--workos'],
        'description' => 'WorkOS AuthKit authentication',
    ],
    'livewire-workos-teams' => [
        'flags' => ['--livewire', '--workos', '--teams'],
        'description' => 'WorkOS AuthKit authentication with teams support',
    ],
];

exit(main($argv));

function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));

    if (isset($options['help'])) {
        usage();

        return 0;
    }

    if (isset($options['list'])) {
        listVariants();

        return 0;
    }

    $installer = locate('laravel');

    if ($installer === null) {
        error('The Laravel installer is required. Install it with:');
        error('  composer global require laravel/installer');

        return 1;
    }

    $composer = locate('composer');

    if ($composer === null && ! isset($options['no-link'])) {
        error('Composer is required to wire this checkout into the applications.');
        error('Install it, or pass --no-link to install the applications on their own.');

        return 1;
    }

    $variants = selectedVariants($options['only'] ?? null);

    if ($variants === []) {
        return 1;
    }

    $package = (string) realpath(__DIR__.'/..');
    $destination = resolveDestination($options['dest'] ?? __DIR__.'/../..');

    if ($destination === null) {
        return 1;
    }

    if (str_starts_with($destination.DIRECTORY_SEPARATOR, $package.DIRECTORY_SEPARATOR)) {
        error("Refusing to install inside the package itself [{$destination}].");

        return 1;
    }

    info('Installing starter kits to '.$destination);

    $installed = [];

    foreach ($variants as $name => $variant) {
        $path = $destination.'/'.PREFIX.$name;

        if (is_dir($path) && ! isset($options['force'])) {
            info("  {$name}: [{$path}] already exists, skipping (pass --force to install over it)");

            continue;
        }

        info('');
        info("  {$name}: {$variant['description']}");

        if (! install($installer, $name, $variant['flags'], $destination, $options)) {
            return 1;
        }

        if ($composer !== null && ! isset($options['no-link'])) {
            if (! linkPackage($composer, $path, $package)) {
                return 1;
            }
        }

        $installed[$name] = $path;
    }

    summarise($installed);

    return 0;
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
 * Resolve the variations to install, reporting unknown names.
 *
 * @return array<string, array{flags: array<int, string>, description: string}>
 */
function selectedVariants(?string $only): array
{
    if ($only === null || $only === '') {
        return VARIANTS;
    }

    $names = array_filter(array_map('trim', explode(',', $only)));
    $unknown = array_diff($names, array_keys(VARIANTS));

    if ($unknown !== []) {
        error('Unknown variation(s): '.implode(', ', $unknown));
        error('Run with --list to see the known variations.');

        return [];
    }

    return array_intersect_key(VARIANTS, array_flip($names));
}

/**
 * Create the directory the applications are installed into.
 */
function resolveDestination(string $destination): ?string
{
    if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
        error("Unable to create [{$destination}].");

        return null;
    }

    $resolved = realpath($destination);

    if ($resolved === false) {
        error("Unable to resolve [{$destination}].");

        return null;
    }

    return $resolved;
}

/**
 * Install one variation by driving `laravel new` with the installer's own flags.
 *
 * @param  array<int, string>  $flags
 * @param  array<string, string>  $options
 */
function install(string $installer, string $name, array $flags, string $destination, array $options): bool
{
    $database = $options['database'] ?? '';

    $command = array_merge([$installer, 'new', PREFIX.$name], $flags, [
        '--database='.($database !== '' ? $database : 'sqlite'),
        isset($options['phpunit']) ? '--phpunit' : '--pest',
        isset($options['no-node']) ? '--no-node' : '--npm',
        '--no-boost',
        '--no-interaction',
    ]);

    if (isset($options['git'])) {
        $command[] = '--git';
    }

    if (isset($options['force'])) {
        $command[] = '--force';
    }

    if (run($command, $destination) !== 0) {
        error("  {$name}: `laravel new` failed.");

        return false;
    }

    return true;
}

/**
 * Point an installed application at this checkout, so `php artisan refit` runs the working copy.
 */
function linkPackage(string $composer, string $path, string $package): bool
{
    $repository = json_encode([
        'type' => 'path',
        'url' => $package,
        'options' => ['symlink' => true],
    ], JSON_UNESCAPED_SLASHES);

    $steps = [
        [$composer, 'config', 'repositories.refit', '--json', (string) $repository],
        [$composer, 'require', '--dev', '--no-interaction', 'onelegstudios/refit:*@dev'],
    ];

    foreach ($steps as $step) {
        if (run($step, $path) !== 0) {
            error('  Unable to require refit into ['.basename($path).'].');

            return false;
        }
    }

    return true;
}

/**
 * Run a command in a directory, streaming its output.
 *
 * @param  array<int, string>  $command
 */
function run(array $command, string $cwd): int
{
    $previous = (string) getcwd();
    chdir($cwd);
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
    chdir($previous);

    return $status;
}

/**
 * Find an executable on the PATH.
 */
function locate(string $binary): ?string
{
    $path = trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null'));

    return $path === '' ? null : $path;
}

/**
 * @param  array<string, string>  $installed
 */
function summarise(array $installed): void
{
    info('');

    if ($installed === []) {
        info('Nothing installed.');

        return;
    }

    info('Installed '.count($installed).' starter kit'.(count($installed) === 1 ? '' : 's').':');

    foreach ($installed as $name => $path) {
        info("  {$name}: {$path}");
    }

    info('');
    info('Try a run with:');
    info('  cd '.reset($installed).' && php artisan refit');
}

function listVariants(): void
{
    info('Livewire starter kit variations:');

    foreach (VARIANTS as $name => $variant) {
        info(sprintf('  %-26s laravel new %s %s', $name, PREFIX.$name, implode(' ', $variant['flags'])));
        info(sprintf('  %-26s %s', '', $variant['description']));
    }
}

function usage(): void
{
    info('Usage: php bin/install-starter-kits.php [--list] [--only=a,b] [--dest=path] [--database=name]');
    info('       [--force] [--no-link] [--no-node] [--phpunit] [--git]');
}

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function error(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
