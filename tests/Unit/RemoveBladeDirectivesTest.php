<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\RemoveBladeDirectives;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * The sweep's transform, on one file's worth of source.
 */
function stripDirectives(string $source, string ...$directives): string
{
    $action = new RemoveBladeDirectives($directives === [] ? ['@fluxAppearance', '@fluxScripts'] : $directives);

    $transform = (new ReflectionClass($action))->getMethod('transform');

    return $transform->invoke(
        $action,
        $source,
        'resources/views/test.blade.php',
        new Project(sys_get_temp_dir(), ComponentStyle::SingleFile, [], [], false),
        new Report,
    );
}

it('takes the whole line when the directive is all that is on it', function (): void {
    $source = <<<'BLADE'
        <head>
            @fluxAppearance
        </head>
        BLADE;

    expect(stripDirectives($source))->toBe(<<<'BLADE'
        <head>
        </head>
        BLADE);
});

it('keeps the markup a directive was sharing a line with', function (): void {
    expect(stripDirectives('<body>@fluxScripts</body>'))->toBe('<body></body>');
});

it('leaves the blank lines that were already there', function (): void {
    $source = "<head>\n\n    @fluxScripts\n\n</head>";

    expect(stripDirectives($source))->toBe("<head>\n\n\n</head>");
});

it('will not match a longer directive that merely starts the same way', function (): void {
    expect(stripDirectives('@fluxScriptsOfMyOwn'))->toBe('@fluxScriptsOfMyOwn');
});

it('leaves a file that names neither directive exactly as it was', function (): void {
    $source = "<x-ui.button>Save</x-ui.button>\n";

    expect(stripDirectives($source))->toBe($source);
});
