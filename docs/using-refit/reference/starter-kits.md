---
title: Supported starter kits
description: The Livewire variations refit knows, and the one it does not.
order: 2
---

# Supported starter kits

Every variation `laravel new` can generate for the Livewire kit:

| Variation | What it is |
|---|---|
| `livewire` | Built-in auth, single-file components |
| `livewire-class-components` | Built-in auth, Livewire class components |
| `livewire-teams` | Built-in auth with teams |
| `livewire-workos` | WorkOS AuthKit |
| `livewire-workos-teams` | WorkOS AuthKit with teams |

They are one repository with five branches, picked from the answers you give
`laravel new` about authentication provider, teams support, and single-file
components.

The **blank** Livewire kit is not supported: it ships without Flux and without
auth, so there is nothing to refit.

## `install:features` and `chisel.php`

The kit does not download only the features you asked for. It ships **every**
authentication feature at once, with the code for each one fenced off by section
markers, and carves the unwanted ones away afterwards.

`chisel.php` is the script that does the carving. It sits in the project root,
asks a single question —

> Which authentication features would you like to enable?
> *Email verification, Registration, Two-factor authentication, Passkeys,
> Password confirmation*

— and for every feature you leave unticked it deletes that feature's files,
strips its marked sections out of the files it shared with others, removes its
imports and traits, and drops the npm packages it needed.
`php artisan install:features` is the command that loads and runs it.

You have almost certainly never run that command yourself, because `laravel new`
runs it for you. The kit lists it under
`extra.laravel.installer.post-create-project` in `composer.json`, so it fires
while the installer is still working — between the questions you answered and
the shell prompt coming back. Its last act is to delete itself: `chisel.php`,
`chisel-paths.php`, `app/Console/Commands/InstallFeaturesCommand.php` and the
`composer.json` lines that invoked it all go. By the time you `cd` into the new
project there is nothing left to see, which is why the file is unfamiliar to
most people who have used the kit for years.

That disappearing act is exactly what makes it a useful signal. A `chisel.php`
still sitting in the root means the carving has **not** happened yet — usually
because the starter kit repository was cloned directly instead of created with
`laravel new`, or because the installer's run was interrupted or failed partway
through. Either way the tree still holds every feature, including the ones that
are about to be deleted, so refit stops rather than spend a run rewriting views
with no future. See [Preflight](/docs/using-refit/guide/the-refit-command#preflight).

## What varies between them

Refit does not branch on the variation name — it never sees one. It reads the
tree and adapts:

- **Component style** decides where the kit's Livewire views live:
  `resources/views/pages` for single-file components,
  `resources/views/livewire` for class components. Single-file components carry
  an installer-applied prefix on the filename, which is why refit globs for those
  views rather than testing an exact path.
- **Features** — teams, WorkOS, passkeys, two-factor, registration — are whatever
  survived `install:features`, and each is detected from a file that either
  exists or does not.
- **Flux edition** decides whether the `@source` cleanup task is offered at all,
  and which stubs are scanned for the icons Flux renders internally.

The upshot is that a variation not on this list still gets a sensible run, as
long as it is recognisably the Livewire kit: tasks that do not fit are not
offered, and nothing is assumed that disk did not answer.

## Fixtures

Refit's test suite runs against real copies of all five, downloaded with
`composer starter-kits`. They are gitignored, and tests that need one skip
themselves when it is absent. See
[Testing](/docs/development/contributing/testing).
