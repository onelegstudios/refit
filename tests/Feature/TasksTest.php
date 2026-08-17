<?php

declare(strict_types=1);

use Onelegstudios\Refit\Contracts\Task;
use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Applier;
use Onelegstudios\Refit\Plan\Plan;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\Project;
use Onelegstudios\Refit\Project\ProjectDetector;
use Onelegstudios\Refit\Refit;
use Onelegstudios\Refit\Tasks\KeepOneLayout;
use Onelegstudios\Refit\Tasks\MoveAuthViewsOutOfPages;
use Onelegstudios\Refit\Tasks\MoveComponentsOutOfPages;
use Onelegstudios\Refit\Tasks\MoveToastsToTop;
use Onelegstudios\Refit\Tasks\NamespaceComponents;
use Onelegstudios\Refit\Tasks\PromotePartialsToComponents;
use Onelegstudios\Refit\Tasks\RemoveFluxProSource;

/**
 * Run one task against a throwaway copy of a fixture.
 *
 * @return array{Project, Report}
 */
function runTask(Task $task, string $kit): array
{
    $root = copyFixture($kit);
    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;
    $report = new Report;

    $task->contribute($plan, $project, $report);

    (new Applier)->apply($plan, $project, $report);

    return [$project, $report];
}

it('registers the configured tasks', function (): void {
    $refit = app(Refit::class);

    expect(array_map(fn (Task $task): string => $task->key(), $refit->tasks()))
        ->toBe([
            'partials-to-components',
            'auth-views-out-of-pages',
            'components-out-of-pages',
            'namespace-components',
            'toasts-at-top',
            'single-layout',
            'remove-flux-pro-source',
        ]);
});

it('only offers tasks that fit the detected kit', function (): void {
    $refit = app(Refit::class);
    $project = detectFixture('livewire')->targeting(new FluxLibrary);
    $keys = array_map(fn (Task $task): string => $task->key(), $refit->tasksFor($project));

    expect($keys)->toContain('partials-to-components')
        ->and($keys)->toContain('remove-flux-pro-source');
});

it('stops offering the Flux Pro cleanup once the project is leaving Flux', function (): void {
    $refit = app(Refit::class);
    $sheaf = array_map(
        fn (Task $task): string => $task->key(),
        $refit->tasksFor(detectFixture('livewire')->targeting(new SheafLibrary)),
    );

    // The whole stylesheet reference goes in Flux's teardown, and trimming one
    // @source line off a file that is losing both would only be confusing.
    expect($sheaf)->not->toContain('remove-flux-pro-source');
});

it('turns partials into components and rewrites the includes', function (): void {
    [$project] = runTask(new PromotePartialsToComponents, 'livewire');

    expect($project->exists('resources/views/components/head.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/partials/head.blade.php'))->toBeFalse()
        ->and($project->get('resources/views/layouts/app/sidebar.blade.php'))
        ->toContain('<x-head />')
        ->not->toContain("@include('partials.head')");
});

it('removes the emptied partials directory', function (string $kit): void {
    [$project, $report] = runTask(new PromotePartialsToComponents, $kit);

    expect($project->exists('resources/views/partials'))->toBeFalse()
        ->and($report->notes())->toContain('Removed the now empty resources/views/partials directory');
})->with(starterKits());

it('keeps the partials directory when something unplanned is left in it', function (): void {
    $root = copyFixture('livewire');
    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;
    $report = new Report;

    (new PromotePartialsToComponents)->contribute($plan, $project, $report);

    file_put_contents($project->path('resources/views/partials/notes.md'), 'keep me');

    (new Applier)->apply($plan, $project, $report);

    expect($project->exists('resources/views/partials/notes.md'))->toBeTrue()
        ->and($report->warnings())
        ->toContain('Left [resources/views/partials] in place — it still holds notes.md.');
});

it('leaves no partial include behind', function (string $kit): void {
    [$project] = runTask(new PromotePartialsToComponents, $kit);

    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain("@include('partials.");
    }
})->with(starterKits());

it('moves the auth views out of pages', function (string $kit): void {
    [$project] = runTask(new MoveAuthViewsOutOfPages, $kit);

    expect($project->exists('resources/views/auth/login.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/auth/reset-password.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/pages/auth'))->toBeFalse();
})->with(['livewire', 'livewire-teams']);

it('points Fortify at the moved views', function (): void {
    [$project] = runTask(new MoveAuthViewsOutOfPages, 'livewire');

    expect($project->get('app/Providers/FortifyServiceProvider.php'))
        ->toContain("view('auth.login')")
        ->toContain("view('auth.forgot-password')")
        ->not->toContain('pages::auth.');
});

it('leaves the Livewire pages in the pages directory', function (): void {
    [$project] = runTask(new MoveAuthViewsOutOfPages, 'livewire');

    expect($project->get('resources/views/pages/settings/⚡profile.blade.php'))
        ->toContain('<x-pages::settings.layout');
});

it('is not offered to a kit that keeps its auth views elsewhere', function (string $kit): void {
    expect((new MoveAuthViewsOutOfPages)->appliesTo(detectFixture($kit)))->toBeFalse();
})->with(['livewire-class-components', 'livewire-workos', 'livewire-workos-teams']);

it('moves the class-component views when it is pointed at them', function (): void {
    [$project] = runTask(
        new MoveAuthViewsOutOfPages('resources/views/livewire/auth', 'livewire.auth.'),
        'livewire-class-components',
    );

    expect($project->exists('resources/views/auth/login.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/livewire/auth'))->toBeFalse()
        ->and($project->get('app/Providers/FortifyServiceProvider.php'))
        ->toContain("view('auth.login')")
        ->not->toContain('livewire.auth.');
});

it('has nothing left to move once the auth views have gone', function (): void {
    [$project] = runTask(new MoveAuthViewsOutOfPages, 'livewire');

    expect((new MoveAuthViewsOutOfPages)->appliesTo($project))->toBeFalse();
});

it('moves the components no route points at out of pages', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->exists('resources/views/components/settings/⚡delete-user-form.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/components/settings/⚡delete-user-modal.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/components/settings/⚡two-factor-setup-modal.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/components/settings/two-factor/⚡recovery-codes.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/pages/settings/⚡delete-user-form.blade.php'))->toBeFalse();
});

it('leaves the pages a route does point at where they are', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->exists('resources/views/pages/settings/⚡profile.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/pages/settings/⚡appearance.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/pages/settings/⚡security.blade.php'))->toBeTrue()
        ->and($project->get('routes/settings.php'))->toContain("'pages::settings.profile'");
});

it('drops the namespace from every reference to a moved component', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->get('resources/views/pages/settings/⚡profile.blade.php'))
        ->toContain('<livewire:settings.delete-user-form />')
        ->and($project->get('resources/views/pages/settings/⚡security.blade.php'))
        ->toContain('<livewire:settings.two-factor.recovery-codes')
        ->toContain('<livewire:settings.two-factor-setup-modal')
        ->and($project->get('resources/views/components/settings/⚡delete-user-form.blade.php'))
        ->toContain('<livewire:settings.delete-user-modal />');
});

it('moves the settings layout to where the class-component kit keeps it', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->exists('resources/views/components/settings/layout.blade.php'))->toBeTrue()
        ->and($project->get('resources/views/pages/settings/⚡profile.blade.php'))
        ->toContain('<x-settings.layout')
        ->toContain('</x-settings.layout>')
        ->not->toContain('x-pages::');
});

it('repoints the component names the kit names as strings', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->get('tests/Feature/Settings/ProfileUpdateTest.php'))
        ->toContain("Livewire::test('settings.delete-user-modal')")
        ->toContain("Livewire::test('pages::settings.profile')");
});

it('removes a folder that held nothing but components', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->exists('resources/views/pages/settings/two-factor'))->toBeFalse()
        ->and($project->exists('resources/views/pages/settings'))->toBeTrue();
});

it('leaves a section no route points into alone', function (): void {
    [$project, $report] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect($project->exists('resources/views/pages/auth/login.blade.php'))->toBeTrue()
        ->and($report->warnings())->toBe([]);
});

it('sorts the team modals out from the two routed team pages', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire-teams');

    expect($project->exists('resources/views/components/teams/⚡delete-team-modal.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/pages/teams/⚡index.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/pages/teams/⚡edit.blade.php'))->toBeTrue()
        ->and($project->get('resources/views/dashboard.blade.php'))
        ->toContain('<livewire:teams.pending-invitations-modal />');
});

it('leaves no namespaced reference behind in any single-file kit', function (string $kit): void {
    [$project] = runTask(new MoveComponentsOutOfPages, $kit);

    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain('pages::');
    }
})->with(['livewire', 'livewire-teams', 'livewire-workos', 'livewire-workos-teams']);

it('is not offered to the class-component kit', function (): void {
    expect((new MoveComponentsOutOfPages)->appliesTo(detectFixture('livewire-class-components')))->toBeFalse();
});

it('has nothing left to move once the components have gone', function (): void {
    [$project] = runTask(new MoveComponentsOutOfPages, 'livewire');

    expect((new MoveComponentsOutOfPages)->appliesTo($project))->toBeFalse();
});

it('groups components by domain and repoints every reference', function (): void {
    [$project] = runTask(new NamespaceComponents, 'livewire');

    expect($project->exists('resources/views/components/brand/logo.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/components/auth/header.blade.php'))->toBeTrue()
        ->and($project->get('resources/views/layouts/app/sidebar.blade.php'))
        ->toContain('<x-brand.logo')
        ->not->toContain('<x-app-logo');
});

it('drops the prefix the folder now carries', function (): void {
    [$project] = runTask(new NamespaceComponents, 'livewire');

    expect($project->get('resources/views/pages/auth/login.blade.php'))
        ->toContain('<x-auth.session-status')
        ->not->toContain('<x-auth-session-status');
});

it('leaves a component with nothing to group with in the fallback folder', function (): void {
    [$project] = runTask(new NamespaceComponents, 'livewire');

    expect($project->exists('resources/views/components/ui/placeholder-pattern.blade.php'))->toBeTrue()
        ->and($project->get('resources/views/dashboard.blade.php'))
        ->toContain('<x-ui.placeholder-pattern');
});

it('does not confuse two components sharing a name prefix', function (): void {
    [$project] = runTask(new NamespaceComponents, 'livewire');

    $logo = $project->get('resources/views/components/brand/logo.blade.php');

    expect($logo)->toContain('<x-brand.logo-icon')
        ->and($project->exists('resources/views/components/brand/logo-icon.blade.php'))->toBeTrue();
});

it('leaves no loose component behind in any kit', function (string $kit): void {
    [$project] = runTask(new NamespaceComponents, $kit);

    expect($project->looseComponents())->toBe([]);
})->with(starterKits());

it('keeps only the layouts the application renders', function (string $kit): void {
    [$project] = runTask(new KeepOneLayout, $kit);

    expect($project->exists('resources/views/layouts/auth/simple.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/layouts/auth/card.blade.php'))->toBeFalse()
        ->and($project->exists('resources/views/layouts/auth/split.blade.php'))->toBeFalse()
        ->and($project->exists('resources/views/layouts/app/sidebar.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/layouts/app/header.blade.php'))->toBeFalse();
})->with(starterKits());

it('follows the delegating layout to the other shell', function (): void {
    $root = copyFixture('livewire');
    $delegate = 'resources/views/layouts/app.blade.php';

    file_put_contents($root.'/'.$delegate, str_replace(
        'app.sidebar',
        'app.header',
        (string) file_get_contents($root.'/'.$delegate),
    ));

    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;
    $report = new Report;

    (new KeepOneLayout)->contribute($plan, $project, $report);
    (new Applier)->apply($plan, $project, $report);

    expect($project->exists('resources/views/layouts/app/header.blade.php'))->toBeTrue()
        ->and($project->exists('resources/views/layouts/app/sidebar.blade.php'))->toBeFalse();
});

it('touches only the families it was given', function (): void {
    [$project] = runTask(new KeepOneLayout(['auth']), 'livewire');

    expect($project->exists('resources/views/layouts/auth/card.blade.php'))->toBeFalse()
        ->and($project->exists('resources/views/layouts/app/header.blade.php'))->toBeTrue();
});

it('has nothing to delete once one layout is left in each family', function (): void {
    [$project] = runTask(new KeepOneLayout, 'livewire');

    expect((new KeepOneLayout)->appliesTo($project))->toBeFalse();
});

it('positions every toast group at the top', function (string $kit): void {
    [$project] = runTask(new MoveToastsToTop, $kit);

    $layouts = array_values(array_filter(
        $project->blades(),
        fn (string $path): bool => str_contains($project->get($path), '<flux:toast.group'),
    ));

    expect($layouts)->not->toBeEmpty();

    foreach ($layouts as $path) {
        expect($project->get($path))->toContain('<flux:toast.group position="top end">');
    }
})->with(starterKits());

it('has nothing left to do once the toasts are at the top', function (): void {
    [$project] = runTask(new MoveToastsToTop, 'livewire');

    expect((new MoveToastsToTop)->appliesTo($project))->toBeFalse();
});

it('keeps a toast group that already chose a position', function (): void {
    $root = copyFixture('livewire');
    $layout = 'resources/views/layouts/app/sidebar.blade.php';

    file_put_contents($root.'/'.$layout, str_replace(
        '<flux:toast.group>',
        '<flux:toast.group position="bottom center">',
        (string) file_get_contents($root.'/'.$layout),
    ));

    $project = (new ProjectDetector)->detect($root);
    $plan = new Plan;
    $report = new Report;

    (new MoveToastsToTop)->contribute($plan, $project, $report);
    (new Applier)->apply($plan, $project, $report);

    expect($project->get($layout))
        ->toContain('<flux:toast.group position="bottom center">')
        ->not->toContain('top end');
});

it('drops the Flux Pro source line', function (): void {
    [$project] = runTask(new RemoveFluxProSource, 'livewire');

    expect($project->get('resources/css/app.css'))
        ->not->toContain('flux-pro')
        ->toContain("@import 'tailwindcss';");
});

it('does not offer the Flux Pro cleanup when Flux Pro is installed', function (): void {
    $root = copyFixture('livewire');

    file_put_contents($root.'/composer.lock', '{"packages":[{"name":"livewire/flux-pro"}]}');

    $project = (new ProjectDetector)->detect($root)->targeting(new FluxLibrary);

    expect($project->library(FluxLibrary::KEY)?->pro)->toBeTrue()
        ->and((new RemoveFluxProSource)->appliesTo($project))->toBeFalse();
});
