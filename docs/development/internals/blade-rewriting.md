---
title: Blade rewriting
description: The tag parser, the offset-based rewriter, and the balance guard.
order: 2
---

# Blade rewriting

Most of what refit does ends in editing Blade. That machinery lives in
`src/Blade` and is deliberately small, pure, and unit-testable against inline
snippets — no filesystem, no application.

## TagParser

A character scanner, not a regex, because the starter kit views contain exactly
the cases a regex gets wrong: attributes spread over several lines, and bound
values holding PHP expressions with angle brackets and nested quotes.

```php
$tags = (new TagParser)->parse($source, 'flux:');
```

It returns every opening and self-closing tag under the prefix as a `Tag` —
name, attributes, self-closing flag, and absolute offset and length in the
source. Closing tags are not returned; `TagRewriter::rename()` handles pairs.

Two details matter:

- **Name boundaries.** A name must end at a boundary character, otherwise
  `<flux:input` matches the leading edge of something merely starting the same.
- **Escaped quotes inside attribute values are not supported.** No starter kit
  view uses them, and handling them would mean tracking Blade's own escaping.

`Attribute` carries its own offsets, plus `isBound()` (a leading `:`, so the
value is an expression) and `isBoolean()` (present with no value).

## Nesting

`TagParser` answers "where are the tags". `Nesting` answers "what does each one
contain", which is what a rewrite needs whenever the markup *around* a tag decides
its meaning — a wrapper that centres its contents, a panel whose direct children
have to declare a place in a grid.

```php
$elements = (new Nesting)->elements($source, ['div', 'form', 'x-ui.']);
```

Each `Element` is an opening tag paired with the closing tag that ends it, so it
can answer `holds($offset)` and `encloses($other)`. Pairing is per name and
stack-based, so nesting of the same element works and every other element is
ignored. An opening tag that never closes, and a closing tag with nothing open,
are both dropped — a Blade branch can legitimately write either, and neither
describes a span of source.

## Edits

Rewrites are collected as offset-based replacements and applied back-to-front in
one pass:

```php
$edits = new Edits;
$edits->replace($offset, $length, 'new-name');

return $edits->apply($source);
```

Sorting descending by offset is what lets several rewrites target the same file
without any of them invalidating the offsets the others were built from.

## TagRewriter

Every method is a pure string transform:

| Method | What it does |
|---|---|
| `rename()` | Rename a tag, both its opening and closing forms, leaving lookalikes alone |
| `addAttribute()` | Add an attribute to tags that do not already carry it |
| `rewriteAttributeValues()` | Rewrite literal attribute values under a prefix, via a callback |
| `rewriteNameSuffix()` | Rewrite the dotted tail of a name, e.g. `flux:icon.**home**` |

`rewriteAttributeValues()` hands the callback the tag *and* the attribute, which
is what lets `RewriteIconNames` treat `name` as an icon on `<flux:icon>` and as
an ordinary field name everywhere else.

## BladeSweep

`Plan\Actions\BladeSweep` is the base class for an action that rewrites every
Blade file in the project. Subclasses implement one method:

```php
protected function transform(string $source, Project $project, Report $report): string;
```

Sweeps run in the `Reconcile` stage, over the settled tree, so they see files
that earlier stages created or moved. Each file is guarded individually: if a
rewrite would leave the Blade less balanced than it found it, that one file is
left alone and the problem is reported.

Skipping beats aborting. A half-applied run is harder to reason about than one
bad file, and the clean-tree precondition means `git checkout .` is always
available as the real undo.

### The PHP half

The Blade tree is not the only place a component is named. `Route::livewire()`
and `Livewire::test()` name one as a string, so `RenameComponentStrings` is the
counterpart sweep: it replaces whole quoted literals across the `.php` files in
`app/`, `routes/` and `tests/`. Quoting is what makes it safe — a name that is
the leading edge of a longer one cannot be half-rewritten, and prose that merely
mentions a component is left alone. There is no balance to guard, so there is no
guard.

## BladeGuard

The balance check is **differential** — it compares before against after, not
after against perfection.

It has to be. The starter kit itself ships tags that do not balance by name: the
two-factor setup modal writes `<flux:icon.document-duplicate …></flux:icon>`. An
absolute check would reject files refit never broke.

Comparison is on the imbalance itself rather than on the message, because a
rename is expected to change the tag name inside a pre-existing problem. Renaming
that modal's icon to `copy` leaves exactly the imbalance it started with, and
must not be reported as new.

Closing tags are counted independently of openers, so a rename that moved only
one end of a pair shows up as both an unclosed opener and an orphaned closer
rather than slipping through on a matching total.

> [!NOTE]
> Compiling the Blade would be a stronger check, but it needs a booted view
> factory and rejects perfectly good starter kit views without one.
