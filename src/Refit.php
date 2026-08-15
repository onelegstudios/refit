<?php

declare(strict_types=1);

namespace Onelegstudios\Refit;

use Onelegstudios\Refit\Contracts\Library;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Tasks\TaskGroup;

/**
 * The task and library registry.
 *
 * Packages register their own from a service provider:
 *
 *     Refit::task(new MyCustomTask);
 *     Refit::library(new MyDesignSystem);
 */
class Refit
{
    /**
     * @var list<Task>
     */
    private array $tasks = [];

    /**
     * @var list<Library>
     */
    private array $libraries = [];

    public function task(Task ...$tasks): static
    {
        foreach ($tasks as $task) {
            $this->tasks[] = $task;
        }

        return $this;
    }

    /**
     * @return list<Task>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * The tasks that make sense for a given project, grouped and ordered.
     *
     * @return list<Task>
     */
    public function tasksFor(Project $project): array
    {
        $applicable = array_values(array_filter(
            $this->tasks,
            static fn (Task $task): bool => $task->appliesTo($project),
        ));

        usort($applicable, static function (Task $a, Task $b): int {
            return [$a->group()->order(), $a->label()] <=> [$b->group()->order(), $b->label()];
        });

        return $applicable;
    }

    /**
     * @param  list<string>  $keys
     * @return list<Task>
     */
    public function resolve(array $keys): array
    {
        return array_values(array_filter(
            $this->tasks,
            static fn (Task $task): bool => in_array($task->key(), $keys, true),
        ));
    }

    /**
     * Applicable tasks as prompt options, keyed by task key.
     *
     * @return array<string, string>
     */
    public function options(Project $project): array
    {
        $options = [];

        foreach ($this->tasksFor($project) as $task) {
            $options[$task->key()] = sprintf(
                '[%s] %s',
                $task->group()->label(),
                $task->label(),
            );
        }

        return $options;
    }

    /**
     * @return list<TaskGroup>
     */
    public function groups(): array
    {
        return TaskGroup::cases();
    }

    public function library(Library ...$libraries): static
    {
        foreach ($libraries as $library) {
            $this->libraries[] = $library;
        }

        return $this;
    }

    /**
     * @return list<Library>
     */
    public function libraries(): array
    {
        return $this->libraries;
    }

    public function resolveLibrary(string $key): ?Library
    {
        foreach ($this->libraries as $library) {
            if ($library->key() === $key) {
                return $library;
            }
        }

        return null;
    }

    /**
     * Registered libraries as prompt options, keyed by library key.
     *
     * Every library is offered, including ones the project is not set up for.
     * A target the project cannot take yet fails its own preflight with the
     * commands to fix it, which is more use than the option quietly missing.
     *
     * @return array<string, string>
     */
    public function libraryOptions(): array
    {
        $options = [];

        foreach ($this->libraries as $library) {
            $options[$library->key()] = sprintf('%s — %s', $library->label(), $library->hint());
        }

        return $options;
    }
}
