<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Element;
use Onelegstudios\Refit\Blade\Nesting;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Put an icon on each settings section, in the menu and over the page.
 *
 * The settings sub-navigation is the one navlist in the kit written without
 * icons. Every other nav row in the shell has one — the sidebar's Dashboard,
 * Repository and Documentation, the user menu's Settings and Log out — and then
 * the menu those rows lead to is four words in a column:
 *
 *     <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
 *     <flux:navlist.item icon="user" :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
 *
 * The same icon goes over the page the row opens, because a menu selection that
 * is only a tint is easy to lose on a wide screen and gone entirely on a narrow
 * one, where the menu is above the fold and the heading is what you are looking
 * at. That heading is the layout's to draw and the section's to name, so the two
 * halves are rewritten together: the layout learns an `icon` prop, and each page
 * passes the one its heading asks for. The sections written further down a page
 * are its own headings, so those are given the icon on the spot.
 *
 *     <div class="flex items-center gap-2">
 *         <flux:icon :name="$icon" class="size-4" />
 *         <flux:heading>{{ $heading ?? '' }}</flux:heading>
 *     </div>
 *
 * Wrapped rather than written into the heading, so the heading tag itself is
 * left exactly as the kit wrote it — `<h2>` with nothing but words in it — and
 * the icon is a sibling the row centres. It goes behind an `isset` guard on the
 * prop, because a project's own settings page is free to call the layout with a
 * heading refit has no icon for; that page keeps the heading it always had.
 *
 * Only the label decides. A row saying "Profile" wants the person, one saying
 * "Teams" wants the group of them, and the table that says so lives in the task
 * — this reads markup and nothing else. A label refit has never seen is reported
 * rather than matched on a guess, and a row that already names an icon has made
 * the decision itself and keeps it.
 *
 * The label is read wherever the library of the moment keeps it: Flux's nav item
 * carries it as contents, and Sheaf's carries it as a `label` attribute by the
 * time this runs, the migration having moved it there. Both spellings are the
 * same words, so both are read the same way.
 */
final class IconSettingsSections extends BladeSweep
{
    /**
     * The settings menu's rows, under whichever prefix the library spells them.
     *
     * The kit writes this component in exactly one place — the settings layout —
     * so there is no narrowing to do here. Its own shell nav is
     * `sidebar.item` and `navbar.item`, both of which already wear icons.
     */
    private const string ITEM = 'navlist.item';

    /** The heading component, which is the one the layout draws the page's title with. */
    private const string HEADING = 'heading';

    /**
     * The settings layout, as any of the names it is called by.
     *
     * `<x-pages::settings.layout>` in the four Volt kits, `<x-settings.layout>`
     * in the class-components one — and in the others too, once the task that
     * moves the non-page components out of the pages folder has run earlier in
     * this same pass. Matched on the tail rather than listed, so the sweep does
     * not need to know which of those happened.
     */
    private const string LAYOUT = 'settings.layout';

    /** The attribute a leading icon goes on, which both libraries spell alike. */
    private const string ATTRIBUTE = 'icon';

    /** The prop the layout learns, and the variable the guard reads it through. */
    private const string PROP = 'icon';

    /** What the layout draws its title from, which is what names the icon beside it. */
    private const string TITLE = 'heading';

    /**
     * How the icon beside a heading is sized.
     *
     * The kit's own, and not a choice: the recovery codes panel already writes an
     * iconed section heading, and it writes a `size-4` icon with no colour on it,
     * so the icon takes the heading's. Flux sizes it to `size-6` otherwise, which
     * beside a `text-sm` heading is a drawing with a line of text next to it.
     */
    private const string CLASSES = 'size-4';

    /**
     * How the icon and the words are laid out, which goes on the heading itself.
     *
     * On the heading rather than on a wrapper around it, because the gap under a
     * heading is the heading's own: Flux draws it with
     * `[&:has(+[data-flux-subheading])]:mb-2`, a rule that only fires while the
     * subheading is the heading's next sibling. A wrapper puts a `<div>` between
     * the two and the gap goes with it — so the icon goes inside the heading,
     * where the kit's own arrangement of the page is left exactly as it was.
     *
     * The svg is `aria-hidden` and `shrink-0` in both libraries, so a heading
     * reads the same to a screen reader with one in it as without.
     */
    private const string ROW = 'flex items-center gap-2';

    /** One Blade echo, with no second one hiding behind it. */
    private const string ECHO = '/^\{\{(?<expression>(?:(?!\}\}).)*)\}\}$/s';

    /** That echo, or a bound attribute, being a single `__()` around one literal string. */
    private const string TRANSLATION = '/^__\(\s*(?<quote>[\'"])(?<text>(?:(?!(?P=quote)).)*)(?P=quote)\s*\)$/s';

    /** What disqualifies contents from being read as a label. */
    private const string MARKUP = '/[<>{}@]/';

    /** The layout's own heading, which echoes the prop rather than naming a section. */
    private const string TITLE_ECHO = '/^\{\{\s*\$heading\b/';

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
     * written, so a caller spelling one "Update Password" is read the same way
     * the markup is.
     *
     * @var array<string, string>
     */
    private readonly array $icons;

    /**
     * @param  Vocabulary  $vocabulary  how the library the project ends on spells its components
     * @param  array<string, string>  $icons  section label => the icon name to write
     */
    public function __construct(
        private readonly Vocabulary $vocabulary,
        array $icons,
        private readonly Nesting $nesting = new Nesting,
        private readonly TagParser $parser = new TagParser,
    ) {
        $keyed = [];

        foreach ($icons as $label => $icon) {
            $keyed[$this->key($label)] = $icon;
        }

        $this->icons = $keyed;
    }

    public function describe(): string
    {
        return sprintf(
            'icon   the settings menu, and the headings on the settings pages (<%s>)',
            $this->vocabulary->prefix.self::ITEM,
        );
    }

    /**
     * The icon names this sweep would write into one file, without writing them.
     *
     * What the task asks to decide whether it is worth offering at all, and —
     * on a Lucide run, where Flux draws an icon from a file rather than from a
     * set — which artwork it has to bring with it.
     *
     * The layout's own heading names nothing: it writes the prop, and the pages
     * that pass it are where the names are. So this is the menu, the call sites
     * and the sections written inside one, which between them cover every name
     * the sweep puts on disk.
     *
     * @return list<string>
     */
    public function names(string $source): array
    {
        $names = [];

        foreach ($this->labels($source) as $label) {
            $icon = $this->iconFor($label);

            if ($icon !== null) {
                $names[$icon] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Every label this sweep would read out of one file, in source order.
     *
     * @return list<string>
     */
    private function labels(string $source): array
    {
        $labels = [];

        foreach ([...$this->rows($source), ...$this->calls($source)] as [, $label]) {
            $labels[] = $label;
        }

        foreach ($this->sections($source) as [, $label]) {
            $labels[] = $label;
        }

        return $labels;
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $edits = new Edits;

        foreach ([...$this->rows($source), ...$this->calls($source)] as [$tag, $label]) {
            $icon = $this->iconFor($label);

            if ($icon === null) {
                $this->bare[$this->key($label)] ??= $label;

                continue;
            }

            // In front of the attributes the tag already carries, where the icon
            // reads as the first thing about the row rather than as something
            // appended to its wiring.
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' %s="%s"', self::ATTRIBUTE, $icon),
            );
        }

        $this->iconHeadings($source, $edits);

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
            'Left the settings %s "%s" without an icon — refit has no icon to match %s.',
            count($labels) === 1 ? 'section' : 'sections',
            implode('", "', $labels),
            count($labels) === 1 ? 'it' : 'them',
        ));

        $this->bare = [];
    }

    /**
     * Every menu row that could take an icon, with the label it reads as.
     *
     * @return list<array{0: Tag, 1: string}>
     */
    private function rows(string $source): array
    {
        $tag = $this->vocabulary->prefix.self::ITEM;
        $elements = [];

        foreach ($this->nesting->elements($source, [$tag]) as $element) {
            if ($element->name() === $tag) {
                $elements[$element->open->offset] = $element;
            }
        }

        $found = [];

        foreach ($this->parser->parse($source, $tag) as $open) {
            // The prefix matches the family, and `navlist.item` is the row rather
            // than the group of them.
            if ($open->name !== $tag || $this->iconed($open)) {
                continue;
            }

            $label = $this->attributeLabel($open, 'label')
                ?? $this->contentsLabel($source, $elements[$open->offset] ?? null);

            if ($label !== null) {
                $found[] = [$open, $label];
            }
        }

        return $found;
    }

    /**
     * Every call to the settings layout that could name an icon, with the label
     * its heading reads as.
     *
     * The heading rather than the menu row, because the heading is the words on
     * the page the icon lands over: the security page's menu row says "Security"
     * and its heading says "Update password", and each is answered on its own
     * terms.
     *
     * @return list<array{0: Tag, 1: string}>
     */
    private function calls(string $source): array
    {
        $found = [];

        foreach ($this->parser->parse($source, 'x-') as $open) {
            if (! $this->isLayout($open->name) || $this->named($open)) {
                continue;
            }

            $label = $this->attributeLabel($open, self::TITLE);

            if ($label !== null) {
                $found[] = [$open, $label];
            }
        }

        return $found;
    }

    /**
     * Is this tag the settings layout, under any of the names it is called by?
     */
    private function isLayout(string $name): bool
    {
        return $name === 'x-'.self::LAYOUT
            || str_ends_with($name, '::'.self::LAYOUT)
            || str_ends_with($name, '.'.self::LAYOUT);
    }

    /**
     * Give the layout's own heading an icon to draw, from a prop.
     *
     * The layout is found by what it writes rather than by where it lives: a
     * heading whose whole contents are the `$heading` it was handed is the page
     * title, and nothing else in the kit writes one. So the file moving out of
     * the pages folder earlier in the run changes nothing here.
     */
    private function iconHeadings(string $source, Edits $edits): void
    {
        $tag = $this->vocabulary->prefix.self::HEADING;

        // Already open to one — the sweep has been here, or the project draws
        // its own icon from the same prop.
        $opened = str_contains($source, sprintf(':%s="$%s"', $this->vocabulary->iconTagAttribute, self::PROP));

        foreach ($this->nesting->elements($source, [$tag]) as $element) {
            if ($element->name() !== $tag || ! $this->isTitle($source, $element) || $opened) {
                continue;
            }

            $this->wrap($source, $element, $this->guarded(), $edits);
        }

        foreach ($this->sections($source) as [$element, $label]) {
            $icon = $this->iconFor($label);

            if ($icon !== null) {
                $this->wrap($source, $element, [$this->icon($icon)], $edits);
            }
        }
    }

    /**
     * The sections a settings page writes inside the layout, with the labels
     * they read as.
     *
     * The security page is where this earns its keep. Its two-factor and passkey
     * sections are headings of their own inside the layout's slot, under the one
     * the layout draws — so they are where the icons go, the same way the menu
     * row is.
     *
     * Scoped to what the layout encloses, which is what makes a label safe to
     * match on at all: `<flux:heading>{{ __('Teams') }}</flux:heading>` is a
     * settings section inside the slot, and the screen-reader heading of the same
     * words above it is not. The headings a page writes outside the slot are left
     * alone for that reason, modal titles among them, and so are the ones that
     * live in components of their own — the account deletion form, the recovery
     * codes panel — because nothing in their own file says they belong to a
     * settings page.
     *
     * @return list<array{0: Element, 1: string}>
     */
    private function sections(string $source): array
    {
        $slots = [];

        foreach ($this->nesting->elements($source, ['x-']) as $element) {
            if ($this->isLayout($element->name())) {
                $slots[] = $element;
            }
        }

        if ($slots === []) {
            return [];
        }

        $tag = $this->vocabulary->prefix.self::HEADING;
        $found = [];

        foreach ($this->nesting->elements($source, [$tag]) as $element) {
            if ($element->name() !== $tag || ! $this->enclosed($element, $slots)) {
                continue;
            }

            if ($this->behindAnIcon($source, $element)) {
                continue;
            }

            $label = $this->contentsLabel($source, $element);

            if ($label !== null) {
                $found[] = [$element, $label];
            }
        }

        return $found;
    }

    /**
     * Does this heading already have an icon in front of it?
     *
     * The icon is a sibling rather than something on the tag, so there is no
     * attribute to read: what says a heading has one is an icon element closing
     * immediately before it. Which is both halves of the same question — a
     * project that drew one itself keeps it, and a heading this sweep has already
     * been past is not wrapped a second time.
     */
    private function behindAnIcon(string $source, Element $element): bool
    {
        $before = rtrim(substr($source, 0, $element->open->offset));

        if (! str_ends_with($before, '/>')) {
            return false;
        }

        $opens = strrpos($before, '<');

        if ($opens === false) {
            return false;
        }

        $tag = substr($before, $opens + 1, strlen($this->vocabulary->iconTag) + 1);

        // The dotted form counts too, so the name is matched up to its boundary
        // rather than whole: `<flux:icon.key />` is the same decision as
        // `<flux:icon name="key" />`.
        return preg_match(
            '/^'.preg_quote($this->vocabulary->iconTag, '/').'[\s.\/]/',
            $tag,
        ) === 1;
    }

    /**
     * Is this heading written inside one of the layout's slots?
     *
     * @param  list<Element>  $slots
     */
    private function enclosed(Element $element, array $slots): bool
    {
        foreach ($slots as $slot) {
            if ($slot->encloses($element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Put an icon in front of a heading's words, inside the heading.
     *
     * The element is reflowed rather than edited in place, because a heading the
     * kit wrote on one line has three things in it afterwards and reads better
     * down the page than along it.
     *
     * @param  list<string>  $icon  the lines that draw it, indented against the heading
     */
    private function wrap(string $source, Element $element, array $icon, Edits $edits): void
    {
        $closes = strpos($source, '>', $element->closes);
        $open = $this->rowed($source, $element->open);

        if ($closes === false || $open === null) {
            return;
        }

        $start = $element->contentStart();
        $indent = $this->indent($source, $element->open->offset);

        $lines = [$open];

        foreach ($icon as $line) {
            $lines[] = $line === '' ? '' : $indent.'    '.$line;
        }

        $lines[] = $indent.'    '.trim(substr($source, $start, $element->closes - $start));
        $lines[] = $indent.substr($source, $element->closes, $closes + 1 - $element->closes);

        $edits->replace(
            $element->open->offset,
            $closes + 1 - $element->open->offset,
            implode("\n", $lines),
        );
    }

    /**
     * A heading's opening tag, laid out as a row.
     *
     * The class is appended to whatever the tag already carries rather than
     * written over it, since a heading the kit or the project styled keeps its
     * styling. A bound class is a decision refit cannot read, and a second
     * `class` beside it would be a fight between the two, so such a heading is
     * left alone — which is what the null says.
     */
    private function rowed(string $source, Tag $tag): ?string
    {
        if ($tag->has(':class')) {
            return null;
        }

        $block = substr($source, $tag->offset, $tag->length);
        $class = $tag->attribute('class');

        if (! $class instanceof Attribute || $class->value === null) {
            return substr_replace(
                $block,
                sprintf(' class="%s"', self::ROW),
                $tag->nameOffset() + strlen($tag->name) - $tag->offset,
                0,
            );
        }

        return substr_replace(
            $block,
            ' '.self::ROW,
            $class->valueOffset() + $class->valueLength() - $tag->offset,
            0,
        );
    }

    /**
     * Is this heading the one the layout draws the page's title with?
     */
    private function isTitle(string $source, Element $element): bool
    {
        $start = $element->contentStart();
        $contents = trim(substr($source, $start, $element->closes - $start));

        return preg_match(self::TITLE_ECHO, $contents) === 1;
    }

    /**
     * The icon element for a name the sweep knows here and now.
     */
    private function icon(string $name): string
    {
        return sprintf(
            '<%s %s="%s" class="%s" />',
            $this->vocabulary->iconTag,
            $this->vocabulary->iconTagAttribute,
            $name,
            self::CLASSES,
        );
    }

    /**
     * The icon element the layout draws its prop through, behind the guard that
     * keeps a page passing no icon rendering.
     *
     * @return list<string>
     */
    private function guarded(): array
    {
        return [
            '@isset($'.self::PROP.')',
            sprintf(
                '    <%s :%s="$%s" class="%s" />',
                $this->vocabulary->iconTag,
                $this->vocabulary->iconTagAttribute,
                self::PROP,
                self::CLASSES,
            ),
            '@endisset',
            '',
        ];
    }

    /**
     * Has this row already named an icon?
     *
     * Every attribute the library carries a name in counts, bound or literal: a
     * bound one is a decision refit cannot read but can certainly leave alone.
     */
    private function iconed(Tag $tag): bool
    {
        foreach ($this->vocabulary->nameAttributes as $attribute) {
            if ($tag->has($attribute) || $tag->has(':'.$attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Has this layout call already been given an icon to draw?
     *
     * The plain prop only. The layout is the kit's own anonymous component, not
     * a library one, and `icon` is the single name it reads.
     */
    private function named(Tag $tag): bool
    {
        return $tag->has(self::ATTRIBUTE) || $tag->has(':'.self::ATTRIBUTE);
    }

    /**
     * What an attribute says, where it says a label.
     *
     * A literal is the words themselves; a bound one is a label only when it is
     * a single `__()` around a literal string, which is how the kit writes every
     * one of these. Anything richer is a decision refit cannot read.
     */
    private function attributeLabel(Tag $tag, string $name): ?string
    {
        $literal = $tag->attribute($name);

        if ($literal instanceof Attribute && $literal->value !== null) {
            return $literal->value;
        }

        $bound = $tag->attribute(':'.$name);

        if (! $bound instanceof Attribute || $bound->value === null) {
            return null;
        }

        return preg_match(self::TRANSLATION, trim($bound->value), $matches) === 1
            ? $matches['text']
            : null;
    }

    /**
     * What a row's contents say, or null when they are not a label.
     */
    private function contentsLabel(string $source, ?Element $element): ?string
    {
        if (! $element instanceof Element) {
            return null;
        }

        $start = $element->contentStart();
        $contents = trim(substr($source, $start, $element->closes - $start));

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
    private function iconFor(string $label): ?string
    {
        return $this->icons[$this->key($label)] ?? null;
    }

    /**
     * What a label is matched on: the words rather than their spacing.
     *
     * Case and runs of whitespace are the two differences that say nothing — a
     * label is written across lines wherever the tag is, and "Log in" and "Log
     * In" are the same row — so they are also what one finding is collapsed on
     * when there is nothing to match.
     */
    private function key(string $label): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($label)) ?? $label);
    }
}
