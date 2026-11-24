<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Take the colour off Sheaf's icon, so whatever it sits in decides.
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
 * So the default comes off. Not written to lose — a zero-specificity rule loses
 * to any class the view names, which fixes the ties above, but it still beats
 * plain inheritance, and inheritance is how an icon inside a control is coloured.
 * A button colours its own label and hands its icon nothing but a size, so a
 * `danger` button came out with a red label beside a neutral glyph; the teams
 * kits show it on their own `plus` and `user-plus`, where the accent surface gets
 * the same neutral grey over it. Nothing a caller can write reaches that, because
 * the caller is the button rather than the view.
 *
 * Taking it off leaves both readings right. A class the view names is still in
 * the bag and still applies, and an icon that names nothing draws in the colour
 * around it — which is what it did under Flux, and what every control it sits in
 * is already saying. It also retires an escaping trap: a class added here would
 * have to go on with `merge(..., escape: false)`, since `class()` escapes what it
 * is given and the bag is handed straight to `<x-dynamic-component>`, which
 * escapes it a second time on the way to the icon set's `<svg {{ $attributes }}>`
 * — `[:where(&)]` reaching the browser as `[:where(&amp;)]` and naming no rule
 * Tailwind wrote. {@see SizeNavItemIcons} is still in that trap, one component
 * over, because a size is a class and has to be added rather than removed.
 *
 * The third place refit edits Sheaf's own source, for the reason the other two
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

    /** The bag with no colour in it, which is the bag Flux's icon rendered. */
    private const string INHERITED = '$attributes';

    public function describe(): string
    {
        return 'colour the icons Sheaf paints over whatever they sit in';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return str_replace(self::OPAQUE, self::INHERITED, $source);
    }
}
