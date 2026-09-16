<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\WireSheafRuntimes;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the action over an entrypoint and a set of runtimes, and hand back the
 * entrypoint it left behind.
 *
 * @param  array<string, string>  $globals  filename => contents
 * @param  array<string, string>  $components  filename => contents
 */
function wireRuntimes(
    string $entrypoint,
    array $globals,
    array $components = [],
    ?Report $report = null,
    bool $primitiveInstalled = true,
): string {
    $root = sys_get_temp_dir().'/refit-sheaf-runtimes-'.uniqid();

    mkdir($root.'/resources/js', 0777, true);
    file_put_contents($root.'/'.WireSheafRuntimes::ENTRYPOINT, $entrypoint);

    file_put_contents($root.'/package.json', (string) json_encode(
        $primitiveInstalled ? ['dependencies' => [WireSheafRuntimes::PRIMITIVE => '^1.0.4']] : ['dependencies' => []],
    ));

    foreach ([WireSheafRuntimes::GLOBALS => $globals, WireSheafRuntimes::RUNTIMES => $components] as $directory => $files) {
        if ($files === []) {
            continue;
        }

        mkdir($root.'/'.$directory, 0777, true);

        foreach ($files as $name => $contents) {
            file_put_contents($root.'/'.$directory.'/'.$name, $contents);
        }
    }

    (new WireSheafRuntimes)->apply(new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report ?? new Report);

    $rewritten = (string) file_get_contents($root.'/'.WireSheafRuntimes::ENTRYPOINT);

    deleteDirectory($root);

    return $rewritten;
}

it('imports the global `sheaf:install` wrote and never wired up', function (): void {
    // The shape a real run leaves behind: `sheaf:init` imported its own theme,
    // and installing the modal dropped modals.js in beside it silently.
    $rewritten = wireRuntimes(
        "import './globals/theme.js'; /* By Sheaf.dev */\n",
        ['theme.js' => '// theme', 'modals.js' => '// modals'],
    );

    expect($rewritten)->toBe(
        "import './globals/modals.js';\n"
        ."import './globals/theme.js'; /* By Sheaf.dev */\n",
    );
});

it('imports the component runtime the same way, ahead of the globals', function (): void {
    // `x-data="selectComponent(…)"` is evaluated as the page is walked, so the
    // Alpine.data() naming it has to have been registered by then.
    $rewritten = wireRuntimes('', [], ['select.js' => "Alpine.data('selectComponent', () => ({}))"]);

    expect($rewritten)->toBe("import './components/select.js';\n");
});

it('registers the plugin the select runtime is written against', function (): void {
    $rewritten = wireRuntimes(
        "import './globals/theme.js';\n",
        ['theme.js' => ''],
        ['select.js' => 'this.$rover.options'],
    );

    // The primitive is imported before the runtime that reads it, and registered
    // in the body — early enough that Alpine has not started walking the page.
    expect($rewritten)->toBe(
        "import rover from '@sheaf/rover';\n"
        ."import './components/select.js';\n"
        ."\n"
        ."Alpine.plugin(rover);\n"
        ."\n"
        ."import './globals/theme.js';\n",
    );
});

it('leaves the plugin alone when no runtime asks for it', function (): void {
    $rewritten = wireRuntimes('', [], ['toast.js' => "Alpine.data('toast', () => ({}))"]);

    expect($rewritten)->not->toContain('rover');
});

it('says what is missing rather than importing a package that is not installed', function (): void {
    $report = new Report;

    $rewritten = wireRuntimes(
        '',
        [],
        ['select.js' => 'this.$rover.options'],
        $report,
        primitiveInstalled: false,
    );

    // The runtime still gets its import — it is the plugin that cannot be
    // registered, and an unresolvable import would fail the whole Vite build.
    expect($rewritten)->toBe("import './components/select.js';\n")
        ->and(implode(' ', $report->warnings()))->toContain('npm install @sheaf/rover');
});

it('leaves an entrypoint that already imports everything untouched', function (): void {
    $source = "import rover from '@sheaf/rover';\n"
        ."import './components/select.js';\n"
        ."\n"
        ."Alpine.plugin(rover);\n"
        ."\n"
        ."import './globals/modals.js';\n";

    expect(wireRuntimes($source, ['modals.js' => ''], ['select.js' => '$rover']))->toBe($source);
});

it('recognises an import however the path is spelled', function (): void {
    // `sheaf:init` writes an absolute-looking path with a trailing comment, and a
    // user may have moved the line since. Matching the whole line would import
    // theme.js a second time.
    $source = "import '/resources/js/globals/theme.js'; /* By Sheaf.dev */\n";

    expect(wireRuntimes($source, ['theme.js' => '']))->toBe($source);
});

it('imports the runtimes ahead of whatever else the entrypoint does', function (): void {
    // A magic has to be registered before the markup reading it is evaluated —
    // and an entrypoint that starts Livewire itself does that on its last line.
    $rewritten = wireRuntimes("import './passkeys.js';\n", ['modals.js' => '']);

    expect($rewritten)->toBe("import './globals/modals.js';\nimport './passkeys.js';\n");
});

it('does nothing when no component brought a runtime with it', function (): void {
    $report = new Report;
    $source = "import './app-bootstrap.js';\n";

    expect(wireRuntimes($source, []))->toBe($source)
        ->and($report->changedFiles())->toBe([]);
});

it('names what it wired up in the report', function (): void {
    $report = new Report;

    wireRuntimes('', ['modals.js' => ''], ['select.js' => '$rover'], $report);

    expect($report->changedFiles())->toBe([WireSheafRuntimes::ENTRYPOINT])
        ->and(implode(' ', $report->notes()))->toContain('globals/modals.js')
        ->and(implode(' ', $report->notes()))->toContain('components/select.js')
        ->and(implode(' ', $report->notes()))->toContain('@sheaf/rover');
});
