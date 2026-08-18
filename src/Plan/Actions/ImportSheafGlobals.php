<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Import the runtimes Sheaf's components ship but never ask for.
 *
 * A handful of Sheaf components are half Blade and half JavaScript. The Blade
 * half is what `sheaf:install` copies into `resources/views/components/ui`; the
 * other half lands in `resources/js/globals`, where each file registers an Alpine
 * magic on `alpine:init` — `$theme` from `theme.js`, `$modal` from `modals.js`.
 *
 * Only `sheaf:init` adds an import, and only for its own `theme.js`. Every global
 * a later `sheaf:install` drops in is written to disk and left out of the module
 * graph, so it never runs and the magic it registers never exists.
 *
 * Nothing about that failure is visible. The component renders, Vite is happy —
 * `$modal.open(...)` is only evaluated when someone clicks, and an undefined
 * magic there throws inside Alpine's handler, where it stops. The button does
 * nothing, and does it silently: in the kit, that is "Enable 2FA" and
 * "Delete account".
 *
 * So refit imports the directory rather than a list. A global is by definition
 * something the page wants loaded once, whatever it happens to register — and
 * naming `modals.js` here would only mean the next component with a runtime
 * arrives just as quietly broken.
 *
 * `resources/js/components` is deliberately not swept alongside it. Those files
 * call `Alpine.data(...)` at module scope against a global that Livewire has not
 * defined yet, so importing them from the entrypoint is not the missing line —
 * it is a ReferenceError before the page has painted.
 */
final class ImportSheafGlobals implements Action
{
    /** The entrypoint Vite builds, and the only place these belong. */
    public const string ENTRYPOINT = 'resources/js/app.js';

    /** Where `sheaf:install` puts a component's runtime half. */
    public const string GLOBALS = 'resources/js/globals';

    public function describe(): string
    {
        return sprintf('edit   %s — import Sheaf\'s Alpine globals', self::ENTRYPOINT);
    }

    public function apply(Project $project, Report $report): void
    {
        if (! $project->exists(self::ENTRYPOINT)) {
            $report->warn('Skipped importing Sheaf\'s globals — '.self::ENTRYPOINT.' does not exist.');

            return;
        }

        $source = $project->exists(self::GLOBALS) ? $project->get(self::ENTRYPOINT) : null;

        if ($source === null) {
            // No globals directory means no component with a runtime was
            // installed. Nothing missing, so nothing to say.
            return;
        }

        $missing = [];

        foreach ($this->globals($project) as $file) {
            if ($this->imported($source, $file)) {
                continue;
            }

            $missing[] = $file;
        }

        if ($missing === []) {
            return;
        }

        $lines = array_map(
            static fn (string $file): string => sprintf("import './globals/%s';", $file),
            $missing,
        );

        // Ahead of whatever else the entrypoint does, because a magic has to be
        // registered before the markup that reads it is evaluated.
        $rewritten = implode(PHP_EOL, $lines).PHP_EOL.$source;

        file_put_contents($project->path(self::ENTRYPOINT), $rewritten);

        $report->changed(self::ENTRYPOINT);
        $report->note(sprintf(
            'Imported %s from %s. Sheaf installs a component\'s Alpine runtime into %s but only '
            .'wires up the one `sheaf:init` writes, so the rest register nothing and every control '
            .'that reads them — a modal trigger, most of all — silently does nothing when clicked.',
            implode(', ', $missing),
            self::ENTRYPOINT,
            self::GLOBALS,
        ));
    }

    /**
     * Is this global already reaching the bundle?
     *
     * Matched on the path tail rather than a whole line, because `sheaf:init`
     * writes its own import with a trailing comment and an absolute-ish path, and
     * a user may well have moved or rewritten it since.
     */
    private function imported(string $source, string $file): bool
    {
        $pattern = sprintf(
            '#[\'"][^\'"]*globals/%s[\'"]#',
            preg_quote($file, '#'),
        );

        return preg_match($pattern, $source) === 1;
    }

    /**
     * Every `.js` file in the globals directory, in a stable order.
     *
     * @return list<string>
     */
    private function globals(Project $project): array
    {
        $found = glob($project->path(self::GLOBALS).'/*.js');

        if ($found === false) {
            return [];
        }

        $files = array_map(static fn (string $path): string => basename($path), $found);

        sort($files);

        return $files;
    }
}
