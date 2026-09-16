<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\PlaceDropdownChildren;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function place(string $source): string
{
    $action = new PlaceDropdownChildren;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, 'resources/views/menu.blade.php', $project, new Report))
        ->call($action);
}

it('spans the block of markup a menu opens with', function (): void {
    $source = <<<'BLADE'
    <x-slot:menu>
        <div class="flex items-center gap-2">
            <x-ui.avatar :name="$name" />
        </div>
    </x-slot:menu>
    BLADE;

    // Without it the block sits in the first of three grid columns.
    expect(place($source))->toContain('<div class="flex items-center gap-2 col-span-full">');
});

it('lets a wrapper fall through to the item it wraps', function (): void {
    $source = <<<'BLADE'
    <x-slot:menu>
        <form method="POST" action="/logout">
            <x-ui.dropdown.item as="button" type="submit">Log out</x-ui.dropdown.item>
        </form>
    </x-slot:menu>
    BLADE;

    // `contents` makes the item the grid child, so it lines up with its
    // neighbours instead of being boxed into one column by the form.
    expect(place($source))->toContain('<form class="contents" method="POST" action="/logout">');
});

it('leaves the parts that place themselves alone', function (): void {
    $source = <<<'BLADE'
    <x-slot:menu>
        <x-ui.dropdown.item href="/settings">Settings</x-ui.dropdown.item>
        <x-ui.dropdown.separator />
    </x-slot:menu>
    BLADE;

    expect(place($source))->toBe($source);
});

it('leaves a child that already says where it sits alone', function (): void {
    $source = '<x-slot:menu><div class="col-span-full">Hi</div></x-slot:menu>';

    expect(place($source))->toBe($source);
});

it('places only the outermost markup, not everything nested in it', function (): void {
    $source = <<<'BLADE'
    <x-slot:menu>
        <div class="grid">
            <div class="inner">
                <span>Ava</span>
            </div>
        </div>
    </x-slot:menu>
    BLADE;

    $placed = place($source);

    expect(substr_count($placed, 'col-span-full'))->toBe(1)
        ->and($placed)->toContain('<div class="grid col-span-full">')
        ->and($placed)->toContain('<div class="inner">');
});

it('wraps a component, whose own element it cannot reach', function (): void {
    $source = <<<'BLADE'
    <x-slot:menu>
        <x-ui.modal.trigger name="create-team">
            <x-ui.dropdown.item icon="plus">New team</x-ui.dropdown.item>
        </x-ui.modal.trigger>
    </x-slot:menu>
    BLADE;

    // Sheaf renders the trigger's outer element, so no class written on the tag
    // would land on the div that ends up in the grid.
    expect(place($source))->toContain(<<<'BLADE'
        <div class="col-span-full">
        <x-ui.modal.trigger name="create-team">
    BLADE)
        ->toContain("</x-ui.modal.trigger>\n    </div>");
});

it('leaves markup with no menu in it untouched', function (): void {
    $source = '<div class="flex"><x-ui.button>Save</x-ui.button></div>';

    expect(place($source))->toBe($source);
});

it('reads the long slot spelling too', function (): void {
    $source = '<x-slot name="menu"><div class="flex">Ava</div></x-slot>';

    expect(place($source))->toContain('<div class="flex col-span-full">');
});
