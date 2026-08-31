# `icon:variant="outline"` leaks into the rendered HTML

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. Lowest severity of the seven — almost certainly invisible on screen —
but it is a stray attribute refit puts there, and it is cheap to remove.

## Answer in one line

`DropSolidIconVariant` only removes `icon:variant="solid"`, so the kit's nine
`icon:variant="outline"` survive onto Sheaf buttons that spell the prop
`iconVariant` and never read the Flux form.

## Problem

`ComponentMap::ATTRIBUTES` translates the other three pass-through icon
attributes:

```php
'icon-trailing' => 'iconAfter',
'icon:trailing' => 'iconAfter',
'icon-leading' => 'icon',
'icon:leading' => 'icon',
```

`icon:variant` is not among them, and this is deliberate —
[`FluxLibrary.php:60`](../src/Libraries/FluxLibrary.php#L60) says so out
loud, and `SheafLibrary` sets `iconVariantAttribute: null`. The reasoning holds:
Sheaf derives the variant from the button's size when none is given —

```php
$iconVariant ??= match($size) {
    'xs' => 'micro',
    'sm' => 'mini',
    'md' => $squared ? 'mini' : 'micro',
    'lg' => $squared ? 'mini' : 'micro',
    default => 'micro',
};
```

— and refit is switching the icon set to Lucide or Phosphor anyway, where the
Heroicons weight names mean nothing. Translating `outline` to Sheaf's
`iconVariant` would be worse than dropping it.

The bug is that it is not dropped. `DropSolidIconVariant` removes the attribute
only when it asks for `solid` *and* the icon is becoming Lucide:

```php
fn (Tag $tag, Attribute $attribute): bool => $this->asksForSolid($attribute)
    && $this->drawsLucide($tag, $attribute),
```

Every `icon:variant="outline"` therefore passes through the rename untouched and
is emitted onto the `<button>` as a literal `icon:variant="outline"` attribute —
a name a colon makes non-conforming in HTML, and which nothing reads.

### Where

9 usages, all `icon:variant="outline"` on `<flux:button>`. No other value of the
attribute appears anywhere in the five variants.

## Fix

The decision is already made and documented; only the cleanup is missing. Two
ways to land it, both small:

**Preferred — remove it in the tag rename.** Add `icon:variant` to whatever
removal pass runs for Sheaf, so *any* value comes off rather than only `outline`.
The kit writes one value today; a project that has written `micro` should get the
same treatment, and a value-specific rule would silently miss it.

**Alternative — widen `DropSolidIconVariant`.** Cheaper diff, but it makes a
class named for dropping `solid` also drop `outline`, and its whole docblock is
about Lucide having one weight. If you go this way, rename it.

Either way, keep the removal conditional on the Sheaf target. `icon:variant` is
meaningful on the Flux path, and `DropSolidIconVariant` is shared.

## Watch out for

Removing it is not quite behaviour-neutral. Flux's `iconVariant` defaults to
`'outline'`, and Sheaf derives `micro` or `mini` from the button size. So the
icons were already going to change weight at the rename — dropping the attribute
does not cause that, it just stops the dead attribute riding along. Worth a
sentence in the docs page that promises what the icon migration does, so the
weight change is stated rather than discovered.

## Tests

`DropSolidIconVariant` has no dedicated unit test today — it is covered
indirectly through `tests/Feature/SheafMigrationTest.php` and
`tests/Feature/IconPlannerTest.php`. Adding one alongside
`tests/Unit/SwitchIconSetTest.php` is worth doing while you are in here.

The cheapest guard is a single sweep assertion in
`tests/Feature/SheafMigrationTest.php`: no `icon:variant` survives anywhere in
the migrated fixture tree. That covers all nine at once and does not care which
of the two fixes you chose.
