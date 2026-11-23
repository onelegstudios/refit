<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Blade\TagRewriter;
use Onelegstudios\Refit\Contracts\Asks;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\AddAttribute;
use Onelegstudios\Refit\Plan\Actions\RewriteToastCalls;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;

/**
 * Stack the toasts in a corner of your choosing.
 *
 * Every kit renders a bare `<flux:toast.group>` in each layout, which leaves the
 * stack in the `bottom right` both libraries default to — the corner a cookie bar
 * or a mobile keyboard tends to occupy. Giving the container a `position` moves
 * it, and is the whole change: the container positions the toasts inside it.
 *
 * Which corner is asked rather than decided, because the project cannot answer
 * it. They are all equally true of it, and the layout is the only thing that
 * makes one better than another.
 *
 * The answer is a corner, not an attribute value, because the two libraries do
 * not spell a corner the same way and do not offer the same ones:
 *
 *     Flux    position="top end"    top|bottom, start|center|end
 *     Sheaf   position="top-right"  top|bottom-left|right, and nothing else
 *
 * Flux's `start` and `end` follow the text direction, which is the better
 * attribute to write on a Flux project even though the labels here read as a
 * left and a right; Sheaf's four corners are literal, and it falls back to its
 * own default for anything it does not recognise. So each corner is spelled per
 * library in {@see SPELLINGS}, and a corner a library cannot reach is not
 * offered on a project heading for it — the two midpoints, on a Sheaf run.
 *
 * The bottom right is the one corner nobody is offered, because it is the one
 * every project already has. Leaving the task unpicked is how you keep it, and
 * that is a cheaper way of saying so than an attribute that changes nothing.
 *
 * The attribute lands on whatever the container is called by the time the plan
 * runs. A project leaving Flux has the group renamed on its way out, in the same
 * reconcile pass and ahead of this, so the tag is read from {@see ComponentMap}
 * rather than written out a second time — and Sheaf positions on the container
 * exactly as Flux does, which is what {@see RewriteToastCalls} reports when it
 * drops a `position` from a per-call `Flux::toast()`.
 *
 * A container that already carries a position is left alone, so a project that
 * has made its own choice keeps it — whichever corner was picked here.
 */
final class PositionToasts implements Asks, Task
{
    /**
     * The container the kit ships, which is what is on disk when this is asked.
     */
    private const string TAG = 'flux:toast.group';

    private const string ATTRIBUTE = 'position';

    /**
     * The corner a run that answers nothing lands on, and the first one offered.
     *
     * Every library spells this one, so it is always a live answer.
     */
    private const string CORNER = 'top-right';

    /**
     * The corners on offer, and the line each is shown as.
     *
     * @var array<string, string>
     */
    private const array CORNERS = [
        'top-right' => 'Top right, opposite the sidebar',
        'top-center' => 'Top centre, like a banner',
        'top-left' => 'Top left, over the sidebar header',
        'bottom-center' => 'Bottom centre',
        'bottom-left' => 'Bottom left, over the sidebar footer',
    ];

    /**
     * How each library spells a corner, and therefore which ones it can reach.
     *
     * @var array<string, array<string, string>>
     */
    private const array SPELLINGS = [
        FluxLibrary::KEY => [
            'top-right' => 'top end',
            'top-center' => 'top center',
            'top-left' => 'top start',
            'bottom-center' => 'bottom center',
            'bottom-left' => 'bottom start',
        ],
        SheafLibrary::KEY => [
            'top-right' => 'top-right',
            'top-left' => 'top-left',
            'bottom-left' => 'bottom-left',
        ],
    ];

    public function __construct(
        private readonly string $corner = self::CORNER,
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
        return 'Puts a position on the toast container in every layout — you pick which corner';
    }

    public function appliesTo(Project $project): bool
    {
        if ($this->corners($project) === []) {
            return false;
        }

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
        $corners = $this->corners($project);

        if ($corners === []) {
            return [];
        }

        return [new Question(
            key: 'corner',
            label: 'Which corner should the toasts stack in?',
            options: $corners,
            hint: 'They cover whatever is under them, and the bottom right is where they are now.',
        )];
    }

    public function answered(Answers $answers): Task
    {
        return new self($answers->get('corner'), $this->rewriter);
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $spellings = $this->spellings($project);

        if ($spellings === []) {
            // Only reachable through --answers, which resolves a task by key
            // without asking whether it applies. Worth a line rather than a
            // silent no-op, which is the shape this used to fail in.
            $report->warn(sprintf(
                'Left the toast position alone — refit does not know how %s spells a corner.',
                $project->target?->label() ?? 'this library',
            ));

            return;
        }

        $plan->add(Stage::Reconcile, new AddAttribute(
            $this->tag($project),
            self::ATTRIBUTE,
            $spellings[$this->corner] ?? $spellings[self::CORNER],
            $this->rewriter,
        ));
    }

    /**
     * The corners this project's target can actually reach.
     *
     * @return array<string, string>
     */
    private function corners(Project $project): array
    {
        return array_intersect_key(self::CORNERS, $this->spellings($project));
    }

    /**
     * How this project's target spells a corner, empty for one refit cannot.
     *
     * No target means nobody has asked the library question yet — a test, or a
     * task driven by hand. The kit ships Flux, so Flux is the honest assumption.
     *
     * @return array<string, string>
     */
    private function spellings(Project $project): array
    {
        return self::SPELLINGS[$project->target?->key() ?? FluxLibrary::KEY] ?? [];
    }

    /**
     * The tag the attribute belongs on once the plan has run.
     *
     * Flux's own container while the project is staying; whatever the migration
     * renames it to when it is not.
     */
    private function tag(Project $project): string
    {
        if ($project->targets(SheafLibrary::KEY)) {
            return ComponentMap::tag(self::TAG) ?? self::TAG;
        }

        return self::TAG;
    }

    /**
     * The rewrite this task would make, used to decide whether it has one to make.
     *
     * Only the comparison matters, so the value is the neutral corner rather
     * than anything a library would read: every corner adds the same missing
     * attribute to the same container, so this answers the same way whichever
     * one the question lands on.
     */
    private function reposition(string $source): string
    {
        return $this->rewriter->addAttribute($source, self::TAG, self::ATTRIBUTE, self::CORNER);
    }
}
