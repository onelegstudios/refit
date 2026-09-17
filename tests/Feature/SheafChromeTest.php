<?php

declare(strict_types=1);

use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Project\ProjectDetector;

it('writes the chrome stubs in Sheaf\'s vocabulary, not Flux\'s', function (): void {
    // The stubs are hand-written Sheaf markup, so they should not be leaning on
    // the mapping table at all — and `current` is the one that reads as fine
    // either way, because Sheaf's URL fallback highlights Dashboard regardless
    // while the expression sits there dead and ships as `current="1"`.
    foreach ((array) glob(__DIR__.'/../../stubs/sheaf/*/*.blade.php.stub') as $stub) {
        expect((string) file_get_contents((string) $stub))->not->toContain(':current');
    }
});
it('keeps the logo tile Sheaf\'s brand would have dropped', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $logo = $project->get('resources/views/components/app-logo.blade.php');

    // Sheaf's brand renders {{ $logo }} and nothing else, so the accent tile has
    // to be an element rather than attributes on the slot. Without it the mark is
    // white on a white sidebar and black on a black one.
    expect($logo)->toContain('<x-slot name="logo">')
        ->toContain('<div class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">')
        ->not->toContain('<x-slot name="logo" class=');

    // And only once, because Sheaf has one brand where Flux had two: the arms of
    // the kit's conditional come out of the rename identical, so the conditional
    // and the prop that drove it go with them.
    expect(substr_count($logo, '<x-ui.brand'))->toBe(1)
        ->and($logo)->not->toContain('@if')
        ->and($logo)->not->toContain('sidebar');

    // Which means nothing may still be passing it, or Sheaf's brand merges the
    // value onto its own <a> as a stray sidebar="1".
    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain(':sidebar');
    }
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('gives the user menu the row a Sheaf nav item would have had', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // Sheaf's navs indent their items by a navlist's gutter of 2. The menu is the
    // last row of the same column, and outside a navlist it runs edge to edge.
    // Sheaf's sidebar is on screen from md, so the menu takes over from the
    // mobile bar there rather than at lg, where Flux's sidebar used to appear.
    expect($project->get('resources/views/layouts/app/sidebar.blade.php'))
        ->toContain("<x-ui.navlist class=\"max-md:hidden\">\n                    <x-desktop-user-menu");

    // And a ghost button is taller, squarer, darker and heavier than a nav item,
    // and hovers neutral where every Sheaf nav hovers on the primary.
    expect($project->get('resources/views/components/desktop-user-menu.blade.php'))
        ->toContain('rounded-box')
        ->toContain('py-1 ps-3! pe-1! font-normal!')
        ->toContain('hover:bg-[--alpha(var(--color-primary)_/5%)]!')
        ->toContain('hover:text-[var(--color-primary)]!')
        // And the chevron ends the row rather than trailing the name, the way the
        // kit's profile had it. Sheaf files a trailing icon under `left-icon`.
        ->toContain('[&>[data-slot=left-icon]]:ms-auto')
        // The panel opens no narrower than the row it belongs to. A minimum
        // rather than a width, so it still grows for a long address.
        ->toContain('<x-slot:menu class="z-[100]! min-w-60">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('stops the mobile bar where Sheaf starts showing the sidebar', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $sidebar = $project->get('resources/views/layouts/app/sidebar.blade.php');

    // Flux stashed its sidebar below lg, so the kit's bar ran to lg with it.
    // Sheaf's sidebar is an overlay below md and a collapsed rail from md up, so
    // the bar and its toggle stop at md — otherwise the rail gets a second
    // toggle and a second menu stacked above it.
    expect($sidebar)
        ->toContain('<x-ui.layout.header class="md:hidden">')
        ->toContain('<x-ui.sidebar.toggle class="md:hidden" />')
        ->not->toContain('lg:hidden');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('empties the user menu to an avatar when the sidebar collapses', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $menu = $project->get('resources/views/components/desktop-user-menu.blade.php');

    // A collapsed sidebar is 64px of icons, and Sheaf empties a navlist item down
    // to one. The trigger goes the same way: the name and the chevron leave, and
    // an avatar of 8 in a padding of 0.5 keeps the 36px square the icons stand in
    // — width included, or the hover tint would run the full width of the row.
    expect($menu)
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:w-auto')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:justify-center')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:p-0.5!')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:[&>[data-slot=left-icon]]:hidden')
        ->toContain('<span class="truncate [[data-collapsed]_[data-slot=sidebar]_&]:hidden">');

    // Sheaf stamps the collapse on the layout, which the header sits under as
    // well, and this is the header's menu too. So every rule names the sidebar,
    // and the header's copy keeps its name at a width where the sidebar is
    // already collapsed underneath it.
    expect($menu)->not->toContain('[[data-collapsed]_&]');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('stops the collapse at the sidebar so the settings menu survives it', function (): void {
    $root = sheafKit('livewire');

    // Sheaf's navlist item, as `sheaf:install` writes it: a label the collapse
    // takes off the row, keyed on a `:has()` with nothing in front of it.
    $item = SheafLibrary::COMPONENT_DIRECTORY.'/navlist/item.blade.php';

    file_put_contents(
        $root.'/'.$item,
        '<a href="{{ $href }}" class="gap-x-2 pl-3 [:has([data-collapsed]_&)_&]:p-2">'
        .'<span class="text-base [:has([data-collapsed]_&)_&]:hidden">{{ $label }}</span></a>'."\n",
    );

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // The settings sub-navigation is a navlist too, out in the main column, and
    // its rows are a label with no icon beside them. Asked of the page rather
    // than of the element, the collapse emptied all three of them.
    expect($project->get($item))
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:hidden')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:p-2')
        ->not->toContain(':has([data-collapsed]');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('composes the header layout the way Sheaf\'s grid reads it', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $header = $project->get('resources/views/layouts/app/header.blade.php');

    // Sheaf's header-sidebar variant keeps a row for the header and a row for the
    // sidebar and main. All three have to be children of the layout to land in
    // them — a header nested inside the main leaves the header row empty, which
    // is a screen-height gap above the page.
    expect(strpos($header, '<x-ui.layout.header'))->toBeLessThan((int) strpos($header, '<x-ui.sidebar '))
        ->and(strpos($header, '<x-ui.sidebar '))->toBeLessThan((int) strpos($header, '<x-ui.layout.main'));

    // The kit centred the bar on a container instead of running it the width of
    // the screen, and measured its gutter from the edge — so the header gives up
    // the padding of 2 it would otherwise keep for itself.
    expect($header)->toContain('<div class="mx-auto flex h-full w-full max-w-7xl items-center px-6 lg:px-8">')
        ->toContain('<x-ui.layout.header class="border-b border-zinc-200 bg-zinc-50 p-0!');

    // And the kit's own main goes, because the stub renders one now.
    expect($project->get('resources/views/layouts/app.blade.php'))
        ->not->toContain('layout.main')
        ->toContain('{{ $slot }}');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('leaves Sheaf\'s sidebar the one surface Sheaf paints', function (string $fixture, string $layout): void {
    $root = migratedSheafKit($fixture);

    $sidebar = (new ProjectDetector)->detect($root)->get($layout);

    // Sheaf's sidebar paints itself twice — the panel, and the sticky brand row
    // above it — and only the panel takes attributes. Restating the kit's tint
    // therefore reaches one of the two, and the row stays white behind the logo.
    expect($sidebar)->not->toContain('bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900')
        // Flux's spellings, which Sheaf takes as `sticky-header` and `collapsable`
        // — left alone they render as literal attributes on the div.
        ->not->toContain('<x-ui.sidebar sticky')
        ->not->toContain('collapsible="mobile"');
})->with([
    'sidebar' => ['livewire', 'resources/views/layouts/app/sidebar.blade.php'],
    'header' => ['livewire', 'resources/views/layouts/app/header.blade.php'],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('gives the page inside Sheaf\'s main the height and padding the kit\'s had', function (string $fixture, string $layout): void {
    $root = migratedSheafKit($fixture);

    $main = (new ProjectDetector)->detect($root)->get($layout);

    // Sheaf's main is a plain block, so a page that sizes itself against it — the
    // kit's dashboard fills the screen — has nothing to measure and collapses to
    // the height of its own borders. The column, and the child that grows into
    // it, are what the kit's `<flux:main>` gave those pages for free.
    // The important is not decoration: Sheaf pads main's children from the main
    // itself, with a selector a plain utility loses to.
    expect($main)->toContain('<x-ui.layout.main class="flex flex-col')
        ->toContain('<div class="flex flex-1 flex-col p-6! lg:p-8!">');
})->with([
    'sidebar' => ['livewire', 'resources/views/layouts/app/sidebar.blade.php'],
    'header' => ['livewire', 'resources/views/layouts/app/header.blade.php'],
])->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('names only icons refit\'s own table knows in the chrome it writes', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // Heroicons the table knows: the ones it can translate, plus the four it
    // translates the kit's vendored Lucide names into.
    $known = array_merge(
        array_keys(IconMap::HEROICONS_TO_LUCIDE),
        array_values(IconMap::LUCIDE_TO_HEROICONS),
    );

    $files = [
        'resources/views/layouts/app/sidebar.blade.php',
        'resources/views/layouts/app/header.blade.php',
        'resources/views/components/desktop-user-menu.blade.php',
    ];

    // The stubs are written rather than renamed, so nothing upstream checks the
    // names in them. A name outside the table is one the Phosphor run prefixes
    // into nothing and the report never mentions — the kit's own `cog` spelled
    // `cog-6-tooth` is a different glyph that fails exactly that quietly.
    $unknown = [];

    foreach ($files as $file) {
        preg_match_all('/\bicon="([^"]+)"/', $project->get($file), $matches);

        foreach ($matches[1] as $name) {
            if (! in_array($name, $known, true)) {
                $unknown[] = $file.': '.$name;
            }
        }
    }

    expect($unknown)->toBe([]);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('keeps the header\'s utility links down to the icons the kit showed', function (): void {
    $root = migratedSheafKit('livewire');

    $header = (new ProjectDetector)->detect($root)->get('resources/views/layouts/app/header.blade.php');

    // The kit's navbar item drew its label from slot content and these three were
    // given none, so they were icons with a tooltip for a name. Sheaf's item draws
    // its label unconditionally, and left alone the bar reads Search Repository
    // Documentation in full.
    foreach (['Search', 'Repository', 'Documentation'] as $name) {
        expect($header)->toContain(':aria-label="__(\''.$name.'\')"')
            ->toContain('<x-ui.tooltip.content>{{ __(\''.$name.'\') }}</x-ui.tooltip.content>');
    }

    // The label is taken out of the row rather than left to render, and only for
    // those three: the Dashboard item beside them showed one in the kit and still
    // renders it rather than answering to a tooltip.
    expect(substr_count($header, '[&>span]:hidden'))->toBe(3)
        ->and($header)->toContain(':label="__(\'Dashboard\')"')
        ->not->toContain(':aria-label="__(\'Dashboard\')"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('sizes the header layout\'s main to the row the grid left it', function (): void {
    $root = migratedSheafKit('livewire');

    // Sheaf's main asks for a screen of height whichever variant it lands in. In
    // the header one the grid already spent a header on the row above it, so a
    // screen is a header too many and the layout clips the overflow — the bottom
    // of every page, unreachable.
    expect((new ProjectDetector)->detect($root)->get('resources/views/layouts/app/header.blade.php'))
        ->toContain('min-h-0! max-h-full!');

    // The sidebar variant gives its main the whole grid, and starts its children
    // rather than stretching them, so there the screen is the right answer.
    expect((new ProjectDetector)->detect($root)->get('resources/views/layouts/app/sidebar.blade.php'))
        ->not->toContain('min-h-0!');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('teleports the user menu out of the sidebar\'s stacking context', function (): void {
    $root = migratedSheafKit('livewire');

    $menu = (new ProjectDetector)->detect($root)->get('resources/views/components/desktop-user-menu.blade.php');

    // Sheaf's sidebar is scrollable by default, and an `overflow-y` of auto makes
    // the `overflow-x: visible` beside it compute to auto too, so the sidebar
    // clips on both axes. This panel grows past the sidebar's 256px for a long
    // address, and in place it came out with its right-hand side sliced off.
    expect($menu)->toContain('<x-ui.dropdown position="bottom-start" portal');

    // But teleporting alone trades one bug for a worse one: at the body the panel
    // is no longer a descendant of the sidebar, and Sheaf's `z-50` panel loses to
    // the inline `z-index:99` the sidebar carries. The whole menu paints behind
    // the sidebar, which reads as a trigger that does nothing at all.
    expect($menu)->toContain('<x-slot:menu class="z-[100]! min-w-60">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('raises the team switcher clear of the sidebar that clips it', function (string $kit): void {
    $root = migratedSheafKit($kit);

    // The switcher is the other dropdown refit puts inside Sheaf's sidebar, and
    // it is a kit file rather than a stub. Measured at 265px against the 256px
    // sidebar, whose `overflow-y: auto` makes the `overflow-x: visible` beside it
    // compute to auto too: the right-hand edge of the menu was cut off.
    expect((new ProjectDetector)->detect($root)->get('resources/views/components/⚡team-switcher.blade.php'))
        ->toContain('<x-ui.dropdown portal position="bottom-start">')
        // And teleporting alone drops it below the sidebar's inline z-index 99.
        ->toContain('<x-slot:menu class="z-[100]! min-w-56">');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('lays the team switcher out the way Flux laid it out', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $switcher = (new ProjectDetector)->detect($root)->get('resources/views/components/⚡team-switcher.blade.php');

    // The trigger holds an icon, the team name and a chevron. Sheaf wraps a
    // button's whole slot in one plain `<span data-text>`, and Tailwind's
    // preflight makes an svg a block — so the three came out as three lines, and
    // the chevron's `ms-auto` had no free space to push against.
    expect($switcher)->toContain('[&>[data-text]]:contents')
        ->toContain('[&>[data-loading=true]:first-child~[data-text]>*]:opacity-0');

    // And the panel's heading. Sheaf's group is `display: contents` and styles
    // `label` alone, so the word arrived in the grid as a bare text node in the
    // first of three columns, with the first team beside it rather than under it.
    expect($switcher)->toContain('<x-ui.dropdown.group :label="__(\'Teams\')" />');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('empties the team switcher to a glyph when the sidebar collapses', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $switcher = (new ProjectDetector)->detect($root)->get('resources/views/components/⚡team-switcher.blade.php');

    // The kit had already dressed this row for a 64px column — a `users` glyph
    // in place of the name, and no chevron. It keyed all of it on the attribute
    // Flux stamps, which no rename can see and Sheaf never sets, so every rule
    // came through the migration inert and the wrong way round: no glyph, a name
    // truncated to nothing, and a chevron with no room for its `ms-auto`.
    expect($switcher)
        ->toContain('<x-ui.icon name="users" class="hidden size-5 [[data-collapsed]_[data-slot=sidebar]_&]:block" />')
        ->toContain('<span class="truncate font-semibold [[data-collapsed]_[data-slot=sidebar]_&]:hidden">')
        ->toContain('class="ms-auto size-4 [[data-collapsed]_[data-slot=sidebar]_&]:hidden"')
        ->not->toContain('in-data-flux-sidebar-collapsed-desktop');

    // And the trigger takes the square a collapsed navlist item stands in, so
    // its hover box is the one the glyphs above it hover rather than the width
    // of the whole row.
    expect($switcher)
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:justify-center')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:w-auto')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:h-9')
        ->toContain('[[data-collapsed]_[data-slot=sidebar]_&]:p-2!');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('puts the team switcher in the gutter the rest of the sidebar sits in', function (string $kit): void {
    $root = migratedSheafKit($kit);

    // Flux padded its sidebar and Sheaf does not — a navlist does, with the
    // `px-2` every other row here already has and the `items-center` that holds
    // a collapsed row in the column.
    expect((new ProjectDetector)->detect($root)->get('resources/views/layouts/app/sidebar.blade.php'))
        ->toContain("<x-ui.navlist>\n                    <livewire:team-switcher />\n                </x-ui.navlist>");
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('leaves the kits without teams without a switcher to raise', function (): void {
    $root = migratedSheafKit('livewire');

    // Nothing to do, and nothing done: the page dropdowns a project writes for
    // itself are not refit's to move around.
    expect((new ProjectDetector)->detect($root)->exists('resources/views/components/⚡team-switcher.blade.php'))->toBeFalse();
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');

it('folds the Sheaf chrome into the layout when the layouts are flattened', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'sheaf', 'icons' => 'heroicons', 'tasks' => ['flatten-layouts', 'single-layout']]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // The stub is what gets folded in, and it already renders the main itself,
    // so the slot lands in it directly rather than in a second main.
    expect($project->exists('resources/views/layouts/app'))->toBeFalse()
        ->and($project->get('resources/views/layouts/app.blade.php'))
        ->toContain('<x-ui.layout')
        ->not->toContain('x-layouts::')
        ->not->toContain('flux:');
});
