<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Draw an outline button icon at the size Flux drew it.
 *
 * Flux's default button icons are the solid `micro` and `mini` weights, which
 * carry their own 16px and 20px artwork. An outline icon is 24px artwork, so
 * Flux sizes it down to match: `size-4` beside a label, `size-5` on an icon-only
 * button that is not `xs`.
 *
 * Sheaf sizes every button icon the same way whatever its weight — `size-5`, or
 * `size-4` on an `xs` button — so a labelled outline button comes out a step
 * larger. The kit's "View recovery codes" and "Hide recovery codes" are the two
 * that show it.
 *
 * Sheaf's button takes extra icon classes through `iconClasses`, and appends
 * them to its own list, where Tailwind's ordering lets `size-5` win the tie. So
 * the size goes on with `!`.
 *
 * Only a literal `outline` is read. A bound weight is an expression refit cannot
 * evaluate, and a button that already passes `iconClasses` has sized its icon
 * for itself.
 */
final class SizeOutlineButtonIcons extends BladeSweep
{
    private const string BUTTON = 'x-ui.button';

    private const string VARIANT = 'iconVariant';

    private const string OUTLINE = 'outline';

    private const string CLASSES = 'iconClasses';

    private const string SIZE = 'size-4!';

    public function __construct(
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return 'size   outline button icons back down to Flux\'s size-4';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are Sheaf's to size.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $labelled = $this->labelled($source);
        $edits = new Edits;

        foreach ($this->parser->parse($source, self::BUTTON) as $tag) {
            if ($tag->name !== self::BUTTON || ! $this->needsSize($tag, $labelled)) {
                continue;
            }

            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' %s="%s"', self::CLASSES, self::SIZE),
            );
        }

        return $edits->apply($source);
    }

    /**
     * @param  array<int, true>  $labelled
     */
    private function needsSize(Tag $tag, array $labelled): bool
    {
        $variant = $tag->attribute(self::VARIANT);

        if (! $variant instanceof Attribute || $variant->value !== self::OUTLINE) {
            return false;
        }

        if ($tag->has(self::CLASSES) || $tag->has(':'.self::CLASSES)) {
            return false;
        }

        // Sheaf already draws an `xs` button's icon at size-4.
        if ($tag->attribute('size')?->value === 'xs') {
            return false;
        }

        // An icon-only button keeps size-5 in both libraries.
        return isset($labelled[$tag->offset]);
    }

    /**
     * The offsets of every button with something in its slot.
     *
     * @return array<int, true>
     */
    private function labelled(string $source): array
    {
        $offsets = [];

        foreach ($this->nesting->elements($source, [self::BUTTON]) as $element) {
            if ($element->name() !== self::BUTTON || $element->open->has('square')) {
                continue;
            }

            $start = $element->contentStart();

            if (trim(substr($source, $start, $element->closes - $start)) !== '') {
                $offsets[$element->open->offset] = true;
            }
        }

        return $offsets;
    }
}
