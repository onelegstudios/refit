<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Flux;

use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Icons\IconScanner;
use Onelegstudios\Refit\Icons\IconStrategy;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\DeleteFile;
use Onelegstudios\Refit\Plan\Actions\DropSolidIconVariant;
use Onelegstudios\Refit\Plan\Actions\RewriteIconNames;
use Onelegstudios\Refit\Plan\Actions\WriteFile;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Plan\Stage;
use Onelegstudios\Refit\Project\Project;

/**
 * Turns an icon strategy into plan actions, for a project staying on Flux.
 *
 * The two directions are not symmetric. Going to Heroicons is subtraction: delete
 * the vendored Lucide overrides and point their usages at names Flux already
 * ships. Going to Lucide is generation, and has to cover the icons Flux renders
 * from inside its own components as well as the ones in application code.
 *
 * Both directions lean on Flux resolving an icon by bare name and letting a Blade
 * file at `resources/views/flux/icon/{name}.blade.php` take precedence — a
 * mechanism no other library refit knows about has, which is why this planner
 * belongs to Flux rather than to icons in general.
 */
final class IconPlanner
{
    private const string OVERRIDE_DIRECTORY = 'resources/views/flux/icon';

    public function __construct(
        private readonly Vocabulary $vocabulary,
        private readonly IconScanner $scanner,
        private readonly OverrideGenerator $generator = new OverrideGenerator,
    ) {}

    public function contribute(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void
    {
        match ($strategy) {
            IconStrategy::Heroicons => $this->planHeroicons($plan, $project, $report),
            IconStrategy::Lucide => $this->planLucide($plan, $project, $report),
            default => $report->note('Icons left as they are — Heroicons with the kit\'s vendored Lucide overrides.'),
        };
    }

    /**
     * Existing override files, keyed by the icon name they define.
     *
     * @return array<string, string> Name mapped to its project-relative path.
     */
    public function existingOverrides(Project $project): array
    {
        $directory = $project->path(self::OVERRIDE_DIRECTORY);

        if (! is_dir($directory)) {
            return [];
        }

        $matches = glob($directory.'/*.blade.php');
        $overrides = [];

        foreach ($matches === false ? [] : $matches as $path) {
            $name = basename($path, '.blade.php');

            $overrides[$name] = self::OVERRIDE_DIRECTORY.'/'.basename($path);
        }

        ksort($overrides);

        return $overrides;
    }

    private function planHeroicons(Plan $plan, Project $project, Report $report): void
    {
        $renames = [];

        foreach ($this->existingOverrides($project) as $name => $path) {
            $heroicon = IconMap::toHeroicons($name);

            if ($heroicon === null) {
                $report->warn(sprintf(
                    'Kept %s — refit has no Heroicons equivalent for "%s".',
                    $path,
                    $name,
                ));

                continue;
            }

            $renames[$name] = $heroicon;

            $plan->add(Stage::Move, new DeleteFile(
                $path,
                sprintf('delete %s (now Heroicons "%s")', $path, $heroicon),
            ));
        }

        if ($renames === []) {
            $report->note('No Lucide overrides found — the kit is already all Heroicons.');

            return;
        }

        $plan->add(Stage::Reconcile, new RewriteIconNames($renames, 'Lucide to Heroicons', $this->vocabulary));
    }

    private function planLucide(Plan $plan, Project $project, Report $report): void
    {
        $renames = [];

        /** @var array<string, string> $overrides Override filename mapped to bundled Lucide art. */
        $overrides = [];

        /** @var list<string> $translated Names, as the views write them today, that end up drawn by Lucide. */
        $translated = [];

        foreach ($this->scanner->scan($project) as $name => $paths) {
            // Names the kit already vendors as Lucide need art, but no rewrite.
            if ($this->generator->has($name) && self::toLucide($name) === null) {
                $overrides[$name] = $name;
                $translated[] = $name;

                continue;
            }

            $lucide = self::toLucide($name);

            if ($lucide === null) {
                $report->warn(sprintf(
                    'No Lucide translation for "%s" — still Heroicons in %s.',
                    $name,
                    implode(', ', $paths),
                ));

                continue;
            }

            // A translation refit has no artwork for cannot be written, and the
            // rename has to be dropped with it: pointing a usage at an override
            // that never gets written would leave a blank where the icon was.
            if (! $this->generator->has($lucide)) {
                $report->warn(sprintf(
                    'No Lucide artwork bundled for "%s" — "%s" stays Heroicons in %s.',
                    $lucide,
                    $name,
                    implode(', ', $paths),
                ));

                continue;
            }

            $translated[] = $name;

            // Flux draws this one itself, so the override keeps Flux's name and
            // the usages are left as they are.
            if (OwnedIcons::owns($name)) {
                $overrides[$name] = $lucide;

                continue;
            }

            if ($lucide !== $name) {
                $renames[$name] = $lucide;
            }

            $overrides[$lucide] = $lucide;
        }

        foreach ($this->internals($project, $report) as $name) {
            $lucide = self::toLucide($name);

            if ($lucide === null || ! $this->generator->has($lucide)) {
                continue;
            }

            // Keyed by the name Flux's own markup asks for, since refit cannot
            // rewrite code inside the vendor directory.
            $overrides[$name] = $lucide;
        }

        ksort($overrides);

        foreach ($overrides as $filename => $art) {
            $plan->add(Stage::Write, new WriteFile(
                sprintf('%s/%s.blade.php', self::OVERRIDE_DIRECTORY, $filename),
                $this->generator->render($art, overrideName: $filename),
                sprintf(
                    'write  %s/%s.blade.php%s',
                    self::OVERRIDE_DIRECTORY,
                    $filename,
                    $filename === $art ? '' : sprintf(
                        ' (Lucide "%s", %s)',
                        $art,
                        OwnedIcons::owns($filename) ? 'in place of Flux\'s own' : 'for Flux internals',
                    ),
                ),
            ));
        }

        // Ahead of the rename, so it reads the names the views still carry.
        if ($translated !== []) {
            $plan->add(Stage::Reconcile, new DropSolidIconVariant($translated, $this->vocabulary));
        }

        if ($renames !== []) {
            $plan->add(Stage::Reconcile, new RewriteIconNames($renames, 'Heroicons to Lucide', $this->vocabulary));
        }
    }

    /**
     * The Lucide artwork that stands in for a name the views write today.
     *
     * Two sources, because they answer different questions. {@see IconMap} knows
     * what a Heroicon is called in Lucide; {@see OwnedIcons} knows what to draw
     * for the handful of names Flux resolves from neither set.
     */
    private static function toLucide(string $name): ?string
    {
        return IconMap::toLucide($name) ?? OwnedIcons::artwork($name);
    }

    /**
     * Icon names Flux renders from inside its own components.
     *
     * @return list<string>
     */
    private function internals(Project $project, Report $report): array
    {
        $scanned = [];

        foreach (Internals::STUB_DIRECTORIES as $relative) {
            foreach ($this->scanner->scanStubDirectory($project->path($relative)) as $name) {
                $scanned[] = $name;
            }
        }

        $scanned = array_values(array_unique($scanned));

        sort($scanned);

        if ($scanned !== []) {
            $untranslatable = array_values(array_filter(
                $scanned,
                static fn (string $name): bool => self::toLucide($name) === null,
            ));

            if ($untranslatable !== []) {
                $report->warn(sprintf(
                    'Flux renders these itself and refit has no Lucide translation, so they stay Heroicons: %s.',
                    implode(', ', $untranslatable),
                ));
            }

            return $scanned;
        }

        // Nothing to scan. The recorded list covers both editions, so a project
        // that turns out to be free-Flux-only gets a couple of overrides it never
        // needed — harmless, where a missing one leaves a stray Heroicon behind.
        $report->note('Flux is not installed here, so refit used its recorded list of the icons Flux renders internally.');

        return Internals::names();
    }
}
