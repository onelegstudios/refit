<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Contracts\Action;
use Onelegstudios\Refit\Plan\RenameLedger;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Tasks\KeepOneLayout;
use RuntimeException;

/**
 * Empty one layout family's folder into the layouts directory.
 *
 * The variant the delegating layout renders is folded into it, because the
 * delegating layout is a wrapper and nothing else:
 *
 *     <x-layouts::app.sidebar :title="$title ?? null">
 *         <flux:main>{{ $slot }}</flux:main>
 *     </x-layouts::app.sidebar>
 *
 * so the result is the variant with what the wrapper put inside it standing where
 * the variant's `{{ $slot }}` was. Every other variant moves up beside it as
 * `layouts/<family>-<name>.blade.php` and is recorded in the ledger, which the
 * reconcile pass reads to repoint whatever rendered it.
 *
 * The folder is read when this runs rather than when it was planned, because an
 * earlier stage may still have changed it — a target library writes the variant
 * from a stub, a task edits the wrapper, and {@see KeepOneLayout} deletes the
 * variants nothing renders. Flattening what is left is the same work whether that
 * task ran or not.
 *
 * Only the fold is refused when it would not survive: an attribute that is not
 * the variable of the same name passed straight through, a named slot, markup
 * around the wrapper, a variant that declares `@props` or reads `$attributes`, or
 * a second view rendering the variant itself. That is reported, and the variant
 * is flattened like any other — the folder still goes, and the delegating layout
 * still renders it, under the name it now has.
 */
final class FlattenLayoutFamily implements Action
{
    private const string DIRECTORY = 'resources/views/layouts';

    private const string INDENT = '    ';

    private const string SLOT = '/\{\{\s*\$slot\s*\}\}/';

    public function __construct(
        private readonly string $family,
        private readonly RenameLedger $ledger,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return sprintf(
            'fold   %s into %s and flatten the rest beside it',
            $this->directory(),
            $this->delegate(),
        );
    }

    public function apply(Project $project, Report $report): void
    {
        if (! is_dir($project->path($this->directory()))) {
            return;
        }

        $folded = $this->fold($project, $report);

        foreach ($this->variants($project) as $name) {
            if ($name !== $folded) {
                $this->flatten($project, $report, $name);
            }
        }
    }

    /**
     * Fold the rendered variant into the layout that renders it.
     *
     * @return string|null the variant it consumed, if it folded one
     */
    private function fold(Project $project, Report $report): ?string
    {
        $name = $this->rendered($project);

        if ($name === null) {
            return null;
        }

        $wrapper = $project->get($this->delegate());
        $variant = $project->get($this->variant($name));
        $element = $this->wrapperElement($wrapper, $this->component($name));

        if ($element === null) {
            $this->refuse($report, $name, sprintf('it is not the only thing %s renders', $this->delegate()));

            return null;
        }

        $problem = match (true) {
            ! $this->passesThrough($element) => sprintf(
                '%s passes it attributes other than its own variables',
                $this->delegate(),
            ),
            str_contains($this->inside($wrapper, $element), '<x-slot') => sprintf(
                '%s fills a named slot',
                $this->delegate(),
            ),
            str_contains($variant, '@props') || str_contains($variant, '$attributes') => 'it reads props or attributes the wrapper does not have',
            preg_match_all(self::SLOT, $variant) !== 1 => 'it does not render {{ $slot }} exactly once',
            $this->renderedElsewhere($project, $name) !== [] => sprintf(
                '%s renders it too',
                implode(' and ', $this->renderedElsewhere($project, $name)),
            ),
            default => null,
        };

        if ($problem !== null) {
            $this->refuse($report, $name, $problem);

            return null;
        }

        $inlined = (string) preg_replace_callback(
            '/^([ \t]*)'.substr(self::SLOT, 1, -1).'/m',
            fn (array $match): string => $match[1].$this->indent($this->body($wrapper, $element), $match[1]),
            $variant,
            1,
        );

        file_put_contents($project->path($this->delegate()), $inlined);
        unlink($project->path($this->variant($name)));

        $report->changed($this->delegate());
        $report->note(sprintf('Folded %s into %s', $this->variant($name), $this->delegate()));

        return $name;
    }

    /**
     * Move a variant up beside the layouts, and record where it went.
     */
    private function flatten(Project $project, Report $report, string $name): void
    {
        $from = $this->variant($name);
        $to = sprintf('%s/%s-%s.blade.php', self::DIRECTORY, $this->family, $name);

        if ($project->exists($to)) {
            $report->warn("Left {$from} alone: {$to} already exists.");

            return;
        }

        if (! rename($project->path($from), $project->path($to))) {
            throw new RuntimeException("Unable to move [{$from}] to [{$to}].");
        }

        $this->ledger->record($this->component($name), sprintf('x-layouts::%s-%s', $this->family, $name));

        $report->note("Moved {$from} to {$to}");
    }

    private function refuse(Report $report, string $name, string $problem): void
    {
        $report->warn(sprintf(
            'Did not fold %s into %s — %s. It was flattened instead, and %s still renders it.',
            $this->variant($name),
            $this->delegate(),
            $problem,
            $this->delegate(),
        ));
    }

    private function directory(): string
    {
        return sprintf('%s/%s', self::DIRECTORY, $this->family);
    }

    private function delegate(): string
    {
        return sprintf('%s/%s.blade.php', self::DIRECTORY, $this->family);
    }

    private function variant(string $name): string
    {
        return sprintf('%s/%s.blade.php', $this->directory(), $name);
    }

    private function component(string $name): string
    {
        return sprintf('x-layouts::%s.%s', $this->family, $name);
    }

    /**
     * The variants still in the folder, in name order.
     *
     * @return list<string>
     */
    private function variants(Project $project): array
    {
        $matches = glob($project->path($this->directory()).'/*.blade.php');

        if ($matches === false) {
            return [];
        }

        sort($matches);

        return array_map(static fn (string $path): string => basename($path, '.blade.php'), $matches);
    }

    /**
     * The variant the delegating layout renders, if it is still a file.
     */
    private function rendered(Project $project): ?string
    {
        if (! $project->exists($this->delegate())) {
            return null;
        }

        $pattern = sprintf('/<x-layouts::%s\.([a-z0-9-]+)/', preg_quote($this->family, '/'));

        if (preg_match($pattern, $project->get($this->delegate()), $matches) !== 1) {
            return null;
        }

        return $project->exists($this->variant($matches[1])) ? $matches[1] : null;
    }

    /**
     * Views other than the delegating layout that render the variant themselves,
     * and would be left pointing at a file the fold deletes.
     *
     * @return list<string>
     */
    private function renderedElsewhere(Project $project, string $name): array
    {
        $pattern = '/<'.preg_quote($this->component($name), '/').'[\s>\/]/';
        $found = [];

        foreach ($project->blades() as $path) {
            if ($path !== $this->delegate() && preg_match($pattern, $project->get($path)) === 1) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * The wrapper element, provided nothing else in the file sits beside it.
     */
    private function wrapperElement(string $source, string $component): ?Element
    {
        foreach ($this->nesting->elements($source, [$component]) as $element) {
            if ($element->name() !== $component) {
                continue;
            }

            $closes = strpos($source, '>', $element->closes);

            if ($closes === false) {
                return null;
            }

            $outside = substr($source, 0, $element->open->offset).substr($source, $closes + 1);

            return trim($outside) === '' ? $element : null;
        }

        return null;
    }

    /**
     * Whether every attribute is `:name="$name"` or `:name="$name ?? null"`.
     *
     * Those are the only ones that mean the same thing once the variant's markup
     * lives in the wrapper, where the variable already arrives under that name.
     */
    private function passesThrough(Element $element): bool
    {
        foreach ($element->open->attributes as $attribute) {
            if (! $this->isPassThrough($attribute)) {
                return false;
            }
        }

        return true;
    }

    private function isPassThrough(Attribute $attribute): bool
    {
        if (! $attribute->isBound() || $attribute->value === null) {
            return false;
        }

        $variable = preg_quote(substr($attribute->name, 1), '/');

        return preg_match('/^\s*\$'.$variable.'(\s*\?\?\s*null)?\s*$/', $attribute->value) === 1;
    }

    private function inside(string $source, Element $element): string
    {
        return substr($source, $element->contentStart(), $element->closes - $element->contentStart());
    }

    /**
     * What the wrapper put inside the variant, pulled back to the left margin.
     *
     * @return list<string>
     */
    private function body(string $source, Element $element): array
    {
        $body = rtrim(preg_replace('/^\R+/', '', $this->inside($source, $element)) ?? '');

        return array_map(
            static fn (string $line): string => str_starts_with($line, self::INDENT) ? substr($line, strlen(self::INDENT)) : ltrim($line),
            preg_split('/\R/', $body) ?: [],
        );
    }

    /**
     * The body re-indented to where `{{ $slot }}` stood.
     *
     * The first line takes no indent of its own: it replaces the slot, which the
     * captured indent already precedes.
     *
     * @param  list<string>  $lines
     */
    private function indent(array $lines, string $indent): string
    {
        foreach ($lines as $number => $line) {
            if ($number > 0 && $line !== '') {
                $lines[$number] = $indent.$line;
            }
        }

        return implode("\n", $lines);
    }
}
