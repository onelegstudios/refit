---
title: Component mapping
description: The Library contract, the vocabulary, and how a target translates itself from Flux.
order: 4
---

# Component mapping

Refit shipped its first version with no library abstraction at all, deliberately.
With Flux as the only target a `Library` would have been an identity adapter —
mapping `flux:button` to `flux:button` — and an interface with one trivial
implementation proves nothing about whether the contract is right.

Sheaf is the second real case, and it disagrees with Flux almost everywhere the
contract has to make a choice. That disagreement is what the shape below is built
from.

| | Flux | Sheaf |
|---|---|---|
| Distribution | vendor package | CLI copies source into the application |
| Tag prefix | `flux:` | `x-ui.` |
| Icons | resolved by bare name, overridden at `resources/views/flux/icon/{name}.blade.php` | an `<x-ui.icon>` component in the application, reading an icon package |
| Internal icons | rendered from vendor code, so they need a recorded manifest | rendered from application code, so the scanner already sees them |
| Trailing icon | `icon-trailing` | `iconAfter` |
| Paid tier | `livewire/flux-pro` in `composer.lock` | an account the CLI logs in to |

## The source is always Flux

Refit only supports the Livewire starter kit, and that kit ships Flux — the
downloader excludes the blank variant for exactly this reason. So a `Library` is
always the **target**, and each target owns its own translation *from* Flux.

That is what keeps a third library to one class. A contract where any library
could be either end would need a translation for every pair; this one needs one
per target.

Flux implements the contract as the identity case: it detects, it supplies a
vocabulary, and its `planMigration()` is empty because a Flux kit is already on
Flux.

## The contract

```php
interface Library
{
    public function key(): string;
    public function label(): string;
    public function hint(): string;

    public function vocabulary(): Vocabulary;
    public function detect(string $root): ?LibraryInstall;

    /** @return list<string> blocking problems, empty to go */
    public function preflight(Project $project): array;

    /** @return list<IconStrategy> */
    public function iconStrategies(): array;

    public function planIcons(Plan $plan, Project $project, IconStrategy $strategy, Report $report): void;
    public function planMigration(Plan $plan, Project $project, Report $report): void;
}
```

`preflight()` runs *after* the target is chosen rather than filtering the menu
before it. A library the project is not set up for is still offered, and then
tells the user what to run — which is more use than the option quietly missing.

## Vocabulary

Everything the Blade rewriters need in order to work on a library without knowing
which one they are looking at: the tag prefix, the generic icon tag and the
attribute it names an icon through, the attributes that carry an icon name on any
component, and — for libraries that have them — the dotted tag form and the
variant attributes.

`RewriteIconNames`, `DropSolidIconVariant`, `PrefixIconNames` and `IconScanner`
all take one. None of them contains the string `flux:` any more.

Nullable fields are how a library says it does not have something. Sheaf's
`dottedIconTag` is null because `<x-ui.icon.home />` is not a thing, and its
`variantAttribute` is null because its icon component supports Heroicons' solid,
mini and micro weights natively — so nothing needs to strip `variant="solid"` the
way the Lucide path does.

## Where the target lives

The chosen library rides on `Project`, attached by `Project::targeting()` once the
question has been answered. Detection fills in everything else; the target is the
one field disk cannot answer.

This deliberately leaves the `Task` contract at the two arguments it has always
had. A task that cares reads `$project->targets('sheaf')`, so every shipped task,
the [custom task example](/docs/using-refit/guide/custom-tasks), and any
third-party task keep working unchanged.

## ComponentMap

Sheaf's translation table, and the same discipline as
[`IconMap`](/docs/development/internals/icon-pipeline): curated, commented where a
call was close, and reported rather than guessed at when there is no answer.

Four kinds of entry:

- **`TAGS`** — `flux:callout` to `x-ui.alerts`, `flux:menu.item` to
  `x-ui.dropdown.item`, `flux:main` to `x-ui.layout.main`.
- **`ATTRIBUTES`** — `icon-trailing` to `iconAfter`, matched by name alone so the
  pass can run after the rename.
- **`VALUES`** — keyed by the *Flux* tag, so the pass looks a Sheaf tag back up
  through the map. Only the variants the kit actually writes are listed; Sheaf
  passes an unknown variant through to classes rather than throwing, so guessing
  would be worse than doing nothing.
- **`UNMAPPED`** — a tag with no counterpart, and the sentence explaining why.

### The manifest keeps it honest

`resources/sheaf/components.json` records every component Sheaf ships and the tags
each answers to, recorded by `composer sheaf:components` from the public
`sheafui/components` registry. Each component's `config.yml` lists where its files
install, and the install path *is* the Blade component name — so the registry is
the authority on tag names rather than a reading of the docs.

`composer sheaf:components:check` fails when a mapping points at a component Sheaf
no longer ships, which is the same job `flux:internals:check` does for icons. Only
names are recorded; none of Sheaf's source is copied into this repository, because
its CLI is what puts components in a project and refit runs that CLI.

Unlike the Flux manifest this needs no licence and no sidecar install —
`sheafui/components` is public and MIT.

## The order the passes run in

Inside `Stage::Reconcile`, and it matters:

1. **`RestructureOverlays`** — first, while the markup still says `flux:`, because
   both of its rewrites read Flux's own arrangement: where a dropdown keeps its
   trigger, and what a modal close button wraps.
2. **`MapComponentTags`** — the dotted icon form is folded into an attribute while
   the suffix is still there to read, then tag names, then attributes and values.
3. **`RestructureBrandLogo`**, **`PlaceDropdownChildren`**,
   **`PreserveTextAlignment`** and **`PromoteContentsToLabel`** — after the
   rename, because all four read the tags the rename produced.
4. **The icon sweeps** — last, so they run against the tags the migration
   produced. This is why `planMigration()` is called before `planIcons()` in
   `RefitCommand::build()`.

Every one of these is a [`BladeSweep`](/docs/development/internals/blade-rewriting),
so each is one traversal, and each file is checked by `BladeGuard` individually.

### A slot is not only a slot

The kit hands its logo to the brand as a slot carrying classes, and Flux renders
that slot as `<div {{ $logo->attributes->class(...) }}>` — which is what turns
those classes into the accent tile the mark sits on. Sheaf's brand renders
`{{ $logo }}` and nothing else, so the attributes go nowhere. The mark is
`text-white dark:text-black`, so losing the tile loses the logo: white on a white
sidebar, black on a black one.

`RestructureBrandLogo` takes the classes off the slot and puts them on an element
inside it — the same element Flux was rendering, written where Sheaf will keep it.
The classes themselves are copied verbatim, so a project that restyled its tile
keeps the tile it wrote.

### A slot is not always read

The same shape, one component along. Flux's nav items take their text as slot
content; Sheaf's `navlist.item`, `navbar.item` and `radio.item` render
`{{ $label }}` into a span and never reference `$slot` at all. A rename therefore
produces a valid, clickable, invisible item — which is what the settings
sub-navigation and the appearance page's segmented control were reduced to.

`PromoteContentsToLabel` moves the contents onto the tag and closes it behind
them. A lone `{{ ... }}` becomes `:label="..."` so `__()` survives as a call
rather than being flattened; text with nothing Blade about it becomes a literal
`label`. Two echoes, markup, or a value carrying a double quote — which the tag
parser cannot read back — are all left as they were and collected into one
warning, because Sheaf's item has an icon, a label and a badge and nowhere to put
anything else.

The other Sheaf components taking a `label` render their slot as well, so a slot
label still shows and none of them are targets.

### A menu is a grid

Flux renders a menu as a stack of blocks, so the kit puts what it likes in one: a
plain div of avatar, name and email at the top, a `<form>` around the log out
item, a modal trigger around "New team". Sheaf renders the panel as
`grid grid-cols-[auto_1fr_auto]`, where every part it ships declares its place —
an item is `col-span-2`, a separator `col-span-full`, a group `contents`.

Anything else lands in the first column. The log out row shows it best: the item
inside the form still asks for `col-span-2`, but the form is the actual grid
child, so the span applies to nothing and the row renders half the width of the
one above it.

`PlaceDropdownChildren` places each direct child of a menu — `contents` for a
wrapper that holds a menu part, `col-span-full` for anything else, and a
spanning div around a component, whose outermost element Sheaf renders rather
than the view. Children that already say where they sit are left alone.

Finding the direct children means knowing what encloses what, which is what
`Nesting` answers — opening tags paired with their closing tags, per name, on a
stack, alongside the rest of the
[Blade rewriting](/docs/development/internals/blade-rewriting) tools.
`PreserveTextAlignment` uses the same pairing to find the wrapper an element
inherited its alignment from.

### Alignment is not only a rename

Flux's heading and text inherit their alignment, so the kit centres an auth
header by putting `text-center` on the wrapping div. Sheaf's components declare
`text-align` themselves — `text-start` in the heading's own class list, and a
`[:where(&)]:text-start` default in its text — and a declared value beats an
inherited one however faint it is. A pure rename therefore leaves every auth page
left-aligned.

`PreserveTextAlignment` restates the alignment on the tag: it reads the nearest
enclosing `div` that sets one, and adds `text-center!` when that differs from what
Sheaf already does. The `!` is load-bearing — Tailwind emits `.text-start` after
`.text-center`, so a plain `text-center` on the tag is a specificity tie the
heading's own class wins.

A tag that already says something about alignment, or whose classes are bound, is
left alone, and so is anything under Sheaf's own component directory.

## Adding a library

1. Implement `Library`. Put anything specific to it under `src/Libraries/<Name>/`.
2. Register it in `config/refit.php` under `libraries`, or from a service provider
   with `Refit::library(new YourLibrary)`.
3. Detection picks it up automatically — the service provider hands the registry's
   libraries to `ProjectDetector`.

If it needs a translation table, follow `ComponentMap`: a public constant, a
comment wherever the call was close, and an `UNMAPPED` entry rather than a guess.
