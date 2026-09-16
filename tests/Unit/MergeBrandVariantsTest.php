<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\MergeBrandVariants;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function merge(string $source): string
{
    $action = new MergeBrandVariants;
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

it('collapses the branch the kit chose its brand with', function (): void {
    // What the kit's app-logo looks like once the rename has sent both of Flux's
    // brands to the one Sheaf ships.
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
    ])

    @if($sidebar)
        <x-ui.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
            <x-slot name="logo">
                <x-app-logo-icon />
            </x-slot>
        </x-ui.brand>
    @else
        <x-ui.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
            <x-slot name="logo">
                <x-app-logo-icon />
            </x-slot>
        </x-ui.brand>
    @endif
    BLADE;

    // One brand, at the depth the conditional was at, and no prop left to take.
    expect(merge($source))->toBe(<<<'BLADE'
    <x-ui.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo">
            <x-app-logo-icon />
        </x-slot>
    </x-ui.brand>

    BLADE);
});

it('keeps the props it did not empty', function (): void {
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
        'href' => '#',
    ])

    @if($sidebar)
        <x-ui.brand :href="$href">Laravel</x-ui.brand>
    @else
        <x-ui.brand :href="$href">Laravel</x-ui.brand>
    @endif
    BLADE;

    expect(merge($source))
        ->toContain("@props([\n    'href' => '#',\n])")
        ->not->toContain('sidebar');
});

it('keeps a prop the rest of the file still reads', function (): void {
    // The conditional is dead either way, but a component spending the prop
    // somewhere else as well is a component that still has to be given it.
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
    ])

    <div @class(['p-2' => $sidebar])>
    @if($sidebar)
        <x-ui.brand>Laravel</x-ui.brand>
    @else
        <x-ui.brand>Laravel</x-ui.brand>
    @endif
    </div>
    BLADE;

    expect(merge($source))
        ->toContain("'sidebar' => false")
        ->not->toContain('@if(')
        ->toContain('<x-ui.brand>Laravel</x-ui.brand>');
});

it('leaves a conditional whose arms actually differ alone', function (): void {
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
    ])

    @if($sidebar)
        <x-ui.brand class="sidebar">Laravel</x-ui.brand>
    @else
        <x-ui.brand>Laravel</x-ui.brand>
    @endif
    BLADE;

    expect(merge($source))->toBe($source);
});

it('leaves a conditional with another one inside it alone', function (): void {
    // Nesting is refused rather than paired off, and a logo that has grown one
    // is a logo somebody has since made a real decision in.
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
    ])

    @if($sidebar)
        @if($slot->isEmpty())
            <x-ui.brand>Laravel</x-ui.brand>
        @endif
    @else
        @if($slot->isEmpty())
            <x-ui.brand>Laravel</x-ui.brand>
        @endif
    @endif
    BLADE;

    expect(merge($source))->toBe($source);
});

it('leaves the kit alone before the rename has run', function (): void {
    // Flux really does have two brands, so the conditional is doing its job.
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
    ])

    @if($sidebar)
        <flux:sidebar.brand>Laravel</flux:sidebar.brand>
    @else
        <flux:brand>Laravel</flux:brand>
    @endif
    BLADE;

    expect(merge($source))->toBe($source);
});

it('leaves a duplicated conditional with no brand in it alone', function (): void {
    $source = <<<'BLADE'
    @props([
        'sidebar' => false,
    ])

    @if($sidebar)
        <x-ui.heading>Laravel</x-ui.heading>
    @else
        <x-ui.heading>Laravel</x-ui.heading>
    @endif
    BLADE;

    expect(merge($source))->toBe($source);
});

it('leaves markup with no conditional in it untouched', function (): void {
    $source = '<x-ui.brand>Laravel</x-ui.brand>';

    expect(merge($source))->toBe($source);
});
