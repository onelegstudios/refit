<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Project;

/**
 * A UI library that is actually installed in the project.
 *
 * Only present libraries are recorded, so holding one of these at all means the
 * library was found. `pro` is the paid tier both libraries refit knows about
 * happen to have — Flux through a licensed Composer package, Sheaf through an
 * account the CLI logs in to.
 */
final class LibraryInstall
{
    public function __construct(
        public readonly string $key,
        public readonly bool $pro = false,
        public readonly ?string $version = null,
    ) {}

    public function describe(): string
    {
        return implode(' ', array_filter([
            $this->pro ? 'Pro' : 'free tier',
            $this->version,
        ]));
    }
}
