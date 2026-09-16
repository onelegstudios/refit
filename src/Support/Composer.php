<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Support;

/**
 * Reads a project's Composer files.
 *
 * Both signals matter and they answer different questions. `composer.json` says
 * what the application asked for; `composer.lock` says what it actually got,
 * which is the only honest place to look for a package installed from a private
 * repository the user added themselves.
 */
final class Composer
{
    public function __construct(private readonly string $root) {}

    /**
     * Is this package named in `require` or `require-dev`?
     */
    public function requires(string $package): bool
    {
        $manifest = $this->manifest();

        foreach (['require', 'require-dev'] as $section) {
            $packages = $manifest[$section] ?? null;

            if (is_array($packages) && array_key_exists($package, $packages)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this package in the lock file?
     *
     * A string check rather than a decode: the lock file is large, the package
     * name is quoted in it unambiguously, and refit only ever asks yes or no.
     */
    public function locks(string $package): bool
    {
        $lock = @file_get_contents($this->root.'/composer.lock');

        return $lock !== false && str_contains($lock, '"'.$package.'"');
    }

    /**
     * Named in either file.
     */
    public function has(string $package): bool
    {
        return $this->requires($package) || $this->locks($package);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $contents = @file_get_contents($this->root.'/composer.json');

        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
