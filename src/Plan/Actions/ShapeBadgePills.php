<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Say a badge's roundness in the prop Flux itself now spells it with.
 *
 * Flux has two names for one shape. `rounded` is the current prop, and
 * `variant="pill"` is a backwards-compatible alias its own component unwrites
 * before it reads anything else:
 *
 *     if ($variant === 'pill') { $rounded = true; $variant = null; }
 *
 * This does the same thing to the markup, so that by the time the rename runs
 * there is one spelling left to translate rather than two. `ComponentMap` then
 * renames `rounded` to Sheaf's `pill`, which is a boolean prop rather than a
 * variant — the same shape, filed under a word Flux spends on a variant.
 *
 * The alias is worth unwriting rather than mapping straight across because it
 * carries two meanings at once. Flux's `pill` is a shape *and* a return to the
 * tinted default, since it clears `$variant` on the way past. A value table can
 * only rewrite the value in place, so it can say one of those and not both:
 * mapping it to Sheaf's `pill` would round a badge that stayed solid, and
 * mapping it to `outline` would tint one that stayed square. Splitting it here
 * lets each half land in the pass that already handles it — the rename for the
 * shape, and the variant `AddAttribute` for the tint, which sees a badge with no
 * variant left on it and adds Sheaf's `outline`.
 *
 * Nothing in the kit writes either spelling, so an ordinary run is a no-op. This
 * is for the project that has written a pill badge of its own, and it is worth
 * the pass because the failure is quiet at both ends: `rounded` is not a Sheaf
 * prop, so it falls out of `{{ $attributes }}` onto the wrapper as a stray HTML
 * attribute, and `variant="pill"` is not a Sheaf variant, so it takes the solid
 * branch by falling off the end of the match.
 *
 * A bound `:variant` holds an expression rather than a word, so there is no
 * alias to recognise. Those are left alone and unreported: Sheaf sends a variant
 * it does not know through to the solid branch, which is visibly wrong on the
 * page rather than silently missing — the ordinary variant argument, and the one
 * that keeps this out of `BOUND_VALUES`.
 */
final class ShapeBadgePills extends BladeSweep
{
    private const string BADGE = 'flux:badge';

    private const string VARIANT = 'variant';

    /** Flux's alias, and the value that means the shape rather than a colour. */
    private const string PILL = 'pill';

    /** Flux's current name for it, which `ComponentMap` renames to Sheaf's `pill`. */
    private const string ROUNDED = 'rounded';

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'shape  a pill badge into the roundness Sheaf declares';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, self::BADGE) as $tag) {
            if ($tag->name !== self::BADGE) {
                continue;
            }

            $variant = $tag->attribute(self::VARIANT);

            if (! $variant instanceof Attribute || $variant->value !== self::PILL) {
                continue;
            }

            // A tag that already says `rounded` has the shape twice over, so the
            // alias is dropped rather than restated. Its whitespace goes with it,
            // the way `without()` takes an attribute off a multi-line tag.
            $replacement = $tag->has(self::ROUNDED) ? '' : self::ROUNDED;

            $edits->replace(
                ...$this->span($source, $variant, $replacement),
            );
        }

        return $edits->apply($source);
    }

    /**
     * Where the edit goes and what it puts there.
     *
     * A replacement keeps the attribute's own offset, so `variant="pill"` becomes
     * `rounded` in place. A removal reaches back over the whitespace in front of
     * it as well, so a tag written a line per attribute does not keep the blank
     * line.
     *
     * @return array{int, int, string}
     */
    private function span(string $source, Attribute $variant, string $replacement): array
    {
        if ($replacement !== '') {
            return [$variant->offset, $variant->length, $replacement];
        }

        $from = $variant->offset;

        while ($from > 0 && in_array($source[$from - 1], TagParser::WHITESPACE, true)) {
            $from--;
        }

        return [$from, $variant->offset - $from + $variant->length, ''];
    }
}
