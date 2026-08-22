<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Give a button's contents back the row Flux laid them out in.
 *
 * Both libraries build a button as a flex row, and the difference is one box.
 * Flux's children are the flex items, so an icon sits beside the text and an
 * `ms-auto` on the last of them pushes it to the far edge. Sheaf wraps the whole
 * slot in a `<span data-text>` first, and that span is the only flex item there
 * is — inside it the children are back in ordinary block flow.
 *
 * Which is fine for a word, and wrong for anything else, because Tailwind's
 * preflight sets `svg { display: block }`. The team switcher is where the kit
 * shows it: a `users` icon, the team name and a chevron come out as three
 * stacked lines, and the chevron's `ms-auto` — a margin against free space that
 * a block box does not have — leaves it under the name rather than opposite it.
 *
 * So the wrapper is taken out of the layout with `display: contents` and the
 * children are the button's flex items again, laid out by the button's own
 * `items-center`, `gap-x-2` and `justify-*` exactly as Flux laid them out. That
 * is one utility, on the tag the view already writes, rather than a wrapper this
 * action would have to invent and the reader would have to maintain.
 *
 * The second utility pays for the first. Sheaf dims a loading button's contents
 * with `[&>[data-loading=true]:first-child~*]:opacity-0`, which names that same
 * span — and opacity on a box that has stopped generating one does nothing, so
 * the label would sit at full strength under the spinner. Restating it one level
 * down, over the children that now generate the boxes, keeps the loading state
 * the component ships.
 *
 * Only buttons whose slot holds markup are touched: text alone lays out the same
 * either way, and a button that has been through this already carries the class
 * that says so.
 */
final class RestoreButtonRow extends BladeSweep
{
    private const string TARGET = 'x-ui.button';

    /** Take the slot wrapper out of the layout, and dim what it now wraps. */
    private const array ROW = [
        '[&>[data-text]]:contents',
        '[&>[data-loading=true]:first-child~[data-text]>*]:opacity-0',
    ];

    /** Slot contents holding an element of any kind — a tag, not an echo. */
    private const string MARKUP = '/<[a-zA-Z]/';

    public function __construct(private readonly Nesting $nesting = new Nesting) {}

    public function describe(): string
    {
        return 'row    the button contents Sheaf stacks down the page';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own button is where the wrapper is written, and the place to
        // read it rather than to work around it.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->nesting->elements($source, [self::TARGET]) as $element) {
            if ($element->name() !== self::TARGET || ! $this->holdsMarkup($source, $element)) {
                continue;
            }

            $this->row($edits, $element->open);
        }

        return $edits->apply($source);
    }

    /**
     * Is there anything in this button that block flow would stack?
     */
    private function holdsMarkup(string $source, Element $element): bool
    {
        $start = $element->contentStart();

        return preg_match(self::MARKUP, substr($source, $start, $element->closes - $start)) === 1;
    }

    /**
     * Add the row to one button, in whichever way its class attribute allows.
     *
     * A bound `:class` is a decision the application made in PHP, and appending
     * to it would mean editing an expression.
     */
    private function row(Edits $edits, Tag $tag): void
    {
        if ($tag->has(':class')) {
            return;
        }

        $class = $tag->attribute('class');
        $row = implode(' ', self::ROW);

        if (! $class instanceof Attribute) {
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' class="%s"', $row),
            );

            return;
        }

        // A bare `class` with no value at all is not markup refit wrote and not
        // markup it can append to.
        if ($class->isBoolean()) {
            return;
        }

        $classes = $class->value ?? '';

        if (str_contains($classes, self::ROW[0])) {
            return;
        }

        $edits->replace(
            $class->valueOffset() + $class->valueLength(),
            0,
            ($classes === '' ? '' : ' ').$row,
        );
    }
}
