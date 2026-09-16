<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Hand a modal's size to the panel Sheaf draws, through the prop it reads.
 *
 * Flux puts a modal's `class` on the dialog itself, so the kit sizes every one
 * with a class: `max-w-lg` on nine of them, `max-w-md md:min-w-md` on the
 * two-factor setup. Sheaf puts `class` on an inline wrapper that stays where the
 * tag was written, and teleports the panel to the end of the body — so the class
 * reaches nothing, and every modal falls back to Sheaf's own `width="sm"`, a
 * third narrower than the kit drew it.
 *
 * Sheaf sizes the panel from `width`: a named size (`lg`) becomes its
 * `max-w-lg`, and anything it does not name falls through its `match` to the
 * panel's class list as written. So a `max-w-*` Sheaf names becomes the name.
 *
 * A `min-w-*` of the same size behind a breakpoint says nothing Sheaf's panel
 * does not already do: it is `w-full` inside a `p-4` container, so from `md` up
 * — 736px of room — any size up to `2xl` is reached without being asked for.
 * That is the two-factor setup's `md:min-w-md`, and it is dropped. Any other
 * class list moves over whole, through the fall-through, so nothing Flux gave
 * the panel is lost.
 *
 * A modal that already names a `width`, or binds its classes, has made a choice
 * refit cannot fold into Sheaf's. Those are left alone and reported.
 */
final class SizeModalPanels extends BladeSweep
{
    private const string MODAL = 'x-ui.modal';

    private const string CLASS_ATTRIBUTE = 'class';

    private const string WIDTH = 'width';

    /**
     * Every size Sheaf's modal names, as the `max-w-*` suffix it maps to.
     *
     * @var list<string>
     */
    private const array WIDTHS = [
        'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl', 'full',
        'screen-sm', 'screen-md', 'screen-lg', 'screen-xl', 'screen-2xl',
    ];

    /**
     * Sizes Sheaf's `w-full` panel reaches by itself from the breakpoints below.
     *
     * @var list<string>
     */
    private const array FILLED_SIZES = ['xs', 'sm', 'md', 'lg', 'xl', '2xl'];

    /**
     * Breakpoints with at least 736px of room inside the panel's container.
     *
     * @var list<string>
     */
    private const array ROOMY_BREAKPOINTS = ['md', 'lg', 'xl', '2xl'];

    /**
     * The files with a modal whose size refit could not move.
     *
     * @var list<string>
     */
    private array $skipped = [];

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'size   each modal panel through the width Sheaf reads';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are Sheaf's to size.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->parser->parse($source, self::MODAL) as $tag) {
            if ($tag->name !== self::MODAL) {
                continue;
            }

            $this->size($edits, $tag, $path);
        }

        return $edits->apply($source);
    }

    private function size(Edits $edits, Tag $tag, string $path): void
    {
        if ($tag->has(':'.self::CLASS_ATTRIBUTE)) {
            $this->noteSkipped($path);

            return;
        }

        $class = $tag->attribute(self::CLASS_ATTRIBUTE);
        $classes = trim((string) $class?->value);

        if (! $class instanceof Attribute || $classes === '') {
            return;
        }

        if ($tag->has(self::WIDTH) || $tag->has(':'.self::WIDTH)) {
            $this->noteSkipped($path);

            return;
        }

        $edits->replace($class->offset, $class->length, sprintf('%s="%s"', self::WIDTH, $this->width($classes)));
    }

    /**
     * The name Sheaf gives the modal's `max-w-*`, or the classes as written.
     */
    private function width(string $classes): string
    {
        $tokens = preg_split('/\s+/', $classes) ?: [];
        $size = null;
        $rest = [];

        foreach ($tokens as $token) {
            if ($size === null && str_starts_with($token, 'max-w-') && in_array(substr($token, 6), self::WIDTHS, true)) {
                $size = substr($token, 6);
            } else {
                $rest[] = $token;
            }
        }

        if ($size === null) {
            return $classes;
        }

        foreach ($rest as $token) {
            if (! $this->alreadyFilled($token, $size)) {
                return $classes;
            }
        }

        return $size;
    }

    /**
     * Whether a class only asks for the width Sheaf's panel already takes.
     */
    private function alreadyFilled(string $token, string $size): bool
    {
        if (! in_array($size, self::FILLED_SIZES, true)) {
            return false;
        }

        [$breakpoint, $utility] = array_pad(explode(':', $token, 2), 2, null);

        return in_array($breakpoint, self::ROOMY_BREAKPOINTS, true) && $utility === 'min-w-'.$size;
    }

    private function noteSkipped(string $path): void
    {
        if (! in_array($path, $this->skipped, true)) {
            $this->skipped[] = $path;
        }
    }

    protected function finish(Report $report): void
    {
        if ($this->skipped === []) {
            return;
        }

        $report->warn(sprintf(
            'Left the modal size alone in %s — Sheaf sizes the panel from `width` and puts `class` on a wrapper the panel is teleported out of, so check those modals are as wide as intended.',
            implode(', ', $this->skipped),
        ));

        $this->skipped = [];
    }
}
