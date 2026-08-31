<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\JoinDropdownPlacement;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function joinPlacement(string $source, ?Report $report = null): string
{
    $action = new JoinDropdownPlacement;
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

it('joins the two attributes into the one value Sheaf reads', function (): void {
    // Flux joins them itself, with a space, on the way to its custom element.
    // Sheaf hands the one value straight to Alpine Anchor as a modifier.
    $source = '<flux:dropdown position="bottom" align="end">';

    expect(joinPlacement($source))->toBe('<flux:dropdown position="bottom-end">');
});

it('takes the align off the tag along with the space in front of it', function (): void {
    // `align` is not a Sheaf prop, so left behind it falls out of
    // `{{ $attributes }}` onto the panel wrapper as a stray, long-deprecated
    // HTML `align` attribute.
    $source = <<<'BLADE'
    <flux:dropdown
        position="top"
        align="start"
        class="w-full"
    >
    BLADE;

    expect(joinPlacement($source))->toBe(<<<'BLADE'
    <flux:dropdown
        position="top-start"
        class="w-full"
    >
    BLADE);
});

it('centres on the bare direction rather than on a -center suffix', function (): void {
    // Sheaf's own default is `bottom-center`, which is not one of Alpine
    // Anchor's placements and resolves to plain `bottom`. So the centre is the
    // direction on its own.
    expect(joinPlacement('<flux:dropdown position="bottom" align="center" />'))
        ->toBe('<flux:dropdown position="bottom" />');
});

it('fills a partial usage from the default Flux would have used', function (): void {
    // The file still says `flux:` at this point, so the half that was left out
    // is the half Flux's own component declares.
    expect(joinPlacement('<flux:dropdown position="left">'))
        ->toBe('<flux:dropdown position="left-start">');

    expect(joinPlacement('<flux:dropdown align="end">'))
        ->toBe('<flux:dropdown position="bottom-end">');
});

it('leaves a tag that asked for no placement at all alone', function (): void {
    expect(joinPlacement('<flux:dropdown class="w-full">'))
        ->toBe('<flux:dropdown class="w-full">');
});

it('leaves a bound placement alone, and names the file that writes one', function (): void {
    $action = new JoinDropdownPlacement;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );
    $report = new Report;

    // An expression is not a placement to join, so refit explains the gap
    // rather than guessing at what it was going to evaluate to.
    $source = '<flux:dropdown :position="$placement" align="end">';

    $rewritten = (function () use ($source, $project, $report): string {
        $rewritten = $this->transform($source, 'resources/views/menu.blade.php', $project, $report);

        $this->finish($report);

        return $rewritten;
    })->call($action);

    expect($rewritten)->toBe($source)
        ->and($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0])
        ->toContain('resources/views/menu.blade.php')
        ->toContain('one hyphenated value');
});

it('has nothing to do to a placement already written Sheaf\'s way', function (): void {
    $source = '<flux:dropdown position="bottom-start">';

    expect(joinPlacement($source))->toBe($source);
});
