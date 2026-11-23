<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Contracts\Asks;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Plan\Actions\AddAttribute;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;

/**
 * Stack the toasts in a corner of your choosing.
 *
 * Every kit renders a bare `<flux:toast.group>` in each layout, which leaves Flux
 * on its `bottom end` default — the corner a cookie bar or a mobile keyboard
 * tends to occupy. Giving the group a `position` moves the stack, and is the
 * whole change: the group positions the toasts inside it.
 *
 * Which corner is asked rather than decided, because the project cannot answer
 * it. Flux reads the attribute as one of `top`/`bottom` and one of
 * `start`/`center`/`end`, so every corner and both midpoints are on offer — the
 * top right by default, which is where this task has always put them, and the
 * bottom left for a layout whose right-hand side is the busy one.
 *
 * The bottom right is the one answer missing, because it is the one the project
 * already has. Leaving the task unpicked is how you keep it, and that is a
 * cheaper way of saying so than an attribute that changes nothing.
 *
 * A group that already carries a position is left alone, so a project that has
 * made its own choice keeps it — whichever corner was picked here.
 */
final class PositionToasts implements Asks, Task
{
    private const string TAG = 'flux:toast.group';

    private const string ATTRIBUTE = 'position';

    /**
     * Where a run that answers nothing puts them, and the first corner offered.
     */
    private const string CORNER = 'top end';

    /**
     * The corners on offer, and the line each is shown as.
     *
     * @var array<string, string>
     */
    private const array CORNERS = [
        'top end' => 'Top right, opposite the sidebar',
        'top center' => 'Top centre, like a banner',
        'top start' => 'Top left, over the sidebar header',
        'bottom center' => 'Bottom centre',
        'bottom start' => 'Bottom left, over the sidebar footer',
    ];

    public function __construct(
        private readonly string $position = self::CORNER,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function key(): string
    {
        return 'position-toasts';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Structure;
    }

    public function label(): string
    {
        return 'Move the toast stack to another corner';
    }

    public function hint(): string
    {
        return 'Puts a position on the <flux:toast.group> in every layout — you pick which';
    }

    public function appliesTo(Project $project): bool
    {
        foreach ($project->blades() as $path) {
            $source = $project->get($path);

            if (! str_contains($source, '<'.self::TAG)) {
                continue;
            }

            if ($this->reposition($source) !== $source) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Question>
     */
    public function questions(Project $project): array
    {
        return [new Question(
            key: 'corner',
            label: 'Which corner should the toasts stack in?',
            options: self::CORNERS,
            hint: 'They cover whatever is under them. The bottom right is where Flux already puts them.',
        )];
    }

    public function answered(Answers $answers): Task
    {
        return new self($answers->get('corner'), $this->rewriter);
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $plan->add(Stage::Reconcile, new AddAttribute(
            self::TAG,
            self::ATTRIBUTE,
            $this->position,
            $this->rewriter,
        ));
    }

    /**
     * The rewrite this task would make, used to decide whether it has one to make.
     *
     * Any of the corners adds the same missing attribute, so this answers the
     * same way whichever one the question lands on.
     */
    private function reposition(string $source): string
    {
        return $this->rewriter->addAttribute($source, self::TAG, self::ATTRIBUTE, $this->position);
    }
}
