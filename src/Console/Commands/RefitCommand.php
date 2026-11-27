<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use JsonException;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Contracts\Asks;
use Onelegstudios\Refit\Contracts\Library;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Plan\Actions\RunProcess;
use Onelegstudios\Refit\Plan\Applier;
use Onelegstudios\Refit\Plan\DependencyFailed;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Project\ProjectDetector;
use Onelegstudios\Refit\Questions\Answers;
use Onelegstudios\Refit\Questions\Question;
use Onelegstudios\Refit\Refit;
use Onelegstudios\Refit\Support\Git;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

/**
 * Refit a freshly installed Livewire starter kit.
 *
 * The command runs in five stages — detect, ask, plan, confirm, apply — and
 * nothing touches disk until the plan has been shown and agreed to.
 */
class RefitCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'refit
        {--dry-run : Show the plan and exit without changing anything}
        {--force : Run even though the git working tree is dirty}
        {--answers= : JSON answers, to run without prompting}';

    /**
     * The command description.
     */
    protected $description = 'Customise the Livewire starter kit around your own conventions.';

    public function handle(Refit $refit, ProjectDetector $detector, Applier $applier): int
    {
        $project = $detector->detect($this->laravel->basePath());

        if (! $this->preflight($project)) {
            return self::FAILURE;
        }

        try {
            $answers = $this->answers();
        } catch (JsonException $exception) {
            $this->components->error('Could not read --answers: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->summarise($project);

        $target = $this->askLibrary($refit, $answers);

        if (! $target instanceof Library) {
            return self::FAILURE;
        }

        $project = $project->targeting($target);

        if (! $this->libraryPreflight($target, $project)) {
            return self::FAILURE;
        }

        $strategy = $this->askIcons($target, $answers);

        // Attached before the tasks are asked anything, so a task's own
        // questions can be written against the icon set the run is heading for.
        $project = $project->withIcons($strategy);

        $tasks = $this->askTasks($refit, $project, $answers);

        $report = new Report;
        $plan = $this->build($refit, $project, $target, $strategy, $tasks, $report);

        if ($plan->isEmpty()) {
            $this->components->info('Nothing to do — the choices you made leave the project as it is.');

            return self::SUCCESS;
        }

        $this->preview($plan);

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->confirmApply($answers)) {
            $this->components->warn('Stopped. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->newLine();

        try {
            $applier->apply($plan, $project, $report, function (Action $action, Closure $run): void {
                $this->line('  <fg=gray>'.$action->describe().'</>');

                // File rewrites finish before a spinner could draw a frame; the
                // installs and Pint can take minutes and print nothing meanwhile.
                if ($action instanceof RunProcess) {
                    spin($run, $action->description());

                    return;
                }

                $run();
            });
        } catch (DependencyFailed $exception) {
            // Dependencies are the first stage, so nothing has been rewritten and
            // there is nothing to unpick — which is the only reason stopping dead
            // is the kind thing to do here.
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->finish($project, $report);

        return self::SUCCESS;
    }

    /**
     * Refuse to run when the project is not in a state refit can safely rewrite.
     */
    private function preflight(Project $project): bool
    {
        if ($project->chiselPending) {
            $this->components->error(
                'This starter kit still has chisel.php — run `php artisan install:features` first, '
                .'so refit sees the file tree you are actually keeping.',
            );

            return false;
        }

        if ($this->option('force')) {
            return true;
        }

        $git = new Git($project->root);

        if (! $git->isRepository()) {
            $this->components->error(
                'Refit rewrites files in place and the changes are one-way. '
                .'This is not a git repository, so there would be no way back — commit to git first, or pass --force.',
            );

            return false;
        }

        if (! $git->isClean()) {
            $this->components->error(
                'Your git working tree has uncommitted changes. Commit or stash them first so '
                .'`git checkout .` can undo this run, or pass --force.',
            );

            return false;
        }

        return true;
    }

    private function summarise(Project $project): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan;options=bold>Starter kit</>', $project->describe());
        $this->components->twoColumnDetail('Views', (string) count($project->blades()).' Blade files');

        foreach ($project->libraries as $library) {
            $this->components->twoColumnDetail(ucfirst($library->key), $library->describe());
        }

        $this->newLine();
    }

    /**
     * Which library the user wants to end on.
     *
     * Asked before icons because it decides what the icon question even means:
     * Flux resolves artwork through a view directory it owns, Sheaf through a
     * component the application owns, and only one of them can offer Lucide.
     *
     * @param  array<string, mixed>|null  $answers
     */
    private function askLibrary(Refit $refit, ?array $answers): ?Library
    {
        $options = $refit->libraryOptions();

        if ($options === []) {
            $this->components->error('No UI libraries are registered — check the `libraries` key in config/refit.php.');

            return null;
        }

        if ($answers !== null) {
            // Flux is the default because it is what the kit ships: an --answers
            // payload written before libraries existed still means "keep Flux".
            $given = $answers['library'] ?? FluxLibrary::KEY;
            $library = $refit->resolveLibrary(is_string($given) ? $given : '');

            if (! $library instanceof Library) {
                $this->components->error(sprintf(
                    'Unknown library [%s]. Registered: %s.',
                    is_string($given) ? $given : gettype($given),
                    implode(', ', array_keys($options)),
                ));

                return null;
            }

            return $library;
        }

        if (count($options) === 1) {
            return $refit->resolveLibrary((string) array_key_first($options));
        }

        $choice = select(
            label: 'Which component library should this project end up on?',
            options: $options,
            default: FluxLibrary::KEY,
            hint: 'Anything other than Flux rewrites every view, and Flux comes out.',
        );

        return $refit->resolveLibrary((string) $choice);
    }

    /**
     * Refuse when the chosen target is not something this project can take yet.
     */
    private function libraryPreflight(Library $target, Project $project): bool
    {
        $problems = $target->preflight($project);

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return $problems === [];
    }

    /**
     * @param  array<string, mixed>|null  $answers
     */
    private function askIcons(Library $target, ?array $answers): IconStrategy
    {
        $strategies = $target->iconStrategies();
        $default = $strategies[0] ?? IconStrategy::Keep;

        if ($answers !== null) {
            $given = $answers['icons'] ?? $default->value;
            $chosen = IconStrategy::tryFrom(is_string($given) ? $given : '');

            return $chosen !== null && in_array($chosen, $strategies, true) ? $chosen : $default;
        }

        if (count($strategies) < 2) {
            return $default;
        }

        $options = [];

        foreach ($strategies as $case) {
            $options[$case->value] = sprintf('%s — %s', $case->label(), $case->hint());
        }

        $choice = select(
            label: 'The kit ships Heroicons with a few Lucide icons vendored in. What would you like?',
            options: $options,
            default: $default->value,
            hint: 'Only the sets your chosen library can resolve are listed.',
        );

        return IconStrategy::from($choice);
    }

    /**
     * Which tasks to run, and whatever else each of them needs to know.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<Task>
     */
    private function askTasks(Refit $refit, Project $project, ?array $answers): array
    {
        $options = $refit->options($project);

        if ($options === []) {
            return [];
        }

        return array_map(
            fn (Task $task): Task => $this->followUp($task, $project, $answers),
            $this->chooseTasks($refit, $options, $answers),
        );
    }

    /**
     * The tasks the user picked, before any of them has been asked anything.
     *
     * @param  array<string, string>  $options
     * @param  array<string, mixed>|null  $answers
     * @return list<Task>
     */
    private function chooseTasks(Refit $refit, array $options, ?array $answers): array
    {
        if ($answers !== null) {
            $given = $answers['tasks'] ?? [];

            return $refit->resolve(is_array($given) ? array_values(array_filter($given, 'is_string')) : []);
        }

        $chosen = multiselect(
            label: 'Which tasks would you like to run?',
            options: $options,
            hint: 'Space to select, enter to confirm. Only tasks that fit this kit are listed.',
        );

        return $refit->resolve(array_values(array_filter($chosen, 'is_string')));
    }

    /**
     * Put a chosen task's own questions to the user, and hand it the answers.
     *
     * Only the tasks the user picked are asked, so nobody answers a question for
     * work that is not going to happen, and the project is settled by now — the
     * target is attached and the icon set chosen, so a question can be narrowed
     * to the project the task is about to rewrite.
     *
     * A task answers its own questions out of `--answers` under a key of its
     * own, beside `library`, `icons` and `tasks`, so one payload drives the whole
     * run. An answer that is missing or not on offer falls back to the question's
     * default, which is also what a question with a single option is given
     * without asking. A question that takes several answers reads a list there
     * and drops the values it never offered one at a time.
     *
     * @param  array<string, mixed>|null  $answers
     */
    private function followUp(Task $task, Project $project, ?array $answers): Task
    {
        if (! $task instanceof Asks) {
            return $task;
        }

        $questions = $task->questions($project);

        if ($questions === []) {
            return $task;
        }

        $given = $answers === null ? null : $answers[$task->key()] ?? null;
        $given = is_array($given) ? $given : [];

        $values = [];

        foreach ($questions as $question) {
            $values[$question->key] = $this->answer(
                $question,
                $given[$question->key] ?? null,
                unprompted: $answers !== null,
            );
        }

        return $task->answered(new Answers($values));
    }

    /**
     * One question's answer — asked, or resolved without asking.
     *
     * A question with a single option is not put, in either shape. There is
     * nothing to choose between, so its one answer is taken as given: a select
     * with one line is a prompt with no decision in it, and a multiselect that
     * insists on an answer can only be answered the one way.
     *
     * The multiselect insists, rather than letting an empty list through, because
     * the task has already been picked by this point. "None of them" is an answer
     * the multiselect question above it already has — leaving the task unchosen.
     *
     * @return string|list<string>
     */
    private function answer(Question $question, mixed $given, bool $unprompted): string|array
    {
        $decided = $unprompted || count($question->options) < 2;

        if ($question->multiple) {
            return $decided
                ? $question->resolveMany($given)
                : array_values(array_filter(multiselect(
                    label: $question->label,
                    options: $question->options,
                    default: $question->defaults(),
                    required: true,
                    hint: $question->hint,
                ), 'is_string'));
        }

        return $decided
            ? $question->resolve($given)
            : (string) select(
                label: $question->label,
                options: $question->options,
                default: $question->default(),
                hint: $question->hint,
            );
    }

    /**
     * @param  list<Task>  $tasks
     */
    private function build(Refit $refit, Project $project, Library $target, IconStrategy $strategy, array $tasks, Report $report): Plan
    {
        $plan = new Plan;

        // Migration first: the icon sweeps rewrite tags, so they have to run
        // against the ones the migration has already produced rather than the
        // Flux ones it replaced.
        $target->planMigration($plan, $project, $strategy, $report);
        $target->planIcons($plan, $project, $strategy, $report);

        // Then the libraries being left clean up after themselves, which they can
        // only do once the tags that used them are gone.
        foreach ($this->leaving($refit, $project, $target) as $library) {
            $library->planTeardown($plan, $project, $report);
        }

        foreach ($tasks as $task) {
            $task->contribute($plan, $project, $report);
        }

        if (! $plan->isEmpty()) {
            $plan->add(Stage::Format, new RunProcess(
                ['./vendor/bin/pint', '--dirty'],
                'Formatting with Pint',
            ));

            // Blade keys a compiled view by the file it was compiled from, and
            // the kit installs `livewire/blaze`, which folds other components'
            // source into that output at compile time. So a compiled view can be
            // stale because a file it folded in changed rather than itself — and
            // a refit run is nothing but that, for every view in the tree. The
            // last thing it does is throw the compiled copies away, so the first
            // page load after it renders what is now on disk.
            $plan->add(Stage::Finish, new RunProcess(
                ['php', 'artisan', 'view:clear'],
                'Clearing the compiled views',
            ));
        }

        return $plan;
    }

    /**
     * The installed libraries this run is leaving behind.
     *
     * Detection records what is on disk; the target is what the user chose. Every
     * library that is one but not the other is being left, and gets to say what
     * that means for the project. In practice that is Flux, because Flux is what
     * the kit ships — but nothing here names it, so the next target is a
     * migration and no cleanup at all.
     *
     * @return list<Library>
     */
    private function leaving(Refit $refit, Project $project, Library $target): array
    {
        $leaving = [];

        foreach ($project->libraries as $install) {
            if ($install->key === $target->key()) {
                continue;
            }

            $library = $refit->resolveLibrary($install->key);

            if ($library instanceof Library) {
                $leaving[] = $library;
            }
        }

        return $leaving;
    }

    private function preview(Plan $plan): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>Refit will make %d change(s):</>', $plan->count()));
        $this->newLine();

        foreach ($plan->grouped() as $stage => $actions) {
            $this->line('  <fg=cyan>'.$stage.'</>');

            foreach ($actions as $action) {
                $this->line('    '.$action->describe());
            }

            $this->newLine();
        }
    }

    /**
     * @param  array<string, mixed>|null  $answers
     */
    private function confirmApply(?array $answers): bool
    {
        if ($answers !== null) {
            return true;
        }

        return confirm(
            label: 'Apply these changes?',
            default: true,
            hint: 'Your working tree is clean, so `git checkout .` undoes everything.',
        );
    }

    private function finish(Project $project, Report $report): void
    {
        $this->newLine();

        foreach ($report->warnings() as $warning) {
            $this->components->warn($warning);
        }

        $notes = $this->laravel->make('config')->get('refit.notes');

        if ($report->hasWarnings() && is_string($notes)) {
            file_put_contents($project->path($notes), $report->toMarkdown());

            $this->components->info("Wrote the details to {$notes}.");
        }

        $this->components->info('Refit done. Review the diff before committing.');

        if ($this->option('answers') !== null) {
            return;
        }

        if (! confirm(label: 'Remove refit from this project now?', default: true)) {
            return;
        }

        $this->removePublishedConfig();

        $this->components->info('Run: composer remove --dev onelegstudios/refit');
    }

    /**
     * Delete the published config, if this project has one.
     *
     * It only ever configured refit itself, so once the package is on its way
     * out the file is a config entry pointing at classes that will not be
     * autoloadable any more.
     */
    private function removePublishedConfig(): void
    {
        $path = $this->laravel->configPath('refit.php');

        if (! is_file($path)) {
            return;
        }

        if (! @unlink($path)) {
            $this->components->warn('Could not delete config/refit.php — remove it by hand.');

            return;
        }

        $this->components->info('Deleted config/refit.php.');
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    private function answers(): ?array
    {
        $raw = $this->option('answers');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
