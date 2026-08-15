<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Icons;

use FilesystemIterator;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Project\Project;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds every icon name an application references.
 *
 * Two forms carry a name: the attribute form (`icon="home"`) and the tag form
 * (`<flux:icon.key />`). Both are collected with the files they appear in, so the
 * report can point at a specific view when a name has no translation.
 *
 * Which tags and attributes those are comes from the library's
 * {@see Vocabulary}, so the same scanner reads a Flux tree or a Sheaf one.
 */
final class IconScanner
{
    public function __construct(
        private readonly Vocabulary $vocabulary,
        private readonly TagParser $parser = new TagParser,
    ) {}

    /**
     * @return array<string, list<string>> Icon name mapped to the paths using it.
     */
    public function scan(Project $project): array
    {
        $found = [];

        foreach ($project->blades() as $path) {
            foreach ($this->scanSource($project->get($path)) as $name) {
                $found[$name][] = $path;

                $found[$name] = array_values(array_unique($found[$name]));
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    public function scanSource(string $source): array
    {
        $names = [];

        foreach ($this->parser->parse($source, $this->vocabulary->prefix) as $tag) {
            $suffix = $this->vocabulary->dottedIconTag === null
                ? null
                : $tag->nameAfter($this->vocabulary->dottedIconTag);

            if ($suffix !== null && $suffix !== '') {
                $names[] = $suffix;
            }

            foreach ($tag->attributes as $attribute) {
                if (! $this->vocabulary->namesAnIcon($tag->name, $attribute->name)) {
                    continue;
                }

                // Bound values hold a PHP expression, not a name refit can read.
                if ($attribute->isBound() || $attribute->isBoolean()) {
                    continue;
                }

                $value = (string) $attribute->value;

                if ($value !== '' && ! self::isInterpolated($value)) {
                    $names[] = $value;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Does this attribute value interpolate Blade rather than name an icon?
     *
     * Flux Pro passes its own prop through in the unbound form —
     * `<flux:icon name="{{ $icon }}" />` in `file-item`, `file-upload/dropzone`
     * and others — which the bound check never sees. Left in, `{{ $icon }}`
     * would be recorded as a name and reported as untranslatable on every Pro
     * project refit touches.
     */
    private static function isInterpolated(string $value): bool
    {
        return str_contains($value, '{{') || str_contains($value, '{!!');
    }

    /**
     * Icon names a component defaults its own icon prop to.
     *
     * Flux's `error` component renders `exclamation-triangle` without ever
     * writing it on a tag — it is the default of the `icon` prop, so only the
     * `@props` array names it. `name` is deliberately not read here: `@props`
     * declares one on components that have nothing to do with icons.
     *
     * @return list<string>
     */
    public function scanPropDefaults(string $source): array
    {
        if (preg_match_all('/@props\s*\(\s*\[(.*?)]\s*\)/s', $source, $blocks) === 0) {
            return [];
        }

        $keys = implode('|', array_map(preg_quote(...), $this->vocabulary->nameAttributes));
        $names = [];

        foreach ($blocks[1] as $block) {
            preg_match_all(
                sprintf('/([\'"])(?:%s)\1\s*=>\s*([\'"])([^\'"]+)\2/', $keys),
                $block,
                $defaults,
            );

            foreach ($defaults[3] as $name) {
                if (self::isInterpolated($name)) {
                    continue;
                }

                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every icon name one directory of a library's own Blade stubs renders.
     *
     * Flux renders icons from inside its own components, so the override set has
     * to cover names that never appear in application code. Reading the installed
     * package beats guessing: it stays correct as Flux changes, and
     * `bin/scan-flux-internals.php` records it through the same parser the command
     * uses. A missing directory yields nothing — an uninstalled edition is
     * ordinary, not an error.
     *
     * @return list<string>
     */
    public function scanStubDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $names = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            foreach ([...$this->scanSource($contents), ...$this->scanPropDefaults($contents)] as $name) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));

        sort($names);

        return $names;
    }
}
