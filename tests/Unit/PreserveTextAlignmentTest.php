<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\PreserveTextAlignment;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function realign(string $source, string $path = 'resources/views/test.blade.php'): string
{
    $action = new PreserveTextAlignment;
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

it('restates the centring the auth header used to inherit', function (): void {
    $source = <<<'BLADE'
    <div class="flex w-full flex-col text-center">
        <x-ui.heading size="xl">{{ $title }}</x-ui.heading>
        <x-ui.text>{{ $description }}</x-ui.text>
    </div>
    BLADE;

    expect(realign($source))
        // With the `!`, because Tailwind emits `.text-start` after `.text-center`
        // and Sheaf's heading writes `text-start` into its own class list.
        ->toContain('<x-ui.heading class="text-center!" size="xl">')
        ->toContain('<x-ui.text class="text-center!">');
});

it('merges the alignment into classes the tag already has', function (): void {
    $source = '<div class="text-center"><x-ui.text class="mt-1">Add a passkey</x-ui.text></div>';

    expect(realign($source))->toContain('class="mt-1 text-center!"');
});

it('leaves a tag that already aligns itself alone', function (): void {
    $source = '<div class="text-center"><x-ui.text class="font-medium text-center">Sent</x-ui.text></div>';

    expect(realign($source))->toBe($source);
});

it('leaves bound classes alone rather than editing PHP', function (): void {
    $source = '<div class="text-center"><x-ui.heading :class="$classes">Hi</x-ui.heading></div>';

    expect(realign($source))->toBe($source);
});

it('reads the nearest wrapper, not the outermost', function (): void {
    $source = <<<'BLADE'
    <div class="text-center">
        <div class="text-left">
            <x-ui.heading>Left</x-ui.heading>
        </div>
        <x-ui.heading>Centre</x-ui.heading>
    </div>
    BLADE;

    // The inner wrapper puts the first heading back where Sheaf already starts
    // its text, so only the second one has anything to restate.
    expect(substr_count(realign($source), 'text-center!'))->toBe(1)
        ->and(realign($source))->toContain('<x-ui.heading>Left</x-ui.heading>');
});

it('leaves text nothing centres alone', function (): void {
    $source = '<div class="flex flex-col"><x-ui.heading>Profile</x-ui.heading></div>';

    expect(realign($source))->toBe($source);
});

it('ignores an alignment that only holds at one breakpoint', function (): void {
    // `md:text-center` is inherited at every other size, so copying it onto the
    // tag would centre text the view deliberately left alone.
    $source = '<div class="md:text-center"><x-ui.text>Hi</x-ui.text></div>';

    expect(realign($source))->toBe($source);
});

it('does not touch Sheaf\'s own components', function (): void {
    $source = '<div class="text-center"><x-ui.text>Hi</x-ui.text></div>';
    $path = SheafLibrary::COMPONENT_DIRECTORY.'/alerts/index.blade.php';

    expect(realign($source, $path))->toBe($source);
});
