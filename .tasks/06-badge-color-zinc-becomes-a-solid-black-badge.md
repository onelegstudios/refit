# `flux:badge color="zinc"` comes out a solid black badge

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. Small blast radius, but it is the one finding with no clean answer, so
decide deliberately rather than by default.

## Answer in one line

Sheaf's badge has no grey in its colour list, so `zinc` falls through to a
`default` arm that paints a solid dark badge — the opposite of Flux's subtle
grey pill.

## Problem

Flux's badge, with `color="zinc"` and no `variant`, takes its non-solid default
arm:

```php
default => 'text-zinc-700 [&_button]:text-zinc-700! dark:text-zinc-200 … bg-zinc-400/15 dark:bg-zinc-400/40 …'
```

A translucent grey pill with dark text.

Sheaf's badge defaults `variant` to `'solid'`, so it takes the else branch, and
its colour match has no `zinc` — nor `neutral`, `gray`, `slate` or `stone`. The
full list is `red`, `orange`, `amber`, `yellow`, `lime`, `green`, `emerald`,
`teal`, `cyan`, `sky`, `blue`, `indigo`, `violet`, `purple`, `fuchsia`, `pink`,
`rose`. So `zinc` hits:

```php
default => 'text-white dark:text-white bg-neutral-900 dark:bg-neutral-600 border-black/5 dark:border-white/5'
```

White text on near-black. Nothing in refit maps badge colours, so it passes
straight through into that fallback.

### Where

4 usages, all `<flux:badge color="zinc">` — the role labels in the team members
table, e.g. `pages/teams/⚡edit.blade.php:214`. They go from quiet grey chips to
solid black ones that pull the eye harder than anything else in the row.

There are also 3 `<flux:badge size="sm">` with no colour. Those are fine: both
libraries treat a colourless badge the same way.

## Fix

No option is a clean match, so pick one and record why.

**Option A — strip `color` and use Sheaf's outline variant.** Nearest in weight:

```
color="zinc"  ->  variant="outline"
```

which gives `text-neutral-900 dark:text-neutral-50 bg-neutral-900/5 dark:bg-white/5 border-neutral-900 dark:border-white border`.
Subtle background and dark text like Flux's, but it adds a 1px border Flux does
not draw. Expressible in `ComponentMap::VALUES` only as a value rewrite of a
*different* attribute name, so it needs a small action or an `AddAttribute` plus
a removal.

**Option B — strip `color` and restate the colours as classes.**  Closest
visual match:

```
class="bg-zinc-400/15 text-zinc-700 dark:bg-zinc-400/40 dark:text-zinc-200"
```

But verify precedence before committing to it. Sheaf's badge merges with
`{{ $attributes->class(Arr::toCssClasses($classes)) }}`, and Laravel's `class()`
concatenates rather than resolving conflicts — two competing `bg-*` utilities
are settled by CSS source order, not by the order they appear in the attribute.
`YieldIconColour` already had to solve this exact precedence problem for icons;
read it before choosing this option.

**Option C — accept the solid badge.** Defensible: the role label arguably
*should* be prominent, and it costs no code. If you take this one, put it in the
`ComponentMap` comment the way the `primary` entry records a deliberate
non-translation, so the next reader knows it was considered.

Recommendation: **A**, with the border accepted as a small deviation. It keeps
the change inside the library's own vocabulary instead of hand-written Tailwind
that has to fight the component for precedence.

## Tests

Whichever option, add the four fixture usages to the migration test so the
rendered result is pinned, and a `ComponentMapTest` case if the change lands in a
table.
