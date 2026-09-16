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
 * Get the sidebar's own dropdowns out from under the sidebar.
 *
 * Sheaf's sidebar is `scrollable` by default, and CSS makes an `overflow-x` of
 * `visible` compute to `auto` whenever the other axis is not visible — so the
 * sidebar clips on both axes at its 256px. A dropdown panel anchored inside it
 * has a floor of `min-w-56` and grows to fit its contents, which for the team
 * switcher measures 265px against that 256px: the right-hand edge of the menu is
 * simply cut off.
 *
 * `portal` fixes the clipping by teleporting the panel to the body — and on its
 * own trades the bug for a worse one. At the body the panel is no longer a
 * descendant of the sidebar, so it stops winning by position and starts losing by
 * z-index: Sheaf's panel is `z-50` and Sheaf's sidebar carries an inline
 * `style="z-index:99"`, which beats every class including Sheaf's own
 * `md:…:z-auto`. The sidebar then paints over the whole menu and the trigger
 * reads as dead.
 *
 * So both go on together, and neither is any use without the other. The `!` is
 * load-bearing: `z-50` is on the panel's own class list, and a plain utility
 * alongside it is only a specificity tie.
 *
 * This is the same pair refit writes into the user menu it renders from a stub.
 * The difference is ownership — the team switcher is a kit file that refit
 * rewrites rather than authors, so it gets here instead.
 */
final class RaiseSidebarDropdowns extends BladeSweep
{
    private const string DROPDOWN = 'x-ui.dropdown';

    private const string MENU = 'x-slot:menu';

    /** One above the sidebar's inline 99, which is all it takes. */
    private const string LAYER = 'z-[100]!';

    /**
     * @param  list<string>  $components  Component names refit renders directly
     *                                    inside `<x-ui.sidebar>`, without the
     *                                    Livewire or Blade tag prefix.
     */
    public function __construct(
        private readonly array $components,
        private readonly TagParser $parser = new TagParser,
    ) {}

    public function describe(): string
    {
        return 'raise  the sidebar dropdowns Sheaf clips and then covers';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/') || ! $this->isSidebarComponent($path)) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->parser->parse($source, self::DROPDOWN) as $tag) {
            if ($tag->name !== self::DROPDOWN || $tag->has('portal') || $tag->has(':portal')) {
                continue;
            }

            $edits->replace($tag->nameOffset() + strlen($tag->name), 0, ' portal');
        }

        foreach ($this->parser->parse($source, self::MENU) as $tag) {
            if ($tag->name !== self::MENU) {
                continue;
            }

            $this->layer($edits, $tag);
        }

        return $edits->apply($source);
    }

    /**
     * Put the panel above the sidebar, in whichever way its class attribute
     * allows. A bound `:class` is a decision the application made in PHP, and
     * appending to it would mean editing an expression.
     */
    private function layer(Edits $edits, Tag $tag): void
    {
        if ($tag->has(':class')) {
            return;
        }

        $class = $tag->attribute('class');

        if (! $class instanceof Attribute) {
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' class="%s"', self::LAYER),
            );

            return;
        }

        $classes = $class->value ?? '';

        if (str_contains($classes, self::LAYER)) {
            return;
        }

        $edits->replace(
            $class->valueOffset(),
            0,
            self::LAYER.($classes === '' ? '' : ' '),
        );
    }

    /**
     * Is this the view behind one of the components refit puts in the sidebar?
     *
     * Matched on the file name rather than a fixed path, because the kits spell
     * a single-file Livewire component `⚡name.blade.php` and a class-backed one
     * `name.blade.php`, and refit's own tasks may have moved either by the time
     * the reconcile stage runs.
     */
    private function isSidebarComponent(string $path): bool
    {
        $file = basename($path);

        foreach ($this->components as $component) {
            if ($file === $component.'.blade.php' || $file === '⚡'.$component.'.blade.php') {
                return true;
            }
        }

        return false;
    }
}
