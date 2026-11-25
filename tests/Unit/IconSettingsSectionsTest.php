<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\IconSettingsSections;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * The sweep as the task builds it, with a table small enough to read in the
 * assertions.
 *
 * @param  array<string, string>  $icons
 */
function settingsSweep(
    array $icons = [
        'profile' => 'user',
        'appearance' => 'swatch',
        'update password' => 'key',
        'passkeys' => 'finger-print',
    ],
    ?Vocabulary $vocabulary = null,
): IconSettingsSections {
    return new IconSettingsSections($vocabulary ?? (new FluxLibrary)->vocabulary(), $icons);
}

function iconSettings(string $source, ?IconSettingsSections $action = null, ?Report $report = null): string
{
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $path = 'resources/views/pages/settings/layout.blade.php';

    return (fn (): string => $this->transform($source, $path, $project, $report ?? new Report))
        ->call($action ?? settingsSweep());
}

it('gives a menu row the icon its label asks for', function (): void {
    expect(iconSettings('<flux:navlist.item :href="route(\'profile.edit\')" wire:navigate>{{ __(\'Profile\') }}</flux:navlist.item>'))
        ->toBe('<flux:navlist.item icon="user" :href="route(\'profile.edit\')" wire:navigate>{{ __(\'Profile\') }}</flux:navlist.item>');
});

it('reads a row\'s label from the attribute the Sheaf migration moves it to', function (): void {
    $sheaf = settingsSweep(vocabulary: (new SheafLibrary)->vocabulary());

    expect(iconSettings('<x-ui.navlist.item :href="route(\'profile.edit\')" :label="__(\'Profile\')" />', $sheaf))
        ->toBe('<x-ui.navlist.item icon="user" :href="route(\'profile.edit\')" :label="__(\'Profile\')" />')
        ->and(iconSettings('<x-ui.navlist.item label="Appearance" />', $sheaf))
        ->toContain('icon="swatch"');
});

it('matches a label whatever its case and however the tag wraps it', function (): void {
    expect(iconSettings("<flux:navlist.item\n    wire:navigate\n>\n    {{ __('PROFILE') }}\n</flux:navlist.item>"))
        ->toContain('<flux:navlist.item icon="user"');
});

it('leaves a row that already named an icon alone', function (): void {
    $leading = '<flux:navlist.item icon="identification">{{ __(\'Profile\') }}</flux:navlist.item>';
    $bound = '<flux:navlist.item :icon="$icon">{{ __(\'Profile\') }}</flux:navlist.item>';

    expect(iconSettings($leading))->toBe($leading)
        ->and(iconSettings($bound))->toBe($bound);
});

it('is not fooled by the navlist group, which is a heading rather than a row', function (): void {
    $group = '<flux:navlist.group heading="Profile">{{ __(\'Profile\') }}</flux:navlist.group>';

    expect(iconSettings($group))->toBe($group);
});

it('names the icon on a call to the settings layout, under any of its names', function (): void {
    foreach (['x-pages::settings.layout', 'x-settings.layout'] as $tag) {
        expect(iconSettings(sprintf('<%s :heading="__(\'Update password\')">body</%s>', $tag, $tag)))
            ->toBe(sprintf('<%s icon="key" :heading="__(\'Update password\')">body</%s>', $tag, $tag));
    }
});

it('leaves a layout call that was already given an icon alone', function (): void {
    $named = '<x-settings.layout icon="cog" :heading="__(\'Profile\')">body</x-settings.layout>';

    expect(iconSettings($named))->toBe($named);
});

it('leaves a heading it cannot read as a section label alone', function (): void {
    $bound = '<x-settings.layout :heading="$section->name">body</x-settings.layout>';
    $arguments = '<x-settings.layout :heading="__(\':team settings\', [\'team\' => $team])">body</x-settings.layout>';

    expect(iconSettings($bound))->toBe($bound)
        ->and(iconSettings($arguments))->toBe($arguments);
});

it('puts an icon the page names inside the layout\'s own heading', function (): void {
    expect(iconSettings("    <flux:heading>{{ \$heading ?? '' }}</flux:heading>"))
        ->toBe(implode("\n", [
            '    <flux:heading class="flex items-center gap-2">',
            '        @isset($icon)',
            '            <flux:icon :name="$icon" class="size-4" />',
            '        @endisset',
            '',
            '        {{ $heading ?? \'\' }}',
            '    </flux:heading>',
        ]));
});

it('leaves the gap under a heading to the heading, by staying out of its way', function (): void {
    // Flux draws that gap with `[&:has(+[data-flux-subheading])]:mb-2`, which
    // only fires while the subheading is the heading's next sibling. A wrapper
    // around the heading would put a div between the two and take the gap with
    // it.
    $source = "<flux:heading>{{ \$heading ?? '' }}</flux:heading>\n<flux:subheading>{{ \$subheading ?? '' }}</flux:subheading>";

    expect(iconSettings($source))
        ->toContain('</flux:heading>'."\n".'<flux:subheading>');
});

it('keeps a class the heading already carries', function (): void {
    $source = '<x-settings.layout :heading="__(\'Teams\')"><flux:heading class="mt-6">{{ __(\'Passkeys\') }}</flux:heading></x-settings.layout>';

    expect(iconSettings($source))->toContain('<flux:heading class="mt-6 flex items-center gap-2">');
});

it('leaves a heading whose class is bound alone', function (): void {
    $source = '<x-settings.layout :heading="__(\'Teams\')"><flux:heading :class="$classes">{{ __(\'Passkeys\') }}</flux:heading></x-settings.layout>';

    expect(iconSettings($source))->toContain('<flux:heading :class="$classes">{{ __(\'Passkeys\') }}</flux:heading>');
});

it('leaves the headings that name a section of their own alone', function (): void {
    $own = '<flux:heading>{{ __(\'Passkeys\') }}</flux:heading>';
    $title = '<flux:heading size="xl" level="1">{{ __(\'Settings\') }}</flux:heading>';

    expect(iconSettings($own))->toBe($own)
        ->and(iconSettings($title))->toBe($title);
});

it('leaves a layout that is already open to an icon alone', function (): void {
    $open = implode("\n", [
        '<flux:heading class="flex items-center gap-2">',
        '    @isset($icon)',
        '        <flux:icon :name="$icon" class="size-4" />',
        '    @endisset',
        '',
        '    {{ $heading ?? \'\' }}',
        '</flux:heading>',
    ]);

    expect(iconSettings($open))->toBe($open);
});

it('gives a section written inside the layout the icon its heading asks for', function (): void {
    $source = implode("\n", [
        '<x-settings.layout :heading="__(\'Update password\')">',
        '    <section class="mt-12">',
        '        <flux:heading>{{ __(\'Passkeys\') }}</flux:heading>',
        '    </section>',
        '</x-settings.layout>',
    ]);

    expect(iconSettings($source))->toContain(implode("\n", [
        '        <flux:heading class="flex items-center gap-2">',
        '            <flux:icon name="finger-print" class="size-4" />',
        '            {{ __(\'Passkeys\') }}',
        '        </flux:heading>',
    ]));
});

it('leaves the headings a page writes outside the layout alone', function (): void {
    // The screen-reader heading above the slot, and a modal title below it: the
    // same words as a section, and neither of them one.
    $source = implode("\n", [
        '<flux:heading level="2" class="sr-only">{{ __(\'Passkeys\') }}</flux:heading>',
        '<x-settings.layout :heading="__(\'Update password\')">body</x-settings.layout>',
        '<flux:heading size="lg">{{ __(\'Passkeys\') }}</flux:heading>',
    ]);

    expect(iconSettings($source))
        ->toContain('<flux:heading level="2" class="sr-only">{{ __(\'Passkeys\') }}</flux:heading>'."\n")
        ->toContain("\n".'<flux:heading size="lg">{{ __(\'Passkeys\') }}</flux:heading>')
        ->not->toContain('flux:icon');
});

it('leaves a section heading that already has an icon in front of it alone', function (): void {
    $drawn = '<x-settings.layout :heading="__(\'Teams\')"><flux:icon.key /><flux:heading>{{ __(\'Passkeys\') }}</flux:heading></x-settings.layout>';
    $named = '<x-settings.layout :heading="__(\'Teams\')"><flux:icon name="key" /><flux:heading>{{ __(\'Passkeys\') }}</flux:heading></x-settings.layout>';

    expect(iconSettings($drawn))->not->toContain('class="flex items-center gap-2"')
        ->and(iconSettings($named))->not->toContain('class="flex items-center gap-2"');
});

it('leaves a section heading it has no icon for alone, and says nothing about it', function (): void {
    $report = new Report;
    $source = '<x-settings.layout :heading="__(\'Teams\')"><flux:heading>{{ __(\'Team members\') }}</flux:heading></x-settings.layout>';

    // A heading is matched where the table knows it and passed over where it does
    // not: the sweep reads every heading in the slot, so a note here would be a
    // list of the application's own headings rather than of its settings sections.
    expect(iconSettings($source, settingsSweep(), $report))->toBe($source)
        ->and($report->notes())->toBeEmpty();
});

it('reads the icons it would write without writing them', function (): void {
    $source = "<flux:navlist.item>{{ __('Profile') }}</flux:navlist.item>\n"
        ."<flux:navlist.item>{{ __('Billing') }}</flux:navlist.item>\n"
        ."<x-settings.layout :heading=\"__('Profile')\">body</x-settings.layout>\n"
        ."<x-settings.layout :heading=\"__('Update password')\">"
        ."<flux:heading>{{ __('Passkeys') }}</flux:heading>"
        .'</x-settings.layout>';

    expect(settingsSweep()->names($source))->toBe(['user', 'key', 'finger-print']);
});

it('says which sections it had no icon for, in one note at the end of the sweep', function (): void {
    $report = new Report;
    $action = settingsSweep(['profile' => 'user']);

    foreach (['Billing', 'Notifications', 'billing', 'Profile'] as $label) {
        iconSettings(sprintf('<flux:navlist.item>{{ __(\'%s\') }}</flux:navlist.item>', $label), $action, $report);
    }

    // The sweep collects as it goes and reports once the tree is walked, so one
    // label said twice is one finding rather than two.
    expect($report->notes())->toBeEmpty();

    (function () use ($report): void {
        $this->finish($report);
    })->call($action);

    expect($report->notes())->toHaveCount(1)
        ->and($report->notes()[0])
        ->toContain('"Billing", "Notifications"')
        ->toContain('refit has no icon to match them');
});
