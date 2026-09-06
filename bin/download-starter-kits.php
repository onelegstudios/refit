#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Starter kit fixture downloader
|--------------------------------------------------------------------------
|
| Downloads every Livewire + Flux starter kit variation that `laravel new`
| can generate into tests/fixtures/starter-kits, so the test suite has real
| starter kit sources to run against.
|
| The variations below mirror laravel/installer: the Livewire starter kit is
| a single repository whose branch is picked from the installer answers for
| authentication provider, teams support, and single-file components.
|
| Usage:
|   php bin/download-starter-kits.php [options]
|
| Options:
|   --list            List the known variations and exit.
|   --only=a,b        Download only the given variations.
|   --force           Re-download even when the local copy is up to date.
|   --dest=path       Write to a different directory.
|   --no-scripts      Keep the raw repository, skipping the kit's post-create scripts.
|   --help            Show this help.
|
| Set GITHUB_TOKEN (or GH_TOKEN) to authenticate the GitHub API calls used to
| resolve each branch to a commit.
|
| The blank Livewire starter kit (laravel/blank-livewire-starter-kit, chosen by
| answering "no" to "Do you want to use a starter kit?") is intentionally absent:
| it ships without Flux and without auth, so refit does not support it.
|
*/

const VARIANTS = [
    'livewire' => [
        'repo' => 'laravel/livewire-starter-kit',
        'branch' => 'main',
        'description' => "Laravel's built-in authentication, single-file Livewire components",
    ],
    'livewire-class-components' => [
        'repo' => 'laravel/livewire-starter-kit',
        'branch' => 'components',
        'description' => "Laravel's built-in authentication, stand-alone Livewire class components",
    ],
    'livewire-teams' => [
        'repo' => 'laravel/livewire-starter-kit',
        'branch' => 'teams',
        'description' => "Laravel's built-in authentication with teams support",
    ],
    'livewire-workos' => [
        'repo' => 'laravel/livewire-starter-kit',
        'branch' => 'workos',
        'description' => 'WorkOS AuthKit authentication',
    ],
    'livewire-workos-teams' => [
        'repo' => 'laravel/livewire-starter-kit',
        'branch' => 'workos-teams',
        'description' => 'WorkOS AuthKit authentication with teams support',
    ],
];

const MANIFEST = 'manifest.json';

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

    if (! class_exists(ZipArchive::class)) {
        error('The zip extension is required to extract the starter kits.');

        return 1;
    }

    $variants = selectedVariants($options['only'] ?? null);

    if ($variants === []) {
        return 1;
    }

    $destination = $options['dest'] ?? __DIR__.'/../tests/fixtures/starter-kits';

    if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
        error("Unable to create [{$destination}].");

        return 1;
    }

    $destination = (string) realpath($destination);
    $manifest = readManifest($destination);
    $force = isset($options['force']);

    info('Downloading starter kits to '.$destination);

    foreach ($variants as $name => $variant) {
        $commit = resolveCommit($variant['repo'], $variant['branch']);

        if ($commit === null) {
            return 1;
        }

        $previous = $manifest['variants'][$name]['commit'] ?? null;
        $path = $destination.'/'.$name;

        if ($previous === $commit && is_dir($path) && ! $force) {
            info("  {$name}: up to date (".shortCommit($commit).')');

            continue;
        }

        if ($previous !== null && $previous !== $commit) {
            info("  {$name}: ".shortCommit($previous).' -> '.shortCommit($commit).' (starter kit changed upstream)');
        }

        if (! download($variant['repo'], $commit, $path)) {
            return 1;
        }

        $scripts = isset($options['no-scripts']) ? [] : runPostCreateScripts($name, $path);

        if ($scripts === null) {
            return 1;
        }

        $manifest['variants'][$name] = [
            'repo' => $variant['repo'],
            'branch' => $variant['branch'],
            'commit' => $commit,
            'downloaded_at' => gmdate('c'),
            'post_create_scripts' => $scripts,
        ];

        ksort($manifest['variants']);
        writeManifest($destination, $manifest);

        info("  {$name}: downloaded ".shortCommit($commit));
    }

    info('Done.');

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
 * Resolve the variations to download, reporting unknown names.
 *
 * @return array<string, array{repo: string, branch: string, description: string}>
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
 * Resolve a branch to the commit it currently points at.
 */
function resolveCommit(string $repo, string $branch): ?string
{
    $response = request(
        "https://api.github.com/repos/{$repo}/commits/{$branch}",
        ['Accept: application/vnd.github.sha'],
    );

    if ($response === null) {
        error("Unable to resolve [{$repo}#{$branch}].");

        return null;
    }

    $commit = trim($response);

    if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        error("Unexpected commit response for [{$repo}#{$branch}]: {$commit}");

        return null;
    }

    return $commit;
}

/**
 * Download a repository at a commit and replace the target directory with it.
 */
function download(string $repo, string $commit, string $path): bool
{
    $name = basename($repo);
    $archive = request("https://codeload.github.com/{$repo}/zip/{$commit}");

    if ($archive === null) {
        error("Unable to download [{$repo}@{$commit}].");

        return false;
    }

    // Staged alongside the target so the final move never crosses a device boundary.
    $temporary = dirname($path).'/.staging-'.$commit;
    $file = $temporary.'.zip';

    deleteDirectory($temporary);

    if (file_put_contents($file, $archive) === false) {
        error("Unable to write [{$file}].");

        return false;
    }

    $zip = new ZipArchive;

    if ($zip->open($file) !== true || ! $zip->extractTo($temporary)) {
        error("Unable to extract [{$file}].");
        $zip->close();
        unlink($file);

        return false;
    }

    $zip->close();
    unlink($file);

    $extracted = $temporary.'/'.$name.'-'.$commit;

    if (! is_dir($extracted)) {
        error("Unexpected archive layout, [{$extracted}] is missing.");
        deleteDirectory($temporary);

        return false;
    }

    deleteDirectory($path);

    if (! rename($extracted, $path)) {
        error("Unable to move [{$extracted}] to [{$path}].");
        deleteDirectory($temporary);

        return false;
    }

    deleteDirectory($temporary);

    return true;
}

/**
 * Run the kit's plain PHP post-create-project scripts, the way `laravel new` does.
 *
 * The Livewire kit uses one to prefix its Livewire blade files with an emoji and to
 * rewrite chisel-paths.php, so skipping it would leave fixtures that no installed
 * application ever looks like. Artisan and inline `-r` scripts are left alone: they
 * need an installed vendor directory and only touch the environment, not the layout.
 *
 * @return array<int, string>|null The scripts that ran, or null on failure.
 */
function runPostCreateScripts(string $name, string $path): ?array
{
    $composer = json_decode((string) @file_get_contents($path.'/composer.json'), true);
    $scripts = $composer['scripts']['post-create-project-cmd'] ?? [];
    $executed = [];

    if (! is_array($scripts)) {
        return $executed;
    }

    foreach ($scripts as $script) {
        if (! is_string($script) || preg_match('/^@php ([\w.\-\/]+\.php)$/', $script, $matches) !== 1) {
            continue;
        }

        $file = $matches[1];

        if (! is_file($path.'/'.$file)) {
            continue;
        }

        $directory = (string) getcwd();
        chdir($path);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output, $status);
        chdir($directory);

        if ($status !== 0) {
            error("  {$name}: [{$file}] failed:");
            error('    '.implode(PHP_EOL.'    ', $output));

            return null;
        }

        $executed[] = $file;
    }

    return $executed;
}

/**
 * Perform a GET request against the GitHub API or codeload.
 */
function request(string $url, array $headers = []): ?string
{
    $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN');

    $headers[] = 'User-Agent: onelegstudios-refit';

    if ($token !== false && $token !== '') {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'follow_location' => 1,
            'timeout' => 60,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        error('Request failed: '.$url.' ('.($http_response_header[0] ?? 'no response').')');

        return null;
    }

    return $response;
}

/**
 * @return array{variants: array<string, array<string, string>>}
 */
function readManifest(string $destination): array
{
    $file = $destination.'/'.MANIFEST;

    if (! is_file($file)) {
        return ['variants' => []];
    }

    $manifest = json_decode((string) file_get_contents($file), true);

    if (! is_array($manifest) || ! is_array($manifest['variants'] ?? null)) {
        return ['variants' => []];
    }

    return ['variants' => $manifest['variants']];
}

function writeManifest(string $destination, array $manifest): void
{
    file_put_contents(
        $destination.'/'.MANIFEST,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
    );
}

function deleteDirectory(string $path): void
{
    if (! is_dir($path)) {
        if (is_file($path)) {
            unlink($path);
        }

        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function shortCommit(string $commit): string
{
    return substr($commit, 0, 7);
}

function listVariants(): void
{
    info('Livewire starter kit variations:');

    foreach (VARIANTS as $name => $variant) {
        info(sprintf('  %-26s %s#%s', $name, $variant['repo'], $variant['branch']));
        info(sprintf('  %-26s %s', '', $variant['description']));
    }
}

function usage(): void
{
    info('Usage: php bin/download-starter-kits.php [--list] [--only=a,b] [--force] [--dest=path] [--no-scripts]');
}

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function error(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
