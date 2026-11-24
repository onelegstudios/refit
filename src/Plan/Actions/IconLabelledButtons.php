<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Put an icon on every button whose label says which one it wants.
 *
 * The kit's buttons are bare almost everywhere. Two of them in the teams kits
 * carry an icon — `plus` on "New team", `user-plus` on "Invite member" — and
 * every other button in five variations is words alone, which is what makes a
 * row of them read as a paragraph rather than as controls:
 *
 *     <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
 *     <flux:button icon="check" variant="primary" type="submit">{{ __('Save') }}</flux:button>
 *
 * The label is the whole input. A button saying "Delete account" wants the
 * trash, one saying "Log out" wants the arrow leaving the rectangle, and no part
 * of that is a judgement about the project — which is why the table of labels
 * lives in the task and this only reads markup. What arrives here is already
 * spelled in whichever icon set the run is heading for.
 *
 * Only contents refit can read as a label count. A single `{{ __('…') }}` or a
 * line of plain text is a label; anything with markup, a variable or a second
 * node in it is not, and is left alone rather than matched on a guess — the
 * passkey buttons wrap two `<span>`s around a bound label, and there is no
 * reading of those that is not one.
 *
 * Nothing is overwritten. A button already naming an icon in any of its
 * library's attributes has made the decision itself, bound or literal, leading
 * or trailing; an empty button is an icon-only one the kit sized and placed on
 * purpose, and has no label to read in the first place.
 *
 * A label with no icon behind it is reported once, with the words on it, so the
 * run says which buttons it left for you rather than leaving you to find them.
 */
final class IconLabelledButtons extends BladeSweep
{
    /** One Blade echo, with no second one hiding behind it. */
    private const string ECHO = '/^\{\{(?<expression>(?:(?!\}\}).)*)\}\}$/s';

    /** That echo being a single `__()` around one literal string. */
    private const string TRANSLATION = '/^__\(\s*(?<quote>[\'"])(?<text>(?:(?!(?P=quote)).)*)(?P=quote)\s*\)$/s';

    /** What disqualifies contents from being read as a label. */
    private const string MARKUP = '/[<>{}@]/';

    /**
     * The labels that went without, as the first spelling each was seen in and
     * keyed by what they matched on, for one note at the end rather than one per
     * file the words appear in.
     *
     * @var array<string, string>
     */
    private array $bare = [];

    /**
     * The table, keyed by what a label is matched on rather than by how it was
     * written, so a caller spelling one "Delete account" is read the same way
     * the markup is.
     *
     * @var array<string, string>
     */
    private readonly array $icons;

    /**
     * @param  string  $tag  the button component, as it is spelled by the time the sweep runs
     * @param  string  $attribute  the attribute a leading icon goes on
     * @param  array<string, string>  $icons  button label => the icon name to write
     * @param  list<string>  $taken  attributes that mean a button already names an icon
     */
    public function __construct(
        private readonly string $tag,
        private readonly string $attribute,
        array $icons,
        private readonly array $taken,
        private readonly Nesting $nesting = new Nesting,
    ) {
        $keyed = [];

        foreach ($icons as $label => $icon) {
            $keyed[$this->key($label)] = $icon;
        }

        $this->icons = $keyed;
    }

    public function describe(): string
    {
        return sprintf('icon   every <%s> whose label refit has an icon for', $this->tag);
    }

    /**
     * The icon names this sweep would write into one file, without writing them.
     *
     * What the task asks to decide whether it is worth offering at all, and —
     * on a Lucide run, where Flux draws an icon from a file rather than from a
     * set — which artwork it has to bring with it.
     *
     * @return list<string>
     */
    public function names(string $source): array
    {
        $names = [];

        foreach ($this->labelled($source) as [, $label]) {
            $icon = $this->icon($label);

            if ($icon !== null) {
                $names[$icon] = true;
            }
        }

        return array_keys($names);
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $edits = new Edits;

        foreach ($this->labelled($source) as [$tag, $label]) {
            $icon = $this->icon($label);

            if ($icon === null) {
                $this->bare[$this->key($label)] ??= $label;

                continue;
            }

            // In front of the attributes the button already carries, where the
            // icon reads as the first thing about the control rather than as
            // something appended to its wiring.
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' %s="%s"', $this->attribute, $icon),
            );
        }

        return $edits->apply($source);
    }

    protected function finish(Report $report): void
    {
        if ($this->bare === []) {
            return;
        }

        $labels = array_values($this->bare);

        sort($labels);

        $report->note(sprintf(
            'Left the %s labelled "%s" bare — refit has no icon to match %s.',
            count($labels) === 1 ? 'button' : 'buttons',
            implode('", "', $labels),
            count($labels) === 1 ? 'it' : 'them',
        ));

        $this->bare = [];
    }

    /**
     * Every button that could take an icon, with the label it reads as.
     *
     * @return list<array{0: Tag, 1: string}>
     */
    private function labelled(string $source): array
    {
        $found = [];

        foreach ($this->nesting->elements($source, [$this->tag]) as $element) {
            // The prefix matches the family, and `flux:button.group` is a row of
            // buttons rather than one of them.
            if ($element->name() !== $this->tag || $this->iconed($element->open)) {
                continue;
            }

            $label = $this->label($source, $element);

            if ($label !== null) {
                $found[] = [$element->open, $label];
            }
        }

        return $found;
    }

    /**
     * Has this button already named an icon?
     *
     * Every attribute the library carries a name in counts, bound or literal: a
     * trailing icon is still an icon, and a bound one is a decision refit cannot
     * read but can certainly leave alone.
     */
    private function iconed(Tag $tag): bool
    {
        foreach ($this->taken as $attribute) {
            if ($tag->has($attribute) || $tag->has(':'.$attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this button's contents say, or null when they are not a label.
     */
    private function label(string $source, Element $element): ?string
    {
        $start = $element->contentStart();
        $contents = trim(substr($source, $start, $element->closes - $start));

        // An icon-only button, which the kit wrote that way on purpose.
        if ($contents === '') {
            return null;
        }

        if (preg_match(self::ECHO, $contents, $matches) === 1) {
            $expression = trim($matches['expression']);

            return preg_match(self::TRANSLATION, $expression, $translated) === 1
                ? $translated['text']
                : null;
        }

        return preg_match(self::MARKUP, $contents) === 1 ? null : $contents;
    }

    /**
     * The icon for a label, or null when the table has none.
     */
    private function icon(string $label): ?string
    {
        return $this->icons[$this->key($label)] ?? null;
    }

    /**
     * What a label is matched on: the words rather than their spacing.
     *
     * Case and runs of whitespace are the two differences that say nothing — a
     * label is written across lines wherever the tag is, and "Log in" and "Log
     * In" are the same button — so they are also what one finding is collapsed
     * on when there is nothing to match.
     */
    private function key(string $label): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($label)) ?? $label);
    }
}
