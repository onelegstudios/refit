<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Questions;

use Onelegstudios\Refit\Contracts\Asks;

/**
 * A follow-up question a task needs answered before it can plan.
 *
 * Every question is a choice between named options, which is the whole shape on
 * purpose: one prompt to render, one value to put in `--answers`, and one answer
 * for a task to branch on. A yes/no question is two options with words on them —
 * "Move them too" and "Leave them where they are" tell the reader more than
 * "Yes" and "No" ever do, the same way an action's `describe()` has to.
 *
 * A question is a choice of one unless `multiple` says otherwise. Some are not:
 * which developer logins a login page should offer is a list rather than a fork,
 * because every answer is true at once and picking one does not rule the others
 * out. Asked as four questions it would be four prompts nobody wants, and asked
 * as one option per combination it would be fifteen lines to read — so the
 * question itself carries which of the two it is, and the command puts a
 * multiselect rather than a select. The only thing that changes for the task is
 * which side of {@see Answers} it reads: `get()` for one answer, `many()` for
 * several.
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
     * @param  string|list<string>|null  $default  the answer a run without prompts falls back to, or null for the first option
     * @param  bool  $multiple  several answers rather than one, for options that do not exclude each other
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $options,
        public readonly string|array|null $default = null,
        public readonly string $hint = '',
        public readonly bool $multiple = false,
    ) {}

    /**
     * The answer to fall back to when nobody has chosen one.
     */
    public function default(): string
    {
        return $this->defaults()[0] ?? '';
    }

    /**
     * The same fallback, for a question that takes several answers.
     *
     * Also what the prompt arrives pre-ticked with, so the default is the shape
     * of the answer rather than only the one a silent run gets.
     *
     * @return list<string>
     */
    public function defaults(): array
    {
        $declared = $this->offered(is_array($this->default) ? $this->default : [$this->default]);

        if ($declared !== []) {
            return $declared;
        }

        $first = array_key_first($this->options);

        return $first === null ? [] : [(string) $first];
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

    /**
     * The same, for a question that takes several answers.
     *
     * Values it never offered are dropped one by one rather than condemning the
     * list, so a payload naming two live answers and one typo still runs the two.
     * A list with nothing left in it falls back to the defaults, for the same
     * reason a single unknown answer does — and because a task whose question was
     * answered with nothing at all would otherwise be picked and then do nothing.
     *
     * @return list<string>
     */
    public function resolveMany(mixed $given): array
    {
        $chosen = $this->offered(is_array($given) ? $given : [$given]);

        return $chosen === [] ? $this->defaults() : $chosen;
    }

    /**
     * The values among these that this question actually offered, deduplicated.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function offered(array $values): array
    {
        $offered = [];

        foreach ($values as $value) {
            if (is_string($value) && array_key_exists($value, $this->options) && ! in_array($value, $offered, true)) {
                $offered[] = $value;
            }
        }

        return $offered;
    }
}
