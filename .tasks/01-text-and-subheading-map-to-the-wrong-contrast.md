# `flux:text` and `flux:subheading` come out at full contrast

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. Rewritten 2026-08-30 after the first version got the fix wrong.

## Answer in one line

The mapping to `x-ui.text` is right and stays; what is wrong is the comment
justifying it and the missing `class="opacity-75"` — Sheaf mutes text with
opacity, not with a second component.

## What the first version of this plan got wrong

It proposed repointing both tags at `x-ui.description`. That is wrong.
[Sheaf's `description`](https://sheafui.dev/docs/components/description) is a
**form element**, not a text style. Its own docs say it is "designed to work
exclusively within forms", and the local source agrees: `field.blade.php` styles
it entirely through sibling selectors on `data-slot="description"` —

```
'[&>[data-slot=label]:has(+[data-slot=description])]:mb-1.5',
'[&>[data-slot=label]+[data-slot=description]]:mt-0',
'[&>*:not([data-slot=label])+[data-slot=description]]:mt-2',
```

— so outside a `<x-ui.field>` its spacing rules simply never fire. It belongs in
the label / description / control / error stack `WrapControlsInFields` already
builds, and nowhere else. It should stay out of `TAGS`.

Nothing in the kit asks for it either: `flux:description` appears **0 times**
across all five fixtures. No `SUPPORTING` entry is needed until some control
grows help text.

## Problem

[`ComponentMap.php:36`](../src/Libraries/Sheaf/ComponentMap.php#L36) says:

> Sheaf has no `subheading`. Its `text` is the muted secondary style the kit uses
> subheading for on every settings and auth page.

The first sentence is true. The second is false, and it is the sentence the
whole mapping is justified by. Read side by side:

| Component | Colour classes | Specificity | Role |
| --- | --- | --- | --- |
| Flux `text` (default) | `[:where(&)]:text-zinc-500 [:where(&)]:dark:text-white/70` | 0 | muted |
| Flux `subheading` | `[:where(&)]:text-zinc-500 [:where(&)]:dark:text-white/70` | 0 | muted |
| Sheaf `x-ui.text` | `text-neutral-950 dark:text-neutral-50` | class | **primary** |

Sheaf's `text` is the opposite end of the scale, and unlike Flux's it is *not*
wrapped in `:where()`.

Two consequences, and the second is the nastier one:

1. Every secondary line in the kit renders at full contrast, indistinguishable
   from the body text above it. That is 61 `flux:text` and 53 `flux:subheading`
   usages — every settings and auth page.
2. The ten usages that carry `text-zinc-500 dark:text-zinc-400` of their own are
   no longer safe. Today they override a specificity-0 Flux default and always
   win. Against Sheaf's bare `text-neutral-950` they are a flat tie, decided by
   whichever utility Tailwind emits last. **Verify this in the playground before
   assuming those ten are fine** — they are all in the teams variants
   (`pages/teams/⚡edit`, `⚡index`, `⚡pending-invitations-modal`).

Three attributes are also dropped, because `x-ui.text` declares no props at all
(`text.blade.php` is a single `<div>` with `{{ $attributes->class(...) }}`).
Each lands on the rendered element as a stray HTML attribute and styles nothing:

- `variant="subtle"` — 9 usages. Flux: `text-zinc-400 dark:text-white/50`.
- `color="red"` — 3 usages. Flux: `text-red-600 dark:text-red-400`.
- `size="lg"` on `flux:subheading` — 5 usages. Flux: `text-base`.

### Where

- `flux:text` — 61 usages: 12 bare, 40 with `class`, 9 `variant="subtle"`,
  3 `color="red"`
- `flux:subheading` — 53 usages: 48 bare, 5 `size="lg" class="mb-6"` (all in
  `partials/settings-heading.blade.php`)
- `color="red"` is the invalid-code message in
  `pages/auth/two-factor-challenge.blade.php:76`, which currently renders in
  ordinary body colour rather than red
- `variant="subtle"` sits in `pages/settings/⚡security.blade.php:252` and
  `pages/settings/two-factor/⚡recovery-codes.blade.php:58,129`

## Fix

**1. Keep the mapping, rewrite the comment.** `x-ui.text` is the right target
for both — Flux's `subheading` is styled as muted text, not as a heading, so
`x-ui.heading` would be worse. Only the justification changes:

```php
// Sheaf has no `subheading`, and its `text` is the primary style rather than
// Flux's muted one — so both land here and MuteSecondaryText restates the
// contrast as a class. Sheaf's `description` is a form element, not a text
// style: it belongs inside a field and stays out of this table.
'flux:subheading' => 'x-ui.text',
'flux:text' => 'x-ui.text',
```

**2. Add a `MuteSecondaryText` sweep** in `Stage::Reconcile`, registered in
[`SheafLibrary.php`](../src/Libraries/SheafLibrary.php#L230) next to
`PreserveTextAlignment` — which is the model to follow throughout, since it
already restates a style Sheaf stops inheriting and already knows how to append
to a `class` that may or may not exist.

Run it **after** the rename, over `x-ui.text`. Both Flux tags have collapsed
into that one name by then, and it is the vocabulary the rest of the
after-the-rename group reads.

Four rules, in this order:

| Input | Output |
| --- | --- |
| `variant="subtle"` | remove attr, add `opacity-50` |
| `color="red"` | remove attr, add `text-red-600! dark:text-red-400!` |
| `size="lg"` | remove attr, add `text-base` |
| anything else | add `opacity-75` |

`opacity-75` is [Sheaf's own documented way to mute
text](https://sheafui.dev/docs/components/text#content-muted-text)
(`opacity-50` is the "more muted" step, which is what `subtle` wants). It also
composes rather than competing — which is exactly why it is the right tool here
and a `text-neutral-500` class would not be.

**Note the asymmetry in `!`.** Sheaf writes its size and alignment inside
`[:where(&)]:` but its colour bare:

```
text-neutral-950  [:where(&)]:text-sm [:where(&)]:text-start dark:text-neutral-50
```

So a plain `text-base` beats `text-sm` on specificity, but a plain
`text-red-600` only ties with `text-neutral-950` and needs the `!` —
the same reasoning `PreserveTextAlignment` documents for `text-center!`.

**Guards**, all of them the shape `PreserveTextAlignment::restate()` already
has:

- Skip a tag with `:class` — a bound expression is the application's decision.
- Skip a tag whose class already declares an `opacity-` (mirrors
  `declaresAlignment()`). This matters: `stubs/sheaf/components/desktop-user-menu.blade.php.stub:86`
  writes `<x-ui.text class="truncate">` and lands in `Stage::Write`, so the
  sweep sees it and mutes it for free — the guard is what keeps a later edit to
  that stub from doubling up.
- Skip a tag that already sets its own colour with `!` — the four
  `!text-green-600 !dark:text-green-400` success messages in `⚡profile` and
  `verify-email` are deliberate and must not be dimmed.
- Skip Sheaf's own component directory, as `PreserveTextAlignment` does.

**3. Decide on the ten zinc-classed usages.** Once `opacity-75` is applied
uniformly, `text-sm text-zinc-500 dark:text-zinc-400` is redundant at best and a
specificity coin-flip at worst. Cleanest is to strip that colour pair and let
the opacity do the work everywhere — one muting mechanism for the whole kit.
That is a small extra rule in the same sweep, and it removes the risk in
Problem (2) rather than leaving it to be verified.

## Watch out for

- Flux's `text` renders a `<p>` (or `<span>` when `inline`); Sheaf's `text`
  renders a `<div>`. Anywhere the kit nests one inside a paragraph this is
  invalid HTML. `flux:subheading` is a `<div>` already, so only the 61
  `flux:text` usages are worth grepping.
- Ordering against `PreserveTextAlignment`, which appends to the same `class`
  attribute on the same tags. Either order works, but both sweeps have to
  tolerate the other having created the attribute first — register
  `MuteSecondaryText` directly after it and test a tag that gets both
  (`verify-email.blade.php:3` is `class="text-center"` on a bare `flux:text`).

## Tests

- `tests/Unit/ComponentMapTest.php`: assert both tags still resolve to
  `x-ui.text`, and assert `description` is **not** in `ComponentMap::components()`
  — a regression test for the mistake this plan corrects.
- `tests/Unit/MuteSecondaryTextTest.php`, in the style of
  `tests/Unit/PreserveTextAlignmentTest.php`: one case per rule, plus one per
  guard (`:class`, existing `opacity-`, `!`-flagged colour, Sheaf's own
  directory), plus the both-sweeps-ran case.
