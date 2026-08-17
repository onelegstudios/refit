<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Point the appearance controls at Sheaf's theme runtime instead of Flux's.
 *
 * Light/dark is the one piece of the kit that lives in JavaScript rather than in
 * a component, so renaming tags does nothing for it. Flux ships an Alpine magic
 * and the kit binds straight to it:
 *
 * ```blade
 * <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
 * ```
 *
 * ```blade
 * :style="($flux.appearance === 'dark' || ($flux.appearance === 'system' && $flux.dark)) ? '…' : ''"
 * ```
 *
 * `sheaf:init --with-dark-mode` writes the same feature a different way, in
 * `resources/js/globals/theme.js`, as a `$theme` magic: `storedTheme` holds the
 * `light | dark | system` preference, `isResolvedToDark` answers what that
 * currently resolves to, and `setTheme()` is the only thing that writes
 * localStorage and puts `.dark` on the document. So the two runtimes line up
 * property for property, and the bindings are all that have to move:
 *
 * - `$flux.appearance` reads as `$theme.storedTheme`;
 * - `$flux.dark` reads as `$theme.isResolvedToDark`, which is the same
 *   "system means ask the OS" resolution Flux's getter does.
 *
 * Writing is the part that is not a rename. `x-model` assigns to whatever it is
 * given, and assigning to `$theme.storedTheme` updates the reactive object
 * without persisting anything or touching the document — the segmented control
 * would move and nothing else would. So the model keeps reading `storedTheme`,
 * for the initial selection, and a `change` listener alongside it puts the click
 * through `setTheme()`. Sheaf's radio group cannot take the getter/setter pair
 * that would be the neater answer: it declares an `x-data` of its own, and a
 * second one passed in is a duplicate attribute the parser drops.
 *
 * Left alone and reported: anything that assigns to `$flux.appearance` by hand,
 * and every other `$flux` magic, because a toast or a modal opened through Flux
 * is a different question with a different Sheaf answer.
 *
 * `@fluxAppearance` is the same feature in a Blade directive, and it is not this
 * sweep's to worry about: it belongs to Flux's teardown, which strips it in the
 * write stage — before anything here reads a file.
 */
final class RebindAppearanceToTheme extends BladeSweep
{
    /**
     * The kit's binding, and what replaces it.
     *
     * Modifiers are carried through — `x-model.live` and friends are Livewire's,
     * not something this rewrite has an opinion about.
     */
    private const string MODEL = '/\bx-model((?:\.[a-z]+)*)\s*=\s*(["\'])\s*\$flux\.appearance\s*\2/';

    /**
     * Reads, which are a straight substitution.
     *
     * The appearance pattern refuses an assignment: `=` not followed by another
     * `=` is a write, and a write has to go through `setTheme()` to mean
     * anything. `===`, `!==` and `==` all fail that test and are read normally.
     */
    private const array READS = [
        '/\$flux\.dark\b/' => '$theme.isResolvedToDark',
        '/\$flux\.appearance\b(?!\s*=(?!=))/' => '$theme.storedTheme',
    ];

    /** Whatever is still Flux's after the substitutions, keyed by path. */
    private const string LEFTOVER = '/\$flux\b(\.[A-Za-z_][A-Za-z0-9_]*)?/';

    /**
     * Files that still name `$flux`, with the magics they name, for one warning
     * at the end rather than one per file.
     *
     * @var array<string, list<string>>
     */
    private array $left = [];

    public function describe(): string
    {
        return 'theme  the appearance controls onto Sheaf\'s $theme runtime';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are Sheaf's, and never named Flux to begin with.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        if (! str_contains($source, '$flux')) {
            return $source;
        }

        $rewritten = (string) preg_replace_callback(
            self::MODEL,
            static fn (array $matches): string => sprintf(
                'x-model%s="$theme.storedTheme" x-on:change="$theme.setTheme($event.target.value)"',
                $matches[1],
            ),
            $source,
        );

        foreach (self::READS as $pattern => $replacement) {
            $rewritten = (string) preg_replace_callback(
                $pattern,
                static fn (): string => $replacement,
                $rewritten,
            );
        }

        $this->record($rewritten, $path);

        return $rewritten;
    }

    protected function finish(Report $report): void
    {
        foreach ($this->left as $path => $magics) {
            $report->warn(sprintf(
                'Left Flux\'s Alpine magic in %s: %s. Sheaf has no `$flux` — the stored appearance is '
                .'`$theme.storedTheme` and writing one is `$theme.setTheme(value)`, and everything else '
                .'`$flux` does has its own Sheaf equivalent.',
                $path,
                implode(', ', $magics),
            ));
        }

        $this->left = [];
    }

    /**
     * Note every `$flux` this rewrite did not have an answer for.
     */
    private function record(string $source, string $path): void
    {
        if (preg_match_all(self::LEFTOVER, $source, $matches) === 0) {
            return;
        }

        $magics = array_values(array_unique($matches[0]));

        sort($magics);

        $this->left[$path] = $magics;
    }
}
