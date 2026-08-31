# `current` is never translated to Sheaf's `active`

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. This one is also wrong in refit's own stubs, which is the part worth
fixing first.

## Answer in one line

Flux marks the selected nav item with `current`; Sheaf reads `active` and
ignores `current` entirely — and refit's stubs hand it `current`.

## Problem

Sheaf's `navlist/item.blade.php` and `navbar/item.blade.php` both declare:

```php
@props([
    'icon' => null,
    'badge' => null,
    'label' => null,
    'href' => '#',
    'active' => null
])
```

and then fall back to a URL comparison when nothing is passed:

```php
// allow other active logic from outside
$active = $active ?? (url($href) === url()->current());
```

Nothing in refit maps `current` to `active` — there is no `TAG_ATTRIBUTES` entry
for either item component. So `current` lands on the rendered `<a>` as a stray
attribute and Sheaf decides the active state by itself.

That fallback is what makes this hard to spot. For an exact-match route it
produces the right answer anyway, so the sidebar looks correct while the prop
does nothing.

### It is wrong in the stubs

Refit authors these three lines itself, in Sheaf's own vocabulary, and still
uses Flux's word:

| File | Line | Tag |
| --- | --- | --- |
| [`stubs/sheaf/layouts/app-sidebar.blade.php.stub`](../stubs/sheaf/layouts/app-sidebar.blade.php.stub#L26) | 26 | `<x-ui.navlist.item>` |
| [`stubs/sheaf/layouts/app-header.blade.php.stub`](../stubs/sheaf/layouts/app-header.blade.php.stub#L28) | 28 | `<x-ui.navbar.item>` |
| [`stubs/sheaf/layouts/app-header.blade.php.stub`](../stubs/sheaf/layouts/app-header.blade.php.stub#L101) | 101 | `<x-ui.navlist.item>` |

All three write `:current="request()->routeIs('dashboard')"`. The URL fallback
covers for it — Dashboard highlights correctly — but the expression is dead code
and `current="1"` ends up in the shipped HTML.

### It is a real regression in one rewritten file

`pages/settings/layout.blade.php`, in the two teams variants, is **not** replaced
from a stub:

```blade
<flux:navlist.item :href="route('teams.index')" :current="request()->routeIs('teams.*')" wire:navigate>{{ __('Teams') }}</flux:navlist.item>
```

`teams.*` is a prefix match — it highlights Teams on `teams.index`, `teams.edit`
and `teams.create` alike. Sheaf's fallback is URL *equality* against
`route('teams.index')`, so on `/teams/{slug}/edit` the Teams item in the settings
sidebar stops being highlighted. There is no fallback that recovers this,
because the whole point of the expression is that it is broader than the href.

## Fix

**1. Map the attribute.** `TAG_ATTRIBUTES` is the right table — it is keyed by
Sheaf tag, which is what this needs, since `current` is only these two
components' word to claim:

```php
'x-ui.navlist.item' => ['current' => 'active'],
'x-ui.navbar.item' => ['current' => 'active'],
```

Remember `TAG_ATTRIBUTES` is read *after* the tag rename, over a tree that
already says `x-ui.` — the existing entries document this.

**2. Fix the three stub lines** to say `:active`. They are hand-written Sheaf
markup and should not be relying on a mapping table at all.

## Watch out for

Because Sheaf's fallback hides the failure whenever the route test happens to
equal the href, a test that only checks the dashboard item will pass either way.
Write the test against the `teams.*` prefix case, where the fallback and the
expression genuinely disagree.

## Tests

A `ComponentMapTest` case for both table entries. A stub assertion that neither
stub contains `:current` — cheap, and it stops the same slip coming back.
