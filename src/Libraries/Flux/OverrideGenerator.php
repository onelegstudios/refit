<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Flux;

use Onelegstudios\Refit\Icons\IconMap;
use RuntimeException;

/**
 * Builds `resources/views/flux/icon/*.blade.php` override files.
 *
 * The template is the one the Livewire starter kit already uses for its vendored
 * Lucide icons, so a generated override is indistinguishable from the ones
 * Laravel ships — same credit comment, same `variant` prop, same `Flux::classes()`
 * sizing and stroke-width tables.
 *
 * Lucide artwork is bundled with refit rather than pulled from a Blade Icons
 * package: refit only ever generates the names in {@see IconMap}, so a package
 * dependency would add weight without widening what the tool can translate. It
 * would also make the output vary with whatever Composer resolved, when the point
 * is to emit the same drawing the kit already vendors. `composer icons` refreshes
 * the bundle from upstream as a reviewable commit instead.
 */
final class OverrideGenerator
{
    private const string PATHS_PLACEHOLDER = '__PATHS__';

    private const string CLASSES_PLACEHOLDER = '__CLASSES__';

    /**
     * The classes every override starts from, before {@see OwnedIcons::EXTRA_CLASSES}.
     */
    private const string BASE_CLASSES = 'shrink-0';

    public function __construct(
        private readonly string $iconDirectory = __DIR__.'/../../../resources/icons/lucide',
        private readonly string $stubPath = __DIR__.'/../../../stubs/flux-icon.blade.php.stub',
    ) {}

    public function has(string $lucideName): bool
    {
        return is_file($this->iconDirectory.'/'.$lucideName.'.svg');
    }

    /**
     * Render the Flux override for a Lucide icon.
     *
     * @param  string|null  $overrideName  The name the file is written at, when it
     *                                     is not the Lucide one. Noted in the
     *                                     output so the reason the file stands in
     *                                     for another name is obvious months later.
     */
    public function render(string $lucideName, ?string $overrideName = null): string
    {
        $overrideName ??= $lucideName;
        $svg = $this->svg($lucideName);
        $stub = @file_get_contents($this->stubPath);

        if ($stub === false) {
            throw new RuntimeException("Unable to read the icon stub at [{$this->stubPath}].");
        }

        $rendered = str_replace(
            [self::PATHS_PLACEHOLDER, self::CLASSES_PLACEHOLDER],
            [$this->paths($svg), $this->classes($overrideName)],
            $stub,
        );

        if ($overrideName === $lucideName) {
            return $rendered;
        }

        return str_replace(
            '{{-- Credit: Lucide (https://lucide.dev) --}}',
            sprintf(
                "{{-- Credit: Lucide (https://lucide.dev) --}}\n{{-- Lucide's \"%s\", overriding the %s. --}}",
                $lucideName,
                OwnedIcons::owns($overrideName)
                    ? 'icon Flux draws itself'
                    : 'Heroicons name Flux resolves internally',
            ),
            $rendered,
        );
    }

    /**
     * The class list the override applies, including anything the artwork alone
     * cannot carry — Lucide's spinner is a still drawing, so it needs `animate-spin`.
     */
    private function classes(string $overrideName): string
    {
        $extra = OwnedIcons::extraClasses($overrideName);

        return $extra === null ? self::BASE_CLASSES : self::BASE_CLASSES.' '.$extra;
    }

    private function svg(string $lucideName): string
    {
        $path = $this->iconDirectory.'/'.$lucideName.'.svg';
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Refit does not bundle the Lucide icon [{$lucideName}].");
        }

        return $contents;
    }

    /**
     * Pull the drawing commands out of a Lucide SVG and indent them to match the
     * surrounding template.
     */
    private function paths(string $svg): string
    {
        if (preg_match('/<svg[^>]*>(.*)<\/svg>/s', $svg, $matches) !== 1) {
            throw new RuntimeException('Unexpected SVG layout — no <svg> element found.');
        }

        $lines = preg_split('/\R/', trim($matches[1]));

        if ($lines === false) {
            return '';
        }

        $indented = array_map(
            static fn (string $line): string => $line === '' ? '' : '    '.trim($line),
            $lines,
        );

        return implode(PHP_EOL, $indented);
    }
}
