<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Project\ProjectDetector;

/**
 * Point the application at a throwaway starter kit, minus the chisel script the
 * command refuses to run alongside.
 */
function useKit(string $kit): string
{
    $root = copyFixture($kit);

    @unlink($root.'/chisel.php');

    app()->setBasePath($root);

    return $root;
}

it('refuses to run before install:features has settled the tree', function (): void {
    $root = copyFixture('livewire');

    app()->setBasePath($root);

    $this->artisan('refit', ['--force' => true])
        ->expectsOutputToContain('install:features')
        ->assertFailed();
});

it('refuses to run outside a clean git working tree', function (): void {
    useKit('livewire');

    $this->artisan('refit')
        ->expectsOutputToContain('git')
        ->assertFailed();
});

it('shows a plan and changes nothing on a dry run', function (): void {
    $root = useKit('livewire');
    $before = (string) file_get_contents($root.'/resources/css/app.css');

    $this->artisan('refit', [
        '--force' => true,
        '--dry-run' => true,
        '--answers' => json_encode(['icons' => 'heroicons', 'tasks' => ['remove-flux-pro-source']]),
    ])
        ->expectsOutputToContain('Refit will make')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertSuccessful();

    expect(file_get_contents($root.'/resources/css/app.css'))->toBe($before)
        ->and(is_dir($root.'/resources/views/flux/icon'))->toBeTrue();
});

it('applies the answers it is given without prompting', function (): void {
    $root = useKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['icons' => 'lucide', 'tasks' => ['remove-flux-pro-source', 'partials-to-components']]),
    ])->assertSuccessful();

    expect(file_get_contents($root.'/resources/css/app.css'))->not->toContain('flux-pro')
        ->and(is_file($root.'/resources/views/components/head.blade.php'))->toBeTrue()
        ->and(is_file($root.'/resources/views/flux/icon/house.blade.php'))->toBeTrue();
});

it('reports a plan with nothing in it rather than pretending to work', function (): void {
    useKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['icons' => 'keep', 'tasks' => []]),
    ])
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});

it('rejects malformed answers instead of guessing', function (): void {
    useKit('livewire');

    $this->artisan('refit', ['--force' => true, '--answers' => '{not-json}'])
        ->expectsOutputToContain('Could not read --answers')
        ->assertFailed();
});

it('deletes the published config when it is asked to remove itself', function (): void {
    $root = useKit('livewire');

    copy(dirname(__DIR__, 2).'/config/refit.php', $root.'/config/refit.php');

    $this->artisan('refit', ['--force' => true])
        ->expectsQuestion('Which component library should this project end up on?', 'flux')
        ->expectsQuestion('The kit ships Heroicons with a few Lucide icons vendored in. What would you like?', 'keep')
        ->expectsQuestion('Which tasks would you like to run?', ['remove-flux-pro-source'])
        ->expectsQuestion('Apply these changes?', true)
        ->expectsQuestion('Remove refit from this project now?', true)
        ->expectsOutputToContain('Deleted config/refit.php.')
        ->assertSuccessful();

    expect(is_file($root.'/config/refit.php'))->toBeFalse();
});

it('leaves the published config alone when refit is staying', function (): void {
    $root = useKit('livewire');

    copy(dirname(__DIR__, 2).'/config/refit.php', $root.'/config/refit.php');

    $this->artisan('refit', ['--force' => true])
        ->expectsQuestion('Which component library should this project end up on?', 'flux')
        ->expectsQuestion('The kit ships Heroicons with a few Lucide icons vendored in. What would you like?', 'keep')
        ->expectsQuestion('Which tasks would you like to run?', ['remove-flux-pro-source'])
        ->expectsQuestion('Apply these changes?', true)
        ->expectsQuestion('Remove refit from this project now?', false)
        ->assertSuccessful();

    expect(is_file($root.'/config/refit.php'))->toBeTrue();
});

it('leaves every view structurally intact after a full run', function (string $kit): void {
    $root = useKit($kit);

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'icons' => 'lucide',
            'tasks' => ['partials-to-components', 'namespace-components', 'toasts-at-top', 'single-layout', 'remove-flux-pro-source'],
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);
    $dangling = [];

    foreach ($project->blades() as $path) {
        $source = $project->get($path);

        // Every anonymous component reference must resolve to a file that is
        // actually on disk after all the moving around.
        foreach (componentReferences($source) as $reference) {
            if (! $project->exists('resources/views/components/'.$reference.'.blade.php')) {
                $dangling[] = $path.' -> <x-'.str_replace('/', '.', $reference).'>';
            }
        }
    }

    expect($dangling)->toBe([]);
})->with(starterKits());

it('groups promoted partials along with everything else', function (): void {
    // Both structure tasks at once used to leave components/ half namespaced,
    // because the folder was read while the plan was built rather than run.
    $root = useKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'icons' => 'keep',
            'tasks' => ['partials-to-components', 'namespace-components'],
        ]),
    ])->assertSuccessful();

    expect(glob($root.'/resources/views/components/*.blade.php'))->toBe([])
        ->and(is_file($root.'/resources/views/components/layout/head.blade.php'))->toBeTrue()
        ->and(is_file($root.'/resources/views/components/settings/heading.blade.php'))->toBeTrue()
        ->and(is_file($root.'/resources/views/components/brand/logo.blade.php'))->toBeTrue()
        ->and(file_get_contents($root.'/resources/views/layouts/app/sidebar.blade.php'))
        ->toContain('<x-layout.head />');
});

/**
 * Anonymous component references in a template, as component paths.
 *
 * Blade's own `x-slot`, and the `x-namespace::view` form Livewire uses for its
 * page and layout views, are resolved differently and are not components here.
 *
 * @return list<string>
 */
function componentReferences(string $source): array
{
    $references = [];

    foreach ((new TagParser)->parse($source, 'x-') as $tag) {
        $name = substr($tag->name, 2);

        if ($name === 'slot' || str_contains($name, '::')) {
            continue;
        }

        $references[] = str_replace('.', '/', $name);
    }

    return array_values(array_unique($references));
}
