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

it('lifts a tooltip\'s child into the trigger slot, and its content out of the tag', function (): void {
    // The teams list writes exactly this, and Sheaf's tooltip renders
    // `{{ $trigger }}` — so left as a rename the page dies on an undefined
    // variable rather than merely looking wrong.
    $source = <<<'BLADE'
    <flux:tooltip :content="__('Leave team')">
        <flux:button variant="ghost" icon="trash" />
    </flux:tooltip>
    BLADE;

    expect(restructure($source))
        ->toMatch('/<x-slot:trigger>\s*<flux:button variant="ghost" icon="trash" \/>\s*<\/x-slot:trigger>/')
        ->toContain('<flux:tooltip.content>{{ __(\'Leave team\') }}</flux:tooltip.content>')
        // Spent on the child, so it does not ride along onto Sheaf's wrapper div.
        ->not->toContain(':content=');
});

it('writes a literal tooltip content as text rather than an echo', function (): void {
    expect(restructure('<flux:tooltip content="Copy"><flux:button icon="clipboard" /></flux:tooltip>'))
        ->toContain('<flux:tooltip.content>Copy</flux:tooltip.content>')
        ->not->toContain('content="Copy"');
});

it('wraps the trigger of a longhand tooltip and leaves its content where it is', function (): void {
    $source = <<<'BLADE'
    <flux:tooltip position="bottom">
        <flux:navbar.item icon="folder" href="#" />
        <flux:tooltip.content>{{ __('Repository') }}</flux:tooltip.content>
    </flux:tooltip>
    BLADE;

    expect(restructure($source))
        ->toMatch('/<x-slot:trigger>\s*<flux:navbar.item icon="folder" href="#" \/>\s*<\/x-slot:trigger>/')
        // One content child, still the kit's own, and still outside the slot.
        ->toMatch('/<\/x-slot:trigger>\s*<flux:tooltip.content>/')
        ->and(substr_count(restructure($source), 'flux:tooltip.content'))->toBe(2);
});

it('does not give a tooltip written both ways two contents', function (): void {
    $source = '<flux:tooltip content="Copy"><flux:button /><flux:tooltip.content>Copy it</flux:tooltip.content></flux:tooltip>';

    // The child wins, and the attribute still comes off rather than landing on
    // Sheaf's wrapper div.
    expect(substr_count(restructure($source), '<flux:tooltip.content>'))->toBe(1)
        ->and(restructure($source))->toContain('Copy it')
        ->not->toContain('content="Copy"');
});

it('leaves a tooltip with nothing to say alone', function (): void {
    // No content either way, so there is no tooltip text to move and no telling
    // what the body was meant to be.
    $source = '<flux:tooltip><flux:button icon="trash" /></flux:tooltip>';

    expect(restructure($source))->toBe($source);
});

it('turns a modal close wrapper into the call Sheaf closes on', function (): void {
    $source = <<<'BLADE'
    <flux:modal.close>
        <flux:button variant="filled">Cancel</flux:button>
    </flux:modal.close>
    BLADE;

    // Not a bare `$dispatch('close-modal')`: Sheaf's modal listens on the window
    // and closes only when `detail.id` is its own, so an event with no detail is
    // heard and ignored. `$data.close()` is the call its own documentation gives
    // this button, and the close button is inside the scope that holds it.
    expect(restructure($source))
        ->toContain('x-on:click="$data.close()"')
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
