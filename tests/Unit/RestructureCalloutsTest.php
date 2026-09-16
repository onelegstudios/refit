<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\RestructureCallouts;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function expandCallouts(string $source): string
{
    $action = new RestructureCallouts;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, 'resources/views/test.blade.php', $project, new Report))
        ->call($action);
}

it('writes a callout\'s heading out as the child Sheaf draws it from', function (): void {
    // Every kit variant writes exactly this line, and it is the one that says
    // why a two-factor code was rejected. Sheaf's alert declares no `heading`
    // prop, so a rename alone leaves an empty red box.
    $source = '<flux:callout variant="danger" icon="x-circle" heading="{{ $message }}"/>';

    expect(expandCallouts($source))
        ->toContain('<flux:callout.heading>{{ $message }}</flux:callout.heading>')
        // Spent on the child, so it does not ride along onto Sheaf's wrapper div.
        ->not->toContain('heading="')
        // The rest of the tag is untouched, and it is no longer self-closing.
        ->toContain('<flux:callout variant="danger" icon="x-circle">')
        ->toContain('</flux:callout>');
});

it('keeps a bound heading an expression', function (): void {
    expect(expandCallouts('<flux:callout :heading="__(\'Heads up\')" />'))
        ->toContain('<flux:callout.heading>{{ __(\'Heads up\') }}</flux:callout.heading>');
});

it('writes both shorthands in the order Flux renders them', function (): void {
    expect(expandCallouts('<flux:callout heading="Title" text="Body" />'))
        ->toMatch('/<flux:callout.heading>Title<\/flux:callout.heading>\s*<flux:callout.text>Body<\/flux:callout.text>/');
});

it('indents the children under the callout they came off', function (): void {
    $source = <<<'BLADE'
        <div>
            <flux:callout heading="Title" />
        </div>
        BLADE;

    expect(expandCallouts($source))->toContain(
        "    <flux:callout>\n        <flux:callout.heading>Title</flux:callout.heading>\n    </flux:callout>",
    );
});

it('adds the child above the ones a callout already writes', function (): void {
    $source = '<flux:callout heading="Title"><flux:callout.text>Existing</flux:callout.text></flux:callout>';

    expect(expandCallouts($source))
        ->toMatch('/<flux:callout.heading>Title<\/flux:callout.heading>\s*<flux:callout.text>Existing<\/flux:callout.text>/')
        // Already had a body, so it is not reopened around one.
        ->and(substr_count(expandCallouts($source), '</flux:callout>'))->toBe(1);
});

it('leaves a callout with nothing to move alone', function (): void {
    $source = '<flux:callout variant="danger" icon="x-circle">Body</flux:callout>';

    expect(expandCallouts($source))->toBe($source);
});

it('leaves a boolean heading alone, having no text to move', function (): void {
    $source = '<flux:callout heading />';

    expect(expandCallouts($source))->toBe($source);
});

it('expands every callout in a file rather than only the first', function (): void {
    $source = "<flux:callout heading=\"One\" />\n<flux:callout heading=\"Two\" />\n";

    expect(expandCallouts($source))
        ->toContain('<flux:callout.heading>One</flux:callout.heading>')
        ->toContain('<flux:callout.heading>Two</flux:callout.heading>');
});
