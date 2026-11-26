<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Tasks;

enum TaskGroup: string
{
    case Structure = 'structure';
    case Naming = 'naming';
    case Tooling = 'tooling';
    case Cleanup = 'cleanup';

    public function label(): string
    {
        return match ($this) {
            self::Structure => 'Structure',
            self::Naming => 'Naming',
            self::Tooling => 'Tooling',
            self::Cleanup => 'Cleanup',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::Structure => 1,
            self::Naming => 2,
            self::Tooling => 3,
            self::Cleanup => 4,
        };
    }
}
