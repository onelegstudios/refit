---
title: The icon pipeline
description: The map, the bundled artwork, and the recorded Flux internals.
order: 3
---

# The icon pipeline

Three things have to agree for an icon translation to work: a **mapping**, the
**artwork**, and — for names Flux renders itself — the **recorded internals**.

All of this is Flux's. It lives under `src/Libraries/Flux/`, because generating an
override file that intercepts a library's own resolution is a mechanism no other
library refit knows about has. What is shared sits in `src/Icons/`: `IconMap`'s
set-to-set tables, `IconStrategy`, and the vocabulary-driven `IconScanner`. See
[Component mapping](/docs/development/internals/component-mapping) for the seam
between them.

```
IconMap  ──▶  bin/download-icons.php  ──▶  resources/icons/lucide/*.svg
   │                                              │
   │                                              ▼
   └────────────▶  IconPlanner  ──▶  OverrideGenerator  ──▶  flux/icon/*.blade.php
                        ▲
                        │
   IconScanner ─────────┘  (application views, then Flux's own stubs)
```

## IconMap

The single source of truth. The translations are semantic, not mechanical:
Heroicons' `arrow-right-start-on-rectangle` is Lucide's `log-out`,
`magnifying-glass` is `search`, and the eyedropper is filed under the tool's
name, `pipette`.

The map covers every name the five starter kit variants reference, plus the ones
Flux's own stubs render. Anything outside it is reported rather than guessed at.

It also carries the rules about *where* a name can appear:

| Constant | Purpose |
|---|---|
| `NAME_ATTRIBUTES` | Attributes that always carry an icon name (`icon`, `icon:trailing`, …) |
| `ICON_TAG` / `ICON_TAG_ATTRIBUTE` | `name` is an icon name on `<flux:icon>` and nowhere else |
| `CANDIDATE_ATTRIBUTES` | Everything worth parsing before the per-tag rules apply |
| `FLUX_OWNED` | Names Flux draws itself, and the Lucide art that stands in |
| `EXTRA_CLASSES` | Classes an override needs that the artwork cannot carry — `loading` needs `animate-spin` |

`icon:variant` is deliberately absent from `NAME_ATTRIBUTES`: it takes an
appearance keyword such as `outline`, not a name, and translating it would
corrupt the tag. `DropSolidIconVariant` reads it from the other end — as the
weight a component hands down to the icons its `icon` and `icon:trailing`
attributes name.

### Adding a translation

1. Add the pair to `HEROICONS_TO_LUCIDE` (or `LUCIDE_TO_HEROICONS` for the
   reverse direction, which only needs to cover the icons the kit vendors).
2. Run `composer icons` to pull the artwork.
3. Commit both.

That is the whole workflow. The downloader derives its set from the map, so the
map is what decides which files need to be on disk.

## The bundled artwork

`bin/download-icons.php` refreshes `resources/icons/lucide` from
`lucide-icons/lucide`. Artwork is committed rather than pulled from a Blade Icons
package, so an update is a reviewable commit rather than silent drift, and the
output does not vary with whatever Composer resolved.

```bash
composer icons                       # refresh from the latest Lucide release
composer icons:check                 # report drift, write nothing
php bin/download-icons.php --list    # what the map requires
php bin/download-icons.php --only=plus,search --ref=1.31.0
```

Upstream ships each icon with `width`/`height` attributes and a multi-line open
tag. Those are normalised away to match the shape the starter kit vendors its own
Lucide icons in — which is the shape `OverrideGenerator` reads.

Set `GITHUB_TOKEN` (or `GH_TOKEN`) to authenticate the API calls that resolve a
release tag to a commit.

## OverrideGenerator

Renders `resources/views/flux/icon/<name>.blade.php` from
`stubs/flux-icon.blade.php.stub`, splicing in the drawing commands and the class
list. The stub is the template the kit already uses for its vendored Lucide
icons, so generated files are indistinguishable from the ones Laravel ships.

When the file is written at a name that is not the artwork's own — a Flux
internal, or a Flux-owned name like `loading` — the generator adds a second
comment line saying so, because the reason a file stands in for another name is
not obvious months later.

The stub throws on the `solid` variant, because Lucide has only one weight. That
is inherited rather than chosen, and it is why the plan carries
`DropSolidIconVariant` alongside the renames: it takes `variant="solid"` off the
usages that are becoming Lucide, ahead of `RewriteIconNames` so it still reads
the names the views carry today.

## The Flux internals manifest

Flux renders some icons from inside its own components. Refit cannot rewrite
vendor code, so it writes overrides at the names Flux asks for.

At runtime `IconScanner::scanFluxPackage()` reads the installed package's Blade
stubs — always more accurate than a recorded list, and it sees the licensed
`flux-pro` stubs on machines that have them. It reads two things: tags and
attributes, and `@props` defaults, because Flux's `error` component renders
`exclamation-triangle` without ever writing it on a tag.

`resources/flux/internal-icons.json` is the fallback for when there is nothing to
scan — a fixture, or a project where Flux is not installed yet — and, more
importantly, it is how the Pro names exist in a repository whose contributors
mostly cannot produce them.

```bash
composer install --working-dir=.flux-pro   # once, needs a licence
composer flux:internals                    # re-record
composer flux:internals:check              # report drift, write nothing
composer flux:internals -- --project=../my-app
```

Editions that are not installed keep whatever the manifest already records, so
running this with only free Flux refreshes those names and leaves the Pro ones
alone. `flux:internals:check` only fails when a *scanned* edition disagrees.

> [!IMPORTANT]
> Only icon names are recorded. None of Flux's source is copied into this
> repository — a name is a fact about which artwork a component asks for.

### Why a sidecar manifest

The licensed repository must never be named in the root `composer.json`.
Composer loads every composer-type repository's `packages.json` before it
resolves anything, so one unauthenticated 401 from `composer.fluxui.dev` fails
the entire install — a contributor without a licence would lose Pest and PHPStan,
not just Flux. `.flux-pro/composer.json` is a separate manifest the root install
never reads.

### Catching drift

A weekly scheduled workflow runs `composer flux:internals:check` against a
licensed install. It catches the one kind of drift no pull request can: Flux
shipping a release that renders an icon the manifest has never seen. The job is
skipped on pull requests from forks, which GitHub gives no secrets, so it can
never fail a contributor's build with a 401 they cannot fix.
