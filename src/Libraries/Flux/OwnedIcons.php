<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Flux;

use Onelegstudios\Refit\Icons\IconMap;

/**
 * Icon names Flux owns rather than resolving from an icon set.
 *
 * `flux:icon.loading` is Flux's own spinner, not a Heroicon, and Flux renders it
 * from inside `flux:button` as well as from application code. So it is overridden
 * in place rather than renamed: an override at Flux's own name reaches both, where
 * a rename would only reach the views refit can rewrite and leave the two spinners
 * drawn differently.
 *
 * Lucide's `loader-circle` is a still drawing, so the override also needs a class
 * the artwork cannot carry on its own.
 *
 * These are facts about Flux, not about Heroicons or Lucide, which is why they sit
 * beside the Flux library rather than in the set-to-set tables of
 * {@see IconMap}.
 */
final class OwnedIcons
{
    /**
     * Flux's own name mapped to the Lucide artwork that stands in for it.
     *
     * @var array<string, string>
     */
    public const array ARTWORK = [
        'loading' => 'loader-circle',
    ];

    /**
     * Classes an override needs on top of the ones the kit's template applies,
     * keyed by the name the override file is written at.
     *
     * @var array<string, string>
     */
    public const array EXTRA_CLASSES = [
        'loading' => 'animate-spin',
    ];

    /**
     * Is this a name Flux draws itself, which keeps its name when overridden?
     */
    public static function owns(string $name): bool
    {
        return array_key_exists($name, self::ARTWORK);
    }

    public static function artwork(string $name): ?string
    {
        return self::ARTWORK[$name] ?? null;
    }

    public static function extraClasses(string $overrideName): ?string
    {
        return self::EXTRA_CLASSES[$overrideName] ?? null;
    }
}
