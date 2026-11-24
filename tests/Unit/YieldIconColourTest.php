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

it('takes the colour off the icon component', function (): void {
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
        ->toContain('{{ $attributes }}')
        ->not->toContain('text-neutral-700')
        ->not->toContain('text-neutral-300')
        ->not->toContain('->class(');
});

it('leaves an icon that names no colour to inherit the one around it', function (): void {
    // Which is what the glyph in a control needs. A button colours its own label
    // and hands its icon nothing but a size, so nothing a caller writes can
    // reach it — a zero-specificity default would still beat the inheritance,
    // and a `danger` button would keep its neutral glyph beside a red label.
    $source = "{{ \$attributes->class(['text-neutral-700 dark:text-neutral-300']) }}";

    expect(recolour($source))->toBe('{{ $attributes }}');
});

it('keeps the bag the caller passed its classes in', function (): void {
    // The colour is Sheaf's own; everything in `$attributes` is the view's, and
    // a class it named still lands on the icon set's `<svg {{ $attributes }}>`.
    $source = '<x-dynamic-component :component="$component" {{ $attributes->class([\'text-neutral-700 dark:text-neutral-300\']) }} data-slot="icon" />';

    expect(recolour($source))
        ->toBe('<x-dynamic-component :component="$component" {{ $attributes }} data-slot="icon" />');
});

it('has nothing to do to a Sheaf that has fixed this upstream', function (): void {
    $source = '{{ $attributes }}';

    expect(recolour($source))->toBe($source);
});

it('leaves the kit\'s own views alone', function (): void {
    // The class it matches on is one no application view writes, but the sweep
    // walks every Blade file in the tree and the guarantee is worth stating.
    $source = '<x-ui.icon name="ps:qr-code" class="relative z-20 dark:text-accent-foreground" />';

    expect(recolour($source, 'resources/views/pages/settings/two-factor-setup-modal.blade.php'))->toBe($source);
});
