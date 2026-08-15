<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\RestructureOverlays;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function restructure(string $source): string
{
    $action = new RestructureOverlays;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, 'resources/views/test.blade.php', $project, new Report))
        ->call($action);
}

it('lifts a dropdown trigger into the slot Sheaf expects', function (): void {
    $source = <<<'BLADE'
    <flux:dropdown position="bottom">
        <flux:button>Open</flux:button>
        <flux:menu>
            <flux:menu.item>Settings</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
    BLADE;

    expect(restructure($source))
        ->toContain('<x-slot:button>')
        ->toContain('</x-slot:button>')
        // The trigger goes in, the menu stays out of it.
        ->toMatch('/<x-slot:button>\s*<flux:button>Open<\/flux:button>\s*<\/x-slot:button>\s*<flux:menu>/');
});

it('leaves a dropdown with no menu alone', function (): void {
    // Nothing to bound the trigger with, so wrapping would be a guess.
    $source = '<flux:dropdown><flux:button>Open</flux:button></flux:dropdown>';

    expect(restructure($source))->toBe($source);
});

it('does not let a nested dropdown steal the outer menu', function (): void {
    $source = '<flux:dropdown><flux:button>a</flux:button><flux:dropdown>b</flux:dropdown><flux:menu>c</flux:menu></flux:dropdown>';

    // The outer dropdown finds another dropdown before any menu, so it declines;
    // the inner one owns the menu that follows it.
    expect(substr_count(restructure($source), '<x-slot:button>'))->toBe(1);
});

it('turns a modal close wrapper into the event Sheaf listens for', function (): void {
    $source = <<<'BLADE'
    <flux:modal.close>
        <flux:button variant="filled">Cancel</flux:button>
    </flux:modal.close>
    BLADE;

    expect(restructure($source))
        ->toContain('x-on:click="$dispatch(\'close-modal\')"')
        ->toContain('class="contents"')
        ->toContain('</div>')
        ->not->toContain('flux:modal.close')
        // The button it wrapped is untouched, and gets renamed by the map pass.
        ->toContain('<flux:button variant="filled">Cancel</flux:button>');
});

it('drops a self-closing close button, which wraps nothing', function (): void {
    expect(restructure('<div><flux:modal.close /></div>'))->toBe('<div></div>');
});

it('leaves markup with neither overlay untouched', function (): void {
    $source = '<flux:button icon="plus">Add</flux:button>';

    expect(restructure($source))->toBe($source);
});
