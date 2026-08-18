<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\BladeGuard;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * An action that rewrites every Blade file in the project.
 *
 * Sweeps run in the reconcile stage, over the settled tree, so they see files
 * that earlier stages created or moved.
 *
 * Each file is guarded individually: if a rewrite would leave the Blade less
 * balanced than it found it, that one file is left alone and the problem is
 * reported. Skipping beats aborting here — a half-applied run is harder to reason
 * about than one bad file, and the clean-tree precondition means `git checkout .`
 * is always available as the real undo.
 */
abstract class BladeSweep implements Action
{
    private BladeGuard $guard;

    /**
     * @param  string  $path  Project-relative, so a sweep can name the file it is
     *                        reporting on rather than only the tag.
     */
    abstract protected function transform(string $source, string $path, Project $project, Report $report): string;

    /**
     * Called once the whole tree has been walked.
     *
     * A sweep that collects as it goes — "these tags had no translation, in these
     * files" — reports here, so one finding is one warning rather than one per
     * file that happens to contain it.
     */
    protected function finish(Report $report): void
    {
        //
    }

    public function apply(Project $project, Report $report): void
    {
        $guard = $this->guard ??= new BladeGuard;

        foreach ($project->blades() as $path) {
            $source = $project->get($path);

            if ($source === '') {
                continue;
            }

            $rewritten = $this->transform($source, $path, $project, $report);

            if ($rewritten === $source) {
                continue;
            }

            $problems = $guard->check($source, $rewritten);

            if ($problems !== []) {
                $report->warn(sprintf(
                    'Left %s alone — "%s" would have broken it: %s',
                    $path,
                    $this->describe(),
                    implode('; ', $problems),
                ));

                continue;
            }

            file_put_contents($project->path($path), $rewritten);

            $report->changed($path);
        }

        $this->finish($report);
    }

    /**
     * A block of tag source with the given attributes taken out of it.
     *
     * Offsets are absolute, so they are read back against where the block started;
     * removals run back to front for the same reason edits do.
     *
     * @param  list<Attribute>  $attributes
     */
    protected function without(string $block, int $base, array $attributes): string
    {
        usort($attributes, static fn (Attribute $a, Attribute $b): int => $b->offset <=> $a->offset);

        foreach ($attributes as $attribute) {
            $start = $attribute->offset - $base;
            $from = $start;

            // The whitespace in front of the attribute goes with it, so a tag
            // written a line per attribute does not keep the blank line.
            while ($from > 0 && in_array($block[$from - 1], TagParser::WHITESPACE, true)) {
                $from--;
            }

            $block = substr_replace($block, '', $from, $start - $from + $attribute->length);
        }

        return $block;
    }

    /**
     * The whitespace in front of a tag on its own line.
     *
     * An empty string when anything else shares the line, since re-indenting
     * around a tag written mid-line would move markup that is not ours.
     */
    protected function indent(string $source, int $offset): string
    {
        $line = strrpos(substr($source, 0, $offset), "\n");
        $start = $line === false ? 0 : $line + 1;
        $indent = substr($source, $start, $offset - $start);

        return trim($indent) === '' ? $indent : '';
    }
}
