<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Give everything in a Sheaf dropdown a place in the panel's grid.
 *
 * Flux renders a menu as a stack of blocks, so the kit drops whatever it likes
 * into one: the user menu opens with a plain div of avatar, name and email, and
 * wraps its log out item in a `<form>`.
 *
 * Sheaf renders the panel as `grid grid-cols-[auto_1fr_auto]`, and every part it
 * ships declares where it sits — an item is `col-span-2`, a separator is
 * `col-span-full`, a group is `contents`. Anything else lands in the first
 * column, one narrow strip of a three-column grid, and takes its contents with
 * it. The log out row is the clearest tell: its item asks for `col-span-2`, but
 * the form around it is the actual grid child, so the item's span applies to
 * nothing and the row sits half the width of the one above it.
 *
 * Each direct child of a menu is therefore placed:
 *
 * - a wrapper — something with a dropdown part inside it, like that form — gets
 *   `contents`, so the part it wraps becomes the grid child and lines up with
 *   its neighbours. This is what Sheaf's own `dropdown.group` does;
 * - anything else gets `col-span-full`, which is the full width Flux gave it;
 * - a component, whose outermost element is rendered by Sheaf rather than
 *   written here, is wrapped in a div that spans instead — no class on the tag
 *   would reach the element that ends up in the grid.
 *
 * Children that already say where they sit are left alone, so a run over an
 * already-placed menu changes nothing.
 */
final class PlaceDropdownChildren extends BladeSweep
{
    /** Sheaf's own menu parts, which place themselves. */
    private const string PART = 'x-ui.dropdown.';

    private const string SLOT = 'x-slot';

    private const string MENU = 'menu';

    /**
     * What a menu can hold: the elements the kit writes into one, and every
     * Sheaf component, since a component needs wrapping rather than a class.
     *
     * @var list<string>
     */
    private const array HOLDS = ['div', 'form', 'span', 'x-ui.'];

    /** The classes that mean "this child already knows where it sits". */
    private const array PLACED = ['contents', 'col-span-full'];

    private const string SPAN = 'col-span-full';

    private const string PASS_THROUGH = 'contents';

    public function __construct(
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return 'place  dropdown menu contents in Sheaf\'s grid';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $menus = $this->menus($source);

        if ($menus === []) {
            return $source;
        }

        $elements = $this->nesting->elements($source, self::HOLDS);
        $edits = new Edits;

        foreach ($menus as $menu) {
            foreach ($this->children($elements, $menu) as $child) {
                $this->place($edits, $source, $child);
            }
        }

        return $edits->apply($source);
    }

    /**
     * The span of each `<x-slot:menu>` in the source.
     *
     * @return list<array{start: int, end: int}>
     */
    private function menus(string $source): array
    {
        $menus = [];

        foreach ($this->parser->parse($source, self::SLOT) as $slot) {
            if ($slot->selfClosing || ! $this->isMenu($slot->name, $slot->attribute('name'))) {
                continue;
            }

            $start = $slot->offset + $slot->length;
            $closes = strpos($source, '</'.self::SLOT, $start);

            if ($closes === false) {
                continue;
            }

            $menus[] = ['start' => $start, 'end' => $closes];
        }

        return $menus;
    }

    /**
     * Blade spells a named slot two ways, and the kit uses both.
     */
    private function isMenu(string $tag, ?Attribute $name): bool
    {
        return $tag === self::SLOT.':'.self::MENU
            || ($tag === self::SLOT && $name instanceof Attribute && $name->value === self::MENU);
    }

    /**
     * The elements written directly in one menu, rather than inside each other.
     *
     * @param  list<Element>  $elements
     * @param  array{start: int, end: int}  $menu
     * @return list<Element>
     */
    private function children(array $elements, array $menu): array
    {
        $inside = array_values(array_filter(
            $elements,
            static fn (Element $element): bool => $element->open->offset >= $menu['start']
                && $element->open->offset < $menu['end'],
        ));

        return array_values(array_filter(
            $inside,
            static function (Element $element) use ($inside): bool {
                foreach ($inside as $other) {
                    if ($other->encloses($element)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Put one child where the grid can see it.
     */
    private function place(Edits $edits, string $source, Element $child): void
    {
        // Sheaf's own parts carry their span already.
        if (str_starts_with($child->name(), self::PART)) {
            return;
        }

        if ($child->open->has(':class') || $this->isPlaced($child)) {
            return;
        }

        // A component's outermost element belongs to Sheaf, so a class on the tag
        // lands somewhere inside the grid child rather than on it.
        if (str_starts_with($child->name(), 'x-')) {
            $this->wrap($edits, $source, $child);

            return;
        }

        $this->addClass(
            $edits,
            $child,
            $this->wraps($source, $child) ? self::PASS_THROUGH : self::SPAN,
        );
    }

    /**
     * Does this child exist only to hold a menu part, the way the log out form
     * holds its item?
     */
    private function wraps(string $source, Element $child): bool
    {
        return str_contains(
            substr($source, $child->contentStart(), $child->closes - $child->contentStart()),
            '<'.self::PART,
        );
    }

    private function isPlaced(Element $child): bool
    {
        $class = $child->open->attribute('class');

        if (! $class instanceof Attribute) {
            return false;
        }

        foreach (preg_split('/\s+/', trim($class->value ?? '')) ?: [] as $utility) {
            if (in_array(rtrim($utility, '!'), self::PLACED, true) || str_starts_with($utility, 'col-span-')) {
                return true;
            }
        }

        return false;
    }

    private function addClass(Edits $edits, Element $child, string $class): void
    {
        $existing = $child->open->attribute('class');

        if (! $existing instanceof Attribute) {
            $edits->replace(
                $child->open->nameOffset() + strlen($child->name()),
                0,
                sprintf(' class="%s"', $class),
            );

            return;
        }

        $value = $existing->value ?? '';

        $edits->replace(
            $existing->valueOffset() + $existing->valueLength(),
            0,
            ($value === '' ? '' : ' ').$class,
        );
    }

    /**
     * Put a spanning div around a child that cannot carry the span itself.
     */
    private function wrap(Edits $edits, string $source, Element $child): void
    {
        $closes = strpos($source, '>', $child->closes);

        if ($closes === false) {
            return;
        }

        $indent = $this->indent($source, $child->open->offset);

        // The wrapped markup keeps the indentation it had, rather than being
        // re-flowed around a div that only exists for the grid.
        $edits->replace(
            $child->open->offset,
            0,
            sprintf('<div class="%s">%s', self::SPAN, "\n".$indent),
        );

        $edits->replace($closes + 1, 0, "\n".$indent.'</div>');
    }
}
