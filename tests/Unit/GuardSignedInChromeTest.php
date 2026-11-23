<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\FluxLibrary;
use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Libraries\Vocabulary;
use Onelegstudios\Refit\Plan\Actions\GuardSignedInChrome;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

/**
 * A project rooted on a throwaway directory holding the given views.
 *
 * The sweep follows a component tag to the file behind it, so a test about that
 * needs the file to be somewhere.
 *
 * @param  array<string, string>  $views  project-relative path mapped to its contents
 */
function guardedProject(array $views = []): Project
{
    $root = sys_get_temp_dir().'/refit-guard-'.bin2hex(random_bytes(6));

    mkdir($root, 0755, true);
    register_shutdown_function(static fn () => deleteDirectory($root));

    foreach ($views as $path => $contents) {
        @mkdir(dirname($root.'/'.$path), 0755, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    return new Project(
        root: $root,
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );
}

/**
 * Run the sweep over one layout.
 */
function guardChrome(
    string $source,
    string $path = 'resources/views/layouts/app/sidebar.blade.php',
    ?Project $project = null,
    ?Vocabulary $vocabulary = null,
    bool $registration = false,
): string {
    $action = new GuardSignedInChrome(
        'app',
        $vocabulary ?? (new FluxLibrary)->vocabulary(),
        $registration,
    );

    return (fn (): string => $this->transform($source, $path, $project ?? guardedProject(), new Report))
        ->call($action);
}

it('guards the chrome that reads the signed-in user', function (): void {
    $source = <<<'BLADE'
    <flux:sidebar>
        <flux:sidebar.nav>
            <flux:sidebar.item :href="route('dashboard')">Dashboard</flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:profile :name="auth()->user()->name" />
    </flux:sidebar>
    BLADE;

    expect(guardChrome($source))->toBe(<<<'BLADE'
    <flux:sidebar>
        <flux:sidebar.nav>
            <flux:sidebar.item :href="route('dashboard')">Dashboard</flux:sidebar.item>
        </flux:sidebar.nav>

        @auth
            <flux:profile :name="auth()->user()->name" />
        @endauth
    </flux:sidebar>
    BLADE);
});

it('grows the guard over everything around it that is the user\'s', function (): void {
    // The profile is the only find in the dropdown, and guarding it on its own
    // would leave a guest looking at an empty menu.
    $source = <<<'BLADE'
    <flux:header>
        <flux:spacer />

        <flux:dropdown position="top">
            <flux:profile :initials="auth()->user()->initials()" />

            <flux:menu>
                <flux:heading>{{ auth()->user()->name }}</flux:heading>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>
    BLADE;

    expect(guardChrome($source))->toBe(<<<'BLADE'
    <flux:header>
        <flux:spacer />

        @auth
            <flux:dropdown position="top">
                <flux:profile :initials="auth()->user()->initials()" />

                <flux:menu>
                    <flux:heading>{{ auth()->user()->name }}</flux:heading>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                    </form>
                </flux:menu>
            </flux:dropdown>
        @else
            <flux:button :href="route('login')">{{ __('Log in') }}</flux:button>
        @endauth
    </flux:header>
    BLADE);
});

it('stops growing where a guest still needs what is beside it', function (): void {
    $source = <<<'BLADE'
    <flux:header>
        <flux:sidebar.toggle />
        <flux:profile :name="auth()->user()->name" />
    </flux:header>
    BLADE;

    expect(guardChrome($source))->toBe(<<<'BLADE'
    <flux:header>
        <flux:sidebar.toggle />
        @auth
            <flux:profile :name="auth()->user()->name" />
        @endauth
    </flux:header>
    BLADE);
});

it('counts whitespace and comments as nothing while it grows', function (): void {
    $source = <<<'BLADE'
    <flux:sidebar>
        <flux:sidebar.nav>
            <flux:sidebar.item :href="route('dashboard')">Dashboard</flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:navlist class="max-md:hidden">
            {{-- The menu sits in a gutter of its own. --}}
            <flux:profile :name="auth()->user()->name" />
        </flux:navlist>
    </flux:sidebar>
    BLADE;

    expect(guardChrome($source))
        ->toContain('    @auth'."\n".'        <flux:navlist class="max-md:hidden">')
        ->toContain('        </flux:navlist>'."\n".'    @endauth');
});

it('follows a component tag to the view that needs the user', function (): void {
    // The layout's own markup says nothing about a user. The component it
    // renders is nothing but one.
    $project = guardedProject([
        'resources/views/components/desktop-user-menu.blade.php' => '<flux:profile :name="auth()->user()->name" />',
    ]);

    $source = <<<'BLADE'
    <flux:header>
        <flux:spacer />
        <x-desktop-user-menu />
    </flux:header>
    BLADE;

    expect(guardChrome($source, project: $project))
        ->toContain('@auth'."\n".'        <x-desktop-user-menu />'."\n".'    @endauth');
});

it('follows a single-file Livewire tag to the component behind it', function (): void {
    $project = guardedProject([
        'resources/views/components/⚡team-switcher.blade.php' => '<?php $team = Auth::user()->currentTeam; ?>',
    ]);

    expect(guardChrome('<livewire:team-switcher />', project: $project))
        ->toBe('@auth'."\n".'<livewire:team-switcher />'."\n".'@endauth');
});

it('follows a class component to the class, where the view says nothing', function (): void {
    $project = guardedProject([
        'resources/views/livewire/team-switcher.blade.php' => '<flux:dropdown>{{ $current }}</flux:dropdown>',
        'app/Livewire/TeamSwitcher.php' => '<?php $team = Auth::user()->currentTeam;',
    ]);

    expect(guardChrome('    <livewire:team-switcher />', project: $project))
        ->toContain('@auth')
        ->toContain('@endauth');
});

it('offers no stand-in for chrome a guest has no counterpart for', function (): void {
    // A team switcher is not a user menu. There is nothing to put in its place.
    $source = '<flux:dropdown>{{ auth()->user()->currentTeam->name }}</flux:dropdown>';

    expect(guardChrome($source))
        ->not->toContain('@else')
        ->not->toContain('route(\'login\')');
});

it('carries the guarded chrome\'s own classes onto the link', function (): void {
    $source = '<flux:profile class="max-lg:hidden ms-auto" :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source))
        ->toContain('<flux:button :href="route(\'login\')" class="max-lg:hidden ms-auto">');
});

it('offers a guest a register link where the kit still registers people', function (): void {
    // Two ways in go in a box of their own. The chrome they replace was one
    // element and its parent laid it out as one — and the kit's `<flux:header>`
    // gives its children no room between them, so two links standing loose in
    // it come out touching. The box asks the parent for nothing.
    $source = '<flux:profile :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source, registration: true))->toContain(<<<'BLADE'
    <div class="flex items-center gap-2">
    <flux:button :href="route('login')" variant="subtle" class="flex-1">{{ __('Log in') }}</flux:button>
    <flux:button :href="route('register')" variant="primary" class="flex-1">{{ __('Register') }}</flux:button>
    </div>
    BLADE);
});

it('offers a guest no register link where the kit has chiselled it out', function (): void {
    // The WorkOS kits hand registration to AuthKit, so there is no route to name.
    $source = '<flux:profile :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source))
        ->toContain('route(\'login\')')
        ->not->toContain('route(\'register\')');
});

it('carries the guarded chrome\'s classes onto the box the ways in sit in', function (): void {
    // The box is what stands where the chrome stood, so the visibility is its.
    // And it says nothing about how it displays: `hidden` beside a bare `flex`
    // is two display utilities in one layer, which the stylesheet would settle.
    $source = '<flux:profile class="hidden lg:block" :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source, registration: true))
        ->toContain('<div class="hidden lg:flex items-center gap-2">')
        // And the links take an equal part of it, so the pair fills the sidebar
        // the way the menu did rather than sitting at the width of its labels.
        ->toContain('<flux:button :href="route(\'login\')" variant="subtle" class="flex-1">');
});

it('gives the box a display where the kit left it one to give', function (): void {
    // Sheaf's rail hides its menu with `max-md:hidden` and leaves every other
    // size to the element's own display. Read as settled, the box comes out
    // with no display at all — and then its `gap-2` has no axis to space and
    // the links inside it no line to share.
    $source = '<x-ui.navlist class="max-md:hidden">'
        .'<flux:profile :name="auth()->user()->name" :href="route(\'logout\')" />'
        .'</x-ui.navlist>';

    expect(guardChrome($source, registration: true))
        ->toContain('<div class="flex max-md:hidden items-center gap-2">');
});

it('leaves a lone way in standing where the chrome stood', function (): void {
    // One link needs no box, and no display either: a button is a flex row
    // already, so the only one written is what a variant is holding up.
    $source = '<flux:profile :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source))
        ->toContain('<flux:button :href="route(\'login\')">{{ __(\'Log in\') }}</flux:button>')
        ->not->toContain('<div');
});

it('leaves the chrome\'s own display behind', function (): void {
    // A block button leaves its own `justify-center` with nothing to centre, and
    // the link needs nothing in its place: what decided the menu's width is the
    // sidebar it is a flex child of, and the link is one too. The variant stays,
    // because the `lg:` beside a `hidden` is what lifts it from `lg` up.
    $source = '<flux:profile class="hidden lg:block" :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source))
        ->toContain('<flux:button :href="route(\'login\')" class="hidden lg:inline-flex">');
});

it('leaves a display standing on its own behind altogether', function (): void {
    $source = '<flux:profile class="inline-block" :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source))
        ->toContain('<flux:button :href="route(\'login\')">{{ __(\'Log in\') }}</flux:button>');
});

it('keeps what the kit wrote beside a display', function (): void {
    $source = '<flux:profile class="block w-full ms-auto" :name="auth()->user()->name" :href="route(\'logout\')" />';

    expect(guardChrome($source))
        ->toContain('<flux:button :href="route(\'login\')" class="w-full ms-auto">');
});

it('asks the library it is ending on for the quieter of the two', function (): void {
    // Writing no variant on the log in link would mean an outline button in Flux
    // and the prominent one in Sheaf, where the pair would then read alike.
    $source = '<x-ui.profile :name="auth()->user()->name" :action="route(\'logout\')" />';

    expect(guardChrome($source, vocabulary: (new SheafLibrary)->vocabulary(), registration: true))
        ->toContain('<x-ui.button :href="route(\'login\')" variant="ghost" class="flex-1">')
        ->toContain('<x-ui.button :href="route(\'register\')" variant="primary" class="flex-1">');
});

it('writes the link in the tag of the library the project is ending on', function (): void {
    $source = '<x-ui.profile :name="auth()->user()->name" :action="route(\'logout\')" />';

    expect(guardChrome($source, vocabulary: (new SheafLibrary)->vocabulary()))
        ->toContain('<x-ui.button :href="route(\'login\')">{{ __(\'Log in\') }}</x-ui.button>');
});

it('leaves a guard somebody has already written', function (): void {
    $source = <<<'BLADE'
    @auth
        <flux:profile :name="auth()->user()->name" />
    @endauth
    BLADE;

    expect(guardChrome($source))->toBe($source);
});

it('leaves the layouts of another family alone', function (): void {
    $source = '<flux:profile :name="auth()->user()->name" />';

    expect(guardChrome($source, 'resources/views/layouts/auth/card.blade.php'))->toBe($source)
        ->and(guardChrome($source, 'resources/views/pages/settings/⚡profile.blade.php'))->toBe($source)
        ->and(guardChrome($source, 'resources/views/components/desktop-user-menu.blade.php'))->toBe($source);
});

it('finds the family wherever refit has left its files', function (): void {
    $source = '    <flux:profile :name="auth()->user()->name" />';

    // The folded layout, and the variants that moved up beside it.
    expect(guardChrome($source, 'resources/views/layouts/app.blade.php'))->toContain('@auth')
        ->and(guardChrome($source, 'resources/views/layouts/app-header.blade.php'))->toContain('@auth');
});
