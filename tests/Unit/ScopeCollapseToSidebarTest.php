<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\FollowSidebarCollapse;
use Onelegstudios\Refit\Plan\Actions\ScopeCollapseToSidebar;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function rescope(string $source, string $path = SheafLibrary::COMPONENT_DIRECTORY.'/navlist/item.blade.php'): string
{
    $action = new ScopeCollapseToSidebar;
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

it('asks the collapse about the element rather than about the page', function (): void {
    // Sheaf's own navlist item: the label a collapsed sidebar takes off its rows.
    // The variant it is written with reaches every navlist in the document, so
    // collapsing the sidebar empties the settings sub-navigation as well.
    $source = '<span class="text-base [:has([data-collapsed]_&)_&]:hidden">{{ $label }}</span>';

    expect(rescope($source))
        ->toBe('<span class="text-base '.FollowSidebarCollapse::SHEAF.'hidden">{{ $label }}</span>');
});

it('re-keys the variant wherever it is stacked or written', function (): void {
    $source = <<<'BLADE'
        <div class="
            [:has([data-collapsed]_&)_&]:items-center
            [:has([data-collapsed]_&)_&]:group-hover:hidden
            [:has([data-collapsed]_&)_&]:[&_[data-slot=brand-name]]:hidden
        ">{{ $slot }}</div>
        BLADE;

    expect(rescope($source))
        ->toContain(FollowSidebarCollapse::SHEAF.'items-center')
        ->toContain(FollowSidebarCollapse::SHEAF.'group-hover:hidden')
        ->toContain(FollowSidebarCollapse::SHEAF.'[&_[data-slot=brand-name]]:hidden')
        ->not->toContain(':has([data-collapsed]');
});

it('leaves the spelling that is on wherever it is written', function (): void {
    // `[:not(:has(…))_&]:` reads as the other half of the same question and is
    // not one: a descendant combinator needs only one ancestor to match, and the
    // sidebar holds no `[data-collapsed]` of its own. Re-keying it would change
    // how a collapsed sidebar looks rather than what it manages to show.
    $source = '<div class="[:not(:has([data-collapsed]_&))_&]:px-4 [:not(:has([data-collapsed]_&))_&]:flex">x</div>';

    expect(rescope($source))->toBe($source);
});

it('has nothing to do to a Sheaf that spells it some other way', function (): void {
    $source = '<span class="text-base '.FollowSidebarCollapse::SHEAF.'hidden">{{ $label }}</span>';

    expect(rescope($source))->toBe($source);
});
