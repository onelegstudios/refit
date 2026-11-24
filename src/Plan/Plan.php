<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Actions\WriteFile;

/**
 * The complete set of changes refit intends to make.
 *
 * A plan is a pure function of the detected project plus the user's answers,
 * which is what lets the test suite assert on plans across every fixture without
 * touching a filesystem, and what makes `--dry-run` free.
 */
final class Plan
{
    /**
     * @var array<int, list<Action>>
     */
    private array $actions = [];

    public function add(Stage $stage, Action $action): static
    {
        $this->actions[$stage->value][] = $action;

        return $this;
    }

    /**
     * Does the plan already write this file?
     *
     * Asked by a contributor that brings a file of its own: a task naming an
     * icon the icon pass is already generating should leave that file to it,
     * rather than putting a second line in the preview for the same work.
     */
    public function writes(string $path): bool
    {
        foreach ($this->actions() as $action) {
            if ($action instanceof WriteFile && $action->path() === $path) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->actions));
    }

    /**
     * Every action in execution order, grouped by stage.
     *
     * @return array<string, list<Action>>
     */
    public function grouped(): array
    {
        $stages = Stage::cases();

        usort($stages, fn (Stage $a, Stage $b): int => $a->value <=> $b->value);

        $grouped = [];

        foreach ($stages as $stage) {
            $actions = $this->actions[$stage->value] ?? [];

            if ($actions !== []) {
                $grouped[$stage->label()] = $actions;
            }
        }

        return $grouped;
    }

    /**
     * @return list<Action>
     */
    public function actions(): array
    {
        $flat = [];

        foreach ($this->grouped() as $actions) {
            foreach ($actions as $action) {
                $flat[] = $action;
            }
        }

        return $flat;
    }

    /**
     * The plan rendered as description lines, which is both the confirmation
     * preview and the snapshot the plan tests assert against.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $lines = [];

        foreach ($this->grouped() as $label => $actions) {
            $lines[] = $label;

            foreach ($actions as $action) {
                $lines[] = '  '.$action->describe();
            }
        }

        return $lines;
    }
}
