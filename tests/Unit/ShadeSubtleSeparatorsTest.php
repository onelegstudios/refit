<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\ShadeSubtleSeparators;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

const SUBTLE_SHADE = '[&:empty]:bg-zinc-800/5 dark:[&:empty]:bg-white/10 [&>div:empty]:bg-zinc-800/5 dark:[&>div:empty]:bg-white/10';

function shadeSeparators(string $source, string $path = 'resources/views/partials/settings-heading.blade.php'): string
{
    $action = new ShadeSubtleSeparators;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, new Report))->call($action);
}

it('draws the subtle line Flux drew, in place of the variant Sheaf ignores', function (): void {
    // The settings heading. Sheaf reserves `variant` and paints bg-gray-300.
    expect(shadeSeparators('<x-ui.separator variant="subtle" />'))
        ->toBe('<x-ui.separator class="'.SUBTLE_SHADE.'" />');
});

it('adds to a class the tag already has', function (): void {
    expect(shadeSeparators("<x-ui.separator\n    variant=\"subtle\"\n    class=\"my-6\"\n/>"))
        ->toBe("<x-ui.separator\n    class=\"my-6 ".SUBTLE_SHADE."\"\n/>")
        ->and(shadeSeparators('<x-ui.separator class="" variant="subtle" />'))
        ->toBe('<x-ui.separator class="'.SUBTLE_SHADE.'" />');
});

it('leaves every other separator alone', function (): void {
    $sources = [
        '<x-ui.separator />',
        '<x-ui.separator class="md:hidden" />',
        '<x-ui.separator variant="faint" />',
        '<x-ui.separator :variant="$variant" />',
        '<x-ui.separator variant="subtle" :class="$classes" />',
        '<x-ui.separator variant="subtle" class />',
        '<x-ui.dropdown.separator variant="subtle" />',
    ];

    foreach ($sources as $source) {
        expect(shadeSeparators($source))->toBe($source);
    }
});

it('leaves Sheaf\'s own components alone', function (): void {
    $source = '<x-ui.separator variant="subtle" />';

    expect(shadeSeparators($source, 'resources/views/components/ui/select/index.blade.php'))->toBe($source);
});
