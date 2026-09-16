<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Sheaf;

use Onelegstudios\Refit\Libraries\Flux\Internals;

/**
 * The recorded inventory of the components Sheaf ships.
 *
 * Sheaf is a copy-paste library: nothing of it is installed until its CLI writes
 * it, so there is no vendor directory to read the way {@see Internals}
 * has one. What refit needs to know — which tag names exist, so
 * {@see ComponentMap} can be checked against reality rather than against a
 * six-month-old reading of the docs — comes from the public registry instead,
 * recorded by `bin/scan-sheaf-components.php`.
 *
 * Only names are recorded. None of Sheaf's source is copied here; the CLI is what
 * puts components in a project, and refit runs it rather than reimplementing it.
 */
final class Components
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $manifest = null;

    public static function manifestPath(): string
    {
        return dirname(__DIR__, 3).'/resources/sheaf/components.json';
    }

    /**
     * Every component name, as the CLI installs them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_keys(self::components());

        sort($names);

        return $names;
    }

    /**
     * Every tag a Sheaf install can answer to, without the `x-ui.` prefix.
     *
     * `button` and `navlist.item` are both here; the first is a component, the
     * second is one of its parts, and a view may write either.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        $tags = [];

        foreach (self::components() as $name => $parts) {
            foreach ($parts as $part) {
                $tags[$part === '' ? $name : $name.'.'.$part] = true;
            }
        }

        $names = array_keys($tags);

        sort($names);

        return $names;
    }

    /**
     * Component name mapped to the tag suffixes it provides.
     *
     * An empty-string suffix is the component's own tag — `index.blade.php`, or a
     * single file named after the component.
     *
     * @return array<string, list<string>>
     */
    public static function components(): array
    {
        $components = self::manifest()['components'] ?? null;

        if (! is_array($components)) {
            return [];
        }

        $normalised = [];

        foreach ($components as $name => $parts) {
            if (! is_string($name) || ! is_array($parts)) {
                continue;
            }

            $normalised[$name] = array_values(array_filter($parts, is_string(...)));
        }

        return $normalised;
    }

    /**
     * Component name mapped to the other components it cannot render without.
     *
     * Recorded from both halves of the registry — what each config declares and
     * what each component's Blade actually writes — because Sheaf's own configs
     * under-declare. Only components with dependencies appear.
     *
     * @return array<string, list<string>>
     */
    public static function dependencies(): array
    {
        $dependencies = self::manifest()['dependencies'] ?? null;

        if (! is_array($dependencies)) {
            return [];
        }

        $normalised = [];

        foreach ($dependencies as $name => $needs) {
            if (! is_string($name) || ! is_array($needs)) {
                continue;
            }

            $normalised[$name] = array_values(array_filter($needs, is_string(...)));
        }

        return $normalised;
    }

    /**
     * The given components plus everything they need, transitively.
     *
     * Sheaf's CLI resolves only what a config declares, so refit asking for the
     * closure itself is the difference between a dropdown that renders and one
     * that dies on `<x-ui.kbd>`. Cycles are fine: a name already seen is not
     * walked twice.
     *
     * @param  list<string>  $components
     * @return list<string>
     */
    public static function closure(array $components): array
    {
        $dependencies = self::dependencies();
        $resolved = [];
        $pending = $components;

        while ($pending !== []) {
            $name = array_shift($pending);

            if (isset($resolved[$name])) {
                continue;
            }

            $resolved[$name] = true;

            foreach ($dependencies[$name] ?? [] as $need) {
                $pending[] = $need;
            }
        }

        $names = array_keys($resolved);

        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = self::manifestPath();

        if (! is_file($path)) {
            return self::$manifest = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return self::$manifest = is_array($decoded) ? $decoded : [];
    }

    /**
     * Drop the cached manifest, so a test can write one and read it back.
     */
    public static function flush(): void
    {
        self::$manifest = null;
    }
}
