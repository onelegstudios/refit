<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Flux;

use FilesystemIterator;
use Onelegstudios\Refit\Project\Project;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The files under `resources/views/flux`, where Flux lets a project replace its
 * own views.
 *
 * The kit puts four Lucide icons and a restyled navlist group in there. Refit
 * treats the two halves differently — the icons belong to the icon question, the
 * rest does not — so both readers can leave a subdirectory out.
 */
final class Overrides
{
    public const string ROOT = 'resources/views/flux';

    public const string ICONS = 'icon';

    /**
     * Every file under the root, as sorted project-relative paths.
     *
     * @param  list<string>  $except  Top-level subdirectories to leave out.
     * @return list<string>
     */
    public static function files(Project $project, array $except = []): array
    {
        $root = $project->path(self::ROOT);

        if (! is_dir($root)) {
            return [];
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen($root) + 1),
            );

            if (in_array(explode('/', $relative)[0], $except, true)) {
                continue;
            }

            $paths[] = self::ROOT.'/'.$relative;
        }

        sort($paths);

        return $paths;
    }

    /**
     * The subdirectories holding the given files, deepest first so each is empty
     * by the time it is asked to go. The root itself is not included.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function directories(array $files): array
    {
        $directories = [];

        foreach ($files as $path) {
            $directory = dirname($path);

            while ($directory !== self::ROOT && $directory !== '.' && $directory !== '/') {
                $directories[$directory] = true;
                $directory = dirname($directory);
            }
        }

        $paths = array_keys($directories);

        // Longest path first is deepest first, which is the only order in which
        // "remove if empty" can succeed all the way up.
        usort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $paths;
    }
}
