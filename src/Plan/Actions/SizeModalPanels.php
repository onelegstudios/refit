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
 * panel's class list as written. So a class that is exactly one of those sizes
 * becomes the name, and any other class moves over whole — Flux gave every
 * class on the tag to the panel, and this keeps it that way.
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
     * The name Sheaf gives a lone `max-w-*` class, or the classes as written.
     */
    private function width(string $classes): string
    {
        $size = str_starts_with($classes, 'max-w-') ? substr($classes, strlen('max-w-')) : null;

        return in_array($size, self::WIDTHS, true) ? $size : $classes;
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
