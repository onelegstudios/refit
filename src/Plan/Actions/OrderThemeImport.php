<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Put Sheaf's theme import back underneath Tailwind's, where a layer survives.
 *
 * `sheaf:init` prepends its own stylesheet to the top of `resources/css/app.css`,
 * so the file opens on `@import './theme.css';` and only then imports Tailwind.
 *
 * Anything ahead of `@import 'tailwindcss'` moves the point at which Tailwind can
 * open `@layer theme`, and the `:root, :host` block holding every theme variable
 * is emitted before it — unlayered. Unlayered declarations beat layered ones no
 * matter the order, so every `.dark { --color-* }` override written into
 * `@layer theme` stops applying: the kit's `--color-accent-content` trio, and
 * Sheaf's own `--color-primary` trio with it.
 *
 * What that looks like is the logo. The kit's mark is `text-white dark:text-black`
 * on a tile of `bg-accent-content`, and the tile only turns white in dark mode
 * because of an override that no longer lands — so the mark goes black on a tile
 * that stayed dark, and the logo disappears at the one setting the layouts hard-code.
 *
 * Moving the line below Tailwind's import is the whole fix. Nothing else about the
 * stylesheet changes, and the import keeps whatever path and comment Sheaf gave it.
 */
final class OrderThemeImport implements Action
{
    public const string STYLESHEET = 'resources/css/app.css';

    /** Tailwind's own import, and the line everything else has to follow. */
    private const string TAILWIND = '/^\s*@import\s+["\']tailwindcss["\']/';

    /** Sheaf's, wherever `sheaf:init` decided to put the file. */
    private const string THEME = '/^\s*@import\s+["\'][^"\']*theme\.css["\']/';

    public function describe(): string
    {
        return sprintf('edit   %s — move Sheaf\'s theme import below Tailwind\'s', self::STYLESHEET);
    }

    /**
     * Is the theme import currently ahead of Tailwind's?
     *
     * Planning calls this only when the stylesheet is already there to read. When
     * refit is the one running `sheaf:init`, the import does not exist yet and the
     * action is planned unconditionally — {@see apply()} is a no-op when it turns
     * out to be in the right place.
     */
    public static function misordered(string $source): bool
    {
        $lines = self::lines($source);
        $theme = self::locate($lines, self::THEME);
        $tailwind = self::locate($lines, self::TAILWIND);

        return $theme !== null && $tailwind !== null && $theme < $tailwind;
    }

    public function apply(Project $project, Report $report): void
    {
        if (! $project->exists(self::STYLESHEET)) {
            $report->warn('Skipped ordering the theme import — '.self::STYLESHEET.' does not exist.');

            return;
        }

        $source = $project->get(self::STYLESHEET);
        $lines = self::lines($source);
        $theme = self::locate($lines, self::THEME);
        $tailwind = self::locate($lines, self::TAILWIND);

        if ($theme === null || $tailwind === null || $theme > $tailwind) {
            return;
        }

        $import = $lines[$theme];

        unset($lines[$theme]);
        $lines = array_values($lines);

        // One line lighter above it, so Tailwind's import has shifted up by one.
        array_splice($lines, $tailwind, 0, [$import]);

        file_put_contents($project->path(self::STYLESHEET), implode(PHP_EOL, $lines));

        $report->changed(self::STYLESHEET);
        $report->note(
            'Moved Sheaf\'s theme import below Tailwind\'s in '.self::STYLESHEET
            .'. Above it, Tailwind emits its theme variables outside @layer theme, '
            .'and the dark-mode overrides for --color-accent-* and --color-primary-* never apply.',
        );
    }

    /**
     * @return list<string>
     */
    private static function lines(string $source): array
    {
        $lines = preg_split('/\R/', $source);

        return $lines === false ? [] : $lines;
    }

    /**
     * The first line matching a pattern, or null when nothing does.
     *
     * @param  list<string>  $lines
     */
    private static function locate(array $lines, string $pattern): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index;
            }
        }

        return null;
    }
}
