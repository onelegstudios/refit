---
title: Refit
description: One-time scaffolding for Laravel's Livewire starter kit.
order: 1
---

# Refit

Refit is a dev-only Laravel package that customises a freshly installed Livewire
starter kit. One interactive command — `php artisan refit` — picks an icon
set and a list of structural tasks, shows you exactly what it intends to change,
and applies it.

```bash
composer require --dev onelegstudios/refit
php artisan refit
```

> [!IMPORTANT]
> Refit is one-time scaffolding. It rewrites files in place, the changes are
> one-way and not idempotent, and it offers to remove itself when it is done.

## What it does

- **Settles the icon question.** A fresh kit speaks Heroicons _and_ Lucide at
  once. Refit takes you to one or the other — including the icons Flux renders
  from inside its own components. See [Icons](/docs/using-refit/guide/icons).
- **Runs structural tasks.** Partials become components, loose components get
  sorted into folders, the layouts nothing renders go. Only tasks that fit the
  kit it detected are offered. See [Tasks](/docs/using-refit/guide/tasks).
- **Shows you the plan first.** Nothing touches disk until you have read the
  list of changes and agreed to it. See
  [The refit command](/docs/using-refit/guide/the-refit-command).
- **Reports what it could not do.** An icon with no translation or an `@include`
  that passes data is named, with the file it is in, never silently guessed at.
  See [Troubleshooting](/docs/using-refit/reference/troubleshooting).

## Where to go next

- New here? Start with [Getting started](/docs/getting-started).
- Want to change what the tasks do, or add your own?
  [Configuration](/docs/using-refit/reference/configuration) and
  [Writing your own task](/docs/using-refit/guide/custom-tasks).
- Working _on_ refit rather than with it? The
  [Development](/docs/development) section covers the architecture, the icon
  pipeline, and the test suite.

## Requirements

|             | Version                                                                                               |
| ----------- | ----------------------------------------------------------------------------------------------------- |
| PHP         | 8.3, 8.4 or 8.5                                                                                       |
| Laravel     | 13                                                                                                    |
| Starter kit | Livewire + Flux, any `laravel new` variation — see [Supported starter kits](/docs/using-refit/reference/starter-kits) |
