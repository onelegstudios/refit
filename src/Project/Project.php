<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Project;

use Onelegstudios\Refit\Contracts\Library;
use Symfony\Component\Finder\Finder;

/**
 * A detected Livewire starter kit installation.
 *
 * Everything refit knows about the application before it starts planning. Paths
 * are resolved against the project root so the whole package can be pointed at a
 * fixture directory during tests.
 *
 * `target` is the one field disk cannot answer — it is the library the user chose
 * to end on, attached by {@see targeting()} once that question has been asked.
 * Carrying it here rather than threading it through every signature keeps the
 * `Task` contract to the two arguments it has always had.
 */
final class Project
{
    /**
     * @param  list<Feature>  $features
     * @param  list<LibraryInstall>  $libraries
     */
    public function __construct(
        public readonly string $root,
        public readonly ComponentStyle $componentStyle,
        public readonly array $features,
        public readonly array $libraries,
        public readonly bool $chiselPending,
        public readonly ?Library $target = null,
    ) {}

    public function has(Feature $feature): bool
    {
        return in_array($feature, $this->features, strict: true);
    }

    /**
     * The installed footprint of one library, or null when it is not installed.
     */
    public function library(string $key): ?LibraryInstall
    {
        foreach ($this->libraries as $library) {
            if ($library->key === $key) {
                return $library;
            }
        }

        return null;
    }

    /**
     * Is the user leaving this project on the named library?
     */
    public function targets(string $key): bool
    {
        return $this->target?->key() === $key;
    }

    /**
     * The same project, with the chosen target attached.
     */
    public function targeting(Library $library): self
    {
        return new self(
            root: $this->root,
            componentStyle: $this->componentStyle,
            features: $this->features,
            libraries: $this->libraries,
            chiselPending: $this->chiselPending,
            target: $library,
        );
    }

    /**
     * Resolve a project-relative path to an absolute one.
     */
    public function path(string $relative = ''): string
    {
        return $relative === ''
            ? $this->root
            : $this->root.DIRECTORY_SEPARATOR.ltrim($relative, '/');
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative));
    }

    public function get(string $relative): string
    {
        $contents = @file_get_contents($this->path($relative));

        return $contents === false ? '' : $contents;
    }

    /**
     * Every Blade file in the application, as project-relative paths.
     *
     * Scanned live rather than cached: the plan moves and deletes files while it
     * runs, and the reconciliation pass has to see the settled tree.
     *
     * @return list<string>
     */
    public function blades(): array
    {
        $directory = $this->path('resources/views');

        if (! is_dir($directory)) {
            return [];
        }

        $finder = Finder::create()
            ->files()
            ->in($directory)
            ->name('*.blade.php')
            ->sortByName();

        $paths = [];

        foreach ($finder as $file) {
            $paths[] = 'resources/views/'.str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                $file->getRelativePathname(),
            );
        }

        return $paths;
    }

    /**
     * Every Livewire component class in the application, as project-relative paths.
     *
     * The other half of where a component's PHP lives. Volt keeps it inside the
     * view, so {@see blades()} already covers four of the five kits; the
     * class-components kit puts it in `app/Livewire` instead, which nothing else
     * in refit has ever needed to read.
     *
     * Deliberately only `app/Livewire`, not all of `app`. Everything that rewrites
     * PHP here rewrites it into `$this->dispatch(...)`, which is a promise about
     * the class it lands in — and `app/Livewire` is the one directory where
     * Laravel guarantees that promise holds. A `Flux::toast()` in a controller is
     * reported rather than rewritten, because there is no `$this` there to
     * dispatch from.
     *
     * Scanned live, for the same reason as {@see blades()}.
     *
     * @return list<string>
     */
    public function livewireClasses(): array
    {
        $directory = $this->path('app/Livewire');

        if (! is_dir($directory)) {
            return [];
        }

        $finder = Finder::create()
            ->files()
            ->in($directory)
            ->name('*.php')
            ->sortByName();

        $paths = [];

        foreach ($finder as $file) {
            $paths[] = 'app/Livewire/'.str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                $file->getRelativePathname(),
            );
        }

        return $paths;
    }

    /**
     * Loose anonymous components, as bare names.
     *
     * Top level only: anything already in a subfolder is namespaced. Read live
     * for the same reason as {@see blades()} — a task earlier in the run may
     * still be adding files to the directory.
     *
     * @return list<string>
     */
    public function looseComponents(): array
    {
        $matches = glob($this->path('resources/views/components').'/*.blade.php');

        if ($matches === false) {
            return [];
        }

        $names = [];

        foreach ($matches as $path) {
            $name = basename($path, '.blade.php');

            // Single-file Livewire components carry an installer-applied prefix
            // that is part of the real filename. Leave those to Livewire.
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * A short human description of the kit, for the command's summary line.
     */
    public function describe(): string
    {
        $parts = [$this->componentStyle->label()];

        foreach ($this->features as $feature) {
            $parts[] = $feature->label();
        }

        return implode(', ', $parts);
    }
}
