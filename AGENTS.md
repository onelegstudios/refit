# Refit

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

The package hasn't been released yet. We don't even have v0.1.0, so changes to the core logic are okay.

`onelegstudios/refit` is a dev-only Laravel package (PHP 8.3+, Laravel 13) that customises a freshly installed Livewire + Flux starter kit. A single interactive command, `php artisan refit`, picks an icon set and a list of structural tasks, shows the plan, and applies it.

It is one-time scaffolding: it rewrites files in place, the changes are one-way and not idempotent, and the command offers to uninstall itself when it finishes.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `onelegstudios/refit`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Documentation

The README is a short introduction only. Everything else lives in `docs/`, as Laradocs-flavoured markdown with YAML front-matter. The folder tree is the navigation — `docs/using-refit/{guide,reference}` for people using refit, `docs/development/{contributing,internals}` for people working on it, and `_index.md` as each section's landing page. Nothing uses `group:`; add a folder rather than a front-matter group. Cross-links use site-absolute paths (`/docs/using-refit/guide/icons`).

Laradocs is a dev dependency and the workbench provider points it at this repository's `docs/`, so `composer serve` renders the documentation at `/docs`. `docs/` is `export-ignore`d, so it stays out of the Composer dist. When behavior changes, update the page that promises it rather than growing the README back.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`
- Starter kit fixtures: `composer fixtures` (downloads every `laravel new` Livewire variation to `tests/fixtures/starter-kits`, gitignored)
- Lucide artwork: `composer icons` (refreshes the committed `resources/icons/lucide` bundle from the latest Lucide release); `composer icons:check` reports drift without writing
- Flux internals: `composer flux:internals` re-records `resources/flux/internal-icons.json` from the `.flux-pro` sidecar (`composer install --working-dir=.flux-pro`, needs a Flux Pro licence); `composer flux:internals:check` reports drift without writing. Both take `-- --project=path` to read another project. Editions that are not installed keep their recorded names, so an unlicensed run never drops the Pro ones.
- Never name `composer.fluxui.dev` in the root `composer.json` — not as a `require`, not even as a bare `repositories` entry. Composer loads every composer-type repository's `packages.json` before resolving, so an unauthenticated 401 there fails the entire install and contributors lose Pest and PHPStan too. That is why the licensed install lives in the separate, committed `.flux-pro/composer.json`, which the root install never reads.
- Refresh everything: `composer refresh` (runs `icons` then `fixtures`; note it can leave a diff in `resources/icons/lucide` to review and commit)

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
