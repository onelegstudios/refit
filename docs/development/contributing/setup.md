---
title: Setup
description: Clone, install, and the composer scripts you will actually use.
order: 1
---

# Setup

```bash
git clone git@github.com:onelegstudios/refit.git
cd refit
composer install
```

That is the whole setup. Refit has no dependency on Flux — free or Pro — and the
full suite passes without either installed.

## Commands

| Command | What it does |
|---|---|
| `composer test` | Full validation: static analysis, formatting, type coverage, tests |
| `composer analyse` | PHPStan (level 7, over `src` and `config`) |
| `composer lint` / `composer lint:check` | Pint, writing or checking |
| `composer test:unit` | Pest, in parallel |
| `composer test:types` | Type coverage, 100% required |
| `composer fixtures` | Download every `laravel new` Livewire variation to `tests/fixtures/starter-kits` |
| `composer icons` / `composer icons:check` | Refresh the committed Lucide bundle, or report drift |
| `composer flux:internals` / `…:check` | Re-record the icons Flux renders internally, or report drift |
| `composer sheaf:components` / `…:check` | Re-record the components Sheaf ships, or report drift |
| `composer refresh` | `icons` then `fixtures` |
| `composer build` / `composer serve` | Build or serve the workbench application |

Run `composer fixtures` early. Tests that exercise a real starter kit skip
themselves until the fixtures are on disk, so a suite that looks green may not
have run the half you are changing.

## The workbench

`composer serve` boots a Testbench application with refit registered, which is
the quickest way to run the command against something. The workbench is *not* a
starter kit, though — for a realistic run, point a copied fixture at it or use a
throwaway `laravel new` project.

The documentation you are reading is served from that same workbench: Laradocs is
a dev dependency, and the workbench provider points it at this repository's
`docs/` directory. `composer serve`, then open `/docs`.

## Contributing without a Flux Pro licence

You do not need one. Anything that needs the licensed `livewire/flux-pro` stubs
skips itself the same way the fixture tests do.

The one thing a licence unlocks is refreshing the record of which icons Flux
renders internally. That reads a sidecar install, set up once:

```bash
composer install --working-dir=.flux-pro
```

`.flux-pro/composer.json` is committed; what it installs is gitignored.

> [!WARNING]
> Never name `composer.fluxui.dev` in the root `composer.json` — not as a
> `require`, not even as a bare `repositories` entry. Composer loads every
> composer-type repository's `packages.json` before resolving anything, so a
> single unauthenticated 401 there fails the whole install, and a contributor
> without a licence loses Pest and PHPStan too. The separate manifest exists
> precisely so the root install never reads it.

See [The icon pipeline](/docs/development/internals/icon-pipeline) for what to do with the
sidecar once it is installed.

## Repository layout

```
src/
├── Blade/        Tag parser, rewriter, offset-based edits
├── Console/      The refit command
├── Contracts/    Task and Action
├── Icons/        Strategy, map, scanner, planner, override generator
├── Plan/         Plan, stages, actions, applier, report, guard
├── Project/      Detection, features, component grouping
├── Support/      Git
├── Tasks/        The tasks refit ships
├── Facades/      The Refit facade
└── Refit.php     The task registry
bin/              Maintenance scripts (fixtures, icons, Flux internals)
resources/        Bundled Lucide artwork, recorded Flux internals
stubs/            The Flux icon override template
docs/             This documentation
```
