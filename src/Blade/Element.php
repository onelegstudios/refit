<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Blade;

/**
 * An element and the source it encloses: an opening tag paired with the closing
 * tag {@see Nesting} matched it to.
 */
final class Element
{
    public function __construct(
        public readonly Tag $open,
        /** Absolute offset of the matching closing tag's `<`. */
        public readonly int $closes,
    ) {}

    public function name(): string
    {
        return $this->open->name;
    }

    /**
     * Absolute offset of the first character inside the element.
     */
    public function contentStart(): int
    {
        return $this->open->offset + $this->open->length;
    }

    /**
     * Is this offset inside the element, rather than in its opening tag?
     */
    public function holds(int $offset): bool
    {
        return $offset >= $this->contentStart() && $offset < $this->closes;
    }

    /**
     * Is the other element written inside this one?
     */
    public function encloses(self $other): bool
    {
        return $this->holds($other->open->offset);
    }
}
