<?php

declare(strict_types=1);

use Onelegstudios\Refit\Blade\TagParser;
use Onelegstudios\Refit\Project\ProjectDetector;

it('sizes every modal panel through the width Sheaf reads', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);
    $parser = new TagParser;
    $sized = 0;

    foreach ($project->blades() as $path) {
        foreach ($parser->parse($project->get($path), 'x-ui.modal') as $tag) {
            if ($tag->name !== 'x-ui.modal') {
                continue;
            }

            // Sheaf puts `class` on a wrapper the panel is teleported out of, so
            // a max-width left there sizes nothing.
            expect((string) $tag->attribute('class')?->value)->not->toContain('max-w-');

            $sized += $tag->has('width') ? 1 : 0;
        }
    }

    expect($sized)->toBeGreaterThan(0);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);
it('carries the kit\'s outline button icons across at Flux\'s size', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);
    $parser = new TagParser;
    $outlined = [];

    foreach ($project->blades() as $path) {
        // Sheaf's button never reads the Flux spelling.
        expect($project->get($path))->not->toContain('icon:variant');

        foreach ($parser->parse($project->get($path), 'x-ui.button') as $tag) {
            if ($tag->attribute('iconVariant')?->value === 'outline') {
                $outlined[] = $tag->attribute('iconClasses')?->value;
            }
        }
    }

    // WorkOS handles two-factor and passkeys itself, so those kits write none.
    if (str_contains($kit, 'workos')) {
        expect($outlined)->toBe([]);

        return;
    }

    // The two labelled recovery-codes buttons shrink to size-4; the icon-only
    // passkey delete keeps Sheaf's size-5, as it had Flux's.
    expect($outlined)->toContain('size-4!')->toContain(null);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);
it('draws the settings heading\'s separator as faintly as Flux did', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);
    $shaded = 0;

    foreach ($project->blades() as $path) {
        $source = $project->get($path);

        // Sheaf reserves the variant and draws the line at full strength.
        expect($source)->not->toMatch('/<x-ui\.separator[^>]*variant="subtle"/');

        $shaded += substr_count($source, '[&>div:empty]:bg-zinc-800/5');
    }

    expect($shaded)->toBeGreaterThan(0);
})->with(starterKits())->skip(
    fn (): bool => ! is_dir(fixturePath('livewire')),
    'Run `composer fixtures`.',
);
it('keeps the document outline the kit wrote', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // Sheaf matches the level against h1..h6 and silently renders an <h2> for
    // anything else, so an untranslated `level="1"` leaves the settings pages
    // with no <h1> at all and nothing on the page to say so.
    expect($project->get('resources/views/partials/settings-heading.blade.php'))
        ->toContain('level="h1"')
        ->not->toContain('level="1"')
        ->and($project->get('resources/views/pages/settings/two-factor/⚡recovery-codes.blade.php'))
        ->toContain('level="h3"')
        ->not->toContain('level="3"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('gives every heading the size it was already rendering at', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // Both libraries spell the sizes with the same words and put them at
    // different points on the scale, so `lg` is `text-base` in Flux and
    // `text-xl` in Sheaf — two steps up on a rename alone.
    expect($project->get('resources/views/pages/settings/⚡delete-user-modal.blade.php'))
        ->toContain('size="sm"')
        ->not->toContain('size="lg"')
        // The one that survives, and only by coincidence: `xl` is `text-2xl` in
        // both.
        ->and($project->get('resources/views/partials/settings-heading.blade.php'))
        ->toContain('<x-ui.heading size="xl"')
        // And the case a value table cannot reach, because there is no value in
        // the file to translate: the two defaults disagree too, so a heading
        // that named no size grew from 14px to 16px. Flux's default is written
        // out ahead of the rename and translated with the rest.
        ->and($project->get('resources/views/pages/settings/⚡delete-user-form.blade.php'))
        ->toContain('<x-ui.heading size="xs"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('keeps a badge the quiet chip Flux drew', function (): void {
    $root = migratedSheafKit('livewire-teams');

    $project = (new ProjectDetector)->detect($root);

    // Flux's badge defaults to a translucent tinted chip and Sheaf's to a solid
    // one, so a rename alone turns the role labels in the members table from
    // grey pills into white-on-near-black — the loudest thing in the row.
    expect($project->get('resources/views/pages/teams/⚡edit.blade.php'))
        ->toContain('<x-ui.badge variant="outline" color="zinc">')
        ->and($project->get('resources/views/pages/teams/⚡index.blade.php'))
        ->toContain('<x-ui.badge variant="outline" color="zinc">');

    // The colour is left where it is because it does nothing in either library:
    // neither colour list has a grey, so `zinc` answers out of the same fallback
    // an uncoloured badge does. Which is why the badges that name no colour are
    // the same finding rather than a separate one — the passkey chips on the
    // security page went solid too.
    expect($project->get('resources/views/pages/settings/⚡security.blade.php'))
        ->toContain('<x-ui.badge variant="outline" size="sm">');

    // And none of the kit's badges may be left taking Sheaf's default, since
    // that default is the solid one.
    foreach ($project->blades() as $path) {
        foreach ((new TagParser)->parse($project->get($path), 'x-ui.badge') as $tag) {
            expect($tag->has('variant'))->toBeTrue();
        }
    }
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('keeps the auth pages centred once Sheaf owns their alignment', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);
    $header = $project->get('resources/views/components/auth-header.blade.php');

    // The wrapper still says text-center, but Sheaf's heading declares text-start
    // of its own and its text defaults to it, so neither inherits any more.
    expect($header)->toContain('text-center')
        ->toContain('<x-ui.heading class="text-center!"')
        // And the description is muted in the same breath, by MuteSecondaryText
        // appending to the class attribute this sweep just created.
        ->toContain('<x-ui.text class="text-center! opacity-75"');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('leaves the auth pages a button worth pressing', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // Sheaf's `solid` is a 5% neutral wash — the quiet one of its set — so
    // translating Flux's `primary` into it demoted every submit in the kit to the
    // look of the link beside it. The word means the same thing in both, and in
    // Sheaf it is what the component falls back to with no variant at all.
    foreach (['login', 'register', 'forgot-password', 'reset-password', 'confirm-password', 'verify-email'] as $page) {
        expect($project->get("resources/views/pages/auth/{$page}.blade.php"))
            ->toContain('variant="primary"')
            ->not->toContain('variant="solid"');
    }

    // The settings pages write the same button for the same reason, so they move
    // with the auth ones rather than ending up a second visual language.
    expect($project->get('resources/views/pages/settings/⚡profile.blade.php'))
        ->toContain('<x-ui.button variant="primary" type="submit" class="w-full" data-test="update-profile-button">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('gives everything in a dropdown menu a place in Sheaf\'s grid', function (): void {
    $root = migratedSheafKit('livewire-teams');

    $project = (new ProjectDetector)->detect($root);
    $menu = $project->get('resources/views/components/desktop-user-menu.blade.php');

    // The panel is a three-column grid: the profile block spans it, and the form
    // steps out of the way so its item is the grid child.
    expect($menu)->toContain('<div class="col-span-full flex items-center')
        ->toContain('<form method="POST" action="{{ route(\'logout\') }}" class="contents">');

    // Sheaf renders the modal trigger's outer element, so it is wrapped instead.
    expect($project->get('resources/views/components/⚡team-switcher.blade.php'))
        ->toContain('<div class="col-span-full">');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('opens a dropdown on the edge Flux aligned it to', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $project = (new ProjectDetector)->detect($root);

    // Flux takes a placement as two attributes and joins them itself; Sheaf
    // takes the one value Alpine Anchor reads. The member-role picker is where
    // it shows: a right-aligned trigger whose panel hung under its middle,
    // because `position="bottom"` survived as a valid, centred placement.
    expect($project->get('resources/views/pages/teams/⚡edit.blade.php'))
        ->toContain('<x-ui.dropdown position="bottom-end">');

    // And the align has to go with it, or it falls out of `{{ $attributes }}`
    // onto the panel wrapper as a stray, long-deprecated HTML attribute.
    foreach ($project->blades() as $path) {
        expect($project->get($path))->not->toContain(' align="');
    }
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('labels the items Sheaf would otherwise render empty', function (): void {
    $root = migratedSheafKit('livewire');

    $project = (new ProjectDetector)->detect($root);

    // Flux read a nav item's text out of its slot. Sheaf's renders `{{ $label }}`
    // and never touches the slot, so the settings sub-navigation came out as three
    // links with a href, a hover state and no text at all.
    expect($project->get('resources/views/pages/settings/layout.blade.php'))
        ->toContain('<x-ui.navlist.item :href="route(\'profile.edit\')" wire:navigate :label="__(\'Profile\')" />')
        ->toContain('<x-ui.navlist.item :href="route(\'security.edit\')" wire:navigate :label="__(\'Security\')" />')
        ->toContain('<x-ui.navlist.item :href="route(\'appearance.edit\')" wire:navigate :label="__(\'Appearance\')" />')
        ->not->toContain('</x-ui.navlist.item>');

    // And the appearance page's segmented control loses its three words the same
    // way, through `x-ui.radio.item`.
    expect($project->get('resources/views/pages/settings/⚡appearance.blade.php'))
        ->toContain(':label="__(\'Light\')" />')
        ->toContain(':label="__(\'Dark\')" />')
        ->toContain(':label="__(\'System\')" />');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire')), 'Run `composer fixtures`.');
it('closes a modal both ways the kit closes one', function (): void {
    $root = migratedSheafKit('livewire-teams');

    $project = (new ProjectDetector)->detect($root);

    // Cancel. Sheaf's modal listens on the window and closes only when the event
    // carries its own id, so the bare `$dispatch('close-modal')` that reads like
    // Flux's wrapper is heard and ignored — the button renders and does nothing.
    // `$data.close()` is the call Sheaf documents for this button, and it needs no
    // id: it is evaluated inside the modal's own Alpine scope.
    expect($project->get('resources/views/pages/teams/⚡invite-member-modal.blade.php'))
        ->toContain('<div class="contents" x-on:click="$data.close()">')
        ->not->toContain("\$dispatch('close-modal')");

    // And the same modal closing itself once the invitation has gone. Livewire
    // sends named arguments as the browser event's detail, and Sheaf reads
    // `detail.id` where Flux read `detail.name`.
    expect($project->get('resources/views/pages/teams/⚡invite-member-modal.blade.php'))
        ->toContain("\$this->dispatch('close-modal', id: 'invite-member')");

    // Including where the modal is named at runtime rather than in the markup.
    expect($project->get('resources/views/pages/teams/⚡remove-member-modal.blade.php'))
        ->toContain("\$this->dispatch('close-modal', id: \$this->modalName)");

    // The toast next to it keeps its own payload: this is addressed by event
    // name, not by argument name.
    expect($project->get('resources/views/pages/teams/⚡index.blade.php'))
        ->toContain("\$this->dispatch('notify', type: 'success'");

    // The kit with no teams has one of these too, on the delete-account dialog.
    expect($project->get('resources/views/pages/settings/⚡delete-user-modal.blade.php'))
        ->toContain('x-on:click="$data.close()"');

    // And the button that opens one. The rename wraps it in a trigger that opens
    // the modal by id, so the kit's own dispatch is redundant as well as
    // mis-addressed — and `x-data=""` was only there to give it a scope to run in.
    expect($project->get('resources/views/pages/teams/⚡index.blade.php'))
        ->toContain('<x-ui.button variant="primary" icon="plus" data-test="teams-new-team-button">')
        ->not->toContain('open-modal')
        ->not->toContain('x-data=""');
})->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
it('gives the tooltips on the team pages the trigger Sheaf renders', function (string $kit): void {
    $root = migratedSheafKit($kit);

    $index = (new ProjectDetector)->detect($root)->get('resources/views/pages/teams/⚡index.blade.php');

    // Flux hangs a tooltip on its child and takes the text as an attribute; Sheaf
    // renders `{{ $trigger }}` and reads a content child. A rename alone left the
    // teams list throwing "Undefined variable $trigger" before it drew a row.
    expect($index)->toContain('<x-slot:trigger>')
        ->toContain('<x-ui.tooltip.content>{{ __(\'Leave team\') }}</x-ui.tooltip.content>')
        ->not->toContain(':content=');
})->with(['livewire-teams', 'livewire-workos-teams'])
    ->skip(fn (): bool => ! is_dir(fixturePath('livewire-teams')), 'Run `composer fixtures`.');
