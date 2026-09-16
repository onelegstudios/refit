<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Give Sheaf's label-only items the label Flux read out of their contents.
 *
 * Flux's nav items take their text as slot content:
 *
 * ```blade
 * <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
 * ```
 *
 * Sheaf's item renders `{{ $label }}` inside a span and never touches `$slot`, so
 * the rename alone leaves an anchor with a href, a hover state and nothing to
 * read. On the settings pages that is the whole sub-navigation: three links that
 * are there, are clickable, and show no text at all. The appearance page loses the
 * three words in its segmented control the same way.
 *
 * So the contents come out and go on as an attribute, and the tag closes itself.
 * A single Blade echo becomes a bound `:label`, keeping `__()` where the kit put
 * it; plain text becomes a literal `label`.
 *
 * Anything richer is left exactly as it was and reported, because Sheaf's item has
 * nowhere to put markup — it draws an icon, a label and a badge, and the honest
 * answer to a slot holding more than a label is to say so rather than to flatten
 * it.
 *
 * A dropdown group is the same rewrite for a different reason: it does render its
 * slot, but only `label` is drawn as a heading, and what the slot holds goes
 * straight into the panel's grid as a child of its own. See {@see self::LABELLED}.
 */
final class PromoteContentsToLabel extends BladeSweep
{
    /**
     * The Sheaf components that draw a label from a prop and drop their slot.
     *
     * A select option is the one other `label` taker, and it renders its slot as
     * well, so a slot label still shows there and it does not belong here.
     *
     * @var list<string>
     */
    private const array TARGETS = ['x-ui.navlist.item', 'x-ui.navbar.item', 'x-ui.radio.item'];

    /**
     * The component that renders its slot too, and still needs the prop.
     *
     * Sheaf's dropdown group is `display: contents` — it exists to hand its
     * children to the panel's grid — and the muted heading it draws comes from
     * `label` alone. Flux's `menu.heading` carries that word as contents, so the
     * rename leaves it as a bare text node in a `grid grid-cols-[auto_1fr_auto]`,
     * which the panel takes for a child of its own: unstyled, in the first of the
     * three columns, with the first item of the list beside it in the other two
     * rather than under it. As `label` it is the row Flux drew.
     *
     * Contents richer than a label are left alone here in silence rather than
     * reported. `flux:menu.radio.group` renames to this same tag, and its
     * children are items that Sheaf's group passes through exactly as Flux did —
     * so there is nothing missing to warn about.
     *
     * @var list<string>
     */
    private const array LABELLED = ['x-ui.dropdown.group'];

    /** One Blade echo, with no second one hiding behind it. */
    private const string ECHO = '/^\{\{(?<expression>(?:(?!\}\}).)*)\}\}$/s';

    /** What disqualifies contents from being read as plain text. */
    private const string MARKUP = '/[<>{}@]/';

    /**
     * Files and tags whose contents were too rich to move, for one warning at the
     * end rather than one per file.
     *
     * @var list<string>
     */
    private array $left = [];

    public function __construct(private readonly Nesting $nesting = new Nesting) {}

    public function describe(): string
    {
        return 'label  the items Sheaf draws from a prop rather than a slot';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are where these props are declared, so they are
        // not a place where one is missing.
        if (str_contains($path, 'resources/views/components/ui/')) {
            return $source;
        }

        $edits = new Edits;

        $targets = [...self::TARGETS, ...self::LABELLED];

        foreach ($this->nesting->elements($source, $targets) as $element) {
            if (! in_array($element->name(), $targets, true)) {
                continue;
            }

            $this->label($edits, $source, $path, $element);
        }

        return $edits->apply($source);
    }

    protected function finish(Report $report): void
    {
        if ($this->left === []) {
            return;
        }

        $report->warn(sprintf(
            'Left the contents of %s alone — Sheaf draws these from a `label` attribute, and this is more than a label: %s',
            count($this->left) === 1 ? '1 item' : count($this->left).' items',
            implode(', ', $this->left),
        ));

        $this->left = [];
    }

    /**
     * Move one element's contents onto it, and close the tag behind them.
     */
    private function label(Edits $edits, string $source, string $path, Element $element): void
    {
        if ($element->open->has('label') || $element->open->has(':label')) {
            return;
        }

        $start = $element->contentStart();
        $contents = substr($source, $start, $element->closes - $start);

        if (trim($contents) === '') {
            return;
        }

        $attribute = $this->attribute($contents);

        if ($attribute === null) {
            if (! in_array($element->name(), self::LABELLED, true)) {
                $this->left[] = $path.': <'.$element->name().'>';
            }

            return;
        }

        $closes = strpos($source, '>', $element->closes);

        if ($closes === false) {
            return;
        }

        // The whole element becomes one self-closing tag, which is the shape every
        // Sheaf item is written in — attributes and no children.
        $open = substr($source, $element->open->offset, $element->open->length);

        $edits->replace(
            $element->open->offset,
            $closes + 1 - $element->open->offset,
            '<'.rtrim(substr($open, 1, -1)).' '.$attribute.' />',
        );
    }

    /**
     * The attribute these contents can be written as, or null when they cannot.
     */
    private function attribute(string $contents): ?string
    {
        $contents = trim($contents);

        if (preg_match(self::ECHO, $contents, $matches) === 1) {
            $expression = trim($matches['expression']);

            // The attribute is double-quoted, and the parser this package ships
            // does not read escaped quotes inside a value. An expression carrying
            // one is left for a person.
            return $expression === '' || str_contains($expression, '"') ? null : ':label="'.$expression.'"';
        }

        if (preg_match(self::MARKUP, $contents) === 1 || str_contains($contents, '"')) {
            return null;
        }

        return 'label="'.$contents.'"';
    }
}
