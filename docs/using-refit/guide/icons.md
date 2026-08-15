---
title: Icons
description: Heroicons, Lucide, Phosphor, and the icons a library draws from inside its own components.
order: 3
---

# Icons

A fresh Livewire kit already speaks two icon sets. Flux resolves
[Heroicons](https://heroicons.com) by name, and the kit vendors four
[Lucide](https://lucide.dev) icons in as `resources/views/flux/icon/*.blade.php`
overrides for names Heroicons does not have.

Which answers refit offers depends on the
[library you chose](/docs/using-refit/guide/libraries), because how an icon gets
resolved is a fact about the library rather than about the icon set.

## Staying on Flux

| Choice | What happens |
|---|---|
| **Keep the mix** | Nothing changes — what a fresh starter kit gives you |
| **Heroicons only** | Deletes the vendored Lucide overrides and points their usages at the Heroicons equivalents Flux already ships |
| **Lucide only** | Generates a Flux override for every icon in use, translating names as it goes |

The two directions are not symmetric. Going to Heroicons is subtraction: four
files deleted, their usages renamed. Going to Lucide is generation: an override
file per icon, written from artwork refit bundles.

## Moving to Sheaf

Sheaf resolves icons through an `<x-ui.icon>` component that lives in your own
codebase, so there is no override directory and nothing to generate.

| Choice | What happens |
|---|---|
| **Heroicons only** | What Sheaf reads by default, and where a Flux kit almost entirely is already. The four vendored Lucide names are pointed back at Heroicons |
| **Phosphor only** | The same, then every icon name is prefixed with `ps:`, which is how Sheaf picks its provider |

Phosphor needs `php artisan sheaf:init --with-phosphor`. Refit says so rather than
running it, because it changes what Sheaf installs.

**Lucide is not offered under Sheaf.** It would need a third artwork mechanism —
`blade-ui-kit/blade-icons` and the `bk:` name prefix — and a dependency you did
not ask for. Nothing stops you doing it by hand afterwards.

## Where names are read from

Icon names are read in all three forms the kit writes them:

```blade
<flux:icon.key />
<flux:button icon="plus" icon:trailing="chevron-down" />
<flux:icon name="users" />
```

The attribute has to be read together with the tag it sits on. `name` names an
icon on `<flux:icon>` and nothing else — treating it as an icon everywhere would
rewrite the `name="email"` on every `<flux:input>` in the kit. Bound values
(`:icon="$icon"`) and interpolated ones (`name="{{ $icon }}"`) hold an
expression rather than a name, so they are left alone.

An icon refit has no translation for is reported with the file it appears in,
never silently dropped or guessed at.

## The icons Flux draws itself

The rest of this page is about a Flux target. Sheaf's components are ordinary
application Blade, so the scanner reads them like any other view and none of the
machinery below applies.

Going all-Lucide also covers the icons Flux renders from *inside* its own
components — the chevron on a `flux:select`, the eye on a `viewable` input.
Refit cannot rewrite vendor code, so it writes those overrides at the
**Heroicons** name instead, and says so in the file:

```blade
{{-- Credit: Lucide (https://lucide.dev) --}}
{{-- Lucide's "chevrons-up-down", overriding the Heroicons name Flux resolves internally. --}}
```

Without that, half your icons would follow the switch and half would not.

`flux:icon.loading` gets the same treatment for the same reason: it is Flux's own
spinner rather than a Heroicon, so the override is written at `loading` and your
markup is left alone. It draws Lucide's `loader-circle`, with `animate-spin` so
it still spins.

Which icons Flux renders internally is read from the Flux package installed
alongside your project, so it stays right as Flux changes — and it sees the
`livewire/flux-pro` stubs when you have a licence. When there is nothing to scan,
refit falls back to the list recorded in `resources/flux/internal-icons.json`.
That recorded list covers both editions, so a free-Flux project may get an
override or two it never needed. Harmless, where a missing one leaves a stray
Heroicon behind.

## What the overrides look like

Generated overrides use the same template the starter kit already uses for its
own vendored Lucide icons — same credit comment, same `variant` prop, same
`Flux::classes()` sizing and stroke-width tables. A generated file is
indistinguishable from the ones Laravel ships.

### Lucide draws one weight

That template throws on `variant="solid"` rather than quietly drawing an outline
in its place — Laravel's decision, and refit keeps it. The kit's own two-factor
setup modal asks for exactly that:

```blade
<flux:icon.check x-show="copied" variant="solid" class="text-green-500" />
```

So going all-Lucide drops the attribute, leaving the template's `outline`
default. The same goes for the pass-through form, `<flux:button icon="trash"
icon:variant="solid">`. Only icons that actually became Lucide are touched: one
refit could not translate keeps its Heroicon and the solid weight with it, and a
`variant="solid"` that belongs to the component rather than its icon — a
`<flux:badge>`, say — is left alone.

The artwork is bundled with refit rather than pulled from a Blade Icons package.
Refit only ever generates the names it can translate, so a package dependency
would add weight without widening what the tool can do, and it would make the
output vary with whatever Composer resolved.

## Unsupported names

Two things can stop a translation, and both are reported rather than guessed:

- **No mapping.** The name is outside refit's curated map.
  *"No Lucide translation for `sparkles` — still Heroicons in
  resources/views/pages/dashboard.blade.php."*
- **No artwork.** The mapping exists but refit does not bundle the drawing. The
  rename is dropped along with it — pointing a usage at an override that never
  gets written would leave a blank where the icon was.

Both land in [`REFIT-NOTES.md`](/docs/using-refit/reference/troubleshooting). Adding a
translation is a two-line change to refit itself; see
[The icon pipeline](/docs/development/internals/icon-pipeline).
