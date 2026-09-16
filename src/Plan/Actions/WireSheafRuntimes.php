<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Wire up the runtimes Sheaf's components ship but never ask for.
 *
 * A handful of Sheaf components are half Blade and half JavaScript. The Blade
 * half is what `sheaf:install` copies into `resources/views/components/ui`; the
 * other half lands in `resources/js`, in one of two directories. A file in
 * `globals` registers an Alpine magic on `alpine:init` — `$theme` from
 * `theme.js`, `$modal` from `modals.js`. A file in `components` registers the
 * `Alpine.data()` a component's own markup names in its `x-data`.
 *
 * Only `sheaf:init` adds an import, and only for its own `theme.js`. Everything a
 * later `sheaf:install` drops in is written to disk and left out of the module
 * graph, so it never runs and what it registers never exists.
 *
 * Nothing about that failure is visible. The components render, Vite is happy —
 * and then the browser hits an expression naming something undefined, throws
 * inside Alpine's handler, and stops there. For a global that expression is a
 * click: `$modal.open(...)` on "Enable 2FA" and "Delete account", buttons that do
 * nothing and do it silently. For a component runtime it is the `x-data` itself:
 * `selectComponent is not defined` before the page has finished painting, which
 * leaves every `<x-ui.select>` a box that will not open. In the teams kit that is
 * the invite modal's Role, so a team can be invited to no role at all.
 *
 * So refit imports the directories rather than a list. A file in either one is by
 * definition something the page wants loaded once, whatever it happens to
 * register — and naming today's files here would only mean the next component
 * with a runtime arrives just as quietly broken.
 *
 * The select needs one thing more. Its runtime drives the option list through
 * `$rover`, an Alpine plugin published separately as `@sheaf/rover` and installed
 * by nothing: the component's own manifest declares no external dependency, and
 * only Sheaf's documentation for the primitive mentions it. Importing the runtime
 * without registering the plugin swaps one silent failure for another, so refit
 * registers it here — and, when the package is not installed, says so rather than
 * writing an import Vite cannot resolve.
 */
final class WireSheafRuntimes implements Action
{
    /** The entrypoint Vite builds, and the only place these belong. */
    public const string ENTRYPOINT = 'resources/js/app.js';

    /** Where `sheaf:install` puts a component's Alpine magic. */
    public const string GLOBALS = 'resources/js/globals';

    /** Where it puts a component's `Alpine.data()`. */
    public const string RUNTIMES = 'resources/js/components';

    /** The npm package holding the primitive Sheaf's select is built on. */
    public const string PRIMITIVE = '@sheaf/rover';

    /** What a runtime that needs the primitive reaches for. */
    private const string PRIMITIVE_MAGIC = 'rover';

    public function describe(): string
    {
        return sprintf('edit   %s — import Sheaf\'s Alpine runtimes', self::ENTRYPOINT);
    }

    public function apply(Project $project, Report $report): void
    {
        if (! $project->exists(self::ENTRYPOINT)) {
            $report->warn('Skipped importing Sheaf\'s runtimes — '.self::ENTRYPOINT.' does not exist.');

            return;
        }

        $runtimes = [
            ...$this->runtimes($project, self::RUNTIMES),
            ...$this->runtimes($project, self::GLOBALS),
        ];

        if ($runtimes === []) {
            // Neither directory means no component with a runtime was installed.
            // Nothing missing, so nothing to say.
            return;
        }

        $source = $project->get(self::ENTRYPOINT);

        $missing = array_values(array_filter(
            $runtimes,
            fn (string $runtime): bool => ! $this->imported($source, $runtime),
        ));

        $primitive = $this->needsPrimitive($project, $runtimes)
            && ! str_contains($source, self::PRIMITIVE);

        if ($primitive && ! self::primitiveInstalled($project)) {
            $report->warn(sprintf(
                'Sheaf\'s select is driven by the `$%s` Alpine plugin, and %s is not installed. '
                .'Run `npm install %s`, then add `import %s from \'%s\'` and `Alpine.plugin(%s)` to %s — '
                .'without it the select renders and never opens.',
                self::PRIMITIVE_MAGIC,
                self::PRIMITIVE,
                self::PRIMITIVE,
                self::PRIMITIVE_MAGIC,
                self::PRIMITIVE,
                self::PRIMITIVE_MAGIC,
                self::ENTRYPOINT,
            ));

            $primitive = false;
        }

        if ($missing === [] && ! $primitive) {
            return;
        }

        file_put_contents(
            $project->path(self::ENTRYPOINT),
            $this->rewrite($source, $missing, $primitive),
        );

        $report->changed(self::ENTRYPOINT);

        if ($missing !== []) {
            $report->note(sprintf(
                'Imported %s from %s. Sheaf installs a component\'s Alpine runtime into %s or %s but only '
                .'wires up the one `sheaf:init` writes, so the rest register nothing and everything that '
                .'reads them — a modal trigger and a select, most of all — renders and then does nothing.',
                implode(', ', $missing),
                self::ENTRYPOINT,
                self::RUNTIMES,
                self::GLOBALS,
            ));
        }

        if ($primitive) {
            $report->note(sprintf(
                'Registered the `%s` Alpine plugin in %s. Sheaf\'s select runtime drives its option list '
                .'through `$%s`, and nothing in an install puts that plugin in the page.',
                self::PRIMITIVE,
                self::ENTRYPOINT,
                self::PRIMITIVE_MAGIC,
            ));
        }
    }

    /**
     * Is the primitive a runtime needs on hand to be imported at all?
     *
     * Read from `package.json` rather than `node_modules`, because the install
     * refit plans for it records the dependency there and a fresh checkout has no
     * modules directory to look in.
     */
    public static function primitiveInstalled(Project $project): bool
    {
        if (! $project->exists('package.json')) {
            return false;
        }

        /** @var array{dependencies?: array<string, string>, devDependencies?: array<string, string>} $manifest */
        $manifest = json_decode($project->get('package.json'), true) ?: [];

        return isset($manifest['dependencies'][self::PRIMITIVE])
            || isset($manifest['devDependencies'][self::PRIMITIVE]);
    }

    /**
     * The entrypoint, with the imports it is missing and the plugin it never
     * registered put in ahead of whatever else it does.
     *
     * Ahead, because a magic has to be registered before the markup that reads it
     * is evaluated, and because an entrypoint that starts Livewire itself starts
     * it on the last line — after which registering a plugin is too late.
     *
     * @param  list<string>  $missing
     */
    private function rewrite(string $source, array $missing, bool $primitive): string
    {
        $lines = array_map(
            static fn (string $runtime): string => sprintf("import './%s';", $runtime),
            $missing,
        );

        if ($primitive) {
            // First of the imports, because the runtime reading it is one of the
            // others and Sheaf asks for the primitive to come before them.
            array_unshift($lines, sprintf("import %s from '%s';", self::PRIMITIVE_MAGIC, self::PRIMITIVE));

            $lines[] = '';
            $lines[] = sprintf('Alpine.plugin(%s);', self::PRIMITIVE_MAGIC);
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines).PHP_EOL.$source;
    }

    /**
     * Does anything that just arrived reach for the primitive?
     *
     * Asked of the files rather than of a list of component names, for the same
     * reason the directories are swept: the next runtime built on `$rover` should
     * arrive working.
     *
     * @param  list<string>  $runtimes
     */
    private function needsPrimitive(Project $project, array $runtimes): bool
    {
        foreach ($runtimes as $runtime) {
            if (str_contains($project->get('resources/js/'.$runtime), '$'.self::PRIMITIVE_MAGIC)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `.js` file in a runtime directory, relative to `resources/js` and in
     * a stable order.
     *
     * @return list<string>
     */
    private function runtimes(Project $project, string $directory): array
    {
        if (! $project->exists($directory)) {
            return [];
        }

        $found = glob($project->path($directory).'/*.js');

        if ($found === false) {
            return [];
        }

        $prefix = basename($directory);

        $files = array_map(
            static fn (string $path): string => $prefix.'/'.basename($path),
            $found,
        );

        sort($files);

        return $files;
    }

    /**
     * Is this runtime already reaching the bundle?
     *
     * Matched on the path tail rather than a whole line, because `sheaf:init`
     * writes its own import with a trailing comment and an absolute-ish path, and
     * a user may well have moved or rewritten it since.
     */
    private function imported(string $source, string $runtime): bool
    {
        $pattern = sprintf(
            '#[\'"][^\'"]*%s[\'"]#',
            preg_quote($runtime, '#'),
        );

        return preg_match($pattern, $source) === 1;
    }
}
