<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\RestructureBrandLogo;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function ground(string $source): string
{
    $action = new RestructureBrandLogo;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, 'resources/views/components/app-logo.blade.php', $project, new Report))
        ->call($action);
}

it('moves the tile off the slot and onto an element inside it', function (): void {
    $source = <<<'BLADE'
    <x-ui.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </x-ui.brand>
    BLADE;

    $rewritten = ground($source);

    expect($rewritten)
        ->toContain('<x-slot name="logo">')
        // The classes are unchanged, on an element Sheaf's brand renders.
        ->toContain('<div class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">')
        ->toContain('<x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />')
        ->toContain('</div>');

    // And it still reads like something a person indented.
    expect($rewritten)->toContain(<<<'BLADE'
        <x-slot name="logo">
            <div class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
                <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
            </div>
        </x-slot>
    BLADE);
});

it('reads the shorthand slot spelling too', function (): void {
    $source = '<x-ui.brand><x-slot:logo class="tile"><x-app-logo-icon /></x-slot:logo></x-ui.brand>';

    expect(ground($source))->toBe('<x-ui.brand><x-slot:logo><div class="tile"><x-app-logo-icon /></div></x-slot:logo></x-ui.brand>');
});

it('leaves a logo slot with nothing to move alone', function (): void {
    $source = '<x-ui.brand><x-slot:logo><x-app-logo-icon /></x-slot:logo></x-ui.brand>';

    expect(ground($source))->toBe($source);
});

it('leaves slots Sheaf\'s brand never reads alone', function (): void {
    // Only `logo` reaches the brand's markup, so moving anything else would be a
    // rewrite with no rendering behind it.
    $source = '<x-ui.brand><x-slot:mark class="tile"><x-app-logo-icon /></x-slot:mark></x-ui.brand>';

    expect(ground($source))->toBe($source);
});

it('leaves a logo slot outside a brand alone', function (): void {
    $source = '<x-ui.card><x-slot:logo class="tile"><x-app-logo-icon /></x-slot:logo></x-ui.card>';

    expect(ground($source))->toBe($source);
});

it('leaves markup with no brand in it untouched', function (): void {
    $source = '<x-ui.heading>Dashboard</x-ui.heading>';

    expect(ground($source))->toBe($source);
});
