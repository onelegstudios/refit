<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Say where a dropdown opens in the one word Sheaf reads it from.
 *
 * Flux takes a placement as two attributes and joins them itself, with a space,
 * on the way to its custom element:
 *
 *     <ui-dropdown position="{{ $position }} {{ $align }}" …>
 *
 * Sheaf takes one value and hands it straight to Alpine Anchor as a modifier —
 * `x-anchor.{{ $position }}.offset.…` — so the two attributes have to become one
 * before the rename ever sees them. Nothing else in the migration merges
 * attributes, which is why this is an action rather than a `ComponentMap` entry:
 * `VALUES` rewrites a value in place and cannot consume a second attribute.
 *
 * Left alone, `position="bottom"` survives as a placement that is valid and
 * centred, and `align` — a prop Sheaf never declares — falls out of
 * `{{ $attributes }}` onto the panel wrapper as a stray, long-deprecated HTML
 * `align`. Every menu in the kit writes both attributes, so every menu opened
 * centred on its trigger: the member-role picker in the team members table is
 * the one that shows it, a right-aligned trigger with a panel hanging under its
 * middle.
 *
 * Runs ahead of the rename, with the rest of the passes that read Flux's own
 * arrangement, which is also what makes the defaults Flux's: a tag that writes
 * only one of the two is still a Flux tag at this point, so the missing half is
 * `position="bottom"` or `align="start"` rather than anything Sheaf would have
 * defaulted to.
 *
 * `align="center"` merges to the bare direction rather than to a `-center`
 * suffix. Sheaf's own default is `bottom-center`, which is not one of Alpine
 * Anchor's placements at all and resolves to plain `bottom` — so the centring
 * this fixes is an accident of that fallback, and the placements worth writing
 * are the four directions plus `-start` and `-end`.
 *
 * A bound `:position` or `:align` holds an expression rather than a placement,
 * so there is nothing to join. Those are left as they are and reported, the way
 * an unmapped tag is: a gap the reader can go and look at beats a guess at what
 * the expression was going to evaluate to.
 */
final class JoinDropdownPlacement extends BladeSweep
{
    private const string DROPDOWN = 'flux:dropdown';

    private const string POSITION = 'position';

    private const string ALIGN = 'align';

    /** Flux's own default for each half, for the tag that writes only the other. */
    private const string POSITION_DEFAULT = 'bottom';

    private const string ALIGN_DEFAULT = 'start';

    /**
     * The directions a placement can start with.
     *
     * @var list<string>
     */
    private const array POSITIONS = ['top', 'bottom', 'left', 'right'];

    /**
     * What each alignment adds to the direction. Centre adds nothing, because
     * the bare direction is what Alpine Anchor centres.
     *
     * @var array<string, string>
     */
    private const array SUFFIXES = ['start' => '-start', 'center' => '', 'end' => '-end'];

    /**
     * The files still writing a placement as an expression.
     *
     * @var list<string>
     */
    private array $bound = [];

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'join   a dropdown\'s position and align into the one Sheaf reads';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, self::DROPDOWN) as $tag) {
            if ($tag->name !== self::DROPDOWN) {
                continue;
            }

            $this->join($edits, $source, $tag, $path);
        }

        return $edits->apply($source);
    }

    /**
     * Merge one tag's two attributes into the one Sheaf declares.
     */
    private function join(Edits $edits, string $source, Tag $tag, string $path): void
    {
        $position = $tag->attribute(self::POSITION);
        $align = $tag->attribute(self::ALIGN);

        // Ahead of everything else, because a bound half is a placement the tag
        // has stated and refit cannot read — with or without a literal beside it.
        if ($tag->has(':'.self::POSITION) || $tag->has(':'.self::ALIGN)) {
            $this->noteBound($path);

            return;
        }

        // Nothing written is nothing to join. Flux's defaults only fill in the
        // half a tag left out, rather than putting a placement on a tag that
        // asked for none.
        if (! $position instanceof Attribute && ! $align instanceof Attribute) {
            return;
        }

        $placement = $this->placement(
            $position instanceof Attribute ? $position->value : self::POSITION_DEFAULT,
            $align instanceof Attribute ? $align->value : self::ALIGN_DEFAULT,
        );

        if ($placement === null) {
            return;
        }

        if ($position instanceof Attribute) {
            $edits->replace($position->valueOffset(), $position->valueLength(), $placement);

            if ($align instanceof Attribute) {
                $this->remove($edits, $source, $align);
            }

            return;
        }

        // The align is the only half written, so the placement takes its place
        // rather than being appended somewhere else on the tag.
        $edits->replace(
            $align->offset,
            $align->length,
            sprintf('%s="%s"', self::POSITION, $placement),
        );
    }

    /**
     * The one value both halves add up to, or null when either is not a
     * placement Flux writes. A half written as a bare attribute, with no value
     * at all, is one of those.
     */
    private function placement(?string $position, ?string $align): ?string
    {
        if (! in_array($position, self::POSITIONS, true) || $align === null) {
            return null;
        }

        return isset(self::SUFFIXES[$align])
            ? $position.self::SUFFIXES[$align]
            : null;
    }

    /**
     * Take an attribute off a tag, along with the whitespace in front of it, so
     * a tag written a line per attribute does not keep the blank line.
     */
    private function remove(Edits $edits, string $source, Attribute $attribute): void
    {
        $from = $attribute->offset;

        while ($from > 0 && in_array($source[$from - 1], TagParser::WHITESPACE, true)) {
            $from--;
        }

        $edits->replace($from, $attribute->offset - $from + $attribute->length, '');
    }

    private function noteBound(string $path): void
    {
        if (! in_array($path, $this->bound, true)) {
            $this->bound[] = $path;
        }
    }

    /**
     * One warning naming every file that still places a dropdown from PHP.
     */
    protected function finish(Report $report): void
    {
        if ($this->bound === []) {
            return;
        }

        $report->warn(sprintf(
            'Left the dropdown placement alone in %s — Sheaf takes position and align as one hyphenated value, and a bound one is an expression refit cannot join.',
            implode(', ', $this->bound),
        ));

        $this->bound = [];
    }
}
