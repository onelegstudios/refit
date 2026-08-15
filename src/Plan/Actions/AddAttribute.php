<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Give a component tag an attribute it does not have yet.
 *
 * Swept over every view rather than aimed at one file, because the tag the
 * attribute belongs on is usually written once per layout, and which layouts a
 * kit ships differs between variations.
 */
final class AddAttribute extends BladeSweep
{
    public function __construct(
        private readonly string $tag,
        private readonly string $attribute,
        private readonly string $value,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function describe(): string
    {
        return sprintf(
            'rewrite <%s> -> <%s %s="%s">',
            $this->tag,
            $this->tag,
            $this->attribute,
            $this->value,
        );
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return $this->rewriter->addAttribute($source, $this->tag, $this->attribute, $this->value);
    }
}
