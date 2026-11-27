<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Questions;

use Onelegstudios\Refit\Contracts\Asks;

/**
 * What a task's own questions were answered with.
 *
 * Built by the command from the questions the task declared, so every key the
 * task asked about is present and holds one of the options it offered — a task
 * reading its own question keys never has to handle a missing answer.
 *
 * A question that took several answers holds a list, and one that took a single
 * answer holds a string. Both sides read either way round: {@see get()} takes
 * the first of a list, {@see many()} wraps a single answer in one. So a task
 * that turns a question from one answer into several keeps working, and nothing
 * here has to know which kind it is being asked about.
 *
 * @see Asks
 */
final class Answers
{
    /**
     * @param  array<string, string|list<string>>  $values  question key => the option(s) chosen
     */
    public function __construct(private readonly array $values = []) {}

    /**
     * The answer to a question, or the first of several.
     */
    public function get(string $key): string
    {
        $value = $this->values[$key] ?? '';

        return is_string($value) ? $value : ($value[0] ?? '');
    }

    public function is(string $key, string $value): bool
    {
        return $this->get($key) === $value;
    }

    /**
     * Every answer to a question — a list of one, where it took a single answer.
     *
     * @return list<string>
     */
    public function many(string $key): array
    {
        $value = $this->values[$key] ?? [];

        return is_string($value) ? [$value] : $value;
    }

    /**
     * Was this option among the answers to a question?
     */
    public function chose(string $key, string $value): bool
    {
        return in_array($value, $this->many($key), true);
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function all(): array
    {
        return $this->values;
    }
}
