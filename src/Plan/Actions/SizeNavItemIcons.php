<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Give a nav item's icon back the size a second escape takes off it.
 *
 * Sheaf's `navlist.item` and `navbar.item` size their icon with a variant written
 * to lose: `[:where(&)]:size-5` compiles to a zero-specificity rule, so a caller's
 * own `icon:class="size-4"` beats it without needing `!`. Both components add it
 * the same way, and it is the way that breaks it:
 *
 *     :attributes="$iconAttributes->class('[:where(&)]:size-5')"
 *
 * `class()` is `merge()`, and `merge()` HTML-escapes what it is given — so the bag
 * carries `[:where(&amp;)]:size-5` from here on. That is correct exactly once, and
 * this class is written into the DOM twice: `x-ui.icon` takes the bag in through
 * `:attributes` and hands it straight back out through `<x-dynamic-component>`,
 * which is a component tag, so the compiler escapes the value again on the way to
 * the icon set's `<svg {{ $attributes }}>`. The browser undoes one of the two and
 * the class lands as `[:where(&amp;)]:size-5`, which names no rule Tailwind wrote.
 *
 * Nothing about that is visible while the icons are Heroicons. `wireui/heroicons`
 * draws every glyph with `width="24" height="24"` on the `<svg>` itself, so the
 * dead class costs nothing and the icon is 24px because the artwork said so.
 * `wireui/phosphoricons` ships a `viewBox` and no dimensions, leaving the size
 * entirely to the class that is no longer there — and an `<svg>` with auto width
 * inside the item's own flex row lays out at zero. So a Phosphor sidebar renders
 * its labels, keeps their gutter, and draws no icons at all.
 *
 * `merge()` takes an `$escape` argument and appends a class the same way, so the
 * fix is to add the variant without the escape that is one too many. The
 * `:where()` survives intact, which is the point of it: `icon:class` goes on
 * winning.
 *
 * One of the four places refit edits Sheaf's own source rather than the kit's,
 * for the reason the other three give: `sheaf:install` copies these files into the
 * project, which is what makes them the project's to fix, and no caller can reach
 * a class the component writes for itself. It matches on the call rather than on a
 * line, so a Sheaf that has fixed this upstream — or sized its icons some other
 * way — has nothing here to change.
 */
final class SizeNavItemIcons extends BladeSweep
{
    /** How Sheaf adds the size, and the `class()` that escapes it a first time. */
    private const string ESCAPED = "\$iconAttributes->class('[:where(&)]:size-5')";

    /** The same class, added without the escape the dynamic component will apply. */
    private const string RAW = "\$iconAttributes->merge(['class' => '[:where(&)]:size-5'], escape: false)";

    public function describe(): string
    {
        return 'size   the nav item icons Sheaf escapes out of existence';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return str_replace(self::ESCAPED, self::RAW, $source);
    }
}
