<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Questions;

use Onelegstudios\Refit\Contracts\Asks;

/**
 * A follow-up question a task needs answered before it can plan.
 *
 * Every question is a choice between named options, which is the whole shape on
 * purpose: one prompt to render, one string to put in `--answers`, and one value
 * for a task to branch on. A yes/no question is two options with words on them —
 * "Move them too" and "Leave them where they are" tell the reader more than
 * "Yes" and "No" ever do, the same way an action's `describe()` has to.
 *
 * The label has to stand on its own. Several tasks can be asking in a row, and
 * the prompt shows the question rather than the task that asked it.
 *
 * @see Asks
 */
final class Question
{
    /**
     * @param  string  $key  stable identifier, scoped to the asking task
     * @param  array<string, string>  $options  answer value => the line shown for it
     * @param  string|null  $default  the answer a run without prompts falls back to, or null for the first option
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $options,
        public readonly ?string $default = null,
        public readonly string $hint = '',
    ) {}

    /**
     * The answer to fall back to when nobody has chosen one.
     */
    public function default(): string
    {
        return $this->default ?? (string) (array_key_first($this->options) ?? '');
    }

    /**
     * The answer to use for whatever was passed in `--answers`.
     *
     * An unknown value falls back to the default rather than failing the run, as
     * an unknown icon set does: the question has already been narrowed to the
     * answers that make sense, so there is nothing to ask again.
     */
    public function resolve(mixed $given): string
    {
        return is_string($given) && array_key_exists($given, $this->options)
            ? $given
            : $this->default();
    }
}
