<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Blade;

/**
 * Pairs opening tags with the closing tags that end them.
 *
 * {@see TagParser} answers "where are the tags"; this answers "what does each
 * one contain", which is what a rewrite needs whenever the markup around a tag
 * decides its meaning — a wrapper that centres its contents, or a panel whose
 * direct children have to declare a place in a grid.
 *
 * Pairing is per name and stack-based, so nesting of the same element is handled
 * and the tags of every other element are ignored. An opening tag that never
 * closes, and a closing tag with nothing open, are both dropped: a Blade branch
 * can legitimately write either, and neither describes a span of source.
 */
final class Nesting
{
    public function __construct(private readonly TagParser $parser = new TagParser) {}

    /**
     * Every element whose name starts with one of the prefixes, in source order.
     *
     * A prefix is whatever {@see TagParser::parse()} takes: an exact name such as
     * `div`, or the leading portion of a family such as `x-ui.`.
     *
     * @param  list<string>  $prefixes
     * @return list<Element>
     */
    public function elements(string $source, array $prefixes): array
    {
        $elements = [];

        foreach ($prefixes as $prefix) {
            foreach ($this->names($source, $prefix) as $name) {
                foreach ($this->pair($source, $name) as $element) {
                    $elements[] = $element;
                }
            }
        }

        usort($elements, static fn (Element $a, Element $b): int => $a->open->offset <=> $b->open->offset);

        return $elements;
    }

    /**
     * The distinct tag names written under one prefix.
     *
     * @return list<string>
     */
    private function names(string $source, string $prefix): array
    {
        $names = [];

        foreach ($this->parser->parse($source, $prefix) as $tag) {
            $names[$tag->name] = true;
        }

        return array_keys($names);
    }

    /**
     * Pair up one element's tags.
     *
     * @return list<Element>
     */
    private function pair(string $source, string $name): array
    {
        $events = [];

        foreach ($this->parser->parse($source, $name) as $tag) {
            if ($tag->name === $name && ! $tag->selfClosing) {
                $events[$tag->offset] = $tag;
            }
        }

        if (preg_match_all('/<\/'.preg_quote($name, '/').'\s*>/', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $events[(int) $match[1]] = null;
            }
        }

        ksort($events);

        $stack = [];
        $elements = [];

        foreach ($events as $offset => $tag) {
            if ($tag instanceof Tag) {
                $stack[] = $tag;

                continue;
            }

            $open = array_pop($stack);

            if ($open instanceof Tag) {
                $elements[] = new Element($open, $offset);
            }
        }

        return $elements;
    }
}
