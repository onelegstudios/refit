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
     * Heroicons to Phosphor, for the Sheaf projects that ask for Phosphor only.
     *
     * Needed for the same reason the Lucide table is: the two sets agree on
     * `check` and `folder` and disagree on nearly everything with more than one
     * word in it. Heroicons' `finger-print` is Phosphor's `fingerprint`, its
     * `x-mark` is `x`, and every chevron is a caret. A bare prefix would have
     * turned half the kit into components that do not exist.
     *
     * Names spelled the same in both sets are listed mapping to themselves, so
     * that being in the table is what decides a name gets the prefix, and a name
     * refit has never heard of is left as the Heroicon it already is.
     *
     * Verified against `wireui/phosphoricons`, in every weight it ships.
     *
     * @var array<string, string>
     */
    public const array HEROICONS_TO_PHOSPHOR = [
        'arrow-path' => 'arrows-clockwise',
        'arrow-right-start-on-rectangle' => 'sign-out',
        'bars-2' => 'list',
        'book-open' => 'book-open',
        'calendar' => 'calendar-blank',
        'check' => 'check',
        'chevron-down' => 'caret-down',
        'chevron-left' => 'caret-left',
        'chevron-right' => 'caret-right',
        'chevron-up' => 'caret-up',
        'chevron-up-down' => 'caret-up-down',
        'clipboard-document' => 'clipboard',
        // Phosphor has no clipboard-with-a-tick, and this name only ever marks
        // the copied half of a copy button, where the tick is the whole message.
        'clipboard-document-check' => 'check',
        'clock' => 'clock',
        'cloud-arrow-up' => 'cloud-arrow-up',
        // Phosphor draws no code-in-a-square; the code block is its nearest.
        'code-bracket-square' => 'code-simple',
        'cog' => 'gear',
        'computer-desktop' => 'desktop',
        'document' => 'file',
        'document-duplicate' => 'copy',
        'envelope' => 'envelope',
        'exclamation-circle' => 'warning-circle',
        'exclamation-triangle' => 'warning',
        'eye' => 'eye',
        'eye-dropper' => 'eyedropper',
        'eye-slash' => 'eye-slash',
        'finger-print' => 'fingerprint',
        'folder' => 'folder',
        'home' => 'house',
        'information-circle' => 'info',
        'key' => 'key',
        'lock-closed' => 'lock-simple',
        'magnifying-glass' => 'magnifying-glass',
        'minus' => 'minus',
        'moon' => 'moon',
        'plus' => 'plus',
        'qr-code' => 'qr-code',
        'squares-2x2' => 'squares-four',
        'sun' => 'sun',
        'trash' => 'trash',
        'user-plus' => 'user-plus',
        'users' => 'users',
        'x-circle' => 'x-circle',
        'x-mark' => 'x',
        // No entry for `slash`: Phosphor has no bare solidus, and a breadcrumb
        // separator drawn as something else would read as a different control.
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

    public static function toPhosphor(string $heroicon): ?string
    {
        return self::HEROICONS_TO_PHOSPHOR[$heroicon] ?? null;
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
