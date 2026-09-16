<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Take a departing library's Blade directives out of every view.
 *
 * A sweep rather than one edit per file, because the plan is built before the
 * target has written a thing and the target rewrites the chrome from stubs. Name
 * `layouts/app/sidebar.blade.php` at plan time and you are promising to edit a
 * file that will not be the same file by the time the promise comes due — which
 * read, correctly, as "nothing matched" in the report.
 *
 * So this reads the tree as it stands when it runs. The stubs the target wrote
 * never had `@fluxScripts` in them, and a view refit has not seen yet is swept
 * like any other.
 *
 * The directive is removed rather than the line it sits on, and the line goes
 * only if that emptied it. The kit gives each of these a line of its own, so the
 * two are the same thing here — but they stop being the same thing the moment
 * somebody has put a directive at the end of a line that was doing something
 * else, and a cleanup should not eat that.
 */
final class RemoveBladeDirectives extends BladeSweep
{
    /**
     * @param  list<string>  $directives  Bare directive names, `@` included.
     */
    public function __construct(private readonly array $directives) {}

    public function describe(): string
    {
        return sprintf('blade  drop %s from every view', implode(' and ', $this->directives));
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $rewritten = $source;

        foreach ($this->directives as $directive) {
            // \b stops @fluxScripts from matching a longer directive that merely
            // starts the same way. These take no arguments, so there is no
            // parenthesised tail to account for.
            $quoted = preg_quote($directive, '/');

            // A line that is nothing but the directive goes, break and all, so
            // the removal does not leave a hole where a line used to be.
            $rewritten = (string) preg_replace('/^[ \t]*'.$quoted.'\b[ \t]*\R?/m', '', $rewritten);

            // Anything left is sharing a line with markup that is staying.
            $rewritten = (string) preg_replace('/'.$quoted.'\b/', '', $rewritten);
        }

        return $rewritten;
    }
}
