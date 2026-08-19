<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\ApplyThemeBeforePaint;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function paintTheme(string $source, ?Report $report = null): string
{
    $action = new ApplyThemeBeforePaint;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, 'resources/views/partials/head.blade.php', $project, $report ?? new Report))
        ->call($action);
}

it('puts the theme in front of the asset tags', function (): void {
    $head = <<<'BLADE'
    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    BLADE;

    $rewritten = paintTheme($head);

    // Inline and synchronous, so it has run before the body is parsed — which is
    // the whole difference between this and Sheaf's own runtime.
    expect($rewritten)->toContain('<script>')
        ->not->toContain('defer')
        ->not->toContain('type="module"');

    // Reading exactly what resources/js/globals/theme.js reads: the `theme` key,
    // defaulting to `system`, resolved against the same media query.
    expect($rewritten)->toContain("localStorage.getItem('theme') ?? 'system'")
        ->toContain("window.matchMedia('(prefers-color-scheme: dark)').matches")
        ->toContain("document.documentElement.classList.toggle('dark', dark)");

    // And again after every wire:navigate. Livewire copies the incoming
    // document's <html> attributes onto the live one, hardcoded `class="dark"`
    // included, and re-runs no head script it already has — so without this the
    // choice lasts until the reader clicks a link.
    expect($rewritten)->toContain("document.addEventListener('livewire:navigated', window.applyStoredTheme)");

    // And ahead of the tags, because @vite is a deferred module either way.
    expect(strpos($rewritten, '<script>'))->toBeLessThan((int) strpos($rewritten, '@vite'));
});

it('tells the browser its own defaults are dark too', function (): void {
    $head = <<<'BLADE'
    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    BLADE;

    $rewritten = paintTheme($head);

    // The other half of what `@fluxAppearance` emitted. Sheaf only ever reads
    // `prefers-color-scheme` to pick a theme; nothing in a migrated project
    // declares `color-scheme`, so the UA keeps its light defaults in dark mode and
    // any text with no colour class of its own renders black on the dark
    // background — in the kit, the recovery-code toggle under the two-factor form.
    expect($rewritten)->toContain('color-scheme: dark;')
        ->toContain(':root.dark {');

    // In the head, ahead of the stylesheet it is correcting for.
    expect(strpos($rewritten, 'color-scheme'))->toBeLessThan((int) strpos($rewritten, '@vite'));
});

it('adds no second colour-scheme rule to a head that has one', function (): void {
    $head = <<<'BLADE'
    @fonts

    @vite(['resources/css/app.css'])
    BLADE;

    $once = paintTheme($head);

    expect(substr_count(paintTheme($once), 'color-scheme: dark;'))->toBe(1);
});

it('sits at the depth the head is written at', function (): void {
    $head = "    <head>\n        @vite(['resources/js/app.js'])\n    </head>";

    expect(paintTheme($head))->toContain("\n        <script>\n")
        ->toContain("\n        </script>\n");
});

it('leaves a component that only wants a script alone', function (): void {
    // The kit writes exactly this in passkey-registration and passkey-verify. A
    // theme script in either runs long after the paint it exists to beat, and
    // sits in the body of a page that already has one in its head.
    $component = "<div>\n    @vite('resources/js/passkeys.js')\n</div>";

    expect(paintTheme($component))->toBe($component);
});

it('adds no second script to a head that already has one', function (): void {
    $head = "<head>\n@vite(['resources/js/app.js'])\n</head>";
    $once = paintTheme($head);

    expect(paintTheme($once))->toBe($once)
        ->and(substr_count($once, '<script>'))->toBe(1);
});

it('leaves a view with no asset tags alone', function (): void {
    $view = '<div>{{ $slot }}</div>';

    expect(paintTheme($view))->toBe($view);
});

it('says so when no head turned up at all', function (): void {
    $report = new Report;

    paintTheme('<div>{{ $slot }}</div>', $report);

    (fn () => $this->finish($report))->call(new ApplyThemeBeforePaint);

    expect($report->warnings())->toHaveCount(1)
        ->and($report->warnings()[0])->toContain('@vite');
});

it('calls the theme again before the body closes', function (): void {
    $layout = <<<'BLADE'
    <html class="dark">
        <head>
            @include('partials.head')
        </head>
        <body>
            {{ $slot }}
        </body>
    </html>
    BLADE;

    // Sheaf's guide asks for this one, and its reason is Livewire: components
    // update independently of each other, and each update is a chance for one
    // that renders its own colours to come back in the state the server assumed.
    expect(paintTheme($layout))->toContain('<script>window.applyStoredTheme?.()</script>')
        ->and(strpos(paintTheme($layout), 'applyStoredTheme?.()'))
        ->toBeLessThan((int) strpos(paintTheme($layout), '</body>'));
});

it('finds the head through the component the partials task leaves', function (): void {
    $layout = "<body>\n    <x-head />\n    {{ \$slot }}\n</body>";

    expect(paintTheme($layout))->toContain('window.applyStoredTheme?.()');
});

it('leaves a document that never reaches the head alone', function (): void {
    // The kit's welcome.blade.php: its own inline styles, no asset tags, no
    // theme, and dark mode answered through prefers-color-scheme alone.
    $welcome = "<html lang=\"en\">\n<head><style>body{}</style></head>\n<body>\n    Hello\n</body>\n</html>";

    expect(paintTheme($welcome))->toBe($welcome);
});

it('adds no second closing call to a layout that has one', function (): void {
    $layout = "<body>\n    <x-head />\n</body>";
    $once = paintTheme($layout);

    expect(paintTheme($once))->toBe($once)
        ->and(substr_count($once, 'applyStoredTheme?.()'))->toBe(1);
});
