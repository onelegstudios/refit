---
title: Configuration
description: Publishing config/refit.php, choosing which libraries and tasks are offered, and where notes are written.
order: 1
---

# Configuration

Refit works with no configuration at all. Publish the file when you want to
change which libraries or tasks are offered, or how one of them behaves:

```bash
php artisan vendor:publish --tag="refit-config"
```

Both `refit` and `refit-config` are registered as publish tags.

## The file

```php
// config/refit.php

return [

    'libraries' => [
        FluxLibrary::class,
        SheafLibrary::class,
    ],

    'tasks' => [
        PromotePartialsToComponents::class,
        NamespaceComponents::class,
        MoveToastsToTop::class,
        KeepOneLayout::class,
        RemoveFluxProSource::class,
    ],

    'notes' => 'REFIT-NOTES.md',

];
```

### `libraries`

The component libraries `php artisan refit` offers as a target. The starter kit
ships Flux, so Flux is always the source — a library here is somewhere a project
can end up, and listing Flux is what makes "keep what I have" an answer rather
than a special case.

Resolved from the container, like tasks. Drop `SheafLibrary` to stop offering it;
with only one entry left refit stops asking the question at all. See
[Libraries](/docs/using-refit/guide/libraries).

A library is also what knows how to take itself back out, so a project leaving
Flux cleans up after it whether or not any task is chosen. Removing an entry here
removes both halves — a library refit no longer knows about is one it can no
longer tear down either.

### `tasks`

The optional tasks `php artisan refit` offers once the icon set is chosen. Each is
resolved from the container, so a task may type-hint its own dependencies.

Only tasks whose `appliesTo()` returns true for the detected starter kit are
shown, so listing one that does not fit is harmless. Remove a class to stop
offering it; add your own to offer it alongside the shipped ones.

### `notes`

Where refit writes the run report when it has anything to flag — icons it could
not translate, files it declined to touch. Set it to `null` to keep the report on
screen only.

The file is written only when there is a warning to record, so a clean run leaves
no notes behind. See [Troubleshooting](/docs/using-refit/reference/troubleshooting).

## Registering from a service provider

Config is not the only way in. The `Refit` facade is a registry you can add to
from any service provider, which is how a package ships a task — or a whole
library — of its own:

```php
use Onelegstudios\Refit\Facades\Refit;

public function boot(): void
{
    Refit::task(new DropTheWelcomePage);
}
```

`Refit::library()` works the same way, and a library registered this way is
detected and offered like any other:

```php
Refit::library(new YourDesignSystem);
```

See [Component mapping](/docs/development/internals/component-mapping) for what
the `Library` contract asks of you.

`Refit::task()` is variadic and returns the registry, so several can go in at
once. Registering an *instance* is also how you reconfigure a shipped task —
`NamespaceComponents` takes a component map and a fallback folder:

```php
use Onelegstudios\Refit\Tasks\NamespaceComponents;

Refit::task(new NamespaceComponents(
    groups: [
        'app-logo' => 'marketing/logo',
        'app-logo-icon' => 'marketing/logo-icon',
    ],
    fallback: 'shared',
));
```

> [!TIP]
> When you reconfigure a task this way, drop its class from the `tasks` array
> first. Otherwise both copies are registered and both appear in the list.

## Removing refit

When the run finishes, refit offers to remove itself. Saying yes deletes the
published `config/refit.php` — it only ever configured refit, so once the package
is on its way out the file is a config entry pointing at classes that will not be
autoloadable any more — and prints the command that finishes the job:

```bash
composer remove --dev onelegstudios/refit
```

Nothing else refit wrote depends on the package staying installed. The generated
icon overrides are plain Blade files, and the moved components are your
application's own.
