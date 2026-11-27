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
| `composer starter-kits` | Download every `laravel new` Livewire variation to `tests/fixtures/starter-kits` |
| `composer starter-kits:install` | Install every variation as a real application next to this checkout |
| `composer icons:run` / `composer icons:check` | Refresh the committed Lucide bundle, or report drift |
| `composer flux:run` / `composer flux:check` | Re-record the icons Flux renders internally, or report drift |
| `composer sheaf:run` / `composer sheaf:check` | Re-record the components Sheaf ships, or report drift |
| `composer refit:check` | All three drift checks in one go; the Flux one skips without a licence |
| `composer refit:refresh` | `icons:run` then `starter-kits` |
| `composer build` / `composer serve` | Build or serve the workbench application |

Run `composer starter-kits` early. Tests that exercise a real starter kit skip
themselves until the fixtures are on disk, so a suite that looks green may not
have run the half you are changing.

## Playground applications

The fixtures are repository sources: no `vendor`, no `.env`, no built assets.
For a run against something that actually boots, install the variations as
applications instead:

```bash
composer starter-kits:install
```

That drives the real `laravel new` once per variation, with the flags the
installer would otherwise have asked for, and leaves the applications in the
directory above this checkout:

```
code/
├── refit/
├── refit-livewire/
├── refit-livewire-class-components/
├── refit-livewire-teams/
├── refit-livewire-workos/
└── refit-livewire-workos-teams/
```

The variation names are the fixture names, so `--only` takes the same list the
downloader does; the directories are prefixed, since they land next to whatever
else you keep there.

Each one gets this checkout wired in as a Composer path repository, which
Composer symlinks, so refit there *is* the working copy — edit `src`, run the
command again, no reinstall:

```bash
cd ../refit-livewire
php artisan refit
```

Refit is one-way scaffolding, so an application is spent once a run has been
applied. Throw it away and install that one again:

```bash
rm -rf ../refit-livewire
composer starter-kits:install -- --only=livewire
```

| Option | What it does |
|---|---|
| `--list` | The variations, and the `laravel new` command behind each |
| `--only=a,b` | Install a subset |
| `--dest=path` | Install somewhere other than the directory above this checkout |
| `--force` | Hand `--force` to the installer, which installs over an existing directory |
| `--no-link` | Leave the application alone instead of requiring refit into it |
| `--no-node` | Skip installing and building NPM dependencies |
| `--phpunit`, `--git`, `--database=name` | Passed straight through to the installer |

The WorkOS variations install without credentials. Their pages render, but
signing in needs `WORKOS_*` values in the application's `.env`.

## The workbench

`composer serve` boots a Testbench application with refit registered, which is
the quickest way to run the command against something. The workbench is *not* a
starter kit, though — for a realistic run, use one of the playground
applications above.

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
bin/              Maintenance scripts (fixtures, playgrounds, icons, Flux internals)
resources/        Bundled Lucide artwork, recorded Flux internals
stubs/            The Flux icon override template
docs/             This documentation
```
