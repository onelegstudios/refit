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
 * Put the logo tile back under the mark, where Sheaf's brand will render it.
 *
 * The kit hands its logo to the brand as a slot carrying classes:
 *
 * ```blade
 * <x-slot name="logo" class="flex aspect-square size-8 ... bg-accent-content">
 *     <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
 * </x-slot>
 * ```
 *
 * Flux's brand renders that slot as `<div {{ $logo->attributes->class(...) }}>`,
 * so those classes become the accent tile the mark sits on. Sheaf's brand renders
 * `{{ $logo }}` and nothing else, so the attributes are dropped on the floor —
 * and with the tile goes the only background the mark contrasts with. The kit's
 * icon is `text-white dark:text-black`, so what is left is a white mark on a
 * near-white sidebar in light mode and a black one on a near-black sidebar in
 * dark mode. The logo does not so much move as disappear.
 *
 * So the classes come off the slot and go onto an element inside it, which is
 * the same element Flux was rendering, written where Sheaf will keep it. Nothing
 * about the classes changes — a project that has restyled its tile keeps exactly
 * the tile it wrote.
 */
final class RestructureBrandLogo extends BladeSweep
{
    private const string BRAND = 'x-ui.brand';

    private const string SLOT = 'x-slot';

    /** The one slot Sheaf's brand reads, in both spellings Blade allows. */
    private const string LOGO = 'logo';

    public function __construct(private readonly TagParser $parser = new TagParser) {}

    public function describe(): string
    {
        return 'shape  the brand logo into the tile Sheaf will render';
    }

    protected function transform(string $source, string $path, Project $project, Report $report): string
    {
        $slots = $this->parser->parse($source, self::SLOT);

        if ($slots === []) {
            return $source;
        }

        $edits = new Edits;

        foreach ($this->parser->parse($source, self::BRAND) as $brand) {
            if ($brand->name !== self::BRAND || $brand->selfClosing) {
                continue;
            }

            $closes = strpos($source, '</'.self::BRAND, $brand->offset);

            if ($closes === false) {
                continue;
            }

            foreach ($slots as $slot) {
                if ($slot->offset < $brand->offset || $slot->offset > $closes) {
                    continue;
                }

                $this->ground($edits, $source, $slot);
            }
        }

        return $edits->apply($source);
    }

    /**
     * Move one logo slot's classes onto a wrapper inside it.
     */
    private function ground(Edits $edits, string $source, Tag $slot): void
    {
        if ($slot->selfClosing || ! $this->isLogo($slot)) {
            return;
        }

        $class = $slot->attribute('class');

        if (! $class instanceof Attribute || $class->value === null || trim($class->value) === '') {
            return;
        }

        $start = $slot->offset + $slot->length;
        $closes = strpos($source, '</'.self::SLOT, $start);

        if ($closes === false) {
            return;
        }

        $edits->replace(
            $this->attributeStart($source, $class),
            $class->offset + $class->length - $this->attributeStart($source, $class),
            '',
        );

        $edits->replace(
            $start,
            $closes - $start,
            $this->tile(substr($source, $start, $closes - $start), $class->value, $this->indent($source, $slot->offset)),
        );
    }

    /**
     * Is this the slot Sheaf's brand renders?
     *
     * Blade spells a named slot two ways, and the kit's own file uses one while
     * refit's stubs use the other, so both have to be recognised.
     */
    private function isLogo(Tag $slot): bool
    {
        if ($slot->name === self::SLOT.':'.self::LOGO) {
            return true;
        }

        $name = $slot->attribute('name');

        return $slot->name === self::SLOT && $name instanceof Attribute && $name->value === self::LOGO;
    }

    /**
     * The slot's contents, wrapped in the element that carries the classes.
     */
    private function tile(string $contents, string $classes, string $indent): string
    {
        // A slot written on one line is put back on one line; only a slot the kit
        // spread over several earns the indented form.
        if (preg_match('/\R/', $contents) !== 1) {
            return sprintf('<div class="%s">%s</div>', $classes, trim($contents));
        }

        $body = preg_replace('/^\R+/', '', rtrim($contents)) ?? '';

        $lines = array_map(
            static fn (string $line): string => trim($line) === '' ? '' : '    '.$line,
            preg_split('/\R/', $body) ?: [],
        );

        return sprintf(
            "\n%s    <div class=\"%s\">\n%s\n%s    </div>\n%s",
            $indent,
            $classes,
            implode("\n", $lines),
            $indent,
            $indent,
        );
    }

    /**
     * Where an attribute starts once the whitespace in front of it is counted as
     * part of it, so removing it does not leave a double space behind.
     */
    private function attributeStart(string $source, Attribute $attribute): int
    {
        $start = $attribute->offset;

        while ($start > 0 && in_array($source[$start - 1], TagParser::WHITESPACE, true)) {
            $start--;
        }

        return $start;
    }
}
