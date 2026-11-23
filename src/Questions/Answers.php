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
 * @see Asks
 */
final class Answers
{
    /**
     * @param  array<string, string>  $values  question key => the option chosen
     */
    public function __construct(private readonly array $values = []) {}

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function is(string $key, string $value): bool
    {
        return $this->get($key) === $value;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
