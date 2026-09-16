<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Turn `@include('partials.head')` into `<x-head />`.
 *
 * Only the argument-less form is rewritten. An `@include` that passes data has no
 * mechanical translation — the component would need matching props — so those are
 * reported and left for the user.
 */
final class ReplaceIncludeWithComponent extends BladeSweep
{
    public function __construct(
        private readonly string $view,
        private readonly string $tag,
    ) {}

    public function describe(): string
    {
        return sprintf("rewrite @include('%s') -> <%s />", $this->view, $this->tag);
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $quoted = preg_quote($this->view, '/');

        $withData = '/@include\(\s*([\'"])'.$quoted.'\1\s*,/';

        if (preg_match($withData, $source) === 1) {
            $report->warn(sprintf(
                "An @include('%s') passes data — left alone, convert it to <%s> by hand.",
                $this->view,
                $this->tag,
            ));
        }

        $pattern = '/@include\(\s*([\'"])'.$quoted.'\1\s*\)/';

        $rewritten = preg_replace($pattern, '<'.$this->tag.' />', $source);

        return $rewritten ?? $source;
    }
}
