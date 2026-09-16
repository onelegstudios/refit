<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Tasks\KeepOneLayout;
use Onelegstudios\Refit\Tasks\MoveAuthViewsOutOfPages;
use Onelegstudios\Refit\Tasks\MoveComponentsOutOfPages;
use Onelegstudios\Refit\Tasks\MoveToastsToTop;
use Onelegstudios\Refit\Tasks\NamespaceComponents;
use Onelegstudios\Refit\Tasks\PromotePartialsToComponents;
use Onelegstudios\Refit\Tasks\RemoveFluxProSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Libraries
    |--------------------------------------------------------------------------
    |
    | The UI component libraries `php artisan refit` offers as a target. The
    | starter kit ships Flux, so Flux is always the source — a library here is
    | somewhere a project can end up, and listing Flux is what makes "keep what
    | I have" an answer rather than a special case.
    |
    | Resolved from the container, like tasks, so a library may type-hint its
    | own dependencies. Add your own here, or from a service provider with
    | `Refit::library(new YourLibrary)`.
    |
    */

    'libraries' => [
        FluxLibrary::class,
        SheafLibrary::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    |
    | The optional tasks `php artisan refit` offers once the icon set is chosen.
    | Each is resolved from the container, so a task may type-hint its own
    | dependencies. Add your own here, or register them from a service provider
    | with `Refit::task(new YourTask)`.
    |
    | Only tasks whose `appliesTo()` returns true for the detected starter kit
    | are shown, so listing one that does not fit is harmless.
    |
    */

    'tasks' => [
        PromotePartialsToComponents::class,
        MoveAuthViewsOutOfPages::class,
        MoveComponentsOutOfPages::class,
        NamespaceComponents::class,
        MoveToastsToTop::class,
        KeepOneLayout::class,
        RemoveFluxProSource::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notes file
    |--------------------------------------------------------------------------
    |
    | Where refit writes the run report when it has anything to flag — icons it
    | could not translate, files it declined to touch. Set to null to keep the
    | report on screen only.
    |
    */

    'notes' => 'REFIT-NOTES.md',

];
