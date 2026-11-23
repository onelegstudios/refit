<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Contracts;

use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;

/**
 * A task with follow-up questions of its own.
 *
 * The multiselect asks whether a task should run. Some tasks need to know more
 * than that before they can plan — which corner the toasts go in, what a guest
 * gets where the user menu was — and the answer is not in the project, because
 * both answers are equally true of it. That is what this is for.
 *
 * Questions are put to the user only for the tasks they picked, and only once
 * the library and the icon set are settled, so a question can be written against
 * the project the task is actually going to rewrite.
 *
 * Implement it alongside {@see Task}; refit asks nothing of a task that does not:
 *
 *     final class PositionToasts implements Task, Asks
 *     {
 *         public function __construct(private readonly Answers $answers = new Answers) {}
 *
 *         public function questions(Project $project): array
 *         {
 *             return [new Question(
 *                 key: 'corner',
 *                 label: 'Where should the toasts stack?',
 *                 options: ['top end' => 'Top right', 'top center' => 'Top centre'],
 *             )];
 *         }
 *
 *         public function answered(Answers $answers): Task
 *         {
 *             return new self($answers);
 *         }
 *     }
 *
 * `answered()` returns a task rather than mutating this one, so the registered
 * instance stays answer-free and a run cannot leak its choices into the next.
 * It returns a {@see Task} rather than `static` on purpose: a fork wide enough
 * to deserve a class of its own can be one, and the task that asked the question
 * hands the planning over to it.
 */
interface Asks
{
    /**
     * The questions to put to the user before this task plans anything.
     *
     * Narrow them to the project in front of you, the way `appliesTo()` narrows
     * the task itself: an option the kit cannot take is worse than no question.
     * A question left with a single option is not asked — its one answer is
     * taken as given.
     *
     * @return list<Question>
     */
    public function questions(Project $project): array;

    /**
     * The task to plan with, now that its questions have answers.
     */
    public function answered(Answers $answers): Task;
}
