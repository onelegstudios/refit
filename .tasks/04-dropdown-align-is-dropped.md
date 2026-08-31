# `flux:dropdown`'s `align` is dropped, so menus hang centred

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. The stubs already get this right; the in-place rewrite does not.

## Answer in one line

Flux takes placement as two attributes and Sheaf takes it as one hyphenated
value, so `position="bottom" align="end"` has to be merged into
`position="bottom-end"`.

## Problem

Flux joins the two itself, with a space, and hands the pair to its custom element:

```blade
@props([
    'position' => 'bottom',
    'align' => 'start',
])

<ui-dropdown position="{{ $position }} {{ $align }}" {{ $attributes }} data-flux-dropdown>
```

Sheaf takes one value and feeds it straight to Alpine Anchor as a modifier:

```blade
@props([
    'position' => 'bottom-center',
    …
])

x-anchor.{{ $position }}.offset.{{ $offset }}="$refs.button;"
```

Nothing in refit merges them. `position="bottom"` survives as a valid but
centred placement, and `align` — not a Sheaf prop — falls through
`{{ $attributes }}` onto the wrapper `<div>` as a stray, long-deprecated HTML
`align` attribute.

Every translated dropdown therefore opens centred on its trigger instead of
aligned to its edge.

Refit already knows the right shape. The stub at
[`desktop-user-menu.blade.php.stub:11`](../stubs/sheaf/components/desktop-user-menu.blade.php.stub#L11)
writes `position="bottom-start"` by hand. It is only the rewrite path that
was never taught.

### Where

Every dropdown in the kit writes both attributes — there are no bare ones — so
all 14 usages are affected. Most sit in chrome that `LayoutStubs` replaces
wholesale, which masks the bug. Two do not:

| File | Line | Written | Should become |
| --- | --- | --- | --- |
| `components/⚡team-switcher.blade.php` | 82 | `position="bottom" align="start"` | `position="bottom-start"` |
| `pages/teams/⚡edit.blade.php` | 196 | `position="bottom" align="end"` | `position="bottom-end"` |

Both in the `livewire-teams` and `livewire-workos-teams` variants. The second is
the member-role picker in the team members table, which currently opens centred
under a right-aligned trigger.

## Fix

This is a merge of two attributes into one, so it needs a small action rather
than a `ComponentMap` table — `VALUES` rewrites a value in place and cannot
consume a second attribute.

Write it in the shape of the existing single-purpose actions
(`ScopeCollapseToSidebar` and `SizeNavItemIcons` are the closest in size):

1. On a dropdown tag, read literal `position` and `align`.
2. Write `position="{position}-{align}"`.
3. Remove `align`.

Defaults matter if you ever meet a partial usage. Flux's are `position="bottom"`
and `align="start"`, so a tag with only one of the two still has a defined
placement — fill the missing half from Flux's default rather than from Sheaf's,
since the source file is still Flux at that point.

Leave bound values (`:position="$x"`) alone and report them, the way
`ComponentMap::UNMAPPED` explains a gap rather than guessing at it.

## Watch out for

Sheaf's own default is `bottom-center`, which is *not* one of Alpine Anchor's
placements — it resolves to plain `bottom`. So "centred" is the current
behaviour by accident, not by design. Don't take `bottom-center` as evidence that
`-center` is a valid suffix to generate; the real placements are the bare
direction plus `-start` / `-end`.

## Tests

A unit test in the style of `tests/Unit/ScopeCollapseToSidebarTest.php` covering
the merge, the removal of `align`, a partial usage, and a bound value left
untouched. Add the two fixture files above to whatever
`tests/Feature/SheafMigrationTest.php` asserts about rewritten pages.
