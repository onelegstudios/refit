<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\SizeNavItemIcons;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function resize(string $source, string $path = SheafLibrary::COMPONENT_DIRECTORY.'/navlist/item.blade.php'): string
{
    $action = new SizeNavItemIcons;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, new Report))
        ->call($action);
}

it('adds the size without the escape the icon component will apply', function (): void {
    // Sheaf's own navlist item. `class()` escapes what it is handed, and
    // `x-ui.icon` passes the bag back out through `<x-dynamic-component>`, which
    // escapes it a second time — so the `&` reaches the DOM as `&amp;` and the
    // rule Tailwind wrote for this class matches nothing.
    $source = <<<'BLADE'
        <x-ui.icon
            :attributes="$iconAttributes->class('[:where(&)]:size-5')"
            :name="$icon"
        />
        BLADE;

    expect(resize($source))
        ->toContain(":attributes=\"\$iconAttributes->merge(['class' => '[:where(&)]:size-5'], escape: false)\"")
        ->not->toContain('->class(');
});

it('keeps the zero-specificity variant that lets icon:class win', function (): void {
    $source = '<x-ui.icon :attributes="$iconAttributes->class(\'[:where(&)]:size-5\')" :name="$icon" />';

    // The point of `:where()` is that a caller's own `icon:class="size-4"` beats
    // the default without `!`. Swapping in a plain `size-5` would fix the escaping
    // and take that with it.
    expect(resize($source))->toContain('[:where(&)]:size-5');
});

it('sizes the navbar item the same way it sizes the navlist item', function (): void {
    $source = '<x-ui.icon :attributes="$iconAttributes->class(\'[:where(&)]:size-5\')" :name="$icon" />';
    $path = SheafLibrary::COMPONENT_DIRECTORY.'/navbar/item.blade.php';

    expect(resize($source, $path))->toContain('escape: false');
});

it('leaves a bag that is passed through without a class alone', function (): void {
    // The button and the dropdown item hand their icon a bag too, and size it with
    // a plain utility that survives being escaped twice. Nothing to do to those.
    $source = '<x-ui.icon :name="$icon" :variant="$iconVariant" :attributes="$iconAttributes" />';

    expect(resize($source))->toBe($source);
});

it('has nothing to do to a Sheaf that has fixed this upstream', function (): void {
    $source = '<x-ui.icon :attributes="$iconAttributes->merge([\'class\' => \'[:where(&)]:size-5\'], escape: false)" />';

    expect(resize($source))->toBe($source);
});
