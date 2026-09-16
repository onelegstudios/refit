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
 * Draw a subtle separator as faintly as Flux drew it.
 *
 * Flux's `variant="subtle"` swaps its line from `bg-zinc-800/15 dark:bg-white/20`
 * to `bg-zinc-800/5 dark:bg-white/10`. Sheaf declares a `variant` prop and
 * reserves it — nothing reads it — so the kit's settings headings sit over a
 * line three times as dark as the one Flux drew.
 *
 * Sheaf paints the line on an element of its own, `bg-gray-300
 * dark:bg-gray-300/30`, and which element that is depends on the layout. An
 * unlabelled vertical separator is the line itself; everywhere else the line is
 * an empty child `<div>`, beside a label that never is. So the colour is stated
 * for both — the element when it is empty, and its empty `<div>` children — and
 * the selector's own weight is what beats Sheaf's plain utility, without `!`.
 *
 * The `variant` goes with it, since it says nothing Sheaf will act on. A bound
 * one, or a tag whose classes are bound, is left as written.
 */
final class ShadeSubtleSeparators extends BladeSweep
{
    private const string SEPARATOR = 'x-ui.separator';

    private const string VARIANT = 'variant';

    private const string SUBTLE = 'subtle';

    /** Flux's subtle line, on whichever element Sheaf draws the line with. */
    private const string SHADE = '[&:empty]:bg-zinc-800/5 dark:[&:empty]:bg-white/10 [&>div:empty]:bg-zinc-800/5 dark:[&>div:empty]:bg-white/10';

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'shade  the subtle separators Sheaf draws at full strength';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are Sheaf's to colour.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->parser->parse($source, self::SEPARATOR) as $tag) {
            if ($tag->name !== self::SEPARATOR) {
                continue;
            }

            $this->shade($edits, $source, $tag);
        }

        return $edits->apply($source);
    }

    private function shade(Edits $edits, string $source, Tag $tag): void
    {
        $variant = $tag->attribute(self::VARIANT);

        if (! $variant instanceof Attribute || $variant->value !== self::SUBTLE || $tag->has(':class')) {
            return;
        }

        $class = $tag->attribute('class');

        // No class to add to, so the class takes the variant's place.
        if (! $class instanceof Attribute) {
            $edits->replace($variant->offset, $variant->length, sprintf('class="%s"', self::SHADE));

            return;
        }

        if ($class->isBoolean()) {
            return;
        }

        $this->remove($edits, $source, $variant);

        $classes = trim($class->value ?? '');

        $edits->replace(
            $class->valueOffset() + $class->valueLength(),
            0,
            ($classes === '' ? '' : ' ').self::SHADE,
        );
    }

    /**
     * Take an attribute off a tag, along with the whitespace in front of it.
     */
    private function remove(Edits $edits, string $source, Attribute $attribute): void
    {
        $from = $attribute->offset;

        while ($from > 0 && in_array($source[$from - 1], TagParser::WHITESPACE, true)) {
            $from--;
        }

        $edits->replace($from, $attribute->offset - $from + $attribute->length, '');
    }
}
