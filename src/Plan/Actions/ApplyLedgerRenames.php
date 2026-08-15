<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Plan\RenameLedger;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Point every reference at the names recorded in the ledger.
 *
 * Runs in the reconcile stage, so it sees the tree after every move has landed.
 */
final class ApplyLedgerRenames extends BladeSweep
{
    public function __construct(
        private readonly RenameLedger $ledger,
        private readonly string $description,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function describe(): string
    {
        return sprintf('rename %s', $this->description);
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        foreach ($this->ledger->all() as $from => $to) {
            $source = $this->rewriter->rename($source, $from, $to);
        }

        return $source;
    }
}
