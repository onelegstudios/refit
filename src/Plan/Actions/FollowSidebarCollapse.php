<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Point the kit's collapsed-sidebar rules at the state Sheaf actually stamps.
 *
 * Both libraries narrow the desktop sidebar to a column of icons, and both let a
 * view style itself against it — Flux by stamping `data-flux-sidebar-collapsed-
 * desktop` on an ancestor, which the kit reads with an `in-*` variant, Sheaf by
 * stamping `data-collapsed` on the layout.
 *
 * A rename cannot see the difference, because it is written in class names rather
 * than in tags. So the team switcher comes through the migration with four rules
 * keyed on an attribute nothing sets any more, and every one of them is the wrong
 * way round: the `users` glyph the kit put there for exactly this moment stays
 * `hidden`, the team name stays and truncates to nothing, the chevron stays with
 * an `ms-auto` and no room to use it, and the trigger stays full-width and
 * left-aligned. A 64px column of a name it cannot show.
 *
 * The kit had already solved this. Re-keying the variant is most of the fix, and
 * the rest is the box: a trigger that centres itself when collapsed is being
 * emptied down to one glyph, so it takes the square a collapsed navlist item
 * keeps — `p-2` around a `size-5`, which is 36px — instead of running the width
 * of the row. The variant outranks the button's own `w-full` and `ps-*` on
 * specificity, and Sheaf's `h-10` comes from inside a `:where()` and carries none
 * at all.
 *
 * The selector names the sidebar as well as the collapse, the same way the user
 * menu refit writes from a stub does: `data-collapsed` sits on the layout, which
 * the header is under too, and a row that also appears in a header should keep
 * its name at every width.
 */
final class FollowSidebarCollapse extends BladeSweep
{
    /** What Flux stamps on an ancestor, and the kit reads with an `in-*` variant. */
    private const string FLUX = 'in-data-flux-sidebar-collapsed-desktop:';

    /** And what Sheaf stamps instead, asked for by the sidebar it narrows. */
    private const string SHEAF = '[[data-collapsed]_[data-slot=sidebar]_&]:';

    /**
     * The variant, at the start of a class name rather than inside one.
     *
     * `not-in-data-flux-sidebar-collapsed-desktop:` is a different rule with a
     * different answer, and swapping the middle out of it would leave neither.
     * It only reaches the kit's header layout, which refit replaces wholesale.
     */
    private const string BOUNDARY = '/(?<![-\w])'.self::FLUX.'/';

    private const string BUTTON = 'x-ui.button';

    private const string ICON = 'x-ui.icon';

    /** A trigger that centres itself when collapsed has one glyph left to centre. */
    private const string CENTRED = 'justify-center';

    /** The square the navlist items above it keep: 20px of glyph inside 8px of padding. */
    private const array BOX = ['w-auto', 'h-9', 'p-2!'];

    /** A glyph the collapse reveals is a glyph the collapse is the only reason for. */
    private const string REVEALED = 'block';

    /** So it is sized against the nav it stands in, not against the row it left. */
    private const string KIT_GLYPH = 'size-4';

    private const string NAV_GLYPH = 'size-5';

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'follow the collapse Sheaf stamps rather than the one Flux stamped';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/') || ! str_contains($source, self::FLUX)) {
            return $source;
        }

        $source = (string) preg_replace(self::BOUNDARY, self::SHEAF, $source);

        $edits = new Edits;

        // Both passes read the source the re-key produced, so the utility they
        // look for is Sheaf's spelling of it and the offsets are the new ones.
        foreach ($this->parser->parse($source, self::BUTTON) as $tag) {
            if ($tag->name === self::BUTTON && $this->collapsesTo($tag, self::CENTRED)) {
                $this->box($edits, $tag);
            }
        }

        foreach ($this->parser->parse($source, self::ICON) as $tag) {
            if ($tag->name === self::ICON && $this->collapsesTo($tag, self::REVEALED)) {
                $this->grow($edits, $tag);
            }
        }

        return $edits->apply($source);
    }

    /**
     * Does the collapse give this tag the utility named?
     *
     * Read off the literal `class` only. The re-key above reaches a bound
     * `:class` as well, because a class name is a class name wherever it is
     * spelled — but the box is an addition, and adding to a bound class means
     * editing the expression around it rather than a value.
     */
    private function collapsesTo(Tag $tag, string $utility): bool
    {
        if ($tag->has(':class')) {
            return false;
        }

        $class = $tag->attribute('class');

        return $class instanceof Attribute
            && ! $class->isBoolean()
            && preg_match('/(?<![-\w])'.preg_quote(self::SHEAF.$utility, '/').'(?![-\w])/', $class->value ?? '') === 1;
    }

    /**
     * Give a collapsed trigger the box a collapsed navlist item stands in.
     */
    private function box(Edits $edits, Tag $tag): void
    {
        $class = $tag->attribute('class');

        if (! $class instanceof Attribute) {
            return;
        }

        $classes = $class->value ?? '';

        $adding = array_values(array_filter(
            self::BOX,
            fn (string $utility): bool => ! str_contains($classes, self::SHEAF.$utility),
        ));

        if ($adding === []) {
            return;
        }

        $edits->replace(
            $class->valueOffset() + $class->valueLength(),
            0,
            ($classes === '' ? '' : ' ').implode(' ', array_map(
                static fn (string $utility): string => self::SHEAF.$utility,
                $adding,
            )),
        );
    }

    /**
     * Size a revealed glyph like the nav glyphs it lines up under.
     *
     * The kit drew it at 16px inside a row that had a name beside it; on its own
     * in the column it stands in for a 20px navlist icon. It is hidden at every
     * other width, so there is no second size to keep.
     */
    private function grow(Edits $edits, Tag $tag): void
    {
        $class = $tag->attribute('class');

        if (! $class instanceof Attribute) {
            return;
        }

        $classes = $class->value ?? '';

        if (preg_match('/(?<![-\w])'.self::KIT_GLYPH.'(?![-\w])/', $classes, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }

        $edits->replace(
            $class->valueOffset() + $match[0][1],
            strlen(self::KIT_GLYPH),
            self::NAV_GLYPH,
        );
    }
}
