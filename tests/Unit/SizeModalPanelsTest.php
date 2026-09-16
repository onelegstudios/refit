<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\SizeModalPanels;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function sizeModals(string $source, ?Report $report = null, string $path = 'resources/views/pages/settings/security.blade.php'): string
{
    $action = new SizeModalPanels;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $report ??= new Report;

    $rewritten = (fn (): string => $this->transform($source, $path, $project, $report))->call($action);

    (fn () => $this->finish($report))->call($action);

    return $rewritten;
}

it('names the size Sheaf gives a lone max-width class', function (): void {
    // Sheaf puts `class` on a wrapper the panel is teleported out of, so the
    // class reaches nothing and the panel falls back to `width="sm"`.
    expect(sizeModals('<x-ui.modal id="delete-team" class="max-w-lg">'))
        ->toBe('<x-ui.modal id="delete-team" width="lg">');
});

it('moves any other classes over whole, which Sheaf puts on the panel', function (): void {
    // Sheaf's width match falls through to the value as written, which is the
    // panel Flux gave every class to.
    expect(sizeModals('<x-ui.modal id="two-factor-setup-modal" class="max-w-md md:min-w-md">'))
        ->toBe('<x-ui.modal id="two-factor-setup-modal" width="max-w-md md:min-w-md">');
});

it('leaves a modal with no classes alone', function (): void {
    expect(sizeModals('<x-ui.modal id="a">'))->toBe('<x-ui.modal id="a">')
        ->and(sizeModals('<x-ui.modal id="a" class="">'))->toBe('<x-ui.modal id="a" class="">')
        ->and(sizeModals('<x-ui.modal.trigger id="a" class="max-w-lg">'))->toBe('<x-ui.modal.trigger id="a" class="max-w-lg">');
});

it('leaves a modal that already sizes itself, or binds its classes, and says where', function (): void {
    $report = new Report;

    expect(sizeModals('<x-ui.modal width="xl" class="max-w-lg">', $report))->toBe('<x-ui.modal width="xl" class="max-w-lg">')
        ->and(sizeModals('<x-ui.modal :class="$wide ? \'max-w-xl\' : \'\'">', $report, 'resources/views/other.blade.php'))
        ->toBe('<x-ui.modal :class="$wide ? \'max-w-xl\' : \'\'">');

    expect(implode("\n", $report->warnings()))
        ->toContain('resources/views/pages/settings/security.blade.php')
        ->toContain('resources/views/other.blade.php');
});

it('leaves Sheaf\'s own components alone', function (): void {
    $source = '<x-ui.modal class="max-w-lg">';

    expect(sizeModals($source, path: 'resources/views/components/ui/select/index.blade.php'))->toBe($source);
});
