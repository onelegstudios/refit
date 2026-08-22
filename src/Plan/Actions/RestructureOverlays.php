<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Reshape the overlays whose contents Flux and Sheaf arrange differently.
 *
 * Runs before the tag rename, while the markup still says `flux:`, because all
 * of these are about where things sit rather than what they are called.
 *
 * **Dropdowns.** Flux takes the trigger as the dropdown's first child and the
 * contents as a `<flux:menu>`. Sheaf takes both as named slots. So everything
 * between the opening tag and the menu is the trigger, and it gets wrapped —
 * after which `flux:menu` is an ordinary rename to `x-slot:menu`.
 *
 * **Tooltips.** The same story with a different slot, and one extra step. Flux
 * writes the tooltip's text as a `content` attribute and the thing it describes
 * as the child; Sheaf reads `{{ $trigger }}` and nothing else, so a rename alone
 * leaves `Undefined variable $trigger` on every page that shows one — the teams
 * list and the team editor, which is where the kit spends them. The child
 * becomes `<x-slot:trigger>`, and the attribute becomes the
 * `<flux:tooltip.content>` child that Flux's own longhand writes and the rename
 * knows how to translate.
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

    private const string TOOLTIP = 'flux:tooltip';

    private const string TOOLTIP_CONTENT = 'flux:tooltip.content';

    /**
     * How Sheaf's modal is told to close from inside its own contents.
     */
    private const string CLOSE = '<div class="contents" x-on:click="$dispatch(\'close-modal\')">';

    /** @var list<string> */
    private array $touched = [];

    public function __construct(
        private readonly TagParser $parser = new TagParser,
        private readonly Nesting $nesting = new Nesting,
    ) {}

    public function describe(): string
    {
        return 'shape  dropdown and tooltip triggers into slots, modal close buttons into events';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $rewritten = $this->wrapTriggers($source);
        $rewritten = $this->wrapTooltips($rewritten);
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
     * Give each tooltip the trigger slot Sheaf reads, and a content child.
     *
     * Both halves have to move together: the slot is only half a tooltip without
     * the text, and the text has nowhere to go until the child it displaces is
     * out of the default slot.
     */
    private function wrapTooltips(string $source): string
    {
        $edits = new Edits;

        foreach ($this->nesting->elements($source, [self::TOOLTIP]) as $element) {
            if ($element->name() !== self::TOOLTIP) {
                continue;
            }

            $this->wrapTooltip($edits, $source, $element);
        }

        return $edits->apply($source);
    }

    /**
     * Rewrite one tooltip's body into the two slots Sheaf draws it from.
     */
    private function wrapTooltip(Edits $edits, string $source, Element $element): void
    {
        $content = $this->contentIn($source, $element);
        $attribute = $element->open->attribute('content') ?? $element->open->attribute(':content');

        // Neither the longhand child nor the shorthand attribute, so there is no
        // tooltip text to keep and no telling what the body is meant to be.
        if (! $content instanceof Tag && ! $attribute instanceof Attribute) {
            return;
        }

        $start = $element->contentStart();
        $end = $content instanceof Tag ? $content->offset : $element->closes;
        $trigger = substr($source, $start, $end - $start);

        if (trim($trigger) === '') {
            return;
        }

        $indent = $this->indent($source, $element->open->offset);
        $inner = $indent.'    ';

        $body = "\n".$inner.'<x-slot:trigger>'."\n"
            .$this->reindent($trigger)."\n"
            .$inner.'</x-slot:trigger>'."\n";

        if ($attribute instanceof Attribute) {
            // The attribute is spent here, so it comes off the tag rather than
            // riding along to land on Sheaf's wrapper div as a stray one.
            $open = substr($source, $element->open->offset, $element->open->length);

            $edits->replace(
                $element->open->offset,
                $element->open->length,
                $this->without($open, $element->open->offset, [$attribute]),
            );

            // A tooltip written both ways keeps the child it already has; the
            // attribute only ever becomes one when there is none.
            if (! $content instanceof Tag) {
                $body .= "\n".$inner.$this->contentFor($attribute)."\n";
            }
        }

        // What follows the trigger is the longhand content child, which is already
        // the shape Sheaf wants and only needs putting back under the slot.
        $rest = substr($source, $end, $element->closes - $end);

        $edits->replace(
            $start,
            $element->closes - $start,
            $body.($rest === '' ? $indent : "\n".$inner.$rest),
        );
    }

    /**
     * The `<flux:tooltip.content>` written inside this tooltip, if there is one.
     *
     * A tooltip nested in another one's trigger would otherwise hand its content
     * to the outer tooltip, and the wrap would take the inner one with it.
     */
    private function contentIn(string $source, Element $element): ?Tag
    {
        foreach ($this->parser->parse($source, self::TOOLTIP) as $tag) {
            if (! $element->holds($tag->offset)) {
                continue;
            }

            if ($tag->name === self::TOOLTIP_CONTENT) {
                return $tag;
            }

            if ($tag->name === self::TOOLTIP) {
                return null;
            }
        }

        return null;
    }

    /**
     * The content child that carries what the shorthand attribute said.
     *
     * A bound attribute holds a PHP expression, so it goes back through an echo;
     * a literal one is already the text Flux drew.
     */
    private function contentFor(Attribute $attribute): string
    {
        $value = $attribute->value ?? '';

        $text = $attribute->isBound() ? '{{ '.trim($value).' }}' : $value;

        return '<'.self::TOOLTIP_CONTENT.'>'.$text.'</'.self::TOOLTIP_CONTENT.'>';
    }

    /**
     * Move a block one level in, to sit under the slot now wrapping it.
     *
     * Blank lines are left blank rather than padded, and the leading newline goes
     * with the wrapper's own.
     */
    private function reindent(string $block): string
    {
        $shifted = preg_replace('/^(?=[^\r\n])/m', '    ', rtrim($block));

        return ltrim($shifted ?? $block, "\r\n");
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
            'Reshaped the dropdowns, tooltips and modal close buttons in %d file(s). Sheaf takes a dropdown\'s '
            .'trigger through <x-slot:button> and a tooltip\'s through <x-slot:trigger>, and closes a modal by '
            .'dispatching close-modal, so all three needed moving rather than renaming — worth a look in the diff.',
            count($this->touched),
        ));

        $this->touched = [];
    }
}
