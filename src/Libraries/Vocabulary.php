<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries;

/**
 * How one UI library spells its component tags and names its icons.
 *
 * Everything the Blade rewriters need to work on a library without knowing which
 * one they are looking at. The two libraries refit ships disagree on every field
 * here, which is the whole reason this is a value object rather than a set of
 * constants: Flux writes `<flux:icon.home />` and `icon-trailing`, Sheaf writes
 * `<x-ui.icon name="home" />` and `iconAfter`.
 */
final class Vocabulary
{
    /**
     * @param  string  $prefix  What every component tag starts with — `flux:`, `x-ui.`.
     * @param  string  $iconTag  The generic icon component's full tag name.
     * @param  string  $iconTagAttribute  The attribute {@see $iconTag} takes its name from.
     * @param  list<string>  $nameAttributes  Attributes that carry an icon name on any component.
     * @param  string|null  $dottedIconTag  The dotted form's prefix (`flux:icon.`), or null when the library has no such form.
     * @param  string|null  $variantAttribute  The attribute holding an icon's own weight, or null.
     * @param  string|null  $iconVariantAttribute  The pass-through form of the same, or null.
     */
    public function __construct(
        public readonly string $prefix,
        public readonly string $iconTag,
        public readonly string $iconTagAttribute,
        public readonly array $nameAttributes,
        public readonly ?string $dottedIconTag = null,
        public readonly ?string $variantAttribute = null,
        public readonly ?string $iconVariantAttribute = null,
    ) {}

    /**
     * Every attribute worth parsing, before the per-tag rules are applied.
     *
     * @return list<string>
     */
    public function candidateAttributes(): array
    {
        return array_values(array_unique([...$this->nameAttributes, $this->iconTagAttribute]));
    }

    /**
     * Does this attribute name an icon, given the tag it sits on?
     *
     * The tag matters. Flux's icon component takes its name from `name`, but
     * treating `name` as an icon everywhere would rewrite the `name="email"` on
     * every `<flux:input>` in the kit.
     */
    public function namesAnIcon(string $tag, string $attribute): bool
    {
        if (in_array($attribute, $this->nameAttributes, true)) {
            return true;
        }

        return $tag === $this->iconTag && $attribute === $this->iconTagAttribute;
    }

    /**
     * The variant attributes that describe an icon's weight, if the library has any.
     *
     * @return list<string>
     */
    public function variantAttributes(): array
    {
        return array_values(array_filter(
            [$this->variantAttribute, $this->iconVariantAttribute],
            static fn (?string $name): bool => $name !== null,
        ));
    }
}
