<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Blade;

/**
 * Targeted rewrites over Blade component tags.
 *
 * Every method is a pure string transform so the whole rewriting surface can be
 * unit tested against inline snippets, with no filesystem and no application.
 */
final class TagRewriter
{
    public function __construct(private readonly TagParser $parser = new TagParser) {}

    /**
     * Rename a component tag, both its opening and closing forms.
     *
     * `<x-app-logo :sidebar="true" />` and the matching `</x-app-logo>` move
     * together, and no other tag that merely starts with the same characters is
     * touched.
     */
    public function rename(string $source, string $from, string $to): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $from) as $tag) {
            if ($tag->name !== $from) {
                continue;
            }

            $edits->replace($tag->nameOffset(), strlen($from), $to);
        }

        $pattern = '/<\/'.preg_quote($from, '/').'(\s*)>/';

        $source = $edits->apply($source);

        $replaced = preg_replace($pattern, '</'.$to.'$1>', $source);

        return $replaced ?? $source;
    }

    /**
     * Rename every tag under a prefix, deciding each one by callback.
     *
     * The bulk form of {@see rename()}, for when the whole set of renames is
     * known up front — a library migration renames forty tags, and forty passes
     * over the same string would be forty chances to disagree with each other.
     * Returning null from the callback leaves that tag alone.
     *
     * @param  callable(string): (string|null)  $resolve
     */
    public function renameTags(string $source, string $prefix, callable $resolve): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            $to = $resolve($tag->name);

            if ($to === null || $to === $tag->name) {
                continue;
            }

            $edits->replace($tag->nameOffset(), strlen($tag->name), $to);
        }

        $source = $edits->apply($source);

        $rewritten = preg_replace_callback(
            '/<\/('.preg_quote($prefix, '/').'[A-Za-z0-9_\-.:]*)(\s*)>/',
            static function (array $matches) use ($resolve): string {
                $to = $resolve($matches[1]);

                return $to === null ? $matches[0] : '</'.$to.$matches[2].'>';
            },
            $source,
        );

        return $rewritten ?? $source;
    }

    /**
     * Fold a dotted tag name down into an attribute.
     *
     * `<flux:icon.home />` becomes `<x-ui.icon name="home" />`, because Flux names
     * an icon in the tag where Sheaf names it in an attribute. The callback maps
     * the suffix to the attribute value, or returns null to leave the tag alone —
     * which is how a suffix that has a component of its own, such as Flux's
     * `icon.loading`, escapes being flattened.
     *
     * @param  callable(string): (string|null)  $accept
     */
    public function collapseNameSuffix(
        string $source,
        string $prefix,
        string $to,
        string $attribute,
        callable $accept,
    ): string {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            $suffix = $tag->nameAfter($prefix);

            if ($suffix === null || $suffix === '') {
                continue;
            }

            $value = $accept($suffix);

            if ($value === null) {
                continue;
            }

            $edits->replace(
                $tag->nameOffset(),
                strlen($tag->name),
                sprintf('%s %s="%s"', $to, $attribute, $value),
            );
        }

        $source = $edits->apply($source);

        $rewritten = preg_replace_callback(
            '/<\/'.preg_quote($prefix, '/').'([A-Za-z0-9_\-.:]+)(\s*)>/',
            static fn (array $matches): string => $accept($matches[1]) === null
                ? $matches[0]
                : '</'.$to.$matches[2].'>',
            $source,
        );

        return $rewritten ?? $source;
    }

    /**
     * Rename attributes on tags under a prefix, keeping their values.
     *
     * @param  callable(Tag, Attribute): (string|null)  $resolve
     */
    public function renameAttributes(string $source, string $prefix, callable $resolve): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            foreach ($tag->attributes as $attribute) {
                $to = $resolve($tag, $attribute);

                if ($to === null || $to === $attribute->name) {
                    continue;
                }

                $edits->replace($attribute->offset, strlen($attribute->name), $to);
            }
        }

        return $edits->apply($source);
    }

    /**
     * Rewrite the literal value of named attributes on tags under a prefix.
     *
     * The callback receives the tag, the attribute, and its current value, and
     * returns a replacement or null to leave it alone. Bound and boolean
     * attributes are skipped: their value is a PHP expression, not a literal.
     *
     * @param  list<string>  $names
     * @param  callable(Tag, Attribute, string): (string|null)  $callback
     */
    public function rewriteAttributeValues(
        string $source,
        string $prefix,
        array $names,
        callable $callback,
    ): string {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            foreach ($tag->attributes as $attribute) {
                if (! in_array($attribute->name, $names, true)) {
                    continue;
                }

                if ($attribute->isBound() || $attribute->isBoolean() || $attribute->quote === '') {
                    continue;
                }

                $value = (string) $attribute->value;
                $replacement = $callback($tag, $attribute, $value);

                if ($replacement === null || $replacement === $value) {
                    continue;
                }

                $edits->replace($attribute->valueOffset(), $attribute->valueLength(), $replacement);
            }
        }

        return $edits->apply($source);
    }

    /**
     * Add an attribute to every tag with the given name that lacks it.
     *
     * A tag that already names the attribute — literally or bound — is left
     * alone: the value sitting there is a decision the application has already
     * made, and overruling it would be a rewrite the user did not ask for.
     */
    public function addAttribute(string $source, string $name, string $attribute, string $value): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $name) as $tag) {
            if ($tag->name !== $name) {
                continue;
            }

            if ($tag->has($attribute) || $tag->has(':'.$attribute)) {
                continue;
            }

            $edits->replace(
                $tag->nameOffset() + strlen($name),
                0,
                sprintf(' %s="%s"', $attribute, $value),
            );
        }

        return $edits->apply($source);
    }

    /**
     * Drop attributes from tags under a prefix, whitespace and all.
     *
     * The callback decides per attribute. The run of whitespace in front of the
     * attribute goes with it, so a multi-line tag closes up instead of keeping a
     * blank line where the attribute used to be.
     *
     * @param  callable(Tag, Attribute): bool  $callback
     */
    public function removeAttributes(string $source, string $prefix, callable $callback): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            foreach ($tag->attributes as $attribute) {
                if (! $callback($tag, $attribute)) {
                    continue;
                }

                $start = $attribute->offset;

                while ($start > 0 && in_array($source[$start - 1], TagParser::WHITESPACE, true)) {
                    $start--;
                }

                $edits->replace($start, $attribute->offset + $attribute->length - $start, '');
            }
        }

        return $edits->apply($source);
    }

    /**
     * Rewrite the dotted suffix of tags such as `<flux:icon.home />`.
     *
     * @param  callable(string): (string|null)  $callback
     */
    public function rewriteNameSuffix(string $source, string $prefix, callable $callback): string
    {
        $edits = new Edits;

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            $suffix = $tag->nameAfter($prefix);

            if ($suffix === null || $suffix === '') {
                continue;
            }

            $replacement = $callback($suffix);

            if ($replacement === null || $replacement === $suffix) {
                continue;
            }

            $edits->replace(
                $tag->nameOffset() + strlen($prefix),
                strlen($suffix),
                $replacement,
            );
        }

        return $edits->apply($source);
    }
}
