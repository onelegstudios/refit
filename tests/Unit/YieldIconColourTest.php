<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\YieldIconColour;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function recolour(string $source, string $path = SheafLibrary::COMPONENT_DIRECTORY.'/icon/index.blade.php'): string
{
    $action = new YieldIconColour;
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

it('writes the default colour to lose to the caller\'s own class', function (): void {
    // Sheaf's own icon component. At full specificity the tie between
    // `dark:text-neutral-300` and a caller's `dark:text-accent-foreground` is
    // settled by the order Tailwind emits them in, and the component wins.
    $source = <<<'BLADE'
        <x-dynamic-component
            :component="$component"
            {{ $attributes->class(['text-neutral-700 dark:text-neutral-300']) }}
            data-slot="icon"
        />
        BLADE;

    expect(recolour($source))
        ->toContain("{{ \$attributes->merge(['class' => '[:where(&)]:text-neutral-700 dark:[:where(&)]:text-neutral-300'], escape: false) }}")
        ->not->toContain('->class(');
});

it('keeps colouring an icon that asks for no colour of its own', function (): void {
    $source = "{{ \$attributes->class(['text-neutral-700 dark:text-neutral-300']) }}";

    // A zero-specificity rule still beats inheritance, so the neutral pair is
    // what an icon with no class of its own goes on being drawn in. Dropping the
    // colours instead would fix the tie and lose that.
    expect(recolour($source))
        ->toContain('text-neutral-700')
        ->toContain('text-neutral-300');
});

it('adds the colour without the escape the dynamic component will apply', function (): void {
    // `class()` escapes what it is handed and `<x-dynamic-component>` escapes it
    // again on the way to the icon set's `<svg {{ $attributes }}>`, so a `&` added
    // through `class()` reaches the browser as `&amp;` and names no rule.
    $source = "{{ \$attributes->class(['text-neutral-700 dark:text-neutral-300']) }}";

    expect(recolour($source))->toContain('escape: false');
});

it('has nothing to do to a Sheaf that has fixed this upstream', function (): void {
    $source = "{{ \$attributes->merge(['class' => '[:where(&)]:text-neutral-700 dark:[:where(&)]:text-neutral-300'], escape: false) }}";

    expect(recolour($source))->toBe($source);
});

it('leaves the kit\'s own views alone', function (): void {
    // The class it matches on is one no application view writes, but the sweep
    // walks every Blade file in the tree and the guarantee is worth stating.
    $source = '<x-ui.icon name="ps:qr-code" class="relative z-20 dark:text-accent-foreground" />';

    expect(recolour($source, 'resources/views/pages/settings/two-factor-setup-modal.blade.php'))->toBe($source);
});
