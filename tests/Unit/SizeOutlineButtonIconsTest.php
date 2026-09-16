<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\SizeOutlineButtonIcons;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function sizeOutlineIcons(string $source, string $path = 'resources/views/pages/settings/recovery-codes.blade.php'): string
{
    $action = new SizeOutlineButtonIcons;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, new Report))->call($action);
}

it('draws a labelled outline icon at size-4, as Flux did', function (): void {
    // Sheaf draws every md button icon at size-5, and its own class wins a tie.
    expect(sizeOutlineIcons("<x-ui.button\n    icon=\"eye\"\n    iconVariant=\"outline\"\n>\n    {{ __('View recovery codes') }}\n</x-ui.button>"))
        ->toBe("<x-ui.button iconClasses=\"size-4!\"\n    icon=\"eye\"\n    iconVariant=\"outline\"\n>\n    {{ __('View recovery codes') }}\n</x-ui.button>");
});

it('leaves an icon-only button at the size-5 both libraries give it', function (): void {
    $selfClosing = '<x-ui.button size="sm" icon="trash" iconVariant="outline" />';
    $empty = '<x-ui.button icon="trash" iconVariant="outline">  </x-ui.button>';
    $square = '<x-ui.button square icon="trash" iconVariant="outline">Delete</x-ui.button>';

    expect(sizeOutlineIcons($selfClosing))->toBe($selfClosing)
        ->and(sizeOutlineIcons($empty))->toBe($empty)
        ->and(sizeOutlineIcons($square))->toBe($square);
});

it('leaves everything that is not a labelled outline button alone', function (): void {
    $sources = [
        // Sheaf's xs button already draws size-4.
        '<x-ui.button size="xs" icon="eye" iconVariant="outline">View</x-ui.button>',
        '<x-ui.button icon="eye" iconVariant="micro">View</x-ui.button>',
        '<x-ui.button icon="eye" :iconVariant="$weight">View</x-ui.button>',
        '<x-ui.button icon="eye">View</x-ui.button>',
        '<x-ui.button icon="eye" iconVariant="outline" iconClasses="size-6">View</x-ui.button>',
        '<x-ui.dropdown.item icon="eye" iconVariant="outline">View</x-ui.dropdown.item>',
    ];

    foreach ($sources as $source) {
        expect(sizeOutlineIcons($source))->toBe($source);
    }
});

it('leaves Sheaf\'s own components alone', function (): void {
    $source = '<x-ui.button icon="eye" iconVariant="outline">View</x-ui.button>';

    expect(sizeOutlineIcons($source, 'resources/views/components/ui/select/index.blade.php'))->toBe($source);
});
