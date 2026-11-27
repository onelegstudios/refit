<?php

declare(strict_types=1);

use Onelegstudios\Refit\Plan\Actions\OfferDeveloperLogins;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * The shape of the kit's login view, down to the two things the sweep reads: the
 * route the form posts to, and where that form closes.
 */
function loginSource(string $action = "route('login.store')"): string
{
    return implode("\n", [
        '<x-layouts::auth :title="__(\'Log in\')">',
        '    <div class="flex flex-col gap-6">',
        '        <form method="POST" action="{{ '.$action.' }}" class="flex flex-col gap-6">',
        '            @csrf',
        '        </form>',
        '',
        '        {{-- @chisel-registration --}}',
        '    </div>',
        '</x-layouts::auth>',
        '',
    ]);
}

/**
 * @param  list<string>  $links
 * @param  array{string, string}|null  $card  the panel to wrap them in, Flux's when nothing is named
 * @param  array{string, string}|null  $heading  the heading over it, likewise
 */
function offer(
    string $source,
    array $links = ['<x-login-link />'],
    ?Report $report = null,
    ?array $card = null,
    ?array $heading = null,
): string {
    $action = new OfferDeveloperLogins(
        $links,
        $card ?? OfferDeveloperLogins::FLUX_CARD,
        $heading ?? OfferDeveloperLogins::FLUX_HEADING,
    );
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform(
        $source,
        'resources/views/pages/auth/login.blade.php',
        $project,
        $report ?? new Report,
    ))->call($action);
}

it('puts a card of links under the form, indented to sit with it', function (): void {
    $rewritten = offer(loginSource(), [
        '<x-login-link email="test@example.com" label="Test User" />',
        '<x-login-link label="First user" />',
    ]);

    // Including what the kit writes below the form: the "Don't have an account?"
    // footer stays the last thing on the page.
    expect($rewritten)->toContain(implode("\n", [
        '        </form>',
        '',
        "        @env('local')",
        '            <flux:card variant="outline" class="rounded-lg p-4 text-sm text-center">',
        '                <flux:heading size="base" class="mb-2">Log in as</flux:heading>',
        '                <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1.5">',
        '                    <x-login-link email="test@example.com" label="Test User" />',
        '                    <span aria-hidden="true" class="text-zinc-300 dark:text-zinc-600">|</span>',
        '                    <x-login-link label="First user" />',
        '                </div>',
        '            </flux:card>',
        '        @endenv',
        '',
        '        {{-- @chisel-registration --}}',
    ]));
});

it('writes the components it was given, props and all', function (): void {
    $sheaf = offer(
        loginSource(),
        ['<x-login-link label="Test User" />'],
        card: ['x-ui.card', ''],
        heading: ['x-ui.heading', 'size="xs"'],
    );

    // Sheaf's card takes no props worth passing, so the tag closes on its class
    // rather than on a space where Flux's one would have been.
    expect($sheaf)->toContain('<x-ui.card class="rounded-lg p-4 text-sm text-center">')
        ->toContain('</x-ui.card>')
        ->toContain('<x-ui.heading size="xs" class="mb-2">Log in as</x-ui.heading>')
        ->not->toContain('flux:');
});

it('divides the logins, and only between them', function (): void {
    $one = offer(loginSource(), ['<x-login-link label="Test User" />']);
    $three = offer(loginSource(), array_fill(0, 3, '<x-login-link label="Test User" />'));

    expect(substr_count($one, '>|<'))->toBe(0)
        ->and(substr_count($three, '>|<'))->toBe(2);
});

it('heads the card with the verb its logins leave out, as a heading', function (): void {
    // In the component the page's own headings are written with, a step down from
    // the title above the form rather than a small grey label of its own.
    expect(offer(loginSource()))->toContain('<flux:heading size="base" class="mb-2">Log in as</flux:heading>');
});

it('leaves a view that is not the login view alone', function (): void {
    $register = loginSource("route('register.store')");

    expect(offer($register))->toBe($register);
});

it('leaves a login view that already offers a login link alone', function (): void {
    $twice = offer(loginSource());

    expect(offer($twice))->toBe($twice);
});

it('does nothing when no account was chosen', function (): void {
    expect(offer(loginSource(), []))->toBe(loginSource());
});

it('says what it added, and that an account comes into being on first use', function (): void {
    $report = new Report;

    offer(loginSource(), ['<x-login-link />'], $report);

    expect(implode("\n", $report->notes()))
        ->toContain('Added 1 developer login(s) to resources/views/pages/auth/login.blade.php')
        ->toContain('local environment only')
        ->toContain('created the first time its link is used');
});

it('reports a login route with no form rather than guessing where to write', function (): void {
    $report = new Report;
    $formless = "<x-layouts::auth>{{ route('login.store') }}</x-layouts::auth>\n";

    expect(offer($formless, ['<x-login-link />'], $report))->toBe($formless)
        ->and(implode("\n", $report->warnings()))->toContain('has no form to put the developer logins under');
});

it('reports a tree it found no login view in at all', function (): void {
    $action = new OfferDeveloperLogins(['<x-login-link />']);
    $report = new Report;

    (fn () => $this->finish($report))->call($action);

    expect(implode("\n", $report->warnings()))
        ->toContain('Could not find the login view')
        ->toContain('the package is installed');
});

it('counts the links it is about to write in the preview', function (): void {
    expect((new OfferDeveloperLogins(['<x-login-link />']))->describe())
        ->toContain('1 developer login under the form')
        ->and((new OfferDeveloperLogins(['<x-login-link />', '<x-login-link />']))->describe())
        ->toContain('2 developer logins under the form');
});
