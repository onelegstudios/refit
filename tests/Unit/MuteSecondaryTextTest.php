<?php

declare(strict_types=1);

use Onelegstudios\Refit\Libraries\SheafLibrary;
use Onelegstudios\Refit\Plan\Actions\MuteSecondaryText;
use Onelegstudios\Refit\Plan\Actions\PreserveTextAlignment;
use Onelegstudios\Refit\Plan\Report;
use Onelegstudios\Refit\Project\ComponentStyle;
use Onelegstudios\Refit\Project\Project;

function mute(string $source, string $path = 'resources/views/test.blade.php'): string
{
    $action = new MuteSecondaryText;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    return (fn (): string => $this->transform($source, $path, $project, new Report))
        ->call($action);
}

it('mutes the secondary line Sheaf would render at body contrast', function (): void {
    expect(mute('<x-ui.text>{{ $description }}</x-ui.text>'))
        ->toBe('<x-ui.text class="opacity-75">{{ $description }}</x-ui.text>');
});

it('merges the opacity into classes the tag already has', function (): void {
    expect(mute('<x-ui.text class="mt-1">Add a passkey</x-ui.text>'))
        ->toContain('class="mt-1 opacity-75"');
});

it('takes variant="subtle" down to the fainter step', function (): void {
    // `x-ui.text` declares no props at all, so left alone `variant` lands on the
    // rendered div as a stray HTML attribute and styles nothing.
    expect(mute('<x-ui.text variant="subtle" class="text-xs">Codes</x-ui.text>'))
        ->toBe('<x-ui.text class="text-xs opacity-50">Codes</x-ui.text>');
});

it('paints color="red" red rather than dimming it', function (): void {
    // Sheaf writes its colour bare, so a plain `text-red-600` would only tie with
    // `text-neutral-950` and be decided by emission order.
    expect(mute('<x-ui.text color="red">{{ __("Invalid code") }}</x-ui.text>'))
        ->toBe('<x-ui.text class="text-red-600! dark:text-red-400!">{{ __("Invalid code") }}</x-ui.text>');
});

it('sizes a large subheading without letting it back up to full contrast', function (): void {
    // Sheaf writes its size inside `[:where(&)]:`, so a plain `text-base` wins
    // on specificity — and a large subheading is still a muted one.
    expect(mute('<x-ui.text size="lg" class="mb-6">{{ __("Settings") }}</x-ui.text>'))
        ->toBe('<x-ui.text class="mb-6 opacity-75 text-base">{{ __("Settings") }}</x-ui.text>');
});

it('drops the hand-written grey the opacity replaces', function (): void {
    // Against Flux's specificity-0 default this pair always won; against Sheaf's
    // bare `text-neutral-950` it is a tie, so it goes and the opacity does the
    // muting for the whole kit.
    expect(mute('<x-ui.text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $member["email"] }}</x-ui.text>'))
        ->toBe('<x-ui.text class="text-sm opacity-75">{{ $member["email"] }}</x-ui.text>');
});

it('leaves bound classes alone rather than editing PHP', function (): void {
    $source = '<x-ui.text :class="$classes">Hi</x-ui.text>';

    expect(mute($source))->toBe($source);
});

it('leaves a tag that already sets its own opacity alone', function (): void {
    // The desktop user menu stub writes `<x-ui.text class="truncate">` and is
    // muted by this sweep for free; the guard is what stops a later edit to that
    // stub from doubling up.
    $source = '<x-ui.text class="truncate opacity-75">{{ $email }}</x-ui.text>';

    expect(mute($source))->toBe($source);
});

it('leaves a colour the view flagged with `!` alone', function (): void {
    // The kit's success messages are deliberate, and dimming them would be a
    // rewrite nobody asked for.
    $source = '<x-ui.text class="font-medium !dark:text-green-400 !text-green-600">Saved</x-ui.text>';

    expect(mute($source))->toBe($source);
});

it('does not touch Sheaf\'s own components', function (): void {
    $source = '<x-ui.text>Hi</x-ui.text>';
    $path = SheafLibrary::COMPONENT_DIRECTORY.'/text.blade.php';

    expect(mute($source, $path))->toBe($source);
});

it('leaves every other component alone', function (): void {
    $source = '<x-ui.heading size="lg">Profile</x-ui.heading>';

    expect(mute($source))->toBe($source);
});

it('composes with the alignment sweep on the same class attribute', function (): void {
    // `verify-email.blade.php` centres a bare text tag, so both sweeps have
    // something to add to a `class` neither of them started with.
    $source = '<div class="text-center"><x-ui.text>{{ __("Sent") }}</x-ui.text></div>';

    $action = new PreserveTextAlignment;
    $project = new Project(
        root: sys_get_temp_dir(),
        componentStyle: ComponentStyle::SingleFile,
        features: [],
        libraries: [],
        chiselPending: false,
    );

    $aligned = (fn (): string => $this->transform($source, 'resources/views/test.blade.php', $project, new Report))
        ->call($action);

    expect(mute($aligned))->toContain('<x-ui.text class="text-center! opacity-75">');
});
