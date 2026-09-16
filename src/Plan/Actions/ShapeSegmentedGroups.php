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
 * Say the two things Flux folded into `variant="segmented"` and Sheaf does not.
 *
 * Both libraries spell a segmented control the same way, so `variant` survives
 * the rename untouched and looks like the whole answer. It is not. Flux reads
 * "segmented" as a complete description — a row of flush segments, no radio dots
 * — while Sheaf reads it as the pill background alone and leaves the rest to two
 * other props, each defaulting to the opposite of what a segment wants:
 *
 * - `direction` defaults to `vertical`, which puts `space-y-2` on the group, so
 *   the segments stack down the page inside a `w-fit` pill;
 * - `indicator` defaults to `true`, so every segment draws the radio dot the
 *   segmented look exists to replace.
 *
 * The appearance page is where the kit uses this, and left alone it comes out as
 * a narrow grey column of three dotted rows. So refit says both out loud.
 *
 * Neither is overwritten. A group that already names `direction` or `indicator`,
 * in either the literal or the bound spelling, has made the decision itself, and
 * a bound `variant` is not a value this sweep can read at all.
 */
final class ShapeSegmentedGroups extends BladeSweep
{
    private const string TARGET = 'x-ui.radio.group';

    /** The variant whose name is only part of its shape. */
    private const string SEGMENTED = 'segmented';

    /**
     * What a segment needs said, and what Sheaf would otherwise assume.
     *
     * @var array<string, string>
     */
    private const array SHAPE = [
        'direction' => 'direction="horizontal"',
        'indicator' => ':indicator="false"',
    ];

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'shape  the segmented groups Sheaf would stack and dot';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own group is where these defaults are declared, not a place
        // where one is missing.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->parser->parse($source, self::TARGET) as $tag) {
            if ($tag->name !== self::TARGET || ! $this->isSegmented($tag)) {
                continue;
            }

            $missing = [];

            foreach (self::SHAPE as $name => $attribute) {
                if (! $tag->has($name) && ! $tag->has(':'.$name)) {
                    $missing[] = $attribute;
                }
            }

            if ($missing === []) {
                continue;
            }

            // One insert rather than one per attribute: edits at a shared offset
            // are applied in whichever order the sort leaves them in.
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                ' '.implode(' ', $missing),
            );
        }

        return $edits->apply($source);
    }

    /**
     * A bound `variant` holds a PHP expression, so what it evaluates to is the
     * application's business rather than something to guess at.
     */
    private function isSegmented(Tag $tag): bool
    {
        $variant = $tag->attribute('variant');

        return $variant instanceof Attribute && $variant->value === self::SEGMENTED;
    }
}
