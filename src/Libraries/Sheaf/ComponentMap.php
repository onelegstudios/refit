<?php

declare(strict_types=1);

namespace Onelegstudios\Refit\Libraries\Sheaf;

use Onelegstudios\Refit\Icons\IconMap;

/**
 * Curated translations from Flux's components to Sheaf's.
 *
 * Semantic, not mechanical, in the same way {@see IconMap}
 * is: Flux's `callout` is Sheaf's `alerts`, and Flux's `menu.*` is Sheaf's
 * `dropdown.*`. The table covers every `<flux:*>` tag the five starter kit
 * variants write. Anything outside it is reported rather than guessed at.
 *
 * Names are checked against `resources/sheaf/components.json`, which
 * `composer sheaf:components` records from Sheaf's own registry — so a component
 * renamed upstream fails CI rather than a user's view.
 */
final class ComponentMap
{
    /** Everything Sheaf's CLI installs answers to this. */
    public const string PREFIX = 'x-ui.';

    /**
     * Flux tag mapped to its Sheaf equivalent.
     *
     * @var array<string, string>
     */
    public const array TAGS = [
        // Text and layout primitives.
        'flux:button' => 'x-ui.button',
        'flux:heading' => 'x-ui.heading',
        // Sheaf has no `subheading`. Its `text` is the muted secondary style the
        // kit uses subheading for on every settings and auth page.
        'flux:subheading' => 'x-ui.text',
        'flux:text' => 'x-ui.text',
        'flux:link' => 'x-ui.link',
        'flux:badge' => 'x-ui.badge',
        'flux:separator' => 'x-ui.separator',
        'flux:avatar' => 'x-ui.avatar',
        'flux:tooltip' => 'x-ui.tooltip',
        // Flux calls it a callout; Sheaf files it under the plural.
        'flux:callout' => 'x-ui.alerts',

        // Form controls.
        'flux:input' => 'x-ui.input',
        'flux:checkbox' => 'x-ui.checkbox',
        // Sheaf ships no bare `radio` — an option is always `radio.item` inside
        // a `radio.group`, which is the shape the kit already writes anyway.
        'flux:radio' => 'x-ui.radio.item',
        'flux:radio.group' => 'x-ui.radio.group',
        'flux:select' => 'x-ui.select',
        'flux:select.option' => 'x-ui.select.option',
        'flux:otp' => 'x-ui.otp',

        // Icons. The dotted form is handled separately — it becomes an attribute
        // rather than a tag name — but Flux's own spinner has a direct
        // counterpart, so it maps like any other tag.
        'flux:icon' => 'x-ui.icon',
        'flux:icon.loading' => 'x-ui.icon.loading',

        // Overlays.
        'flux:modal' => 'x-ui.modal',
        'flux:modal.trigger' => 'x-ui.modal.trigger',
        'flux:dropdown' => 'x-ui.dropdown',
        // Sheaf takes a dropdown's contents as a named slot. RestructureOverlays
        // has already lifted the trigger into <x-slot:button> by the time this
        // rename runs, so the menu is all that is left to move.
        'flux:menu' => 'x-slot:menu',
        'flux:menu.item' => 'x-ui.dropdown.item',
        'flux:menu.separator' => 'x-ui.dropdown.separator',
        'flux:menu.heading' => 'x-ui.dropdown.group',
        'flux:menu.radio.group' => 'x-ui.dropdown.group',

        // Chrome. These appear only in the layout files, which refit replaces
        // wholesale — but a project that has moved one somewhere else still gets
        // a sensible rewrite rather than a warning.
        'flux:sidebar' => 'x-ui.sidebar',
        'flux:sidebar.toggle' => 'x-ui.sidebar.toggle',
        'flux:sidebar.nav' => 'x-ui.navlist',
        'flux:sidebar.item' => 'x-ui.navlist.item',
        'flux:sidebar.group' => 'x-ui.navlist.group',
        'flux:sidebar.brand' => 'x-ui.brand',
        'flux:navlist' => 'x-ui.navlist',
        'flux:navlist.item' => 'x-ui.navlist.item',
        'flux:navlist.group' => 'x-ui.navlist.group',
        'flux:navbar' => 'x-ui.navbar',
        'flux:navbar.item' => 'x-ui.navbar.item',
        'flux:brand' => 'x-ui.brand',
        'flux:header' => 'x-ui.layout.header',
        'flux:main' => 'x-ui.layout.main',
        'flux:toast' => 'x-ui.toast',
        // Flux's toast.group is the container the toasts render into, which is
        // what Sheaf's bare `toast` is.
        'flux:toast.group' => 'x-ui.toast',
    ];

    /**
     * Flux tags with no Sheaf counterpart, and what to say about each.
     *
     * Kept as an explicit table rather than left to fall through the unknown-tag
     * path, so the report can explain the gap instead of only naming it. All of
     * these are chrome, and refit rewrites the chrome files from stubs, so an
     * ordinary run never produces these warnings — they exist for the project
     * that has moved layout markup somewhere unusual.
     *
     * @var array<string, string>
     */
    public const array UNMAPPED = [
        'flux:profile' => 'Sheaf ships no profile component — the sidebar footer is ordinary markup.',
        'flux:sidebar.profile' => 'Sheaf ships no profile component — the sidebar footer is ordinary markup.',
        'flux:sidebar.header' => 'Sheaf\'s sidebar takes its header through <x-slot:brand> rather than a tag.',
        'flux:sidebar.collapse' => 'Sheaf collapses the sidebar from <x-ui.sidebar.toggle> instead.',
        'flux:spacer' => 'Inside a sidebar this is <x-ui.sidebar.push>; elsewhere it has no counterpart.',
    ];

    /**
     * Attributes Sheaf spells differently, keyed by the Flux name.
     *
     * @var array<string, string>
     */
    public const array ATTRIBUTES = [
        'icon-trailing' => 'iconAfter',
        'icon:trailing' => 'iconAfter',
        'icon-leading' => 'icon',
        'icon:leading' => 'icon',
    ];

    /**
     * Attribute values Sheaf spells differently, keyed by tag then attribute.
     *
     * Flux's `filled` is Sheaf's `soft`, and Flux's `subtle` is Sheaf's `ghost`.
     * Only the variants the kit actually writes are listed; an unrecognised value
     * is left alone, because Sheaf passes unknown variants through to classes
     * rather than throwing.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    public const array VALUES = [
        'flux:button' => [
            'variant' => [
                'primary' => 'solid',
                'filled' => 'soft',
                'subtle' => 'ghost',
                'danger' => 'danger',
            ],
        ],
        'flux:badge' => [
            'variant' => [
                'solid' => 'solid',
                'pill' => 'soft',
            ],
        ],
    ];

    public static function tag(string $flux): ?string
    {
        return self::TAGS[$flux] ?? null;
    }

    public static function attribute(string $flux): ?string
    {
        return self::ATTRIBUTES[$flux] ?? null;
    }

    public static function value(string $tag, string $attribute, string $value): ?string
    {
        return self::VALUES[$tag][$attribute][$value] ?? null;
    }

    /**
     * The reason a Flux tag has no Sheaf equivalent, when refit knows one.
     */
    public static function whyUnmapped(string $flux): ?string
    {
        return self::UNMAPPED[$flux] ?? null;
    }

    /**
     * Every Sheaf component the map names directly, as install names.
     *
     * `x-ui.navlist.item` needs the `navlist` component, so only the top-level
     * name is returned. What each of those needs in turn is not this table's to
     * know — {@see Components::closure()} works that out from the recorded
     * registry, and refit installs the closure rather than this list.
     *
     * @return list<string>
     */
    public static function components(): array
    {
        $components = [];

        foreach (self::TAGS as $sheaf) {
            // `x-slot:menu` is Blade's own, not something the CLI can install.
            if (! str_starts_with($sheaf, self::PREFIX)) {
                continue;
            }

            $components[self::componentFor($sheaf)] = true;
        }

        $names = array_keys($components);

        sort($names);

        return $names;
    }

    /**
     * `x-ui.navlist.item` -> `navlist`.
     */
    public static function componentFor(string $tag): string
    {
        $name = str_starts_with($tag, self::PREFIX) ? substr($tag, strlen(self::PREFIX)) : $tag;

        return explode('.', $name)[0];
    }
}
