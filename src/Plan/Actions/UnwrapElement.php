<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Take an element's tags out of a file and keep what was inside them.
 *
 * Scoped to a named file rather than swept over the tree, because unwrapping is
 * only ever right where something else has taken the wrapper's job — which is a
 * fact about one file, not about a tag.
 *
 * The contents come back one indent level shallower, so the result reads like it
 * was written that way.
 */
final class UnwrapElement implements Action
{
    private const string INDENT = '    ';

    public function __construct(
        private readonly string $path,
        private readonly string $tag,
        private readonly ?string $description = null,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return $this->description ?? sprintf('edit   %s — drop the <%s> wrapper', $this->path, $this->tag);
    }

    public function apply(Project $project, Report $report): void
    {
        if (! $project->exists($this->path)) {
            $report->warn("Skipped unwrapping <{$this->tag}> — [{$this->path}] does not exist.");

            return;
        }

        $source = $project->get($this->path);
        $edits = new Edits;
        $unwrapped = 0;

        foreach ($this->nesting->elements($source, [$this->tag]) as $element) {
            if ($element->name() !== $this->tag) {
                continue;
            }

            $closes = strpos($source, '>', $element->closes);

            if ($closes === false) {
                continue;
            }

            $edits->replace(
                $element->open->offset,
                $closes + 1 - $element->open->offset,
                $this->contents($source, $element),
            );

            $unwrapped++;
        }

        if ($unwrapped === 0) {
            $report->warn("Nothing in [{$this->path}] is wrapped in <{$this->tag}>.");

            return;
        }

        file_put_contents($project->path($this->path), $edits->apply($source));

        $report->changed($this->path);
    }

    /**
     * What was inside the element, pulled back one indent level.
     *
     * The first line loses its indentation outright: the replacement starts where
     * the opening tag did, which is already past the indent on that line.
     */
    private function contents(string $source, Element $element): string
    {
        $inside = substr($source, $element->contentStart(), $element->closes - $element->contentStart());
        $body = rtrim(preg_replace('/^\R+/', '', $inside) ?? '');

        $lines = preg_split('/\R/', $body) ?: [];

        foreach ($lines as $number => $line) {
            $lines[$number] = $number === 0
                ? ltrim($line)
                : (str_starts_with($line, self::INDENT) ? substr($line, strlen(self::INDENT)) : $line);
        }

        return implode("\n", $lines);
    }
}
