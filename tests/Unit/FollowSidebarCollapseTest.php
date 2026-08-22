<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\FollowSidebarCollapse;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function recollapse(string $source, string $path = 'resources/views/components/⚡team-switcher.blade.php'): string
{
    $action = new FollowSidebarCollapse;
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

it('points the kit\'s collapse rules at the state Sheaf stamps', function (): void {
    $source = '<span class="truncate in-data-flux-sidebar-collapsed-desktop:hidden">{{ $name }}</span>';

    // The selector names the sidebar as well as the collapse: `data-collapsed`
    // sits on the layout, and the header is under it too.
    expect(recollapse($source))
        ->toBe('<span class="truncate [[data-collapsed]_[data-slot=sidebar]_&]:hidden">{{ $name }}</span>');
});

it('gives a trigger that centres itself the box a collapsed nav item stands in', function (): void {
    $source = '<x-ui.button variant="ghost" class="w-full justify-start in-data-flux-sidebar-collapsed-desktop:justify-center" />';

    // 20px of glyph inside 8px of padding is the 36px square the navlist items
    // above it keep — and the width has to go with it, or the hover tint runs
    // the whole 48px of the row while theirs does not.
    expect(recollapse($source))->toContain(
        '[[data-collapsed]_[data-slot=sidebar]_&]:justify-center '
        .'[[data-collapsed]_[data-slot=sidebar]_&]:w-auto '
        .'[[data-collapsed]_[data-slot=sidebar]_&]:h-9 '
        .'[[data-collapsed]_[data-slot=sidebar]_&]:p-2!',
    );
});

it('sizes a glyph the collapse reveals like the nav glyphs it stands under', function (): void {
    $source = '<x-ui.icon name="users" class="hidden size-4 in-data-flux-sidebar-collapsed-desktop:block" />';

    // Drawn at 16px in a row that had a name beside it; on its own in the column
    // it is standing in for a 20px navlist icon. There is no second size to keep
    // — it is hidden at every other width.
    expect(recollapse($source))
        ->toBe('<x-ui.icon name="users" class="hidden size-5 [[data-collapsed]_[data-slot=sidebar]_&]:block" />');
});

it('leaves the glyphs the collapse hides at the size the row drew them', function (): void {
    $source = '<x-ui.icon name="chevron-up-down" class="ms-auto size-4 in-data-flux-sidebar-collapsed-desktop:hidden" />';

    expect(recollapse($source))->toContain('ms-auto size-4 [[data-collapsed]');
});

it('leaves a rule the kit spelled as the opposite alone', function (): void {
    // `not-in-…` is a different rule with a different answer, and swapping the
    // middle out of it would leave neither. It only reaches the header layout,
    // which refit replaces wholesale.
    $source = '<x-ui.button class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />';

    expect(recollapse($source))->toBe($source);
});

it('re-keys a bound class without appending to one', function (): void {
    $bound = '<x-ui.button :class="$collapsed ? \'in-data-flux-sidebar-collapsed-desktop:justify-center\' : \'\'" />';

    // The variant is a class name wherever it is spelled, so it is re-keyed in
    // an expression the same as anywhere else. The box is not: adding to a bound
    // class would mean editing the expression around it rather than a value.
    expect(recollapse($bound))
        ->toContain('\'[[data-collapsed]_[data-slot=sidebar]_&]:justify-center\'')
        ->not->toContain(':w-auto');

    $already = '<x-ui.button class="[[data-collapsed]_[data-slot=sidebar]_&]:justify-center '
        .'[[data-collapsed]_[data-slot=sidebar]_&]:w-auto '
        .'[[data-collapsed]_[data-slot=sidebar]_&]:h-9 '
        .'[[data-collapsed]_[data-slot=sidebar]_&]:p-2! in-data-flux-sidebar-collapsed-desktop:hidden" />';

    expect(substr_count(recollapse($already), ':w-auto'))->toBe(1);
});

it('leaves Sheaf\'s own components to say what a collapse means', function (): void {
    $source = '<span class="in-data-flux-sidebar-collapsed-desktop:hidden">{{ $slot }}</span>';

    expect(recollapse($source, SheafLibrary::COMPONENT_DIRECTORY.'/navlist/item.blade.php'))->toBe($source);
});
