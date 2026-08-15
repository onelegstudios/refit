<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Icons;

use Onelegstudios\Refit\Libraries\Flux\OwnedIcons;
use Onelegstudios\Refit\Libraries\Vocabulary;

/**
 * Curated translations between the icon sets the starter kit uses.
 *
 * These are semantic, not mechanical: Heroicons' `arrow-right-start-on-rectangle`
 * is Lucide's `log-out`, and `magnifying-glass` is `search`. The set covers every
 * name the five starter kit variants reference, plus the ones Flux's own stubs
 * render. Anything outside it is reported rather than guessed at.
 *
 * Set to set only. Which attribute carries a name, and which names a library
 * draws itself, are facts about a library rather than about an icon set — those
 * live in {@see Vocabulary} and
 * {@see OwnedIcons}.
 */
final class IconMap
{
    /**
     * @var array<string, string>
     */
    public const array HEROICONS_TO_LUCIDE = [
        'arrow-path' => 'refresh-cw',
        'arrow-right-start-on-rectangle' => 'log-out',
        'bars-2' => 'menu',
        'calendar' => 'calendar',
        'check' => 'check',
        'chevron-down' => 'chevron-down',
        'chevron-left' => 'chevron-left',
        'chevron-right' => 'chevron-right',
        'chevron-up' => 'chevron-up',
        'chevron-up-down' => 'chevrons-up-down',
        'clipboard-document' => 'clipboard',
        'clipboard-document-check' => 'clipboard-check',
        'clock' => 'clock',
        'cloud-arrow-up' => 'cloud-upload',
        'cog' => 'settings',
        'computer-desktop' => 'monitor',
        // Heroicons' plain page, so Lucide's plain `file` rather than `file-text`.
        'document' => 'file',
        'document-duplicate' => 'copy',
        'envelope' => 'mail',
        'exclamation-triangle' => 'triangle-alert',
        'eye' => 'eye',
        // Lucide files the eyedropper under the tool's name.
        'eye-dropper' => 'pipette',
        'eye-slash' => 'eye-off',
        'finger-print' => 'fingerprint-pattern',
        'home' => 'house',
        'information-circle' => 'info',
        'key' => 'key-round',
        'lock-closed' => 'lock',
        'magnifying-glass' => 'search',
        'minus' => 'minus',
        'moon' => 'moon',
        'plus' => 'plus',
        'qr-code' => 'qr-code',
        'slash' => 'slash',
        'sun' => 'sun',
        'trash' => 'trash-2',
        'user-plus' => 'user-plus',
        'users' => 'users',
        'x-circle' => 'circle-x',
        'x-mark' => 'x',
    ];

    /**
     * The reverse direction only needs to cover the Lucide icons the kit vendors
     * in, since everything else is already a Heroicon.
     *
     * @var array<string, string>
     */
    public const array LUCIDE_TO_HEROICONS = [
        'book-open-text' => 'book-open',
        'chevrons-up-down' => 'chevron-up-down',
        'folder-git-2' => 'folder',
        'layout-grid' => 'squares-2x2',
    ];

    public static function toLucide(string $heroicon): ?string
    {
        return self::HEROICONS_TO_LUCIDE[$heroicon] ?? null;
    }

    public static function toHeroicons(string $lucide): ?string
    {
        return self::LUCIDE_TO_HEROICONS[$lucide] ?? null;
    }

    /**
     * Names that are spelled the same in both sets need no rewrite, only an
     * override file.
     */
    public static function isSharedName(string $name): bool
    {
        return (self::HEROICONS_TO_LUCIDE[$name] ?? null) === $name;
    }
}
