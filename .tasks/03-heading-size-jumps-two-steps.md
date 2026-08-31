# `flux:heading` sizes jump two steps up Sheaf's scale

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. Affects every heading in the kit — 45 sized, 56 bare.

## Answer in one line

The two libraries spell their sizes with the same words but put them at
different points on the scale, so `size="lg"` grows from 16px to 20px and a bare
heading grows from 14px to 16px.

## Problem

Flux's heading has three outcomes:

```php
->add(match ($size) {
    'xl' => 'text-2xl …',
    'lg' => 'text-base …',
    default => 'text-sm …',
})
```

with `'size' => 'base'` as the prop default. Sheaf's has eight:

```php
$variantClasses = match ($size) {
    'xs' => 'text-sm',
    'sm' => 'text-base',
    'md' => 'text-lg',
    'lg' => 'text-xl',
    'xl' => 'text-2xl',
    '2xl' => 'text-4xl',
    '3xl' => 'text-6xl',
    '4xl' => 'text-8xl',
    default => 'text-base'
};
```

with `'size' => 'sm'` as the prop default. Lining the rendered sizes up:

| Flux `size` | Flux renders | Sheaf word for that size |
| --- | --- | --- |
| *(absent — `base`)* | `text-sm` | `xs` |
| `lg` | `text-base` | `sm` |
| `xl` | `text-2xl` | `xl` |

Only `xl` survives the move unchanged, and it does so by coincidence. `lg` is
two steps off, and — the easy one to miss — **a heading with no `size` at all is
also wrong**, because the two libraries disagree about the default. Refit only
rewrites attributes that are present, so those 56 bare headings need a size
added rather than translated.

### Where

- `size="lg"` — 35 usages
- `size="xl"` — 10 usages (correct already)
- no `size` — 56 usages (29 bare, 17 `class="sr-only"`, 10 `class="truncate"`)

The 17 `sr-only` ones are visually hidden, so they do not matter in practice, but
translating them costs nothing and keeps the rule simple.

## Fix

**1. Translate the sizes that are written**, in `ComponentMap::VALUES`:

```php
'flux:heading' => [
    'size' => [
        'lg' => 'sm',
        'xl' => 'xl',
    ],
],
```

`xl => xl` is a no-op and belongs in the table anyway — it records that the
match was checked rather than missed, which is the same argument the existing
`'danger' => 'danger'` entry on `flux:button` makes.

**2. Add `size="xs"` when the attribute is absent.** `VALUES` cannot express
this — it rewrites values, it does not add attributes. Use `AddAttribute`, which
already exists for exactly this shape, applied to `flux:heading` tags with no
`size`.

Note the ordering: add the attribute *before* the tag rename if you key the
action off `flux:heading`, or after it if you key off `x-ui.heading`. The
existing actions do both, so either fits — just be explicit about which
vocabulary the action reads, the way `TAG_ATTRIBUTES` documents that it runs
after the rename.

## Watch out for

`flux:subheading` has its own size scale, but it stays mapped to `x-ui.text`,
which declares no props at all — so its `size="lg"` is restated as a class by
the sweep in [01](01-text-and-subheading-map-to-the-wrong-contrast.md) rather
than translated through a value table. Don't fold the two into one table: this
one rewrites an attribute on `x-ui.heading`, that one removes an attribute from
`x-ui.text`.

Do this one alongside [02](02-heading-level-renders-an-h2.md).

## Tests

Cases in `tests/Unit/ComponentMapTest.php` for the value table, and a rewrite
test that a bare `<flux:heading>` acquires `size="xs"` while
`<flux:heading size="lg">` becomes `size="sm"`.
