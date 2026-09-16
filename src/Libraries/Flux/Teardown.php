<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Flux;

use FilesystemIterator;
use Onelegstudios\Refit\Plan\Actions\DeleteFile;
use Onelegstudios\Refit\Plan\Actions\RemoveBladeDirectives;
use Onelegstudios\Refit\Plan\Actions\RemoveDirectoryIfEmpty;
use Onelegstudios\Refit\Plan\Actions\RemoveLinesContaining;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Take Flux's remaining traces out of a project that has moved off it.
 *
 * The component tags are gone by the time this runs — that is the target's
 * migration doing its job — but three things outlive them: the two Tailwind
 * `@source` lines pointing into Flux's vendor stubs, the `@fluxAppearance` and
 * `@fluxScripts` directives in the head and layouts, and `resources/views/flux`,
 * a directory that exists only to intercept Flux's own resolution. The kit puts
 * four icons and a navlist override in there; whatever a project has added is
 * just as dead.
 *
 * None of it is specific to where the project is going, which is the whole
 * argument for it living here rather than in the target: Sheaf does not need to
 * know what a `@fluxAppearance` is, and neither will the library after it.
 *
 * The directives are the one part that cannot be planned file by file. This runs
 * after the target has contributed, but *before* the target has written anything,
 * and a target may well rewrite the very files the directives are in — so which
 * files those are is a question only the apply stage can answer.
 *
 * The Composer package itself is not removed. Refit prints the line rather than
 * running it, the same way it does for its own uninstall: dropping a dependency
 * is a decision worth taking deliberately, and it is one command.
 */
final class Teardown
{
    private const string STYLESHEET = 'resources/css/app.css';

    private const string OVERRIDE_ROOT = 'resources/views/flux';

    /**
     * Blade directives Flux registers, which fatal once the package is gone.
     *
     * @var list<string>
     */
    private const array DIRECTIVES = ['@fluxAppearance', '@fluxScripts'];

    /**
     * The stylesheet's Flux lines, both the import and the two `@source` globs.
     *
     * One file that no target rewrites, so this one can still be planned by name.
     */
    private const string STYLESHEET_NEEDLE = 'livewire/flux';

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        if ($project->exists(self::STYLESHEET) && str_contains($project->get(self::STYLESHEET), self::STYLESHEET_NEEDLE)) {
            $plan->add(Stage::Write, new RemoveLinesContaining(
                self::STYLESHEET,
                self::STYLESHEET_NEEDLE,
                'edit   '.self::STYLESHEET.' — drop the Flux @source lines',
            ));
        }

        // Swept rather than planned file by file: the target rewrites the chrome
        // from its own stubs in this same stage, so the list of files carrying a
        // directive is only true until it runs. See RemoveBladeDirectives.
        if ($this->directivesUsed($project)) {
            $plan->add(Stage::Write, new RemoveBladeDirectives(self::DIRECTIVES));
        }

        // The overrides only ever existed to intercept Flux's own resolution, so
        // they mean nothing once Flux is gone. Emptied at Move, alongside the
        // other structural work, then the directories go with them.
        foreach ($this->overrides($project) as $path) {
            $plan->add(Stage::Move, new DeleteFile(
                $path,
                sprintf('delete %s (a Flux override, with nothing left to override)', $path),
            ));
        }

        foreach ($this->directories($project) as $directory) {
            $plan->add(Stage::Move, new RemoveDirectoryIfEmpty($directory));
        }

        $report->note('Flux is no longer referenced. Run `composer remove livewire/flux` to drop the package itself.');
    }

    /**
     * Whether any view still names one of the directives.
     *
     * A read, not a promise: it decides whether the sweep is worth a line in the
     * plan at all, and the sweep itself works out which files by the time it runs.
     */
    private function directivesUsed(Project $project): bool
    {
        foreach ($project->blades() as $path) {
            $source = $project->get($path);

            foreach (self::DIRECTIVES as $directive) {
                if (str_contains($source, $directive)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every Blade file under `resources/views/flux`.
     *
     * Not just the icons: the kit also overrides `flux/navlist/group`, and any
     * project may have added more. All of it is a Flux extension point, so all of
     * it is dead once Flux is.
     *
     * @return list<string>
     */
    private function overrides(Project $project): array
    {
        $root = $project->path(self::OVERRIDE_ROOT);

        if (! is_dir($root)) {
            return [];
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];

        foreach ($files as $file) {
            if ($file->isFile()) {
                $paths[] = self::OVERRIDE_ROOT.'/'.str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($file->getPathname(), strlen($root) + 1),
                );
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * The override directories, deepest first so each is empty by the time it is
     * asked to go.
     *
     * @return list<string>
     */
    private function directories(Project $project): array
    {
        $directories = [];

        foreach ($this->overrides($project) as $path) {
            $directory = dirname($path);

            while ($directory !== self::OVERRIDE_ROOT && $directory !== '.' && $directory !== '/') {
                $directories[$directory] = true;
                $directory = dirname($directory);
            }
        }

        $paths = array_keys($directories);

        // Longest path first is deepest first, which is the only order in which
        // "remove if empty" can succeed all the way up.
        usort($paths, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $paths[] = self::OVERRIDE_ROOT;

        return $paths;
    }
}
