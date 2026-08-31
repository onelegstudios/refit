# `flux:heading level="1"` renders an `<h2>`

Found 2026-08-30 auditing the `replace-flux` branch against Sheaf's installed
source. An accessibility regression, not a cosmetic one.

## Answer in one line

Flux numbers its heading levels and Sheaf names them — `level="1"` has to become
`level="h1"` or Sheaf silently falls back to `h2`.

## Problem

Flux casts the level to an integer and switches on it:

```php
<?php switch ((int) $level): case(1): ?>
    <h1 {{ $attributes->class($classes) }} data-flux-heading>{{ $slot }}</h1>
```

Sheaf matches it against a list of tag names, and falls back when it misses:

```php
@props([
    'level' => 'h2',
    'size' => 'sm',
])

$tag = in_array($level, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) ? $level : 'h2';
```

`"1"` is not in that list. Every `level="1"` becomes `<h2>`, and every
`level="3"` becomes `<h2>` too.

Nothing in refit touches `level` — no `VALUES` entry, no action. It passes
through unchanged and lands in the fallback.

The document outline loses its `<h1>` on every page that sets one, and the
`level="3"` headings flatten into the same level as everything else. Because the
fallback is silent and `h2` is a plausible-looking tag, nothing about the
rendered page makes this visible — it only shows up in a screen reader or an
outline check.

### Where

- `level="1"` — 5 usages, all `partials/settings-heading.blade.php:2`
  (`<flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>`),
  one per starter kit variant
- `level="3"` — 3 usages, the 2FA recovery-codes panel, e.g.
  `pages/settings/two-factor/⚡recovery-codes.blade.php:56`

Both files are rewritten in place, not replaced from a stub, so the fix has to
live in the rewrite rather than in the stubs.

## Fix

`ComponentMap::VALUES` is keyed by tag, attribute and value, which is exactly the
shape this needs:

```php
'flux:heading' => [
    'level' => [
        '1' => 'h1',
        '2' => 'h2',
        '3' => 'h3',
        '4' => 'h4',
        '5' => 'h5',
        '6' => 'h6',
    ],
],
```

List all six even though the kit only writes two. The table costs nothing and
the failure mode for an unlisted level is silent, which is the argument for not
leaving gaps in it.

## Watch out for

`VALUES` only rewrites literal values. If a project has moved to a bound level
(`:level="$depth"`) this will not catch it, and the fallback stays silent. Worth
a line in the report when a bound `level` is seen on a heading, in the same
spirit as `ComponentMap::UNMAPPED` explaining a gap rather than only naming it.

Do this one alongside [03](03-heading-size-jumps-two-steps.md) — both are
`flux:heading` attribute translations and they share the tests.

## Tests

`tests/Unit/ComponentMapTest.php` for the table itself, plus a rewrite case
asserting `<flux:heading size="xl" level="1">` comes out as
`<x-ui.heading size="…" level="h1">`.
