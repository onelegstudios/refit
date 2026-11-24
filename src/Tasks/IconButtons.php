<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Flux\OverrideGenerator;
use Onelegstudios\Refit\Libraries\Flux\Overrides;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\IconLabelledButtons;
use Onelegstudios\Refit\Plan\Actions\WriteFile;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;

/**
 * Put an icon on the kit's buttons.
 *
 * The kit ships two buttons wearing an icon and several dozen that are words
 * alone. Both of the two are in the teams kits — `plus` on "New team",
 * `user-plus` on "Invite member" — so the starter kit has already decided that a
 * button with an icon is how it wants to look, and then written every other one
 * without one. "Save", "Cancel", "Delete account", "Log out", "Reset password":
 * all text, in a shell whose sidebar, navbar and user menu are nothing but
 * icon-and-label rows.
 *
 * This finishes the job. Each button's own label picks its icon, out of
 * {@see ICONS} — a table of the words the five variations actually put on a
 * button, because that is the set refit can answer for. A label refit has never
 * seen gets no icon and is named in the run report instead of being matched on a
 * guess; "Keep invitation" is deliberately not in the table, there being no icon
 * for declining to cancel something that is not the one for cancelling it.
 *
 * Nothing is asked, because nothing here is a choice the project cannot make for
 * itself. The icons are the kit's own labels read back, and which set they are
 * spelled in was settled by the icon question — {@see IconMap} translates each
 * name the same way the icon pass translates the kit's own, and on a Lucide run
 * the task brings the artwork for the names it is the first to use, because
 * nothing else in the run can know they are coming.
 *
 * It runs ahead of the tasks that write buttons of their own, so an answer given
 * to one of those is the one that stands: a guest's way in shows what
 * {@see OpenLayoutsToGuests} was told it should show, icon or no icon, and is
 * written after this sweep has been and gone.
 *
 * Pass your own table to the constructor to name your application's own buttons.
 * It replaces this one rather than adding to it, so {@see ICONS} is public to
 * spread: labels are matched without regard to case or to how the tag happens to
 * be wrapped, and the names are Heroicons', the set every translation starts
 * from.
 *
 *     Refit::task(new IconButtons([...IconButtons::ICONS, 'publish' => 'cloud-arrow-up']));
 */
final class IconButtons implements Task
{
    /**
     * The button as the kit writes it, which is what is on disk when this is
     * asked whether it applies.
     */
    private const string TAG = 'flux:button';

    /**
     * The attribute a leading icon goes on, which both libraries spell alike.
     *
     * Flux's trailing icon is `icon-trailing` and Sheaf's is `iconAfter`, so the
     * two disagree there; the one in front of the label is `icon` in both, and
     * there is nothing to ask the vocabulary for. What the vocabulary does
     * answer is the wider question of which attributes mean a button has already
     * named an icon — {@see Vocabulary::$nameAttributes}.
     */
    private const string ATTRIBUTE = 'icon';

    /**
     * The icon each button label wants, as Heroicons spells it.
     *
     * Heroicons because that is the set the kit speaks and the one every
     * translation in {@see IconMap} starts from. Every name here is one that map
     * can carry into Lucide and into Phosphor, so the table reads the same
     * whichever set the run is heading for.
     *
     * The labels are the kit's own, lower-cased, across all five variations. A
     * pair that undoes itself gets one drawing and its opposite — "View recovery
     * codes" and "Hide recovery codes" are the eye and the eye crossed out — and
     * a button that removes something gets the trash whatever it is removing,
     * because what the user is about to lose is the part worth drawing.
     *
     * A handful of them are icons the kit chose itself, recorded here rather than
     * decided: the recovery codes already read this way, so a project that wrote
     * one of those buttons bare gets back what the kit would have given it.
     *
     * @var array<string, string>
     */
    public const array ICONS = [
        'accept' => 'check',
        'add passkey' => 'finger-print',
        'back' => 'chevron-left',
        'cancel' => 'x-mark',
        'cancel invitation' => 'trash',
        'confirm' => 'check',
        'continue' => 'chevron-right',
        'create account' => 'user-plus',
        'create team' => 'plus',
        'decline' => 'x-mark',
        'delete account' => 'trash',
        'delete team' => 'trash',
        // The lock for turning it on, and the crossed-out circle for turning it
        // off: an open lock would read as the state rather than as the control.
        'disable 2fa' => 'x-circle',
        'email password reset link' => 'envelope',
        'enable 2fa' => 'lock-closed',
        'hide recovery codes' => 'eye-slash',
        'invite member' => 'user-plus',
        'leave team' => 'arrow-right-start-on-rectangle',
        'log in' => 'arrow-right-end-on-rectangle',
        'log out' => 'arrow-right-start-on-rectangle',
        'new team' => 'plus',
        'regenerate codes' => 'arrow-path',
        'register' => 'user-plus',
        'remove member' => 'trash',
        'remove passkey' => 'trash',
        'resend verification email' => 'envelope',
        'reset password' => 'key',
        'save' => 'check',
        'send invitation' => 'envelope',
        'view recovery codes' => 'eye',
    ];

    /**
     * @param  array<string, string>  $icons  label => the Heroicons name it wants
     * @param  OverrideGenerator  $generator  the Lucide artwork refit bundles, which is what a Flux project draws a Lucide icon from
     */
    public function __construct(
        private readonly array $icons = self::ICONS,
        private readonly OverrideGenerator $generator = new OverrideGenerator,
    ) {}

    public function key(): string
    {
        return 'icon-buttons';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Structure;
    }

    public function label(): string
    {
        return 'Put an icon on the buttons that have none';
    }

    public function hint(): string
    {
        return 'Reads each button\'s label and gives it the icon that goes with it';
    }

    public function appliesTo(Project $project): bool
    {
        $action = $this->action($project, self::TAG);

        foreach ($project->blades() as $path) {
            if ($action->names($project->get($path)) !== []) {
                return true;
            }
        }

        return false;
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $this->bundleArtwork($plan, $project);

        // In the reconcile stage, because the buttons are not settled before it:
        // a target renames every tag in the tree from its own map, and refit's
        // own structure tasks move the files they are written in.
        $plan->add(Stage::Reconcile, $this->action($project, $this->tag($project)));
    }

    /**
     * The sweep, aimed at one spelling of the button.
     *
     * Two spellings are in play across a run: the tag on disk, which is what
     * decides whether the task applies and which artwork it has to bring, and
     * the tag the project ends on, which is what the plan rewrites.
     */
    private function action(Project $project, string $tag): IconLabelledButtons
    {
        return new IconLabelledButtons(
            $tag,
            self::ATTRIBUTE,
            $this->named($project),
            $this->vocabulary($project)->nameAttributes,
        );
    }

    /**
     * The button component by the time the plan runs.
     *
     * Flux's own while the project is staying; whatever the migration renames it
     * to when it is not, read from {@see ComponentMap} rather than written out a
     * second time.
     */
    private function tag(Project $project): string
    {
        if ($project->targets(SheafLibrary::KEY)) {
            return ComponentMap::tag(self::TAG) ?? self::TAG;
        }

        return self::TAG;
    }

    /**
     * How the library this project is ending on names an icon.
     *
     * Flux when nothing has been chosen yet, because Flux is what the kit ships.
     */
    private function vocabulary(Project $project): Vocabulary
    {
        return $project->target?->vocabulary() ?? (new FluxLibrary)->vocabulary();
    }

    /**
     * The table, with every icon spelled in the set the run is heading for.
     *
     * The icon pass has already run by the time the sweep does, so what is
     * written here is the final name rather than one for a later sweep to
     * translate.
     *
     * A label whose icon the target set cannot spell drops out and is reported
     * as a bare button, rather than becoming a name that resolves to nothing.
     *
     * No strategy means nobody has asked the icon question yet — a test, or a
     * task driven by hand. The kit ships Heroicons, so Heroicons is the honest
     * assumption, the same way Flux is for the library.
     *
     * @return array<string, string>
     */
    private function named(Project $project): array
    {
        $named = [];

        foreach ($this->icons as $label => $heroicon) {
            $name = match ($project->icons ?? IconStrategy::Keep) {
                IconStrategy::Keep, IconStrategy::Heroicons => $heroicon,
                IconStrategy::Lucide => $this->lucide($heroicon),
                IconStrategy::Phosphor => $this->phosphor($heroicon),
            };

            if ($name !== null) {
                $named[$label] = $name;
            }
        }

        return $named;
    }

    /**
     * The Lucide name, where refit bundles something to draw it with.
     *
     * The artwork is asked about here rather than when it is written, because a
     * name with no drawing behind it is not a name this task can offer: Flux
     * would fall back to its own Heroicons and leave one button in the set the
     * project has just left.
     */
    private function lucide(string $heroicon): ?string
    {
        $lucide = IconMap::toLucide($heroicon);

        return $lucide !== null && $this->generator->has($lucide) ? $lucide : null;
    }

    /**
     * The Phosphor name, carrying the mark Sheaf's icon component reads a set
     * that is not its default by — the same prefix the Phosphor sweep puts on
     * every other name in the tree.
     */
    private function phosphor(string $heroicon): ?string
    {
        $phosphor = IconMap::toPhosphor($heroicon);

        return $phosphor === null ? null : SheafLibrary::PHOSPHOR_PREFIX.$phosphor;
    }

    /**
     * Bring the artwork for the Lucide icons this task is the first to name.
     *
     * Flux draws Lucide through override files, and the icon pass generates one
     * for every Lucide name the project already uses. A button icon is a name
     * the project does not use yet — refit is what puts it there — so the task
     * that names it is the task that has to bring its drawing.
     *
     * Only the names that are actually going to be written, which is why the
     * tree is read rather than the table: a project with no teams has no "New
     * team" button, and a preview promising a file nothing will draw from is a
     * change nobody needs. An override somebody else is already writing is left
     * to them, for the same reason.
     *
     * Nothing to do on any other run. Heroicons needs no file at all — both
     * libraries resolve a bare name out of the set themselves — and Phosphor
     * arrives as a Composer package with Sheaf.
     */
    private function bundleArtwork(Plan $plan, Project $project): void
    {
        if (($project->icons ?? IconStrategy::Keep) !== IconStrategy::Lucide) {
            return;
        }

        $action = $this->action($project, self::TAG);
        $names = [];

        foreach ($project->blades() as $path) {
            foreach ($action->names($project->get($path)) as $name) {
                $names[$name] = true;
            }
        }

        ksort($names);

        foreach (array_keys($names) as $name) {
            $path = Overrides::icon($name);

            if ($plan->writes($path)) {
                continue;
            }

            $plan->add(Stage::Write, new WriteFile(
                $path,
                $this->generator->render($name),
                sprintf('write  %s (Lucide "%s", for a button icon)', $path, $name),
            ));
        }
    }
}
