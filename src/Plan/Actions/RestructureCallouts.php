<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Plan\Actions;

use Onelegstudios\Refit\Blade\Attribute;
use Onelegstudios\Refit\Blade\Edits;
use Onelegstudios\Refit\Blade\Tag;
use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;

/**
 * Write out the callout shorthands Sheaf's alert has no prop for.
 *
 * Flux lets a callout carry its two lines as attributes:
 *
 * ```blade
 * <flux:callout variant="danger" icon="x-circle" heading="{{ $message }}" />
 * ```
 *
 * Sheaf's alert takes both as children — `<x-ui.alerts.heading>` and
 * `<x-ui.alerts.description>` — and declares neither prop, so the rename alone
 * drops `heading` on the wrapper div as a stray HTML attribute and the callout
 * renders as an icon, a border and no words at all. Every starter kit variant
 * writes exactly the line above, and it is the one that tells you why a
 * two-factor code was rejected.
 *
 * The expansion is Flux's own: its component renders the shorthands by writing
 * `<flux:callout.heading>{{ $heading }}</flux:callout.heading>`, so this puts the
 * longhand the kit could have written into the file and leaves the rename to
 * translate it — the same two steps {@see RestructureOverlays} takes a tooltip's
 * `content` through. Which is why this runs before the rename, while the markup
 * still says `flux:`.
 *
 * A callout already writing its own children keeps them, and gains whichever
 * child the attribute was standing in for above them.
 */
final class RestructureCallouts extends BladeSweep
{
    private const string CALLOUT = 'flux:callout';

    /**
     * Shorthand attribute mapped to the child Flux expands it into.
     *
     * In render order, so a callout written with both keeps Flux's arrangement.
     * `text` is here for the same reason `flux:navlist.group` is in the map
     * though refit rewrites the layouts itself: nothing in the kit writes it, and
     * a project that has is owed the same rewrite rather than the same silence.
     *
     * @var array<string, string>
     */
    private const array SHORTHANDS = [
        'heading' => 'flux:callout.heading',
        'text' => 'flux:callout.text',
    ];

    /** @var list<string> */
    private array $touched = [];

    public function __construct(
        private readonly TagParser $parser = new TagParser,
    ) {}

    public function describe(): string
    {
        return 'expand callout headings into the children Sheaf draws them from';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $rewritten = $this->expand($source);

        if ($rewritten !== $source) {
            $this->touched[] = $path;
        }

        return $rewritten;
    }

    /**
     * Rewrite every callout in one file.
     *
     * Collected rather than spliced as they are found, so each replacement is
     * built against the offsets the parse handed out — the same ledger every
     * other sweep rewrites through.
     */
    private function expand(string $source): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, self::CALLOUT) as $tag) {
            if ($tag->name !== self::CALLOUT) {
                continue;
            }

            $this->expandOne($edits, $source, $tag);
        }

        return $edits->apply($source);
    }

    /**
     * Take one callout's shorthands off its tag and put them back as children.
     */
    private function expandOne(Edits $edits, string $source, Tag $tag): void
    {
        $shorthands = $this->shorthandsOn($tag);

        if ($shorthands === []) {
            return;
        }

        $indent = $this->indent($source, $tag->offset);
        $inner = $indent.'    ';

        $open = $this->without(
            substr($source, $tag->offset, $tag->length),
            $tag->offset,
            array_values($shorthands),
        );

        $children = '';

        foreach ($shorthands as $child => $attribute) {
            $children .= "\n".$inner.$this->childFor($child, $attribute);
        }

        // A self-closing callout has to grow a body before it can hold children,
        // so it is reopened and closed around them. One that already has a body
        // keeps it, with the children going in above what was there.
        $replacement = $tag->selfClosing
            ? $this->reopen($open).$children."\n".$indent.'</'.self::CALLOUT.'>'
            : $open.$children;

        $edits->replace($tag->offset, $tag->length, $replacement);
    }

    /**
     * The shorthand attributes written on this callout, keyed by their child tag.
     *
     * Both spellings answer to the same prop — `:heading` differs from `heading`
     * only in what its value holds — and a tag carrying both is Flux's problem
     * rather than this sweep's, so the first one found wins.
     *
     * @return array<string, Attribute>
     */
    private function shorthandsOn(Tag $tag): array
    {
        $found = [];

        foreach (self::SHORTHANDS as $name => $child) {
            $attribute = $tag->attribute($name) ?? $tag->attribute(':'.$name);

            // A boolean `heading` names no text to move, and taking it off would
            // lose whatever the author meant by it.
            if (! $attribute instanceof Attribute || $attribute->isBoolean()) {
                continue;
            }

            $found[$child] = $attribute;
        }

        return $found;
    }

    /**
     * The child element carrying what the shorthand said.
     *
     * A bound attribute holds a PHP expression, so it goes back through an echo;
     * a literal one is already the text Flux drew.
     */
    private function childFor(string $child, Attribute $attribute): string
    {
        $value = $attribute->value ?? '';

        $text = $attribute->isBound() ? '{{ '.trim($value).' }}' : $value;

        return '<'.$child.'>'.$text.'</'.$child.'>';
    }

    /**
     * A self-closing tag as an opening one.
     */
    private function reopen(string $open): string
    {
        $rewritten = preg_replace('/\s*\/>$/', '>', $open, 1);

        return $rewritten ?? $open;
    }

    protected function finish(Report $report): void
    {
        if ($this->touched === []) {
            return;
        }

        $report->note(sprintf(
            'Wrote out the callout headings in %d file(s). Sheaf\'s alert takes its heading as an '
            .'<x-ui.alerts.heading> child and declares no prop for one, so the attribute the kit writes had to '
            .'become the child Flux\'s own component expands it into — otherwise the two-factor errors render '
            .'as an empty red box.',
            count($this->touched),
        ));

        $this->touched = [];
    }
}
