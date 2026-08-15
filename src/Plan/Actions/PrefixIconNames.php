<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Put an icon-set prefix in front of every icon name.
 *
 * Sheaf's icon component picks its provider from the name: a bare name is a
 * Heroicon, `ps:` is Phosphor, `bk:` is the Blade Icons ecosystem. So switching
 * set is a prefix rather than a translation — the names themselves are close
 * enough between Heroicons and Phosphor that refit does not need a table.
 *
 * Idempotent by inspection rather than by luck: a name that already carries the
 * prefix is left alone, so a second pass changes nothing.
 */
final class PrefixIconNames extends BladeSweep
{
    public function __construct(
        private readonly string $prefix,
        private readonly Vocabulary $vocabulary,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function describe(): string
    {
        return sprintf('icons  prefix every name with "%s"', $this->prefix);
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return $this->rewriter->rewriteAttributeValues(
            $source,
            $this->vocabulary->prefix,
            $this->vocabulary->candidateAttributes(),
            function (Tag $tag, Attribute $attribute, string $value): ?string {
                if (! $this->vocabulary->namesAnIcon($tag->name, $attribute->name)) {
                    return null;
                }

                if ($value === '' || str_starts_with($value, $this->prefix)) {
                    return null;
                }

                return $this->prefix.$value;
            },
        );
    }
}
