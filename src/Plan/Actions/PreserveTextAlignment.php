<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Restate the alignment Sheaf's text components stop inheriting.
 *
 * The kit centres text the CSS way: `text-center` goes on a wrapping div and
 * everything inside inherits it. `<x-auth-header>` is the one every user sees —
 * a `flex w-full flex-col text-center` div around a heading and a subheading —
 * and the two-factor setup modal and the empty passkey list do the same thing.
 *
 * Sheaf's components declare their own `text-align`, so inheritance stops
 * carrying that the moment the tags are renamed. `<x-ui.heading>` writes
 * `text-start` into its own class list and `<x-ui.text>` writes a
 * `[:where(&)]:text-start` default; either way the element sets the property
 * itself, and a declared value beats an inherited one however faint it is. Left
 * alone, every auth page in the kit comes out left-aligned.
 *
 * So the alignment is restated on the tag itself, with `!` — Tailwind emits
 * `.text-start` after `.text-center`, so a plain `text-center` on the tag would
 * be a specificity tie that the heading's own class wins.
 *
 * Only divs are read for the enclosing alignment, and only alignments that
 * differ from Sheaf's own `text-start` default are restated. That covers every
 * case the kit has without the sweep having to model CSS inheritance in general.
 */
final class PreserveTextAlignment extends BladeSweep
{
    /** The Sheaf components that declare a `text-align` of their own. */
    private const array TARGETS = ['x-ui.heading', 'x-ui.text'];

    /** How the kit wraps centred text, in every case it has. */
    private const string DIV = 'div';

    /** Every alignment utility, so the nearest wrapper's answer is the one read. */
    private const array ALIGNMENTS = [
        'text-left',
        'text-center',
        'text-right',
        'text-justify',
        'text-start',
        'text-end',
    ];

    /**
     * The alignments worth restating.
     *
     * `text-start` and `text-left` are what a Sheaf component already does, so a
     * wrapper asking for either changes nothing and earns no edit.
     *
     * @var list<string>
     */
    private const array RESTATED = ['text-center', 'text-right', 'text-justify', 'text-end'];

    public function __construct(
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return 'align  the text Sheaf\'s own classes would stop centring';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are Sheaf's to align. This sweep is about the
        // application views that render them.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $wrappers = $this->wrappers($source);

        if ($wrappers === []) {
            return $source;
        }

        $edits = new Edits;

        foreach (self::TARGETS as $name) {
            foreach ($this->parser->parse($source, $name) as $tag) {
                if ($tag->name !== $name) {
                    continue;
                }

                $alignment = $this->inherited($tag, $wrappers);

                if ($alignment === null) {
                    continue;
                }

                $this->restate($edits, $tag, $alignment);
            }
        }

        return $edits->apply($source);
    }

    /**
     * Put the alignment on the tag, in whichever way its class attribute allows.
     *
     * A tag that already says something about alignment is left alone, and so is
     * one whose classes are bound — a `:class` expression is a decision the
     * application made, and appending to it would mean editing PHP.
     */
    private function restate(Edits $edits, Tag $tag, string $alignment): void
    {
        if ($tag->has(':class')) {
            return;
        }

        $class = $tag->attribute('class');

        if (! $class instanceof Attribute) {
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' class="%s!"', $alignment),
            );

            return;
        }

        $classes = $class->value ?? '';

        if ($this->declaresAlignment($classes)) {
            return;
        }

        $edits->replace(
            $class->valueOffset() + $class->valueLength(),
            0,
            ($classes === '' ? '' : ' ').$alignment.'!',
        );
    }

    /**
     * The alignment a tag used to inherit, when Sheaf will no longer honour it.
     *
     * The nearest enclosing wrapper wins, which is what inheritance does: an
     * inner `text-left` inside an outer `text-center` leaves the tag aligned the
     * way Sheaf already aligns it, and nothing is restated.
     *
     * @param  list<array{element: Element, alignment: string}>  $wrappers
     */
    private function inherited(Tag $tag, array $wrappers): ?string
    {
        $nearest = null;

        foreach ($wrappers as $wrapper) {
            if (! $wrapper['element']->holds($tag->offset)) {
                continue;
            }

            if ($nearest === null || $wrapper['element']->contentStart() > $nearest['element']->contentStart()) {
                $nearest = $wrapper;
            }
        }

        if ($nearest === null || ! in_array($nearest['alignment'], self::RESTATED, true)) {
            return null;
        }

        return $nearest['alignment'];
    }

    /**
     * Every div that sets an alignment, with the alignment it sets.
     *
     * @return list<array{element: Element, alignment: string}>
     */
    private function wrappers(string $source): array
    {
        $wrappers = [];

        foreach ($this->nesting->elements($source, [self::DIV]) as $element) {
            $class = $element->open->attribute('class');
            $alignment = $class instanceof Attribute ? $this->alignmentIn($class->value ?? '') : null;

            if ($alignment !== null) {
                $wrappers[] = ['element' => $element, 'alignment' => $alignment];
            }
        }

        return $wrappers;
    }

    /**
     * The alignment a class list sets unconditionally.
     *
     * Variant-prefixed utilities are not alignment a tag can copy — `md:text-center`
     * is centred at one breakpoint and inherited at the rest — so a wrapper that
     * only says that is read as saying nothing.
     */
    private function alignmentIn(string $classes): ?string
    {
        foreach (preg_split('/\s+/', trim($classes)) ?: [] as $class) {
            if (in_array($class, self::ALIGNMENTS, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Does this class list already have an opinion about alignment?
     *
     * Looser than {@see alignmentIn()} on purpose: a variant, an `!`, or a
     * `dark:` prefix all mean the view has said something, and restating over the
     * top of it would be a rewrite nobody asked for.
     */
    private function declaresAlignment(string $classes): bool
    {
        foreach (preg_split('/\s+/', trim($classes)) ?: [] as $class) {
            $utility = trim((string) strrchr(':'.$class, ':'), ':!');

            if (in_array($utility, self::ALIGNMENTS, true)) {
                return true;
            }
        }

        return false;
    }
}
