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
 * Restate the contrast Sheaf's text component sits at the opposite end of.
 *
 * Flux draws `text` and `subheading` identically, and draws both muted:
 * `[:where(&)]:text-zinc-500 [:where(&)]:dark:text-white/70`. Sheaf's `text` is
 * the other end of the scale — `text-neutral-950 dark:text-neutral-50`, and
 * written bare rather than inside `:where()`. So the rename that collapses both
 * Flux tags onto `<x-ui.text>` is right about the component and wrong about the
 * contrast: left alone, every secondary line the kit writes — the sentence under
 * each settings heading, the description under each auth title — comes out at
 * full body contrast, indistinguishable from the text above it.
 *
 * Sheaf mutes text with opacity rather than with a second component, so that is
 * what is restated here: `opacity-75` is [the documented muted step][1] and
 * `opacity-50` the more muted one. Opacity composes with whatever colour the
 * component declares instead of competing with it on specificity, which is the
 * reason it is the right tool and a `text-neutral-500` class would not be.
 *
 * Three of Flux's props go the same way, because `x-ui.text` declares no props at
 * all — `text.blade.php` is one `<div>` with `{{ $attributes->class(...) }}` —
 * so each of them lands on the rendered element as a stray HTML attribute that
 * styles nothing:
 *
 * - `variant="subtle"` is the fainter of Flux's two muted greys.
 * - `color="red"` is the invalid-code message on the two-factor challenge, which
 *   otherwise renders in ordinary body colour.
 * - `size="lg"` is the settings-page subheading.
 *
 * Note the asymmetry in `!`. Sheaf writes its size inside `[:where(&)]:` but its
 * colour bare, so a plain `text-base` beats `[:where(&)]:text-sm` on specificity
 * while a plain `text-red-600` only ties with `text-neutral-950` — the same
 * reasoning {@see PreserveTextAlignment} documents for `text-center!`.
 *
 * The kit's own `text-zinc-500 dark:text-zinc-400` pairs come off at the same
 * time. They used to override a specificity-0 Flux default and always win;
 * against Sheaf's bare `text-neutral-950` they are a flat tie, decided by
 * whichever utility Tailwind happens to emit last. Dropping them leaves the kit
 * with one muting mechanism rather than two, one of which is a coin flip.
 *
 * Runs after the rename, over `x-ui.text`, which is the vocabulary the rest of
 * the after-the-rename group reads.
 *
 * [1]: https://sheafui.dev/docs/components/text#content-muted-text
 */
final class MuteSecondaryText extends BladeSweep
{
    /** The one Sheaf component both of Flux's secondary text tags became. */
    private const string TARGET = 'x-ui.text';

    /** Sheaf's documented muted step. */
    private const string MUTED = 'opacity-75';

    /** And the more muted one below it. */
    private const string SUBTLE = 'opacity-50';

    /**
     * The two props that decide how dark the text is, keyed by prop name, each
     * holding the value it has to carry and the classes that say the same thing.
     *
     * Anything the kit does not flag one of these ways is the ordinary secondary
     * line, and takes {@see MUTED}. Red is the one case that takes no opacity at
     * all: dimming a rejected two-factor code is the opposite of the point.
     *
     * @var array<string, array{string, string}>
     */
    private const array CONTRAST = [
        'variant' => ['subtle', self::SUBTLE],
        'color' => ['red', 'text-red-600! dark:text-red-400!'],
    ];

    /**
     * The one prop that is orthogonal to contrast.
     *
     * A large subheading is still a muted one, so this takes its class alongside
     * whichever contrast rule fired rather than instead of it.
     *
     * @var array{string, string, string}
     */
    private const array SIZE = ['size', 'lg', 'text-base'];

    /** A text colour, as against a text size or a text alignment. */
    private const string COLOUR = '/^text-(?:[a-z]+-\d{2,3}|white|black|current|inherit|transparent)(?:\/\d{1,3})?$/';

    /** The muted grey the kit writes by hand, in every palette it might have picked. */
    private const string GREY = '/^text-(?:zinc|neutral|gray|grey|slate|stone)-(?:400|500|600)$/';

    public function __construct(
        private readonly TagParser $parser = new TagParser,
    ) {}

    public function describe(): string
    {
        return 'mute   the secondary text Sheaf renders at full contrast';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        // Sheaf's own components are Sheaf's to colour. This sweep is about the
        // application views that render them.
        if (str_starts_with($path, SheafLibrary::COMPONENT_DIRECTORY.'/')) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->parser->parse($source, self::TARGET) as $tag) {
            if ($tag->name !== self::TARGET) {
                continue;
            }

            $this->mute($edits, $source, $tag);
        }

        return $edits->apply($source);
    }

    /**
     * Say in classes what the tag used to say in props and in inheritance.
     *
     * A tag whose classes are bound is left alone — a `:class` expression is a
     * decision the application made, and appending to it would mean editing PHP —
     * and so is one that has already had its say about contrast, whether by
     * carrying an `opacity-` or by flagging a colour of its own with `!`. The
     * four `!text-green-600` success messages in the kit are that second case:
     * they are deliberate, and dimming them would be a rewrite nobody asked for.
     */
    private function mute(Edits $edits, string $source, Tag $tag): void
    {
        if ($tag->has(':class')) {
            return;
        }

        $class = $tag->attribute('class');
        $classes = $this->tokens($class instanceof Attribute ? $class->value ?? '' : '');

        if ($this->declaresContrast($classes)) {
            return;
        }

        $added = [];
        $dropped = [];

        foreach (self::CONTRAST as $name => [$value, $utilities]) {
            $prop = $this->saying($tag, $name, $value);

            if ($prop instanceof Attribute) {
                $added[] = $utilities;
                $dropped[] = $prop;
            }
        }

        // Nothing louder was asked for, so this is an ordinary secondary line.
        if ($added === []) {
            $added[] = self::MUTED;
        }

        [$name, $value, $utilities] = self::SIZE;
        $size = $this->saying($tag, $name, $value);

        if ($size instanceof Attribute) {
            $added[] = $utilities;
            $dropped[] = $size;
        }

        foreach ($dropped as $prop) {
            $this->drop($edits, $source, $prop);
        }

        $this->reclass($edits, $class, $tag, $classes, $added);
    }

    /**
     * Has this class list already said something about how dark the text is?
     *
     * @param  list<string>  $classes
     */
    private function declaresContrast(array $classes): bool
    {
        foreach ($classes as $class) {
            $utility = $this->utility($class);

            if (str_starts_with($utility, 'opacity-')) {
                return true;
            }

            if ($this->flagged($class) && preg_match(self::COLOUR, $utility) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write the class attribute back, with the hand-written grey taken out of it.
     *
     * The value is only rebuilt when something is actually removed; otherwise the
     * additions are appended, so a class list refit has nothing to say about
     * keeps whatever spacing the kit gave it.
     *
     * @param  list<string>  $classes
     * @param  list<string>  $added
     */
    private function reclass(Edits $edits, ?Attribute $class, Tag $tag, array $classes, array $added): void
    {
        if (! $class instanceof Attribute) {
            $edits->replace(
                $tag->nameOffset() + strlen($tag->name),
                0,
                sprintf(' class="%s"', implode(' ', $added)),
            );

            return;
        }

        $kept = array_values(array_filter(
            $classes,
            fn (string $token): bool => preg_match(self::GREY, $this->utility($token)) !== 1,
        ));

        if ($kept === $classes) {
            $edits->replace(
                $class->valueOffset() + $class->valueLength(),
                0,
                ($classes === [] ? '' : ' ').implode(' ', $added),
            );

            return;
        }

        $edits->replace(
            $class->valueOffset(),
            $class->valueLength(),
            implode(' ', [...$kept, ...$added]),
        );
    }

    /**
     * The attribute, when the tag sets it to exactly the literal value given.
     *
     * A bound prop holds an expression rather than a word refit can read, and a
     * value refit has no translation for is left where it is rather than dropped
     * for a class that would only be a guess.
     */
    private function saying(Tag $tag, string $name, string $value): ?Attribute
    {
        $attribute = $tag->attribute($name);

        return $attribute instanceof Attribute
            && ! $attribute->isBound()
            && $attribute->value === $value
                ? $attribute
                : null;
    }

    /**
     * Take an attribute off the tag, with the whitespace that introduced it.
     */
    private function drop(Edits $edits, string $source, Attribute $attribute): void
    {
        $from = $attribute->offset;

        while ($from > 0 && in_array($source[$from - 1], TagParser::WHITESPACE, true)) {
            $from--;
        }

        $edits->replace($from, $attribute->offset - $from + $attribute->length, '');
    }

    /**
     * @return list<string>
     */
    private function tokens(string $classes): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($classes)) ?: []));
    }

    /**
     * A class with its `!` and its variants taken off, e.g. `text-green-400` for
     * `!dark:text-green-400`.
     */
    private function utility(string $class): string
    {
        return trim((string) strrchr(':'.trim($class, '!'), ':'), ':');
    }

    /**
     * Tailwind takes the `!` at either end, and the kit writes both.
     */
    private function flagged(string $class): bool
    {
        return str_starts_with($class, '!') || str_ends_with($class, '!');
    }
}
