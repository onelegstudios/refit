<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\ShapeBadgePills;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function shapePills(string $source, ?Report $report = null): string
{
    $action = new ShapeBadgePills;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform(
        $source,
        'resources/views/pages/teams/edit.blade.php',
        $project,
        $report ?? new Report,
    ))->call($action);
}

it('unwrites the alias the way Flux\'s own badge does', function (): void {
    // `if ($variant === 'pill') { $rounded = true; $variant = null; }` — the
    // same two moves, made in the markup instead of in the props.
    expect(shapePills('<flux:badge variant="pill">Owner</flux:badge>'))
        ->toBe('<flux:badge rounded>Owner</flux:badge>');
});

it('leaves the variant a badge actually asked for', function (): void {
    // `solid` is a variant rather than an alias, and the value table translates
    // it. Anything else Sheaf sends through to the solid branch, which is
    // visibly wrong rather than silently missing.
    expect(shapePills('<flux:badge variant="solid">Live</flux:badge>'))
        ->toBe('<flux:badge variant="solid">Live</flux:badge>')
        ->and(shapePills('<flux:badge variant="subtle">Live</flux:badge>'))
        ->toBe('<flux:badge variant="subtle">Live</flux:badge>');
});

it('leaves a badge that already says rounded alone', function (): void {
    // Flux's current spelling needs nothing done to it — `ComponentMap` renames
    // it to Sheaf's `pill` with the rest of the attributes.
    expect(shapePills('<flux:badge rounded>Owner</flux:badge>'))
        ->toBe('<flux:badge rounded>Owner</flux:badge>');
});

it('drops the alias rather than saying the shape twice', function (): void {
    // Both spellings on one tag is one shape stated two ways, so the older one
    // goes and the current one carries it.
    expect(shapePills('<flux:badge rounded variant="pill">Owner</flux:badge>'))
        ->toBe('<flux:badge rounded>Owner</flux:badge>');
});

it('takes the alias off a multi-line tag without leaving the line behind', function (): void {
    $source = <<<'BLADE'
    <flux:badge
        rounded
        variant="pill"
        class="ms-2"
    >Owner</flux:badge>
    BLADE;

    expect(shapePills($source))->toBe(<<<'BLADE'
    <flux:badge
        rounded
        class="ms-2"
    >Owner</flux:badge>
    BLADE);
});

it('passes over a variant written as an expression', function (): void {
    // A bound variant holds an expression rather than a word, so there is no
    // alias to recognise.
    expect(shapePills('<flux:badge :variant="$variant">Owner</flux:badge>'))
        ->toBe('<flux:badge :variant="$variant">Owner</flux:badge>');
});

it('leaves every other tag\'s variant alone', function (): void {
    // Only the badge spells a shape as a variant. A button's `pill` is not a
    // thing Flux declares, but the sweep may not be the one to decide that.
    expect(shapePills('<flux:button variant="pill">Save</flux:button>'))
        ->toBe('<flux:button variant="pill">Save</flux:button>');
});
