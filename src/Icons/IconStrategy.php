<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Icons;

use Onelegstudios\Refit\Contracts\Library;

/**
 * What to do about the starter kit's mixed icon set.
 *
 * Flux ships Heroicons and resolves them by bare name. The Livewire kit then
 * vendors a handful of Lucide icons in as `resources/views/flux/icon/*.blade.php`
 * overrides, because those names have no Heroicons equivalent — so a fresh
 * install is already speaking two icon sets at once.
 *
 * Not every answer makes sense for every target, so the list on offer comes from
 * the chosen library's {@see Library::iconStrategies()}
 * rather than from this enum. `Keep` only means something while the library stays
 * put, and `Lucide` depends on Flux's override mechanism.
 */
enum IconStrategy: string
{
    /** Leave the mix alone. */
    case Keep = 'keep';

    /** Drop the Lucide overrides and map their usages onto Heroicons. */
    case Heroicons = 'heroicons';

    /** Move everything to Lucide, including the icons Flux renders internally. */
    case Lucide = 'lucide';

    /** Move everything to Phosphor, which Sheaf can install alongside itself. */
    case Phosphor = 'phosphor';

    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Keep the current mix of Heroicons and Lucide',
            self::Heroicons => 'Heroicons only',
            self::Lucide => 'Lucide only',
            self::Phosphor => 'Phosphor only',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Keep => 'Changes nothing — what a fresh starter kit gives you',
            self::Heroicons => 'Drops the kit\'s vendored Lucide overrides for names the set already has',
            self::Lucide => 'Generates Flux icon overrides so even Flux\'s own internals match',
            self::Phosphor => 'Installs Phosphor with Sheaf and prefixes every icon name with ps:',
        };
    }
}
