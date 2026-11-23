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
 * Stack toasts at the top of the screen instead of the bottom.
 *
 * Every kit renders a bare `<flux:toast.group>` in each layout, which leaves Flux
 * on its `bottom end` default — the corner a sidebar footer, a cookie bar or a
 * mobile keyboard tends to occupy. Adding a `position` moves the stack out of the
 * way, and is the whole change: the group positions the toasts inside it.
 *
 * Which top corner is asked rather than decided, because the project cannot
 * answer it: all three are equally true of it, and the sidebar is the only thing
 * that makes one better than another. The right-hand corner is the default, so a
 * run that does not answer lands where this task has always put them.
 *
 * A group that already carries a position is left alone, so a project that has
 * made its own choice keeps it — whichever corner was picked here.
 */
final class MoveToastsToTop implements Asks, Task
{
    private const string TAG = 'flux:toast.group';

    private const string ATTRIBUTE = 'position';

    private const string POSITION = 'top end';

    /**
     * The corners on offer, and the line each is shown as.
     *
     * @var array<string, string>
     */
    private const array CORNERS = [
        'top end' => 'Top right, opposite the sidebar',
        'top center' => 'Top centre, like a banner',
        'top start' => 'Top left, above the sidebar',
    ];

    public function __construct(
        private readonly string $position = self::POSITION,
        private readonly TagRewriter $rewriter = new TagRewriter,
    ) {}

    public function key(): string
    {
        return 'toasts-at-top';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Structure;
    }

    public function label(): string
    {
        return 'Show toasts at the top of the screen';
    }

    public function hint(): string
    {
        return 'Puts a position on the <flux:toast.group> in every layout — you pick the corner';
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
            hint: 'They cover whatever is under them, so pick the corner your layout leaves free.',
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
