<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\ImportSheafGlobals;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the action over an entrypoint and a set of globals, and hand back the
 * entrypoint it left behind.
 *
 * @param  array<string, string>  $globals  filename => contents
 */
function importGlobals(string $entrypoint, array $globals, ?Report $report = null): string
{
    $root = sys_get_temp_dir().'/refit-sheaf-globals-'.uniqid();

    mkdir($root.'/resources/js', 0777, true);
    file_put_contents($root.'/'.ImportSheafGlobals::ENTRYPOINT, $entrypoint);

    if ($globals !== []) {
        mkdir($root.'/'.ImportSheafGlobals::GLOBALS, 0777, true);

        foreach ($globals as $name => $contents) {
            file_put_contents($root.'/'.ImportSheafGlobals::GLOBALS.'/'.$name, $contents);
        }
    }

    (new ImportSheafGlobals)->apply(new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report ?? new Report);

    $rewritten = (string) file_get_contents($root.'/'.ImportSheafGlobals::ENTRYPOINT);

    deleteDirectory($root);

    return $rewritten;
}

it('imports the global `sheaf:install` wrote and never wired up', function (): void {
    // The shape a real run leaves behind: `sheaf:init` imported its own theme,
    // and installing the modal dropped modals.js in beside it silently.
    $rewritten = importGlobals(
        "import './globals/theme.js'; /* By Sheaf.dev */\n",
        ['theme.js' => '// theme', 'modals.js' => '// modals'],
    );

    expect($rewritten)->toBe(
        "import './globals/modals.js';\n"
        ."import './globals/theme.js'; /* By Sheaf.dev */\n",
    );
});

it('leaves an entrypoint that already imports everything untouched', function (): void {
    $source = "import './globals/modals.js';\nimport './globals/theme.js';\n";

    expect(importGlobals($source, ['theme.js' => '', 'modals.js' => '']))->toBe($source);
});

it('recognises an import however the path is spelled', function (): void {
    // `sheaf:init` writes an absolute-looking path with a trailing comment, and a
    // user may have moved the line since. Matching the whole line would import
    // theme.js a second time.
    $source = "import '/resources/js/globals/theme.js'; /* By Sheaf.dev */\n";

    expect(importGlobals($source, ['theme.js' => '']))->toBe($source);
});

it('imports the globals ahead of whatever else the entrypoint does', function (): void {
    // A magic has to be registered before the markup reading it is evaluated.
    $rewritten = importGlobals("import './passkeys.js';\n", ['modals.js' => '']);

    expect($rewritten)->toBe("import './globals/modals.js';\nimport './passkeys.js';\n");
});

it('does nothing when no component brought a runtime with it', function (): void {
    $report = new Report;
    $source = "import './app-bootstrap.js';\n";

    expect(importGlobals($source, [], $report))->toBe($source)
        ->and($report->changedFiles())->toBe([]);
});

it('names the globals it imported in the report', function (): void {
    $report = new Report;

    importGlobals('', ['modals.js' => ''], $report);

    expect($report->changedFiles())->toBe([ImportSheafGlobals::ENTRYPOINT])
        ->and(implode(' ', $report->notes()))->toContain('modals.js');
});
