<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\PromoteContentsToLabel;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function relabel(string $source, string $path = 'resources/views/pages/settings/layout.blade.php', ?Report $report = null): string
{
    $action = new PromoteContentsToLabel;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, $report ?? new Report))
        ->call($action);
}

it('moves a Blade echo onto the tag as a bound label', function (): void {
    $source = <<<'BLADE'
    <x-ui.navlist aria-label="{{ __('Settings') }}">
        <x-ui.navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</x-ui.navlist.item>
    </x-ui.navlist>
    BLADE;

    expect(relabel($source))
        // The translation call is kept where the kit wrote it, bound rather than
        // flattened into a literal.
        ->toContain('<x-ui.navlist.item :href="route(\'profile.edit\')" wire:navigate :label="__(\'Profile\')" />')
        ->not->toContain('</x-ui.navlist.item>');
});

it('moves plain text onto the tag as a literal label', function (): void {
    $source = '<x-ui.navbar.item href="/docs">Documentation</x-ui.navbar.item>';

    expect(relabel($source))->toBe('<x-ui.navbar.item href="/docs" label="Documentation" />');
});

it('labels a radio item the same way', function (): void {
    $source = '<x-ui.radio.item value="light" icon="sun">{{ __(\'Light\') }}</x-ui.radio.item>';

    expect(relabel($source))->toBe('<x-ui.radio.item value="light" icon="sun" :label="__(\'Light\')" />');
});

it('labels a dropdown group, which draws a heading from the prop alone', function (): void {
    $source = '<x-ui.dropdown.group>{{ __(\'Teams\') }}</x-ui.dropdown.group>';

    // Sheaf's group is `display: contents`, so a slot heading is not a heading:
    // it arrives in the panel's grid as a bare text node in the first of three
    // columns, with the first item of the list beside it rather than under it.
    expect(relabel($source))->toBe('<x-ui.dropdown.group :label="__(\'Teams\')" />');
});

it('leaves a group holding items alone, and says nothing about it', function (): void {
    // `flux:menu.radio.group` renames to this tag too, and Sheaf passes its
    // children through exactly as Flux did — so there is nothing missing here.
    $source = <<<'BLADE'
    <x-ui.dropdown.group>
        <x-ui.dropdown.item icon="sun">{{ __('Light') }}</x-ui.dropdown.item>
    </x-ui.dropdown.group>
    BLADE;

    $report = new Report;

    $action = new PromoteContentsToLabel;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $rewritten = (function () use ($source, $project, $report): string {
        $rewritten = $this->transform($source, 'resources/views/menu.blade.php', $project, $report);

        $this->finish($report);

        return $rewritten;
    })->call($action);

    expect($rewritten)->toBe($source)
        ->and($report->warnings())->toBeEmpty();
});

it('leaves an item that already names its label alone', function (): void {
    $source = '<x-ui.navlist.item :label="__(\'Dashboard\')" icon="home" :href="route(\'dashboard\')" />';

    expect(relabel($source))->toBe($source);
});

it('leaves contents richer than a label alone', function (): void {
    // Sheaf's item draws an icon, a label and a badge, and has nowhere to put
    // markup — so this is reported rather than flattened.
    $source = <<<'BLADE'
    <x-ui.navlist.item href="/inbox">
        <span class="font-bold">{{ __('Inbox') }}</span>
        <x-ui.badge>3</x-ui.badge>
    </x-ui.navlist.item>
    BLADE;

    expect(relabel($source))->toBe($source);
});

it('reports the items it could not label', function (): void {
    $source = '<x-ui.navlist.item href="/inbox"><span>{{ __(\'Inbox\') }}</span></x-ui.navlist.item>';
    $report = new Report;

    $action = new PromoteContentsToLabel;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    (function () use ($source, $project, $report): void {
        $this->transform($source, 'resources/views/pages/inbox.blade.php', $project, $report);
        $this->finish($report);
    })->call($action);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0])->toContain('resources/views/pages/inbox.blade.php: <x-ui.navlist.item>');
});

it('leaves an expression carrying a double quote for a person', function (): void {
    $source = '<x-ui.navlist.item href="/x">{{ __("Profile") }}</x-ui.navlist.item>';

    expect(relabel($source))->toBe($source);
});

it('leaves two echoes alone rather than guessing which is the label', function (): void {
    $source = '<x-ui.navlist.item href="/x">{{ $first }} {{ $last }}</x-ui.navlist.item>';

    expect(relabel($source))->toBe($source);
});

it('leaves an empty item alone', function (): void {
    $source = '<x-ui.navlist.item href="/x"></x-ui.navlist.item>';

    expect(relabel($source))->toBe($source);
});

it('leaves Sheaf\'s own components alone', function (): void {
    $source = '<x-ui.navlist.item href="/x">{{ $label }}</x-ui.navlist.item>';

    expect(relabel($source, 'resources/views/components/ui/navlist/index.blade.php'))->toBe($source);
});

it('keeps an opening tag spread over several lines', function (): void {
    $source = <<<'BLADE'
    <x-ui.navlist.item
        icon="folder"
        href="https://github.com/laravel/livewire-starter-kit"
        target="_blank"
    >{{ __('Repository') }}</x-ui.navlist.item>
    BLADE;

    $rewritten = relabel($source);

    expect($rewritten)->toContain('icon="folder"')
        ->toContain('target="_blank"')
        ->toContain(':label="__(\'Repository\')" />')
        ->not->toContain('</x-ui.navlist.item>');
});
