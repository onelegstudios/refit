#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lucide artwork downloader
|--------------------------------------------------------------------------
|
| Refreshes resources/icons/lucide from lucide-icons/lucide, so the artwork
| refit bundles can be brought up to date as a reviewable commit rather than
| drifting silently or being pulled from a runtime dependency.
|
| The set is derived from Onelegstudios\Refit\Icons\IconMap: refit only ever
| generates the names it can translate, so the map is the single source of
| truth for what needs to be on disk. Adding a mapping and re-running this is
| the whole workflow for supporting a new icon.
|
| Usage:
|   php bin/download-icons.php [options]
|
| Options:
|   --list            List the icons the map requires and exit.
|   --check           Report artwork that differs from upstream, write nothing.
|   --only=a,b        Refresh only the given icons.
|   --ref=1.31.0      Use a specific tag, branch, or commit instead of the
|                     latest Lucide release.
|   --force           Rewrite even when the bundle is already at that commit.
|   --dest=path       Write to a different directory.
|   --help            Show this help.
|
| Set GITHUB_TOKEN (or GH_TOKEN) to authenticate the GitHub API calls used to
| resolve the release tag to a commit.
|
| Upstream ships each icon with width/height attributes and a multi-line open
| tag. Those are normalised away here to match the shape the Livewire starter
| kit vendors its own Lucide icons in, which is the shape OverrideGenerator
| reads and the format already committed to this repository.
|
*/

use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Libraries\Flux\OwnedIcons;

require __DIR__.'/../vendor/autoload.php';

const REPO = 'lucide-icons/lucide';

const MANIFEST = 'manifest.json';

const LICENSE = 'LICENSE';

/**
 * Attributes dropped from the `<svg>` open tag.
 *
 * Flux sizes and strokes the icon through its own class tables, so upstream's
 * fixed width/height would fight it, and the `class` is a Lucide-web concern.
 */
const DROPPED_ATTRIBUTES = ['width', 'height', 'class'];

exit(main($argv));

function main(array $argv): int
{
    $options = parseOptions(array_slice($argv, 1));

    if (isset($options['help'])) {
        usage();

        return 0;
    }

    if (isset($options['list'])) {
        listIcons();

        return 0;
    }

    $icons = selectedIcons($options['only'] ?? null);

    if ($icons === []) {
        return 1;
    }

    $destination = $options['dest'] ?? __DIR__.'/../resources/icons/lucide';

    if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
        error("Unable to create [{$destination}].");

        return 1;
    }

    $destination = (string) realpath($destination);
    $check = isset($options['check']);
    $partial = ($options['only'] ?? '') !== '';

    $ref = resolveRef($options['ref'] ?? null);

    if ($ref === null) {
        return 1;
    }

    $commit = resolveCommit($ref);

    if ($commit === null) {
        return 1;
    }

    $manifest = readManifest($destination);
    $previous = $manifest['commit'] ?? null;

    info(sprintf(
        '%s %d Lucide icon(s) from %s@%s (%s)',
        $check ? 'Checking' : 'Downloading',
        count($icons),
        REPO,
        $ref,
        shortCommit($commit),
    ));

    if (! $check && ! isset($options['force']) && $previous === $commit && allPresent($destination, $icons)) {
        info('  Already at that commit — nothing to do. Pass --force to rewrite anyway.');

        return 0;
    }

    if ($previous !== null && $previous !== $commit) {
        info('  Lucide moved '.shortCommit($previous).' -> '.shortCommit($commit).' since the bundle was last refreshed.');
    }

    $changed = [];
    $missing = [];

    foreach ($icons as $name) {
        $svg = fetchIcon($commit, $name);

        // Keep going rather than bailing: one rename upstream should not hide
        // the drift in the other thirty-one icons.
        if ($svg === null) {
            $missing[] = $name;

            continue;
        }

        $normalised = normalise($svg);

        if ($normalised === null) {
            error("  {$name}: unexpected SVG layout upstream.");

            return 1;
        }

        $path = $destination.'/'.$name.'.svg';
        $current = is_file($path) ? (string) file_get_contents($path) : null;

        if ($current === $normalised) {
            continue;
        }

        $changed[] = $name;

        if ($check) {
            info('  '.$name.': '.($current === null ? 'missing' : 'differs from upstream'));

            continue;
        }

        if (file_put_contents($path, $normalised) === false) {
            error("Unable to write [{$path}].");

            return 1;
        }

        info('  '.$name.': '.($current === null ? 'added' : 'updated'));
    }

    if ($missing !== []) {
        error(sprintf(
            '%d icon(s) are not at that name in %s@%s: %s',
            count($missing),
            REPO,
            $ref,
            implode(', ', $missing),
        ));
        error('Lucide renames icons across releases. Point IconMap at the new name, rename the bundled file to match, and re-run.');
    }

    if ($check) {
        if ($changed === [] && $missing === []) {
            info('Bundle matches '.REPO.'@'.$ref.'.');

            return 0;
        }

        if ($changed !== []) {
            error(sprintf('%d icon(s) differ from %s@%s. Run `composer icons` to refresh them.', count($changed), REPO, $ref));
        }

        return 1;
    }

    if (! writeLicense($destination, $commit)) {
        return 1;
    }

    if ($missing !== []) {
        error('Manifest left alone — the bundle is not fully at '.$ref.'.');

        return 1;
    }

    if ($partial) {
        info('Manifest left alone — a partial run leaves the bundle at mixed versions.');
    } else {
        writeManifest($destination, [
            'repo' => REPO,
            'ref' => $ref,
            'commit' => $commit,
            'downloaded_at' => gmdate('c'),
            'icons' => $icons,
        ]);
    }

    info($changed === []
        ? 'Done — the artwork was already identical to upstream.'
        : sprintf('Done — %d icon(s) changed. Review the diff before committing.', count($changed)));

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
 * Every Lucide name refit can generate artwork for.
 *
 * Both directions of the map matter: the values are the icons a Heroicons name
 * translates to, and the keys of the reverse map are the ones the starter kit
 * vendors in and refit has to be able to re-emit. The names Flux draws itself
 * are overridden with Lucide artwork too, so their stand-ins are needed as well.
 *
 * @return list<string>
 */
function requiredIcons(): array
{
    $names = array_unique(array_merge(
        array_values(IconMap::HEROICONS_TO_LUCIDE),
        array_keys(IconMap::LUCIDE_TO_HEROICONS),
        array_values(OwnedIcons::ARTWORK),
    ));

    sort($names);

    return array_values($names);
}

/**
 * Resolve the icons to refresh, reporting names the map does not know.
 *
 * @return list<string>
 */
function selectedIcons(?string $only): array
{
    $required = requiredIcons();

    if ($only === null || $only === '') {
        return $required;
    }

    $names = array_values(array_filter(array_map('trim', explode(',', $only))));
    $unknown = array_diff($names, $required);

    if ($unknown !== []) {
        error('Unknown icon(s): '.implode(', ', $unknown));
        error('Run with --list to see the icons IconMap requires.');

        return [];
    }

    sort($names);

    return $names;
}

/**
 * Resolve the Lucide ref to pull from, defaulting to the latest release tag.
 */
function resolveRef(?string $ref): ?string
{
    if ($ref !== null && $ref !== '') {
        return $ref;
    }

    $response = request('https://api.github.com/repos/'.REPO.'/releases/latest');

    if ($response === null) {
        error('Unable to resolve the latest '.REPO.' release. Pass --ref to pick one explicitly.');

        return null;
    }

    $release = json_decode($response, true);
    $tag = is_array($release) ? ($release['tag_name'] ?? null) : null;

    if (! is_string($tag) || $tag === '') {
        error('Unexpected release response from '.REPO.'.');

        return null;
    }

    return $tag;
}

/**
 * Resolve a tag, branch, or commit-ish to the commit it points at.
 */
function resolveCommit(string $ref): ?string
{
    $response = request(
        'https://api.github.com/repos/'.REPO.'/commits/'.rawurlencode($ref),
        ['Accept: application/vnd.github.sha'],
    );

    if ($response === null) {
        error('Unable to resolve ['.REPO.'#'.$ref.'].');

        return null;
    }

    $commit = trim($response);

    if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
        error('Unexpected commit response for ['.REPO.'#'.$ref."]: {$commit}");

        return null;
    }

    return $commit;
}

/**
 * Fetch one icon, or null when it is not at that name for that commit.
 *
 * The failing URL and status are already reported by {@see request()}; the
 * caller decides what an absence means.
 */
function fetchIcon(string $commit, string $name): ?string
{
    return request('https://raw.githubusercontent.com/'.REPO."/{$commit}/icons/{$name}.svg");
}

/**
 * Rewrite an upstream Lucide SVG into the shape the starter kit uses.
 *
 * Elements are re-emitted one per line and re-indented, so a `<g>` wrapper or a
 * redrawn glyph produces a diff that is readable rather than a single long line.
 */
function normalise(string $svg): ?string
{
    if (preg_match('/(<svg\b[^>]*>)(.*)<\/svg>/s', $svg, $matches) !== 1) {
        return null;
    }

    if (preg_match_all('/<[^>]+>/', $matches[2], $elements) === false) {
        return null;
    }

    $lines = [];
    $depth = 1;

    foreach ($elements[0] as $element) {
        $element = collapse($element);
        $closing = str_starts_with($element, '</');

        if ($closing) {
            $depth = max(1, $depth - 1);
        }

        $lines[] = str_repeat('  ', $depth).$element;

        if (! $closing && ! str_ends_with($element, '/>')) {
            $depth++;
        }
    }

    if ($lines === []) {
        return null;
    }

    // No trailing newline: this matches the artwork already committed here, and
    // the icons are read whole rather than streamed line by line.
    return openTag($matches[1]).PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL.'</svg>';
}

/**
 * Flatten the multi-line `<svg>` open tag upstream writes, keeping the rendering
 * attributes in their original order so a change to Lucide's defaults shows up
 * in the diff instead of being masked by a hard-coded header.
 */
function openTag(string $tag): string
{
    preg_match_all('/([\w:-]+)="([^"]*)"/', $tag, $attributes, PREG_SET_ORDER);

    $kept = [];

    foreach ($attributes as [, $name, $value]) {
        if (in_array($name, DROPPED_ATTRIBUTES, true)) {
            continue;
        }

        $kept[] = $name.'="'.$value.'"';
    }

    return '<svg '.implode(' ', $kept).'>';
}

/**
 * Collapse an element onto one line, dropping the space upstream puts before
 * the self-closing slash.
 */
function collapse(string $element): string
{
    $element = (string) preg_replace('/\s+/', ' ', trim($element));

    return (string) preg_replace('/\s+(\/?>)$/', '$1', $element);
}

/**
 * Keep the ISC licence next to the artwork it covers, at the same commit.
 */
function writeLicense(string $destination, string $commit): bool
{
    $license = request('https://raw.githubusercontent.com/'.REPO."/{$commit}/".LICENSE);

    if ($license === null) {
        error('Unable to download the Lucide licence.');

        return false;
    }

    $path = $destination.'/'.LICENSE;

    if (is_file($path) && file_get_contents($path) === $license) {
        return true;
    }

    if (file_put_contents($path, $license) === false) {
        error("Unable to write [{$path}].");

        return false;
    }

    info('  '.LICENSE.': updated');

    return true;
}

/**
 * @param  list<string>  $icons
 */
function allPresent(string $destination, array $icons): bool
{
    foreach ($icons as $name) {
        if (! is_file($destination.'/'.$name.'.svg')) {
            return false;
        }
    }

    return true;
}

/**
 * Perform a GET request against the GitHub API or raw.githubusercontent.
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
 * @return array<string, mixed>
 */
function readManifest(string $destination): array
{
    $file = $destination.'/'.MANIFEST;

    if (! is_file($file)) {
        return [];
    }

    $manifest = json_decode((string) file_get_contents($file), true);

    return is_array($manifest) ? $manifest : [];
}

function writeManifest(string $destination, array $manifest): void
{
    file_put_contents(
        $destination.'/'.MANIFEST,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
    );
}

function shortCommit(string $commit): string
{
    return substr($commit, 0, 7);
}

function listIcons(): void
{
    $aliases = [];

    foreach ([...IconMap::HEROICONS_TO_LUCIDE, ...OwnedIcons::ARTWORK] as $heroicon => $lucide) {
        $aliases[$lucide][] = $heroicon;
    }

    info('Lucide icons IconMap requires:');

    foreach (requiredIcons() as $name) {
        info(sprintf(
            '  %-18s %s',
            $name,
            isset($aliases[$name])
                ? 'stands in for '.implode(', ', $aliases[$name])
                : 'vendored by the starter kit',
        ));
    }
}

function usage(): void
{
    info('Usage: php bin/download-icons.php [--list] [--check] [--only=a,b] [--ref=tag] [--force] [--dest=path]');
}

function info(string $message): void
{
    fwrite(STDOUT, $message.PHP_EOL);
}

function error(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
