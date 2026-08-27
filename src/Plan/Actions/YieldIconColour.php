<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Let a caller's own colour beat the one Sheaf's icon paints over it.
 *
 * Flux's icon had no colour of its own: it drew in `currentColor` and the view
 * decided, either by putting a class on the tag or by letting the glyph inherit
 * whatever the surrounding text was. Sheaf's `x-ui.icon` colours itself, and it
 * does so at full specificity:
 *
 *     {{ $attributes->class(['text-neutral-700 dark:text-neutral-300']) }}
 *
 * `class()` is `merge()`, and `merge()` puts the default in *front* of what the
 * caller passed — but the order of names inside a `class` attribute decides
 * nothing. What decides is the order Tailwind emits the rules in, and inside one
 * variant it sorts them by name: `.dark\:text-accent-foreground` is written out
 * before `.dark\:text-neutral-300`, so of two classes at the same specificity the
 * component's is the one that wins.
 *
 * The two-factor setup modal is where the kit shows it. Its QR glyph sits on a
 * disc that is light in both appearances — `bg-stone-100 dark:bg-stone-200` — and
 * asks for `dark:text-accent-foreground` to stay dark on it. Sheaf answers with
 * `neutral-300`, and the icon goes white on white. Phosphor is where anyone
 * notices, because a filled QR block disappears completely; the Heroicon draws
 * the same colour as 1.5px strokes and merely looks faint. The same tie decides
 * the alert's `text-blue-600` and the copy button's `text-green-500` against
 * `text-neutral-700`, and both lose.
 *
 * So the default is rewritten to lose on purpose. `[:where(&)]:` compiles to a
 * zero-specificity selector, which is how Sheaf's own nav items let a caller
 * resize their icon, and a zero-specificity rule loses to any class the view
 * writes whatever the order — while still beating plain inheritance, so an icon
 * that asks for nothing is coloured exactly as it was.
 *
 * It has to be added with `merge(..., escape: false)`. `class()` escapes what it
 * is given, and this bag is handed straight to `<x-dynamic-component>`, which
 * escapes it a second time on the way to the icon set's `<svg {{ $attributes }}>`
 * — the class lands as `[:where(&amp;)]:text-neutral-700` and names no rule
 * Tailwind wrote. {@see SizeNavItemIcons} is the same trap, one component over.
 *
 * The fourth place refit edits Sheaf's own source, for the reason the other three
 * give: `sheaf:install` copies these files into the project, which is what makes
 * them the project's to fix, and no caller can reach a class the component writes
 * for itself. It matches on the call rather than on a line, so a Sheaf that has
 * fixed this upstream — or coloured its icons some other way — has nothing here
 * to change.
 */
final class YieldIconColour extends BladeSweep
{
    /** How Sheaf colours its icon, at a specificity no caller can beat. */
    private const string OPAQUE = "\$attributes->class(['text-neutral-700 dark:text-neutral-300'])";

    /** The same colour, written to lose to anything the view says instead. */
    private const string YIELDING = "\$attributes->merge(['class' => '[:where(&)]:text-neutral-700 dark:[:where(&)]:text-neutral-300'], escape: false)";

    public function describe(): string
    {
        return 'colour the icons Sheaf paints over the view\'s own class';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return str_replace(self::OPAQUE, self::YIELDING, $source);
    }
}
