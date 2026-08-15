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
 * Translate icon names across every view, in every form the library accepts.
 */
final class RewriteIconNames extends BladeSweep
{
    /**
     * @param  array<string, string>  $map  Current name mapped to its replacement.
     */
    public function __construct(
        private readonly array $map,
        private readonly string $description,
        private readonly Vocabulary $vocabulary,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function describe(): string
    {
        return sprintf('icons  %s (%d name%s)', $this->description, count($this->map), count($this->map) === 1 ? '' : 's');
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Attribute forms: icon="home", icon-trailing="chevron-down", and the
        // generic <flux:icon name="home" />. The tag is checked as well as the
        // attribute, so the name="email" on every <flux:input> is left alone.
        $source = $this->rewriter->rewriteAttributeValues(
            $source,
            $this->vocabulary->prefix,
            $this->vocabulary->candidateAttributes(),
            function (Tag $tag, Attribute $attribute, string $value): ?string {
                if (! $this->vocabulary->namesAnIcon($tag->name, $attribute->name)) {
                    return null;
                }

                return $this->map[$value] ?? null;
            },
        );

        // Tag form: <flux:icon.key />, for the libraries that have one.
        if ($this->vocabulary->dottedIconTag === null) {
            return $source;
        }

        return $this->rewriter->rewriteNameSuffix(
            $source,
            $this->vocabulary->dottedIconTag,
            fn (string $suffix): ?string => $this->map[$suffix] ?? null,
        );
    }
}
