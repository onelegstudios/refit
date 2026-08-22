<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\RestoreButtonRow;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function rowUp(string $source, string $path = 'resources/views/components/⚡team-switcher.blade.php'): string
{
    $action = new RestoreButtonRow;
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

it('takes the slot wrapper out of the layout when the button holds markup', function (): void {
    $source = <<<'BLADE'
    <x-ui.button variant="ghost" class="w-full justify-start">
        <x-ui.icon name="users" class="size-4" />
        <span class="truncate font-semibold">{{ $team }}</span>
        <x-ui.icon name="chevron-up-down" class="ms-auto size-4" />
    </x-ui.button>
    BLADE;

    // Sheaf's `<span data-text>` is the only flex item the button has, and
    // Tailwind's preflight makes an svg a block — so these three come out as
    // three lines, with the chevron's `ms-auto` pushing against nothing.
    expect(rowUp($source))
        ->toContain('class="w-full justify-start [&>[data-text]]:contents')
        // And the dim Sheaf puts on that span while a button loads has to move
        // down a level with it, or it lands on a box that no longer exists.
        ->toContain('[&>[data-loading=true]:first-child~[data-text]>*]:opacity-0"');
});

it('gives a button with no class of its own one to say it in', function (): void {
    $source = '<x-ui.button wire:click="save"><x-ui.icon name="check" /> Save</x-ui.button>';

    expect(rowUp($source))->toContain('<x-ui.button class="[&>[data-text]]:contents');
});

it('leaves a button whose slot is only text alone', function (): void {
    $source = '<x-ui.button variant="primary" type="submit">{{ __(\'Log in\') }}</x-ui.button>';

    expect(rowUp($source))->toBe($source);
});

it('leaves an icon-only button alone', function (): void {
    // Nothing in the slot to stack: Sheaf draws this one from the prop.
    $source = '<x-ui.button icon="cog" variant="ghost" />';

    expect(rowUp($source))->toBe($source);
});

it('leaves a button it has already rowed up alone', function (): void {
    $source = <<<'BLADE'
    <x-ui.button class="w-full [&>[data-text]]:contents [&>[data-loading=true]:first-child~[data-text]>*]:opacity-0">
        <x-ui.icon name="users" />
        <span>{{ $team }}</span>
    </x-ui.button>
    BLADE;

    expect(rowUp($source))->toBe($source);
});

it('leaves a bound class alone', function (): void {
    // An expression is a decision the application made in PHP, and appending to
    // it would mean editing that decision.
    $source = '<x-ui.button :class="$classes"><x-ui.icon name="users" /> {{ $team }}</x-ui.button>';

    expect(rowUp($source))->toBe($source);
});

it('leaves Sheaf\'s own button alone', function (): void {
    $source = '<x-ui.button variant="ghost"><x-ui.icon name="users" /> {{ $team }}</x-ui.button>';

    expect(rowUp($source, 'resources/views/components/ui/button/index.blade.php'))->toBe($source);
});

it('rows up every button in a file, and only the buttons', function (): void {
    $source = <<<'BLADE'
    <x-ui.button.abstract as="div">
        <x-ui.icon name="users" />
    </x-ui.button.abstract>

    <x-ui.button variant="ghost">
        <x-ui.icon name="users" />
        <span>{{ $one }}</span>
    </x-ui.button>

    <x-ui.button variant="ghost">
        <x-ui.icon name="check" />
        <span>{{ $two }}</span>
    </x-ui.button>
    BLADE;

    expect(substr_count(rowUp($source), '[&>[data-text]]:contents'))->toBe(2)
        ->and(rowUp($source))->toContain('<x-ui.button.abstract as="div">');
});
