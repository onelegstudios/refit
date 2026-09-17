<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Icons\IconMap;
use Onelegstudios\Refit\Plan\Actions\OrderThemeImport;
use Onelegstudios\Refit\Project\ProjectDetector;

it('leaves no Flux tag behind anywhere in the tree', function (string $kit): void {
    $root = migratedSheafKit($kit);

    expect(fluxTagsUnder($root))->toBe([]);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);
it('gives the kit\'s toasts something that is listening for them', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);
    $raised = [];

    foreach ([...$project->blades(), ...$project->livewireClasses()] as $path) {
        $source = $project->get($path);

        // The container is only half of it. A Flux::toast() left behind still
        // runs and still succeeds — it just dispatches an event that nothing on
        // the page answers any more, which is a form that saves in silence.
        expect($source)->not->toContain('Flux::toast')
            ->not->toContain('use Flux\\Flux;');

        if (str_contains($source, "dispatch('notify'")) {
            $raised[] = $path;
        }
    }

    expect($raised)->not->toBeEmpty();

    // And the thing they are dispatched at is in the layout.
    $layouts = array_values(array_filter(
        $project->blades(),
        fn (string $path): bool => str_starts_with($path, 'resources/views/layouts/'),
    ));

    expect(array_filter($layouts, fn (string $path): bool => str_contains($project->get($path), '<x-ui.toast')))
        ->not->toBeEmpty();
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);
it('only ever produces components Sheaf actually ships', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $known = sheafComponents();
    $project = (new ProjectDetector)->detect($root);
    $parser = new TagParser;
    $unknown = [];

    foreach ($project->blades() as $path) {
        foreach ($parser->parse($project->get($path), 'x-ui.') as $tag) {
            $name = substr($tag->name, strlen('x-ui.'));

            if (! in_array($name, $known, true)) {
                $unknown[$name] = true;
            }
        }
    }

    expect(array_keys($unknown))->toBe([]);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);
it('takes the Flux directives and overrides out with it', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    expect($project->exists('resources/views/flux'))->toBeFalse()
        ->and($project->get('resources/css/app.css'))->not->toContain('livewire/flux')
        ->and($project->get('resources/views/partials/head.blade.php'))->not->toContain('@fluxAppearance');

    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain('@fluxScripts');
    }
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('points the kit\'s vendored Lucide names back at Heroicons', function (): void {
    $root = migratedSheafKit('livewire');

    $names = array_keys(sheafScanner()->scan((new ProjectDetector)->detect($root)));

    // The four the kit vendors as Flux overrides have no artwork once Flux is
    // gone, so they have to name something Heroicons has.
    expect($names)->not->toContain('folder-git-2')
        ->and($names)->not->toContain('book-open-text')
        ->and($names)->not->toContain('chevrons-up-down')
        ->and($names)->not->toContain('layout-grid');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('translates and prefixes every icon name when Phosphor is asked for', function (): void {
    $root = migratedSheafKit('livewire', 'phosphor');

    $names = array_keys(sheafScanner()->scan((new ProjectDetector)->detect($root)));

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect($name)->toStartWith('ps:');

        // The prefix is only half of it. A name Phosphor spells differently has
        // to be spelled its way too, or the component behind it does not exist
        // and the page 500s instead of losing an icon.
        expect(IconMap::HEROICONS_TO_PHOSPHOR)
            ->toContain(substr($name, strlen('ps:')));
    }

    // The three the kit writes that Phosphor spells differently, and the one it
    // spells the same, so a table that quietly emptied itself would be caught.
    expect($names)->toContain('ps:fingerprint')
        ->toContain('ps:house')
        ->toContain('ps:gear')
        ->toContain('ps:eye-slash');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('settles light or dark before the first paint', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $head = $project->get('resources/views/partials/head.blade.php');

    // Flux's @fluxAppearance did this synchronously in the head, and its teardown
    // takes it away. Sheaf's replacement registers on `alpine:init` and arrives
    // through a deferred module, so without a pre-paint script the hardcoded
    // `dark` class is what the reader sees first and the correction is what they
    // see next — the whole page snapping to light on every load.
    expect($head)->not->toContain('@fluxAppearance')
        ->toContain("localStorage.getItem('theme') ?? 'system'")
        ->toContain("document.documentElement.classList.toggle('dark', dark)")
        // And once more per navigation: wire:navigate writes the incoming
        // document's <html> attributes onto the live one, so the hardcoded
        // `class="dark"` comes back on every link the reader clicks, and Livewire
        // re-runs no head script the page already has.
        ->toContain("document.addEventListener('livewire:navigated', window.applyStoredTheme)");

    // Ahead of the tags: @vite is a module either way, so the only thing that can
    // beat the paint is an inline script above it.
    expect(strpos($head, '<script>'))->toBeLessThan((int) strpos($head, '@vite'));

    // One script, in the one file every layout includes.
    $scripts = array_filter(
        $project->blades(),
        fn (string $path): bool => str_contains($project->get($path), 'classList.toggle(\'dark\''),
    );

    expect(array_values($scripts))->toBe(['resources/views/partials/head.blade.php']);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('follows the head when a task turns it into a component', function (): void {
    $root = sheafKit('livewire');

    // The partials task moves partials/head.blade.php to components/head.blade.php
    // in the move stage. This runs in the reconcile stage, over the settled tree,
    // so it finds the head there rather than writing to a path that has gone.
    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
            'tasks' => ['partials-to-components'],
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->exists('resources/views/partials/head.blade.php'))->toBeFalse()
        ->and($project->get('resources/views/components/head.blade.php'))
        ->toContain("localStorage.getItem('theme') ?? 'system'");
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('keeps the browser\'s own defaults dark once Flux stops declaring it', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // `@fluxAppearance` emitted a script and a stylesheet, and the teardown takes
    // both. Sheaf reads `prefers-color-scheme` to choose a theme but never
    // declares `color-scheme`, so without this the UA keeps its light defaults in
    // dark mode: black text wherever nothing has coloured it.
    expect($project->get('resources/views/partials/head.blade.php'))
        ->toContain(':root.dark {')
        ->toContain('color-scheme: dark;')
        // The directive itself is gone — the only mention left is the comment
        // explaining what took its place.
        ->not->toMatch('/^\s*@fluxAppearance/m');

    // The kit's own components colour themselves, so what this rescues is the
    // plain markup between them — here, the toggle under the two-factor form,
    // which carries opacity and an underline and no colour at all.
    expect($project->get('resources/views/pages/auth/two-factor-challenge.blade.php'))
        ->toContain('login using a recovery code');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('keeps Tailwind\'s import ahead of Sheaf\'s so the theme stays layered', function (): void {
    $root = migratedSheafKit('livewire');

    $stylesheet = (new ProjectDetector)->detect($root)->get(OrderThemeImport::STYLESHEET);
    $lines = preg_split('/\R/', $stylesheet) ?: [];

    $tailwind = array_search("@import 'tailwindcss';", $lines, true);
    $theme = array_search("@import './theme.css'; /* By Sheaf.dev */", $lines, true);

    // Above Tailwind's import, Sheaf's pushes the `:root` block holding every
    // theme variable out of `@layer theme`, and an unlayered declaration beats the
    // `.dark` overrides that flip the accent and primary colours. The kit's logo
    // is the visible half of that: a `dark:text-black` mark on a tile that never
    // turns white.
    expect($tailwind)->toBeInt()
        ->and($theme)->toBeInt()
        ->and($theme)->toBeGreaterThan($tailwind);
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('drives the appearance control with Sheaf\'s theme runtime, not Flux\'s magic', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $appearance = $project->get('resources/views/pages/settings/⚡appearance.blade.php');

    // The segmented control was bound to `$flux.appearance`, which is gone with
    // the package: the buttons rendered, moved, and changed nothing.
    expect($appearance)->not->toContain('$flux')
        ->toContain('x-model="$theme.storedTheme"')
        // Reading `storedTheme` selects the right button; only `setTheme()`
        // persists the choice and puts `.dark` on the document.
        ->toContain('x-on:change="$theme.setTheme($event.target.value)"');

    // The QR code inverts itself in dark mode by asking what the appearance
    // currently resolves to, which is one property on Sheaf's side.
    expect($project->get('resources/views/pages/settings/⚡two-factor-setup-modal.blade.php'))
        ->not->toContain('$flux')
        ->toContain('$theme.storedTheme')
        ->toContain('$theme.isResolvedToDark');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('leaves no Flux Alpine magic anywhere in the tree', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);
    $found = [];

    foreach ($project->blades() as $path) {
        if (str_contains($project->get($path), '$flux')) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
})->with(['livewire', 'livewire-class-components', 'livewire-teams', 'livewire-workos', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('refuses to guess at a hand-written write to Flux\'s appearance', function (): void {
    $root = sheafKit('livewire');

    file_put_contents(
        $root.'/resources/views/dashboard.blade.php',
        '<div x-data><button x-on:click="$flux.appearance = \'dark\'">Dark</button></div>'."\n",
    );

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode([
            'library' => 'sheaf',
            'icons' => 'heroicons',
        ]),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    // A write is not a rename: assigning to `$theme.storedTheme` moves the
    // reactive value and persists nothing, so refit says so rather than
    // producing a button that looks right and does half the job.
    expect($project->get('resources/views/dashboard.blade.php'))
        ->toContain('$flux.appearance = \'dark\'')
        ->and($project->get('REFIT-NOTES.md'))
        ->toContain('resources/views/dashboard.blade.php')
        ->toContain('$theme.setTheme(value)');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('takes Flux out on the way to Sheaf without being asked to', function (): void {
    $root = sheafKit('livewire');

    // No `tasks` in the answers at all: the teardown belongs to the library being
    // left, so choosing to leave it is the whole instruction.
    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'sheaf', 'icons' => 'heroicons']),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->get('resources/views/partials/head.blade.php'))
        ->not->toContain('@fluxAppearance')
        ->not->toContain('@fluxScripts')
        ->and($project->get('resources/css/app.css'))->not->toContain('livewire/flux')
        // The override directory only ever existed to intercept Flux's own
        // resolution, so it goes with the icons that were in it.
        ->and($project->exists('resources/views/flux'))->toBeFalse();

    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain('@fluxScripts');
    }

    // And nothing to report about any of it. The chrome files the layout stubs
    // overwrite never carried a directive by the time the sweep ran, so the run
    // must not claim it went looking in them and came back empty-handed.
    expect($project->get('REFIT-NOTES.md'))
        ->not->toContain('@fluxAppearance')
        ->not->toContain('Nothing in');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('leaves Flux alone when Flux is where the project is staying', function (): void {
    $root = sheafKit('livewire');

    $this->artisan('refit', [
        '--force' => true,
        '--answers' => json_encode(['library' => 'flux', 'icons' => 'keep']),
    ])->assertSuccessful();

    $project = (new ProjectDetector)->detect($root);

    expect($project->get('resources/views/partials/head.blade.php'))->toContain('@fluxAppearance')
        ->and($project->exists('resources/views/flux'))->toBeTrue();
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
