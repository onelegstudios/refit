<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Reshape the two overlays whose contents Flux and Sheaf arrange differently.
 *
 * Runs before the tag rename, while the markup still says `flux:`, because both
 * of these are about where things sit rather than what they are called.
 *
 * **Dropdowns.** Flux takes the trigger as the dropdown's first child and the
 * contents as a `<flux:menu>`. Sheaf takes both as named slots. So everything
 * between the opening tag and the menu is the trigger, and it gets wrapped —
 * after which `flux:menu` is an ordinary rename to `x-slot:menu`.
 *
 * **Modal close buttons.** `<flux:modal.close>` is a wrapper whose only job is
 * "clicking my child closes the modal". Sheaf's modal listens for a
 * `close-modal` event instead, so the wrapper becomes an element carrying that
 * dispatch. `class="contents"` keeps it out of the layout, which is what Flux's
 * own wrapper does.
 */
final class RestructureOverlays extends BladeSweep
{
    private const string DROPDOWN = 'flux:dropdown';

    private const string MENU = 'flux:menu';

    private const string MODAL_CLOSE = 'flux:modal.close';

    /**
     * How Sheaf's modal is told to close from inside its own contents.
     */
    private const string CLOSE = '<div class="contents" x-on:click="$dispatch(\'close-modal\')">';

    /** @var list<string> */
    private array $touched = [];

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'shape  dropdown triggers into slots, modal close buttons into events';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $rewritten = $this->wrapTriggers($source);
        $rewritten = $this->rewriteCloseButtons($rewritten);

        if ($rewritten !== $source) {
            $this->touched[] = $path;
        }

        return $rewritten;
    }

    /**
     * Wrap each dropdown's trigger in the slot Sheaf expects.
     */
    private function wrapTriggers(string $source): string
    {
        $tags = $this->parser->parse($source, 'flux:');
        $edits = new Edits;

        foreach ($tags as $index => $tag) {
            if ($tag->name !== self::DROPDOWN || $tag->selfClosing) {
                continue;
            }

            $menu = $this->menuAfter($tags, $index);

            if (! $menu instanceof Tag) {
                continue;
            }

            $edits->replace($tag->offset + $tag->length, 0, "\n<x-slot:button>");
            $edits->replace($menu->offset, 0, "</x-slot:button>\n");
        }

        return $edits->apply($source);
    }

    /**
     * The `<flux:menu>` belonging to the dropdown opened at $index.
     *
     * A nested dropdown would claim the outer one's menu, so the search stops at
     * the next dropdown rather than running past it. The starter kits never nest
     * one, but a wrong wrap is silent and this is two lines.
     *
     * @param  list<Tag>  $tags
     */
    private function menuAfter(array $tags, int $index): ?Tag
    {
        foreach (array_slice($tags, $index + 1) as $candidate) {
            if ($candidate->name === self::DROPDOWN) {
                return null;
            }

            if ($candidate->name === self::MENU) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Turn `<flux:modal.close>` into something that dispatches Sheaf's event.
     */
    private function rewriteCloseButtons(string $source): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, self::MODAL_CLOSE) as $tag) {
            if ($tag->name !== self::MODAL_CLOSE) {
                continue;
            }

            // A self-closing close button wraps nothing, so there is nothing to
            // keep — Sheaf's modal draws its own close control.
            $edits->replace($tag->offset, $tag->length, $tag->selfClosing ? '' : self::CLOSE);
        }

        $source = $edits->apply($source);

        $rewritten = preg_replace(
            '/<\/'.preg_quote(self::MODAL_CLOSE, '/').'(\s*)>/',
            '</div>',
            $source,
        );

        return $rewritten ?? $source;
    }

    protected function finish(Report $report): void
    {
        if ($this->touched === []) {
            return;
        }

        $report->note(sprintf(
            'Reshaped the dropdowns and modal close buttons in %d file(s). Sheaf takes a dropdown\'s trigger '
            .'through <x-slot:button> and closes a modal by dispatching close-modal, so both needed moving '
            .'rather than renaming — worth a look in the diff.',
            count($this->touched),
        ));

        $this->touched = [];
    }
}
