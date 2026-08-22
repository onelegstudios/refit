<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Keep Sheaf's collapsed-sidebar rules inside the sidebar.
 *
 * Sheaf writes "when the sidebar is collapsed" as `[:has([data-collapsed]_&)_&]:`,
 * and Tailwind compiles that to
 *
 *     :has([data-collapsed] .the-class) .the-class { … }
 *
 * Neither half of which asks about the element being styled. Nothing stands in
 * front of the `:has()`, so any ancestor may answer it and `<html>` does; and the
 * class inside it is the utility rather than this element, so one navlist item
 * under the collapsed layout satisfies the question on behalf of the whole page.
 * Tailwind emits one class per utility, so what the rule then reaches is every
 * element in the document that happens to share it.
 *
 * The settings pages are where that lands. Their sub-navigation is a navlist as
 * well — out in the main column, three rows that are a label and nothing else.
 * Collapse the sidebar and the label span's own `[:has([data-collapsed]_&)_&]:hidden`
 * matches out there too: three rows of nothing, and a settings menu that comes
 * back only when the sidebar is opened again.
 *
 * So refit re-keys the variant to the spelling it already writes everywhere else,
 * `[[data-collapsed]_[data-slot=sidebar]_&]:`, which asks about the element
 * instead of about the page: it is inside a sidebar, and the layout above it is
 * collapsed. Inside the sidebar every rule keeps meaning exactly what it meant.
 * Outside it, none of them mean anything at all.
 *
 * Sheaf's opposite spelling, `[:not(:has([data-collapsed]_&))_&]:`, is left alone.
 * It reads as the other half of the same question and does not behave like one: a
 * descendant combinator needs only one ancestor to match, the sidebar holds no
 * `[data-collapsed]` of its own, and so the rule is on wherever it is written —
 * inside a collapsed sidebar included. That is already what the page outside the
 * sidebar wants, and re-keying it would change how a collapsed sidebar looks
 * rather than what it manages to show.
 *
 * Like the OTP patch, this edits files `sheaf:install` copied into the project,
 * which is what makes them the project's; a later install overwrites it. It
 * matches on the variant rather than on a line, so a Sheaf that has fixed this
 * upstream — or spelled it some other way — has nothing here to change.
 */
final class ScopeCollapseToSidebar extends BladeSweep
{
    /** Sheaf's own spelling: a question every element on the page answers yes to. */
    private const string LEAKED = '[:has([data-collapsed]_&)_&]:';

    public function describe(): string
    {
        return 'scope  Sheaf\'s collapse rules to the sidebar they are about';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        return str_replace(self::LEAKED, FollowSidebarCollapse::SHEAF, $source);
    }
}
