<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

use Onelegstudios\Refit\Contracts\Asks;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\Sheaf\ComponentMap;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\OfferDeveloperLogins;
use Onelegstudios\Refit\Plan\Actions\RunProcess;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;
use Onelegstudios\Refit\Support\Composer;

/**
 * Offer one-click developer logins under the login form.
 *
 * Typing a password into your own application a dozen times a day is a tax the
 * kit leaves you paying, and the usual answer is a seeder plus a password you
 * have memorised. `spatie/laravel-login-link` is the better one: a link that
 * signs you in as whoever it names. The task installs it as a dev dependency and
 * writes the links into the login view, inside `@env('local')`.
 *
 * Which accounts is asked rather than decided, because the project cannot answer
 * it. The kit seeds exactly one user — `test@example.com`, from
 * `DatabaseSeeder` — and everything beyond that is about how you work: an admin
 * to check the other half of a permission with, an empty account to see onboarding
 * through a new user's eyes, somebody who has not confirmed their address yet, or
 * whoever happens to be first in the table after a fresh migrate.
 *
 * Only the ones that mean something here are offered, the same way the task
 * itself is only offered to a kit it fits: the unverified login is narrowed out of
 * the question where nothing stops an unverified user, because landing somewhere
 * else is the entire point of it.
 *
 * It is one question with several answers rather than one question per account,
 * because the accounts do not exclude each other — see {@see Question}. Nothing
 * here needs the account to exist first: the package creates a user it cannot
 * find through the model's factory, so a link is a login the first time it is
 * clicked and the same login every time after.
 *
 * The exception is {@see ACCOUNTS}' `new`, which is deliberately a different
 * account on every render — a random local part means the package never finds it,
 * so every click is a brand-new user with nothing behind it. That is the one
 * thing a seeded account cannot give you, and it costs a row in the users table
 * each time you use it.
 *
 * The two accounts you come back to are named, through `:user-attributes`. The
 * kit's `UserFactory` fills the name with `fake()->name()`, so without this an
 * admin login is an admin called Rosalyn Bartoletti and the name on the user menu
 * is noise — "Admin User" is the whole point of having a second account. The
 * package passes those attributes to the factory, so the name is what the row is
 * created with.
 *
 * `new` is left to the factory on purpose, name and all. It is standing in for
 * somebody who has just signed up, and a signup is not an account refit named in
 * advance — a random name alongside the random address is closer to the thing it
 * is imitating, and there is nothing to come back to anyway.
 *
 * The package passes those attributes to the lookup as well, which is the one
 * thing to know about the table below: a named account is found by its name *and*
 * its address, so a row at that address under a different name is not a match,
 * and the package would try to create a second one at an address the users table
 * holds unique. The names here are chosen around that — `test` is spelled exactly
 * as the kit's own `DatabaseSeeder` writes it, so the seeded row matches it, and
 * `admin` is an address a fresh kit has nothing at. Rename one in the view
 * afterwards and rename it in your seeder too.
 *
 * Nothing is offered on the WorkOS variations, which have no login view to put a
 * link in: the kit redirects to AuthKit and the password never reaches the
 * application at all.
 */
final class LoginLinks implements Asks, Task
{
    public const string PACKAGE = 'spatie/laravel-login-link';

    /**
     * The accounts on offer, and the line each is shown as.
     *
     * @var array<string, string>
     */
    public const array ACCOUNTS = [
        'test' => 'Test User — test@example.com, the account the kit seeds',
        'admin' => 'Admin User — admin@example.com',
        'new' => 'A brand-new user — a random address and name, so every click is a fresh account',
        self::UNVERIFIED => 'Unverified User — lands on the "verify your email" screen instead of the dashboard',
        'first' => 'Whoever is first in the users table',
    ];

    /**
     * The account that only means something where something enforces
     * verification, and so the only one that is narrowed out of the question.
     */
    private const string UNVERIFIED = 'unverified';

    /**
     * Where the contract that makes an unverified account mean anything is
     * declared.
     */
    private const string USER_MODEL = 'app/Models/User.php';

    /**
     * Sheaf's name for its card, which is both the tag's suffix and what
     * `sheaf:install` is asked for.
     */
    private const string SHEAF_CARD = 'card';

    /**
     * The two components the block is built from, per library: the card it sits
     * in and the heading over it, each as a tag and the props it is written with.
     *
     * Two spellings rather than one, because this is written in the same pass
     * that renames Flux's tags and after it — nothing downstream would translate
     * a `flux:card` into Sheaf's.
     *
     * Sheaf's card takes no props worth passing: its `size` is a max width rather
     * than a padding, its default is already wider than the column the login form
     * sits in, and it has no variants — the surface it paints is Sheaf's own,
     * which is the reason to be using it.
     *
     * Its heading does take one, because the two libraries put the same words at
     * different points on the scale: Flux's `base` is 14px, and `xs` is where
     * Sheaf writes that. {@see ComponentMap::VALUES} is where the translation is
     * recorded, for the headings the kit itself wrote — this is the same step,
     * taken by hand because the pass that would have taken it has already run by
     * the time this is written.
     *
     * @var array<string, array{array{string, string}, array{string, string}}>
     */
    private const array COMPONENTS = [
        FluxLibrary::KEY => [
            OfferDeveloperLogins::FLUX_CARD,
            OfferDeveloperLogins::FLUX_HEADING,
        ],
        SheafLibrary::KEY => [
            [ComponentMap::PREFIX.self::SHEAF_CARD, ''],
            [ComponentMap::PREFIX.'heading', 'size="xs"'],
        ],
    ];

    /**
     * The one account that is already there, and so the only one a run that
     * answers nothing can be sure of.
     *
     * @var list<string>
     */
    public const array DEFAULT = ['test'];

    /**
     * The link each account is written as.
     *
     * `first` is the one with no attributes on it at all, because "whoever is
     * first in the users table" is what the package does when you tell it
     * nothing — naming that one would turn it into another account. `new` carries
     * an address and no name, which is the factory's to fill.
     *
     * `unverified` carries a name and a null `email_verified_at` and no address,
     * which is what makes it the one link you can use twice. The lookup becomes
     * `where name = ? and email_verified_at is null`, so it comes back to the same
     * unverified account while it is unverified — and the moment you verify it,
     * which is the whole reason to land there, it stops matching and the factory
     * invents the next one. An address of its own would turn that into a unique
     * constraint on the second click instead.
     *
     * Each label is the account rather than a sentence, because the card the
     * sweep writes puts "Log in as" above the row and the verb would be said five
     * times under it otherwise. The two named accounts wear their names, capital
     * U and all; the three refit cannot name are described.
     *
     * The labels are not wrapped in `__()`, unlike every other string the kit
     * renders. These are developer chrome: they appear in one environment, for
     * the person who installed the package, and a translation file is not where
     * anyone would look for them.
     *
     * @var array<string, string>
     */
    private const array LINKS = [
        'test' => '<x-login-link email="test@example.com" :user-attributes="[\'name\' => \'Test User\']" label="Test User" />',
        'admin' => '<x-login-link email="admin@example.com" :user-attributes="[\'name\' => \'Admin User\']" label="Admin User" />',
        'new' => '<x-login-link :email="\'new-\'.Str::random(8).\'@example.com\'" label="New user" />',
        self::UNVERIFIED => '<x-login-link :user-attributes="[\'name\' => \'Unverified User\', \'email_verified_at\' => null]" label="Unverified user" />',
        'first' => '<x-login-link label="First user" />',
    ];

    /**
     * @param  list<string>  $accounts  which of {@see ACCOUNTS} to offer a link for
     */
    public function __construct(private readonly array $accounts = self::DEFAULT) {}

    public function key(): string
    {
        return 'login-links';
    }

    public function group(): TaskGroup
    {
        return TaskGroup::Tooling;
    }

    public function label(): string
    {
        return 'Offer developer logins on the login page';
    }

    public function hint(): string
    {
        return 'Installs spatie/laravel-login-link and puts one-click logins under the form, in local only';
    }

    public function appliesTo(Project $project): bool
    {
        foreach ($project->blades() as $path) {
            $source = $project->get($path);

            if (str_contains($source, OfferDeveloperLogins::MARKER)
                && ! str_contains($source, '<'.OfferDeveloperLogins::COMPONENT)) {
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
            key: 'accounts',
            label: 'Which developer logins should the login page offer?',
            options: $this->offered($project),
            default: self::DEFAULT,
            hint: 'Space to select, enter to confirm. An account that is not there yet is created the first time you use its link.',
            multiple: true,
        )];
    }

    /**
     * The accounts that mean something on this project.
     *
     * All of them, bar one: an unverified login is only somewhere else to land if
     * something stops an unverified user getting to the dashboard. Where nothing
     * does, it is a login like any other wearing a name that says otherwise.
     *
     * @return array<string, string>
     */
    private function offered(Project $project): array
    {
        return $this->verifies($project)
            ? self::ACCOUNTS
            : array_diff_key(self::ACCOUNTS, [self::UNVERIFIED => '']);
    }

    /**
     * Does this project hold a user to their address?
     *
     * The user model is what decides it, not the routes: Laravel's `verified`
     * middleware waves through a user model that does not implement
     * `MustVerifyEmail`, so the kit's routes are guarded either way and only
     * the contract says whether the guard does anything.
     *
     * Read as a class declaration rather than a mention, because the WorkOS
     * variations ship the import commented out above a class that does not
     * implement it — a project refit would otherwise read as verifying on the
     * strength of a comment.
     */
    private function verifies(Project $project): bool
    {
        return preg_match(
            '/\bclass\s+User\b[^{]*\bimplements\b[^{]*\bMustVerifyEmail\b/',
            $project->get(self::USER_MODEL),
        ) === 1;
    }

    public function answered(Answers $answers): Task
    {
        return new self($answers->many('accounts'));
    }

    public function contribute(Plan $plan, Project $project, Report $report): void
    {
        $links = $this->links();

        if ($links === []) {
            // Not reachable through a prompt or through --answers: the question
            // insists on an answer, and an unknown one falls back to the default.
            // This is the task driven by hand, with accounts refit does not have
            // a link for — worth a line rather than a silent no-op, and worth it
            // before the install rather than after, because installing a package
            // to use it nowhere is not a thing to do quietly.
            $report->warn(sprintf(
                'Left the login page alone — none of the accounts it was given are ones refit offers (%s).',
                implode(', ', array_keys(self::ACCOUNTS)),
            ));

            return;
        }

        if (! (new Composer($project->root))->has(self::PACKAGE)) {
            // Required, because the view this plan is about to rewrite points at
            // a component the package owns. A login page with a tag nothing
            // resolves is worse than one with no link on it, and the dependency
            // stage runs before a single file has changed.
            $plan->add(Stage::Dependencies, new RunProcess(
                ['composer', 'require', self::PACKAGE, '--dev', '--no-interaction'],
                'Installing '.self::PACKAGE,
                required: true,
            ));
        }

        $this->bringCard($plan, $project);

        [$card, $heading] = $this->components($project);

        // In the reconcile stage, because the login view is not settled before
        // it: the auth views move out of `pages/` in the move stage, and the
        // sweep has to read the tree that move left behind.
        $plan->add(Stage::Reconcile, new OfferDeveloperLogins($links, $card, $heading));
    }

    /**
     * The card and the heading the block is written with, as the library the run
     * ends on spells them.
     *
     * No target means nobody has asked the library question yet — a test, or a
     * task driven by hand. The kit ships Flux, so Flux is the honest assumption.
     *
     * @return array{array{string, string}, array{string, string}}
     */
    private function components(Project $project): array
    {
        return self::COMPONENTS[$project->target?->key() ?? FluxLibrary::KEY]
            ?? self::COMPONENTS[FluxLibrary::KEY];
    }

    /**
     * Bring the card component on a run that will not otherwise have one.
     *
     * Flux's is in the free edition, so a Flux project already has it. Sheaf's
     * arrives one component at a time, and the migration only installs the ones
     * the kit's own views need — a card is not among them, because the kit builds
     * its cards out of divs. So the task that names it is the task that has to
     * bring it, the same way the settings icons bring their own artwork.
     *
     * Required, for the same reason the package install is: the view this plan
     * writes points at the component, and a login page wrapped in a tag nothing
     * resolves renders no page at all.
     */
    private function bringCard(Plan $plan, Project $project): void
    {
        if (! $project->targets(SheafLibrary::KEY)
            || SheafLibrary::hasComponent($project, self::SHEAF_CARD)) {
            return;
        }

        $command = ['php', 'artisan', 'sheaf:install', self::SHEAF_CARD, '--no-interaction'];

        // A folder with the file missing. Sheaf asks what to do about a component
        // that already exists and cannot answer its own question without a
        // terminal, and --force is the only flag that skips it.
        if ($project->exists(SheafLibrary::COMPONENT_DIRECTORY.'/'.self::SHEAF_CARD)) {
            $command[] = '--force';
        }

        $plan->add(Stage::Dependencies, new RunProcess(
            $command,
            sprintf('Installing Sheaf\'s "%s"', self::SHEAF_CARD),
            required: true,
        ));
    }

    /**
     * The chosen accounts' links, in the order they are offered in.
     *
     * Read off {@see LINKS} rather than the answer, so the block reads the same
     * way whichever order they were picked in.
     *
     * @return list<string>
     */
    private function links(): array
    {
        $links = [];

        foreach (self::LINKS as $account => $link) {
            if (in_array($account, $this->accounts, true)) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
