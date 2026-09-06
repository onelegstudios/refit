<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Icons\IconPlanner;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Plan\Actions\RunProcess;
use Onelegstudios\Refit\Plan\Applier;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Project\ProjectDetector;
use Onelegstudios\Refit\Refit;
use Onelegstudios\Refit\Support\Git;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

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

        $strategy = $this->askIcons($answers);
        $tasks = $this->askTasks($refit, $project, $answers);

        $report = new Report;
        $plan = $this->build($project, $strategy, $tasks, $report);

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

        $applier->apply($plan, $project, $report, function (Action $action): void {
            $this->line('  <fg=gray>'.$action->describe().'</>');
        });

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
        $this->components->twoColumnDetail('Flux', $project->fluxPro ? 'Pro' : 'free tier');
        $this->newLine();
    }

    /**
     * @param  array<string, mixed>|null  $answers
     */
    private function askIcons(?array $answers): IconStrategy
    {
        if ($answers !== null) {
            $given = $answers['icons'] ?? IconStrategy::Keep->value;

            return IconStrategy::tryFrom(is_string($given) ? $given : '') ?? IconStrategy::Keep;
        }

        $options = [];

        foreach (IconStrategy::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        $choice = select(
            label: 'The kit ships Heroicons with a few Lucide icons vendored in. What would you like?',
            options: $options,
            default: IconStrategy::Keep->value,
            hint: 'Lucide also overrides the icons Flux renders inside its own components.',
        );

        return IconStrategy::from($choice);
    }

    /**
     * @param  array<string, mixed>|null  $answers
     * @return list<Task>
     */
    private function askTasks(Refit $refit, Project $project, ?array $answers): array
    {
        $options = $refit->options($project);

        if ($options === []) {
            return [];
        }

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
     * @param  list<Task>  $tasks
     */
    private function build(Project $project, IconStrategy $strategy, array $tasks, Report $report): Plan
    {
        $plan = new Plan;

        (new IconPlanner)->contribute($plan, $project, $strategy, $report);

        foreach ($tasks as $task) {
            $task->contribute($plan, $project, $report);
        }

        if (! $plan->isEmpty()) {
            $plan->add(Stage::Format, new RunProcess(
                ['./vendor/bin/pint', '--dirty'],
                'Formatting with Pint',
            ));
        }

        return $plan;
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
            default: false,
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
