<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tests\Doubles;

use Onelegstudios\Refit\Contracts\Asks;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Plan\Actions\WriteFile;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;
use Onelegstudios\Refit\Tasks\TaskGroup;

/**
 * A task with follow-up questions, standing in for one refit ships.
 *
 * Nothing refit ships asks anything yet, so this is what the suite points the
 * command at to assert that a task's own questions are put, answered and acted
 * on. It is deliberately the whole shape in miniature: one question with two
 * answers that lead to different writes, and one with a single answer, which is
 * never asked about.
 */
final class AsksHowToGreet implements Asks, Task
{
    public const string WHERE = 'resources/views/greeting.blade.php';

    public function __construct(
        private readonly Answers $answers = new Answers(['tone' => 'formal', 'where' => self::WHERE]),
    ) {}

    public function key(): string
    {
        return 'greeting';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Cleanup;
    }

    public function label(): string
    {
        return 'Write a greeting';
    }

    public function hint(): string
    {
        return 'Stands in for a task that asks something';
    }

    public function appliesTo(Project $project): bool
    {
        return ! $project->exists(self::WHERE);
    }

    /**
     * @return list<Question>
     */
    public function questions(Project $project): array
    {
        return [
            new Question(
                key: 'tone',
                label: 'How should the greeting read?',
                options: ['formal' => 'Formally', 'casual' => 'Casually'],
                hint: 'Either way it is one line.',
            ),
            new Question(
                key: 'where',
                label: 'Where should the greeting go?',
                options: [self::WHERE => 'Beside the views'],
            ),
        ];
    }

    public function answered(Answers $answers): Task
    {
        return new self($answers);
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $plan->add(Stage::Write, new WriteFile(
            $this->answers->get('where'),
            $this->answers->is('tone', 'casual') ? "Hey\n" : "Good day\n",
        ));
    }
}
