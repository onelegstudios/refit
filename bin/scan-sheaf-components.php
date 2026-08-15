#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sheaf component recorder
|--------------------------------------------------------------------------
|
| Refreshes resources/sheaf/components.json: the components Sheaf ships and the
| tags each one answers to. ComponentMap is checked against it, so a component
| renamed upstream fails CI here rather than a user's view at runtime.
|
| Sheaf is a copy-paste library — its CLI writes source into the application and
| nothing of it lives in vendor/ — so there is no installed package to read the
| way bin/scan-flux-internals.php reads Flux. The public registry is the source
| instead, and it is genuinely public: sheafui/components is MIT, so this needs
| no licence, no credentials, and no sidecar install.
|
| Each component directory carries a config.yml listing where its files land:
|
|   files:
|     index: resources/views/components/ui/navlist/index.blade.php
|     item:  resources/views/components/ui/navlist/item.blade.php
|
| which is the authority on the tag names, because the install path *is* the
| Blade component name. `index` is the component's own tag, a single file named
| after its directory likewise; anything else is a dotted part.
|
| The config also declares what a component needs:
|
|   dependencies:
|     internal: [icon]
|
| but it under-declares. Sheaf's dropdown writes <x-ui.kbd> and <x-ui.button> and
| names neither, so `sheaf:install dropdown` leaves a view that cannot render.
| The recorder therefore reads each component's Blade source as well and unions
| what it finds there with what the config claims, which is why it fetches files
| rather than only configs. Refit installs the closure of that graph.
|
| Only names are recorded. None of Sheaf's source is copied here.
|
| Usage:
|   php bin/scan-sheaf-components.php [options]
|
| Options:
|   --repo=owner/name  Registry to read. Defaults to sheafui/components.
|   --ref=branch       Branch or tag. Defaults to the repository's default.
|   --path=dir         Read a local checkout instead of GitHub.
|   --check            Report drift against the manifest, write nothing.
|                      Exits non-zero when the registry disagrees.
|   --dest=path        Write to a different manifest file.
|   --help             Show this help.
|
*/

use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\Sheaf\Components;

require __DIR__.'/../vendor/autoload.php';

const DEFAULT_REPO = 'sheafui/components';

const INSTALL_ROOT = 'resources/views/components/ui/';

exit(main($argv));

function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));

    if (isset($options['help'])) {
        info('Usage: php bin/scan-sheaf-components.php [--repo=owner/name] [--ref=branch] [--path=dir] [--check] [--dest=path]');

        return 0;
    }

    $destination = $options['dest'] ?? Components::manifestPath();
    $check = isset($options['check']);
    $repo = $options['repo'] ?? DEFAULT_REPO;

    try {
        [$components, $dependencies, $source] = isset($options['path'])
            ? readLocal(rtrim($options['path'], '/'))
            : readRegistry($repo, $options['ref'] ?? null);
    } catch (RuntimeException $exception) {
        error($exception->getMessage());

        return 1;
    }

    if ($components === []) {
        error('No components found — the registry layout may have changed.');

        return 1;
    }

    ksort($components);
    $dependencies = pruneDependencies($dependencies, $components);

    info(sprintf('%s %d component(s) from %s', $check ? 'Checked' : 'Recorded', count($components), $source));

    $recorded = Components::components();
    $added = array_values(array_diff(array_keys($components), array_keys($recorded)));
    $removed = array_values(array_diff(array_keys($recorded), array_keys($components)));
    $changed = [];

    foreach ($components as $name => $parts) {
        $was = $recorded[$name] ?? null;

        if ($was !== null && $was !== $parts) {
            $changed[] = $name;
        }
    }

    $rewired = [];
    $wasDependencies = Components::dependencies();

    foreach (array_keys($components + $wasDependencies) as $name) {
        if (($wasDependencies[$name] ?? []) !== ($dependencies[$name] ?? [])) {
            $rewired[] = (string) $name;
        }
    }

    foreach ([['new', $added], ['gone', $removed], ['changed', $changed], ['needs', $rewired]] as [$label, $names]) {
        if ($names !== []) {
            info(sprintf('  %-8s %s', $label, implode(', ', $names)));
        }
    }

    $broken = reportBrokenMappings($components);
    $drifted = $added !== [] || $removed !== [] || $changed !== [] || $rewired !== [];

    if ($check) {
        if ($broken !== []) {
            error('ComponentMap points at components Sheaf no longer ships.');
            error('Fix the mapping, then run `composer sheaf:components` to re-record.');

            return 1;
        }

        if (! $drifted) {
            info('Manifest matches the registry.');

            return 0;
        }

        error('Sheaf ships a different set of components than the manifest records.');
        error('Run `composer sheaf:components` to refresh it, then review ComponentMap.');

        return 1;
    }

    if (! $drifted) {
        info('Done — the manifest already matched the registry.');

        return 0;
    }

    if (! writeManifest($destination, [
        'generated_at' => gmdate('c'),
        'source' => $source,
        'components' => $components,
        'dependencies' => $dependencies,
    ])) {
        return 1;
    }

    info('Done — review the diff before committing.');

    return 0;
}

/**
 * Read every component's config.yml and Blade source out of the GitHub tree.
 *
 * One tree call plus one call per file, rather than cloning: the files are a few
 * hundred bytes each and this runs on a schedule, not in a hot path. The Blade
 * sources are fetched because the configs alone under-report what a component
 * needs — see the header.
 *
 * @return array{array<string, list<string>>, array<string, list<string>>, string}
 */
function readRegistry(string $repo, ?string $ref): array
{
    $ref ??= (string) (json(request("https://api.github.com/repos/{$repo}"))['default_branch'] ?? 'main');
    $tree = json(request("https://api.github.com/repos/{$repo}/git/trees/{$ref}?recursive=1"));

    $configs = [];
    $sources = [];

    foreach ($tree['tree'] ?? [] as $entry) {
        $path = $entry['path'] ?? '';

        if (($entry['type'] ?? '') !== 'blob') {
            continue;
        }

        if (preg_match('#^components/([^/]+)/config\.yml$#', $path, $matches) === 1) {
            $configs[$matches[1]] = $path;
        }

        if (preg_match('#^components/([^/]+)/.+\.blade\.php$#', $path, $matches) === 1) {
            $sources[$matches[1]][] = $path;
        }
    }

    $components = [];
    $dependencies = [];

    foreach ($configs as $name => $path) {
        $body = request("https://raw.githubusercontent.com/{$repo}/{$ref}/{$path}");
        $parts = partsFrom($name, $body);

        if ($parts === []) {
            continue;
        }

        $components[$name] = $parts;
        $dependencies[$name] = needsFrom($name, $body, array_map(
            fn (string $file): string => request("https://raw.githubusercontent.com/{$repo}/{$ref}/{$file}"),
            $sources[$name] ?? [],
        ));
    }

    return [$components, $dependencies, "{$repo}@{$ref}"];
}

/**
 * @return array{array<string, list<string>>, array<string, list<string>>, string}
 */
function readLocal(string $path): array
{
    $directory = $path.'/components';

    if (! is_dir($directory)) {
        throw new RuntimeException("No components directory under [{$path}].");
    }

    $components = [];
    $dependencies = [];

    foreach ((array) glob($directory.'/*/config.yml') as $config) {
        $name = basename(dirname((string) $config));
        $body = (string) file_get_contents((string) $config);
        $parts = partsFrom($name, $body);

        if ($parts === []) {
            continue;
        }

        $components[$name] = $parts;
        $dependencies[$name] = needsFrom($name, $body, array_map(
            fn (string $file): string => (string) file_get_contents($file),
            bladesUnder($directory.'/'.$name),
        ));
    }

    return [$components, $dependencies, $path];
}

/**
 * Every Blade file beneath a component's directory, at any depth.
 *
 * `navlist` nests its group variants two levels down, so a flat glob would miss
 * whatever those reach for.
 *
 * @return list<string>
 */
function bladesUnder(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * The tag suffixes one component provides, read from its config's install paths.
 *
 * The path is the authority, not the key beside it: Blade resolves a component
 * by where its file sits, and `text: resources/views/components/ui/text.blade.php`
 * is `<x-ui.text>` while `index: .../button/index.blade.php` is `<x-ui.button>`.
 *
 * Deliberately not a YAML parser. The configs are two flat keys deep and adding
 * symfony/yaml to a package whose whole point is having few dependencies, for one
 * scheduled script, is not a trade worth making.
 *
 * @return list<string>
 */
function partsFrom(string $component, string $yaml): array
{
    $prefix = INSTALL_ROOT.$component;
    $parts = [];

    foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
        if (preg_match('/^\s+\S+:\s*(\S+\.blade\.php)\s*$/', $line, $matches) !== 1) {
            continue;
        }

        $path = $matches[1];

        // The component's own tag, as a single file named after it.
        if ($path === $prefix.'.blade.php') {
            $parts[''] = true;

            continue;
        }

        if (! str_starts_with($path, $prefix.'/')) {
            continue;
        }

        $suffix = substr($path, strlen($prefix) + 1, -strlen('.blade.php'));

        // Sheaf's own internals: `abstract` is a shared partial, `runtime` is
        // wiring, and `variant/*` is chosen by a prop rather than written as a
        // tag. None of them is something a view says out loud.
        if (in_array($suffix, ['abstract', 'runtime'], true) || str_contains($suffix, '/')) {
            continue;
        }

        $parts[$suffix === 'index' ? '' : $suffix] = true;
    }

    $names = array_keys($parts);

    sort($names);

    return $names;
}

/**
 * The other components one component cannot render without.
 *
 * Two sources, unioned, because neither is complete on its own. The config's
 * `dependencies.internal` names things the source does not always write out —
 * `sidebar` declares `button` without a `<x-ui.button>` anywhere in it — while
 * the source writes tags the config never declares, which is the failure that
 * put this function here: `dropdown/item.blade.php` renders `<x-ui.kbd>` and the
 * config lists only `icon`.
 *
 * @param  list<string>  $sources  Blade source of every file the component ships.
 * @return list<string>
 */
function needsFrom(string $component, string $yaml, array $sources): array
{
    $needs = [];

    foreach (declaredDependencies($yaml) as $name) {
        $needs[$name] = true;
    }

    foreach ($sources as $source) {
        preg_match_all('/<'.preg_quote(ComponentMap::PREFIX, '/').'([a-z0-9-]+)/i', $source, $matches);

        foreach ($matches[1] as $name) {
            $needs[strtolower($name)] = true;
        }
    }

    // A component reaching for its own parts is not a dependency.
    unset($needs[$component]);

    $names = array_keys($needs);

    sort($names);

    return $names;
}

/**
 * The `dependencies.internal` list a config declares, in either YAML spelling.
 *
 * Still not a YAML parser, for the reason {@see partsFrom} gives. Both an inline
 * `[icon, button]` and a block sequence of `- icon` lines appear in the registry,
 * so both are read; anything else falls through to the source scan.
 *
 * @return list<string>
 */
function declaredDependencies(string $yaml): array
{
    $names = [];
    $inList = false;

    foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
        if (preg_match('/^\s+internal:\s*(.*)$/', $line, $matches) === 1) {
            $inline = trim($matches[1]);
            $inList = $inline === '';

            foreach (explode(',', trim($inline, '[] ')) as $name) {
                $names[] = trim($name);
            }

            continue;
        }

        if ($inList && preg_match('/^\s+-\s*(\S+)\s*$/', $line, $matches) === 1) {
            $names[] = $matches[1];

            continue;
        }

        $inList = false;
    }

    return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
}

/**
 * Drop anything the graph names that the registry does not actually ship.
 *
 * A dependency refit cannot install is worse than one it never knew about: it
 * would put a `sheaf:install` for a nonexistent component in the plan, and that
 * step is required, so the run would stop before rewriting a thing.
 *
 * @param  array<string, list<string>>  $dependencies
 * @param  array<string, list<string>>  $components
 * @return array<string, list<string>>
 */
function pruneDependencies(array $dependencies, array $components): array
{
    $pruned = [];
    $unknown = [];

    ksort($dependencies);

    foreach ($dependencies as $name => $needs) {
        $known = array_values(array_filter($needs, fn (string $need): bool => isset($components[$need])));
        $unknown = array_merge($unknown, array_diff($needs, $known));

        if ($known !== []) {
            $pruned[$name] = $known;
        }
    }

    $unknown = array_values(array_unique($unknown));

    if ($unknown !== []) {
        // Not fatal — a tag that resolves to no component is Sheaf's problem to
        // have, and refit has nothing it could install for it either way.
        info('  unknown  '.implode(', ', $unknown));
    }

    return $pruned;
}

/**
 * Mappings pointing at a component the registry no longer has.
 *
 * @param  array<string, list<string>>  $components
 * @return list<string>
 */
function reportBrokenMappings(array $components): array
{
    $available = [];

    foreach ($components as $name => $parts) {
        foreach ($parts as $part) {
            $available[$part === '' ? $name : $name.'.'.$part] = true;
        }
    }

    $broken = [];

    foreach (ComponentMap::TAGS as $flux => $sheaf) {
        // Not every target is a Sheaf component: `flux:menu` becomes Blade's own
        // `<x-slot:menu>`, which no registry can vouch for and none needs to.
        if (! str_starts_with($sheaf, ComponentMap::PREFIX)) {
            continue;
        }

        $tag = substr($sheaf, strlen(ComponentMap::PREFIX));

        if (! isset($available[$tag])) {
            $broken[] = sprintf('%s -> %s', $flux, $sheaf);
        }
    }

    foreach ($broken as $line) {
        error('  no such component: '.$line);
    }

    return $broken;
}

function request(string $url): string
{
    $headers = ['User-Agent: onelegstudios-laravel-refit', 'Accept: application/vnd.github+json'];
    $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN');

    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer '.$token;
    }

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['header' => implode("\r\n", $headers), 'timeout' => 30, 'ignore_errors' => true],
    ]));

    if ($body === false) {
        throw new RuntimeException("Unable to reach [{$url}].");
    }

    return $body;
}

/**
 * @return array<mixed>
 */
function json(string $body): array
{
    $decoded = json_decode($body, true);

    if (! is_array($decoded)) {
        throw new RuntimeException('GitHub returned something that is not JSON. Rate limited? Set GITHUB_TOKEN.');
    }

    if (isset($decoded['message']) && ! isset($decoded['tree']) && ! isset($decoded['default_branch'])) {
        throw new RuntimeException('GitHub said: '.(string) $decoded['message']);
    }

    return $decoded;
}

/**
 * @return array<string, string>
 */
function parseOptions(array $arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        if (! str_starts_with((string) $argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr((string) $argument, 2), 2), 2, '');

        $options[$key] = $value;
    }

    return $options;
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

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function error(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
