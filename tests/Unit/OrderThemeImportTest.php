<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\OrderThemeImport;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * Run the action over a stylesheet, and hand back what it left behind.
 */
function reorder(string $source): string
{
    $root = sys_get_temp_dir().'/refit-theme-import-'.uniqid();

    mkdir($root.'/resources/css', 0777, true);
    file_put_contents($root.'/'.OrderThemeImport::STYLESHEET, $source);

    (new OrderThemeImport)->apply(new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), new Report);

    $rewritten = (string) file_get_contents($root.'/'.OrderThemeImport::STYLESHEET);

    deleteDirectory($root);

    return $rewritten;
}

it('moves Sheaf\'s theme import below Tailwind\'s', function (): void {
    $source = <<<'CSS'
    @import './theme.css'; /* By Sheaf.dev */
    @import 'tailwindcss';
    @import '../../vendor/livewire/flux/dist/flux.css';

    @source '../views';
    CSS;

    expect(reorder($source))->toBe(<<<'CSS'
    @import 'tailwindcss';
    @import './theme.css'; /* By Sheaf.dev */
    @import '../../vendor/livewire/flux/dist/flux.css';

    @source '../views';
    CSS);
});

it('leaves a stylesheet that already imports them in order alone', function (): void {
    $source = <<<'CSS'
    @import 'tailwindcss';
    @import './theme.css'; /* By Sheaf.dev */

    @source '../views';
    CSS;

    expect(reorder($source))->toBe($source)
        ->and(OrderThemeImport::misordered($source))->toBeFalse();
});

it('leaves a stylesheet with no theme import alone', function (): void {
    $source = <<<'CSS'
    @import 'tailwindcss';

    @source '../views';
    CSS;

    expect(reorder($source))->toBe($source)
        ->and(OrderThemeImport::misordered($source))->toBeFalse();
});

it('reads the import wherever sheaf:init put the file', function (): void {
    $source = <<<'CSS'
    @import "../../resources/css/sheaf/theme.css";
    @import "tailwindcss";
    CSS;

    expect(OrderThemeImport::misordered($source))->toBeTrue()
        ->and(reorder($source))->toBe(<<<'CSS'
    @import "tailwindcss";
    @import "../../resources/css/sheaf/theme.css";
    CSS);
});

it('warns rather than failing when there is no stylesheet to order', function (): void {
    $root = sys_get_temp_dir().'/refit-theme-import-'.uniqid();

    mkdir($root, 0777, true);

    $report = new Report;

    (new OrderThemeImport)->apply(new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    ), $report);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->changedFiles())->toBeEmpty();

    deleteDirectory($root);
});
